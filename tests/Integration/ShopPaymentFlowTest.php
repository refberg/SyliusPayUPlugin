<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\Response;
use Tests\Refberg\SyliusPayUPlugin\Support\FakePayU;
use function json_encode;
use function parse_url;
use function str_replace;
use const JSON_THROW_ON_ERROR;
use const PHP_URL_PATH;

final class ShopPaymentFlowTest extends IntegrationTestCase
{
    public function test_customer_is_redirected_to_payu_with_server_side_amount(): void
    {
        $payment = $this->createPlacedOrder();

        $redirect = $this->payInShop();

        $orderId = $this->payu->lastOrderId();
        self::assertSame('https://merch-prod.snd.payu.com/pay/?orderId=' . $orderId, $redirect);

        $order = $this->payu->order($orderId);
        self::assertSame('1500', $order['totalAmount']);
        self::assertSame('PLN', $order['currencyCode']);
        self::assertSame(FakePayU::POS_ID, $order['merchantPosId']);
        self::assertSame('http://localhost/payment-methods/PAYU', $order['notifyUrl']);
        self::assertMatchesRegularExpression('#^http://localhost/en_US/order/after-pay/[0-9a-f-]{36}$#', $order['continueUrl']);
        $paymentId = $payment->getId();
        self::assertIsInt($paymentId);
        self::assertMatchesRegularExpression('#^000000001-' . $paymentId . '-[0-9a-f]{12}$#', $order['extOrderId']);

        self::assertSame('new', $this->reloadPayment($payment)->getState());
        self::assertSame($orderId, $this->details($payment)->getCurrentOrderId());
    }

    public function test_opening_the_pay_page_again_reuses_the_payu_order(): void
    {
        $this->createPlacedOrder();

        $first = $this->payInShop();
        $second = $this->payInShop();

        self::assertSame($first, $second);
        self::assertCount(1, $this->payu->orderIds());
    }

    public function test_returning_from_payu_does_not_pay_the_order_by_itself(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();

        // The return URL can be opened by anyone and PayU may append "?error=501": it is not proof of payment.
        $this->client->request('GET', parse_url($this->payu->order($orderId)['continueUrl'], PHP_URL_PATH) . '?error=501');

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame('new', $this->reloadPayment($payment)->getState());
        self::assertCount(1, $this->payu->requests('GET', '#^/api/v2_1/orders/#'));
    }

