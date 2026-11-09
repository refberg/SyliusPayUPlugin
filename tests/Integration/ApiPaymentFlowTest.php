<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Integration;

use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\Response;
use function json_encode;
use function ksort;
use const JSON_THROW_ON_ERROR;

/**
 * Headless checkout through Sylius API Platform endpoints, without any shop UI.
 */
final class ApiPaymentFlowTest extends IntegrationTestCase
{
    public function test_api_client_gets_the_payu_redirect_url_and_checks_status(): void
    {
        $payment = $this->createPlacedOrder();

        $created = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'payload' => ['continue_url' => 'https://app.example/payment/return'],
        ]);

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame('capture', $created['action']);
        self::assertSame('processing', $created['state']);
        $orderId = $this->payu->lastOrderId();
        self::assertSame('https://merch-prod.snd.payu.com/pay/?orderId=' . $orderId, self::at($created, 'responseData', 'redirect_url'));
        self::assertSame('https://app.example/payment/return', $this->payu->order($orderId)['continueUrl']);

        // Payment still unpaid; the client asks for the status after the customer returns.
        $this->payu->setOrderStatus($orderId, 'COMPLETED');
        $status = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'action' => 'status',
        ]);

        self::assertSame('completed', $status['state']);
        self::assertSame('completed', self::at($status, 'responseData', 'payment_state'));
        self::assertSame('COMPLETED', self::at($status, 'responseData', 'payu_status'));
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    public function test_invalid_continue_url_fails_the_request_without_calling_payu(): void
    {
        $payment = $this->createPlacedOrder();

        $created = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'payload' => ['continue_url' => 'javascript:alert(1)'],
        ]);

        self::assertSame('failed', $created['state']);
        self::assertSame([], $this->payu->orderIds());
        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_processing_payu_payment_rejects_a_method_change_but_not_a_payment_request_with_another_method(): void
    {
        $payment = $this->createPlacedOrder();
        $paymentId = $payment->getId();
        self::assertIsInt($paymentId);
        $this->createBankTransferMethod($this->reloadPayment($payment));
        $this->em()->flush();
        $this->postPaymentRequest(['paymentId' => $payment->getId(), 'paymentMethodCode' => self::METHOD_CODE, 'payload' => ['continue_url' => 'https://app.example/payment/return']]);
        $orderId = $this->payu->lastOrderId();
        $this->payu->setOrderStatus($orderId, 'PENDING');
        $this->postPaymentRequest(['paymentId' => $payment->getId(), 'paymentMethodCode' => self::METHOD_CODE, 'action' => 'status']);

        $this->client->request(
            'PATCH',
            '/api/v2/shop/orders/order-token/payments/' . $paymentId,
            server: ['CONTENT_TYPE' => 'application/merge-patch+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['paymentMethod' => '/api/v2/shop/payment-methods/BANK_TRANSFER'], JSON_THROW_ON_ERROR),
        );
        self::assertGreaterThanOrEqual(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame(self::METHOD_CODE, $this->reloadPayment($payment)->getMethod()?->getCode());

        $this->postPaymentRequest(['paymentId' => $payment->getId(), 'paymentMethodCode' => 'BANK_TRANSFER']);
        // Sylius creates the request for the other gateway; the Payum offline gateway of this test fails it without a target_path payload.
        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $this->client->getResponse()->getStatusCode());

        $captureStates = [];
        foreach ($this->paymentRequests(PaymentRequestInterface::ACTION_CAPTURE) as $capture) {
            $captureStates[(string) $capture->getMethod()->getCode()] = $capture->getState();
        }
        ksort($captureStates);
        self::assertSame(['BANK_TRANSFER' => PaymentRequestInterface::STATE_NEW, self::METHOD_CODE => PaymentRequestInterface::STATE_PROCESSING], $captureStates);
        $payment = $this->reloadPayment($payment);
        self::assertSame('processing', $payment->getState());
        self::assertSame(self::METHOD_CODE, $payment->getMethod()?->getCode());
    }

    public function test_refund_action_is_not_available_to_shop_api_clients(): void
    {
        $payment = $this->createPlacedOrder();

        $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'action' => 'refund',
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->payu->requests('POST', '#/refunds$#'));
    }
}