    public function test_returning_while_payu_is_processing_shows_thank_you_page_and_keeps_payment_unpaid(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();
        $this->payu->setOrderStatus($orderId, 'PENDING');

        $this->client->request('GET', (string) parse_url($this->payu->order($orderId)['continueUrl'], PHP_URL_PATH));

        self::assertStringContainsString('/order/thank-you', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_returning_after_payu_completed_the_payment_marks_it_paid(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();
        $this->payu->setOrderStatus($orderId, 'COMPLETED');

        $this->client->request('GET', (string) parse_url($this->payu->order($orderId)['continueUrl'], PHP_URL_PATH));

        self::assertStringContainsString('/order/thank-you', (string) $this->client->getResponse()->headers->get('Location'));
        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame('paid', $payment->getOrder()?->getPaymentState());
    }

    public function test_signed_completed_notification_pays_the_order(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();
        $this->payu->setOrderStatus($orderId, 'COMPLETED');

        $response = $this->notifyOrder($orderId);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame('paid', $payment->getOrder()?->getPaymentState());
        self::assertSame($orderId, PaymentDetails::of($payment)->getCompletedOrderId());
    }

    public function test_duplicated_notifications_are_accepted_and_change_nothing(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();
        $this->payu->setOrderStatus($orderId, 'COMPLETED');

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId)->getStatusCode());
        }

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame([], PaymentDetails::of($payment)->warnings());
        self::assertCount(3, $this->paymentRequests(PaymentRequestInterface::ACTION_NOTIFY));
    }

    public function test_notification_with_wrong_signature_is_rejected_and_not_stored(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();
        $this->payu->setOrderStatus($orderId, 'COMPLETED');
        $body = $this->payu->orderNotificationBody($orderId);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($body, FakePayU::signatureHeader($body, 'attacker-key'))->getStatusCode());
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($body, null)->getStatusCode());
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($body, 'sender=checkout;signature=abc;algorithm=MD5')->getStatusCode());

        $tampered = str_replace('"status":"COMPLETED"', '"status":"CANCELED"', $body);
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($tampered, FakePayU::signatureHeader($body))->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
        self::assertCount(0, $this->paymentRequests(PaymentRequestInterface::ACTION_NOTIFY));
        self::assertCount(0, $this->payu->requests('GET', '#^/api/v2_1/orders/#'));
    }

    public function test_notification_for_an_unknown_payu_order_is_rejected(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();
        $extOrderId = $this->payu->order($orderId)['extOrderId'];

        $body = json_encode(['order' => ['orderId' => 'FOREIGNORDER', 'extOrderId' => $extOrderId, 'status' => 'COMPLETED']], JSON_THROW_ON_ERROR);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->notify($body, FakePayU::signatureHeader($body))->getStatusCode());

        $body = json_encode(['order' => ['orderId' => $orderId, 'extOrderId' => 'other-999-abcdef012345', 'status' => 'COMPLETED']], JSON_THROW_ON_ERROR);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->notify($body, FakePayU::signatureHeader($body))->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_notification_for_a_method_without_credentials_returns_503_so_payu_retries(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();
        $this->payu->setOrderStatus($orderId, 'COMPLETED');
        $this->createPayUMethod('PAYU2', FakePayU::POS_ID, FakePayU::SECOND_KEY)->getGatewayConfig()?->setConfig(['environment' => 'sandbox']);
        $this->em()->flush();
        $body = $this->payu->orderNotificationBody($orderId);

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $this->notify($body, FakePayU::signatureHeader($body), 'PAYU2')->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
        self::assertCount(0, $this->paymentRequests(PaymentRequestInterface::ACTION_NOTIFY));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mismatches(): iterable
    {
        yield 'amount' => ['totalAmount', '1'];
        yield 'currency' => ['currencyCode', 'EUR'];
        yield 'ext order id' => ['extOrderId', 'tampered-1-abcdef012345'];
        yield 'pos' => ['merchantPosId', '999999'];
    }

    #[DataProvider('mismatches')]
    public function test_order_that_does_not_match_the_payment_never_pays_it(string $field, string $value): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();
        $body = $this->payu->orderNotificationBody($orderId, 'COMPLETED');

        $this->payu->setOrderStatus($orderId, 'COMPLETED');
        $this->payu->overrideOrder($orderId, $field, $value);

        self::assertSame(Response::HTTP_OK, $this->notify($body, FakePayU::signatureHeader($body))->getStatusCode());
        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_notification_body_status_is_not_trusted(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();

        // Correctly signed, claims COMPLETED, but PayU API still reports NEW.
        self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId, 'COMPLETED')->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_late_pending_and_canceled_notifications_do_not_undo_the_payment(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();
        $this->payu->setOrderStatus($orderId, 'COMPLETED');
        $this->notifyOrder($orderId);

        self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId, 'PENDING')->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId, 'CANCELED')->getStatusCode());

        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    public function test_canceled_payu_order_fails_the_payment(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();
        $this->payu->setOrderStatus($orderId, 'CANCELED');

        self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId)->getStatusCode());

        self::assertSame('failed', $this->reloadPayment($payment)->getState());
    }

    public function test_two_paid_payu_orders_never_pay_the_sylius_order_twice(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $firstOrderId = $this->payu->lastOrderId();

        // The first attempt is abandoned; the customer starts a new one.
        $this->finalizeCaptureRequests(PaymentRequestTransitions::TRANSITION_FAIL);
        $this->payInShop();
        $secondOrderId = $this->payu->lastOrderId();
        self::assertNotSame($firstOrderId, $secondOrderId);

        $this->payu->setOrderStatus($secondOrderId, 'COMPLETED');
        $this->notifyOrder($secondOrderId);
        $this->payu->setOrderStatus($firstOrderId, 'COMPLETED');
        self::assertSame(Response::HTTP_OK, $this->notifyOrder($firstOrderId)->getStatusCode());

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        $details = PaymentDetails::of($payment);
        self::assertSame($secondOrderId, $details->getCompletedOrderId());
        self::assertSame([['type' => PaymentDetails::WARNING_DUPLICATE_PAYMENT, 'order_id' => $firstOrderId]], $details->warnings());
    }

    public function test_transient_payu_error_returns_5xx_so_payu_retries(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();
        $this->payu->setOrderStatus($orderId, 'COMPLETED');
        $this->payu->failNextRequest(503);

        $this->client->catchExceptions(true);
        self::assertGreaterThanOrEqual(500, $this->notifyOrder($orderId)->getStatusCode());
        self::assertSame('new', $this->reloadPayment($payment)->getState());

        self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId)->getStatusCode());
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    #[TestWith([401])]
    #[TestWith([403])]
    public function test_payu_api_rejecting_the_plugin_credentials_returns_5xx_so_payu_retries(int $httpStatus): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();
        $this->payu->setOrderStatus($orderId, 'COMPLETED');
        $this->payu->failNextRequest($httpStatus, 'UNAUTHORIZED');
        if (401 === $httpStatus) {
            // The client retries a 401 once with a fresh token.
            $this->payu->failNextRequest($httpStatus, 'UNAUTHORIZED');
        }

        $this->client->catchExceptions(true);
        self::assertGreaterThanOrEqual(500, $this->notifyOrder($orderId)->getStatusCode());
        self::assertSame('new', $this->reloadPayment($payment)->getState());

        self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId)->getStatusCode());
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    #[TestWith(['notification'])]
    #[TestWith(['return'])]
    public function test_payment_made_in_payu_after_the_customer_switched_payment_method_completes_it_with_a_warning(string $confirmedBy): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();

        $bankTransfer = $this->factory('payment_method')->createNew();
        self::assertInstanceOf(PaymentMethodInterface::class, $bankTransfer);
        $bankTransfer->setCode('BANK_TRANSFER');
        $bankTransfer->setCurrentLocale('en_US');
        $bankTransfer->setFallbackLocale('en_US');
        $bankTransfer->setName('Bank transfer');
        $payment = $this->reloadPayment($payment);
        $payment->setMethod($bankTransfer);
        $this->em()->persist($bankTransfer);
        $this->em()->flush();

        $this->payu->setOrderStatus($orderId, 'COMPLETED');
        $continuePath = (string) parse_url($this->payu->order($orderId)['continueUrl'], PHP_URL_PATH);
        if ('notification' === $confirmedBy) {
            self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId)->getStatusCode());
            $this->client->request('GET', $continuePath);
            self::assertTrue($this->client->getResponse()->isRedirect());
        } else {
            $this->client->request('GET', $continuePath);
            self::assertTrue($this->client->getResponse()->isRedirect());
            self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId)->getStatusCode());
        }

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame(self::METHOD_CODE, $payment->getMethod()?->getCode());
        $details = PaymentDetails::of($payment);
        self::assertSame($orderId, $details->getCompletedOrderId());
        self::assertSame([['type' => PaymentDetails::WARNING_PAYMENT_METHOD_CHANGED, 'order_id' => $orderId]], $details->warnings());

        // The restored PayU method lets an admin refund reach PayU.
        $stateMachine = self::service(StateMachineInterface::class, 'sylius_abstraction.state_machine');
        $stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        $this->em()->flush();

        self::assertCount(1, $this->payu->requests('POST', '#/orders/' . $orderId . '/refunds$#'));
    }

    #[TestWith(['notification'])]
    #[TestWith(['return'])]
    public function test_pending_payu_order_after_the_customer_switched_payment_method_keeps_the_new_method(string $checkedBy): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();

        $bankTransfer = $this->factory('payment_method')->createNew();
        self::assertInstanceOf(PaymentMethodInterface::class, $bankTransfer);
        $bankTransfer->setCode('BANK_TRANSFER');
        $bankTransfer->setCurrentLocale('en_US');
        $bankTransfer->setFallbackLocale('en_US');
        $bankTransfer->setName('Bank transfer');
        $payment = $this->reloadPayment($payment);
        $payment->setMethod($bankTransfer);
        $this->em()->persist($bankTransfer);
        $this->em()->flush();

        $this->payu->setOrderStatus($orderId, 'PENDING');
        if ('notification' === $checkedBy) {
            self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId)->getStatusCode());
        } else {
            $this->client->request('GET', (string) parse_url($this->payu->order($orderId)['continueUrl'], PHP_URL_PATH));
            self::assertTrue($this->client->getResponse()->isRedirect());
        }

        $payment = $this->reloadPayment($payment);
        self::assertSame('new', $payment->getState());
        self::assertSame('BANK_TRANSFER', $payment->getMethod()?->getCode());
        self::assertSame([], PaymentDetails::of($payment)->warnings());
    }

    public function test_payment_made_in_the_old_payu_order_after_switching_to_another_payu_method_is_refunded_with_the_paid_method(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();

        $payment = $this->reloadPayment($payment);
        $payment->setMethod($this->createPayUMethod('PAYU2', '999999', 'other-second-key'));
        $this->em()->flush();

        $this->payu->setOrderStatus($orderId, 'COMPLETED');
        $this->client->request('GET', (string) parse_url($this->payu->order($orderId)['continueUrl'], PHP_URL_PATH));
        self::assertTrue($this->client->getResponse()->isRedirect());

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame(self::METHOD_CODE, $payment->getMethod()?->getCode());
        self::assertSame([['type' => PaymentDetails::WARNING_PAYMENT_METHOD_CHANGED, 'order_id' => $orderId]], PaymentDetails::of($payment)->warnings());

        $stateMachine = self::service(StateMachineInterface::class, 'sylius_abstraction.state_machine');
        $stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        $this->em()->flush();

        self::assertSame(self::METHOD_CODE, $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND)[0]->getMethod()->getCode());
        self::assertCount(1, $this->payu->requests('POST', '#/orders/' . $orderId . '/refunds$#'));
    }

    public function test_notification_for_another_payu_method_never_pays_the_payment(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->payu->lastOrderId();
        $this->createPayUMethod('PAYU2', '999999', 'other-second-key');
        $this->em()->flush();
        $this->payu->setOrderStatus($orderId, 'COMPLETED');
        $body = $this->payu->orderNotificationBody($orderId);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($body, FakePayU::signatureHeader($body), 'PAYU2')->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->notify($body, FakePayU::signatureHeader($body, 'other-second-key'), 'PAYU2')->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    private function finalizeCaptureRequests(string $transition): void
    {
        $stateMachine = self::service(StateMachineInterface::class, 'sylius_abstraction.state_machine');

        foreach ($this->paymentRequests(PaymentRequestInterface::ACTION_CAPTURE) as $request) {
            if ($stateMachine->can($request, PaymentRequestTransitions::GRAPH, $transition)) {
                $stateMachine->apply($request, PaymentRequestTransitions::GRAPH, $transition);
            }
        }
        $this->em()->flush();
    }
}
