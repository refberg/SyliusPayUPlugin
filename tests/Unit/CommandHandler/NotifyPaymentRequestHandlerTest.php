<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\CommandHandler;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusPayUPlugin\Api\Exception\PayUApiException;
use Refberg\SyliusPayUPlugin\Api\Model\RefundCreated;
use Refberg\SyliusPayUPlugin\Command\NotifyPaymentRequest;
use Refberg\SyliusPayUPlugin\CommandHandler\NotifyPaymentRequestHandler;
use Refberg\SyliusPayUPlugin\Config\CredentialsResolver;
use Refberg\SyliusPayUPlugin\Notification\NotificationParser;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Refberg\SyliusPayUPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusPayUPlugin\Payment\PaymentSynchronizerInterface;
use Refberg\SyliusPayUPlugin\PayUGateway;
use Refberg\SyliusPayUPlugin\Signature\SignatureVerifier;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Tests\Refberg\SyliusPayUPlugin\Support\FakePayU;
use Tests\Refberg\SyliusPayUPlugin\Support\MemoryLogger;
use Tests\Refberg\SyliusPayUPlugin\Support\PaymentStateMachine;
use function array_column;
use function array_filter;
use function json_encode;
use const JSON_THROW_ON_ERROR;

final class NotifyPaymentRequestHandlerTest extends TestCase
{
    private const HASH = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';

    public function test_invalid_configuration_fails_the_request(): void
    {
        $synchronizer = $this->createMock(PaymentSynchronizerInterface::class);
        $synchronizer->expects(self::never())->method('synchronize');
        $method = self::payuMethod();
        $method->getGatewayConfig()?->setConfig(['environment' => 'sandbox']);
        [$handler, $paymentRequest] = $this->handler($synchronizer, self::orderBody('ORDER1'), method: $method);

        $handler(new NotifyPaymentRequest(self::HASH));

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(['error' => 'Payment method "PAYU" is missing the PayU "posId" setting.'], $paymentRequest->getResponseData());
    }

    public function test_rejected_notification_fails_the_request_without_locking_or_api_calls(): void
    {
        $synchronizer = $this->createMock(PaymentSynchronizerInterface::class);
        $synchronizer->expects(self::never())->method('synchronize');
        $synchronizer->expects(self::never())->method('retrieveRefund');
        $locker = $this->createMock(PaymentLockerInterface::class);
        $locker->expects(self::never())->method('lock');
        [$handler, $paymentRequest] = $this->handler($synchronizer, self::orderBody('ORDER1'), 'wrong-key', locker: $locker);

        $handler(new NotifyPaymentRequest(self::HASH));

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(['error' => 'Invalid notification signature or body.'], $paymentRequest->getResponseData());
    }

    public function test_order_not_linked_to_the_payment_is_rejected(): void
    {
        $synchronizer = $this->createMock(PaymentSynchronizerInterface::class);
        $synchronizer->expects(self::never())->method('synchronize');
        [$handler, $paymentRequest] = $this->handler($synchronizer, self::orderBody('OTHER'));

        $handler(new NotifyPaymentRequest(self::HASH));

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(['error' => 'PayU order is not linked to this payment.'], $paymentRequest->getResponseData());
    }

    #[TestWith([500])]
    #[TestWith([503])]
    #[TestWith([401])]
    #[TestWith([403])]
    public function test_errors_worth_a_retry_are_rethrown(int $httpStatus): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('synchronize')->willThrowException(new PayUApiException('failed', $httpStatus));
        [$handler, $paymentRequest] = $this->handler($synchronizer, self::orderBody('ORDER1'));

        try {
            $handler(new NotifyPaymentRequest(self::HASH));
            self::fail('Expected an exception.');
        } catch (PayUApiException) {
            self::assertSame(PaymentRequestInterface::STATE_NEW, $paymentRequest->getState());
        }
    }

    public function test_permanent_api_error_fails_the_request(): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('synchronize')->willThrowException(new PayUApiException('Retrieving PayU order failed (HTTP 400).', 400));
        [$handler, $paymentRequest] = $this->handler($synchronizer, self::orderBody('ORDER1'));

        $handler(new NotifyPaymentRequest(self::HASH));

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(['error' => 'Retrieving PayU order failed (HTTP 400).'], $paymentRequest->getResponseData());
    }

    #[TestWith([500, true])]
    #[TestWith([401, true])]
    #[TestWith([403, true])]
    #[TestWith([404, false])]
    public function test_refund_api_error_follows_the_retry_policy(int $httpStatus, bool $rethrown): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('retrieveRefund')->willThrowException(new PayUApiException('failed', $httpStatus));
        [$handler, $paymentRequest, $payment] = $this->handler($synchronizer, self::refundBody('ORDER1', '5000000001', 'FINALIZED', 1500));
        self::recordRefund($payment);

        try {
            $handler(new NotifyPaymentRequest(self::HASH));
            self::assertFalse($rethrown);
            self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        } catch (PayUApiException) {
            self::assertTrue($rethrown);
            self::assertSame(PaymentRequestInterface::STATE_NEW, $paymentRequest->getState());
        }
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame('PENDING', PaymentDetails::of($payment)->refunds()['r-1']['status'] ?? null);
    }

    public function test_refund_not_listed_by_the_api_fails_the_request_and_changes_nothing(): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('retrieveRefund')->willReturn(null);
        [$handler, $paymentRequest, $payment] = $this->handler($synchronizer, self::refundBody('ORDER1', '5000000001', 'FINALIZED', 1500));
        self::recordRefund($payment);

        $handler(new NotifyPaymentRequest(self::HASH));

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(['error' => 'The PayU API does not list this refund for the order.'], $paymentRequest->getResponseData());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame('PENDING', PaymentDetails::of($payment)->refunds()['r-1']['status'] ?? null);
    }

    public function test_refund_state_comes_from_the_api_not_from_the_notification(): void
    {
        $synchronizer = $this->createMock(PaymentSynchronizerInterface::class);
        $synchronizer->expects(self::once())->method('retrieveRefund')
            ->with(self::isInstanceOf(PaymentInterface::class), 'ORDER1', '5000000001', self::anything())
            ->willReturn(new RefundCreated('5000000001', 'r-1', 'PENDING', 1500, 'PLN'));
        [$handler, $paymentRequest, $payment] = $this->handler($synchronizer, self::refundBody('ORDER1', '5000000001', 'FINALIZED', 1500));
        self::recordRefund($payment);

        $handler(new NotifyPaymentRequest(self::HASH));

        self::assertSame(['type' => 'refund', 'refund_status' => 'PENDING'], $paymentRequest->getResponseData());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame('PENDING', PaymentDetails::of($payment)->refunds()['r-1']['status'] ?? null);
    }

    public function test_canceled_refund_is_logged_as_error(): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('retrieveRefund')->willReturn(new RefundCreated('5000000001', 'r-1', RefundCreated::STATUS_CANCELED, 1500, 'PLN'));
        $logger = new MemoryLogger();
        [$handler, $paymentRequest, $payment] = $this->handler($synchronizer, self::refundBody('ORDER1', '5000000001', 'CANCELED', 1500), logger: $logger);
        self::recordRefund($payment);

        $handler(new NotifyPaymentRequest(self::HASH));

        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $paymentRequest->getState());
        self::assertSame(['error'], array_column($logger->records, 'level'));
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
    }

    public function test_duplicate_finalized_refund_notification_refunds_the_payment_once(): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('retrieveRefund')->willReturn(new RefundCreated('5000000001', 'r-1', RefundCreated::STATUS_FINALIZED, 1500, 'PLN'));
        $stateMachine = new PaymentStateMachine();
        [$handler, , $payment, $method] = $this->handler($synchronizer, self::refundBody('ORDER1', '5000000001', 'FINALIZED', 1500), stateMachine: $stateMachine);
        self::recordRefund($payment);
        [$duplicate] = $this->handler($synchronizer, self::refundBody('ORDER1', '5000000001', 'FINALIZED', 1500), method: $method, payment: $payment, stateMachine: $stateMachine);

        $handler(new NotifyPaymentRequest(self::HASH));
        $duplicate(new NotifyPaymentRequest(self::HASH));

        self::assertSame(PaymentInterface::STATE_REFUNDED, $payment->getState());
        self::assertCount(1, array_filter($stateMachine->applied, static fn (array $applied): bool => PaymentTransitions::TRANSITION_REFUND === $applied[1]));
    }

    public function test_external_refund_of_another_payu_order_is_ignored(): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('retrieveRefund')->willReturn(new RefundCreated('7000000001', null, RefundCreated::STATUS_FINALIZED, 1500, 'PLN'));
        [$handler, $paymentRequest, $payment] = $this->handler($synchronizer, self::refundBody('ORDER2', '7000000001', 'FINALIZED', 1500));

        $handler(new NotifyPaymentRequest(self::HASH));

        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $paymentRequest->getState());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame([], PaymentDetails::of($payment)->refunds());
    }

    #[TestWith([1501])]
    #[TestWith([0])]
    public function test_external_refund_outside_the_payment_amount_is_ignored(int $amount): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('retrieveRefund')->willReturn(new RefundCreated('7000000001', null, RefundCreated::STATUS_FINALIZED, $amount, 'PLN'));
        [$handler, , $payment] = $this->handler($synchronizer, self::refundBody('ORDER1', '7000000001', 'FINALIZED', 1500));

        $handler(new NotifyPaymentRequest(self::HASH));

        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame([], PaymentDetails::of($payment)->refunds());
    }

    public function test_external_refund_of_the_whole_payment_refunds_it(): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('retrieveRefund')->willReturn(new RefundCreated('7000000001', null, RefundCreated::STATUS_FINALIZED, 1500, 'PLN'));
        [$handler, , $payment] = $this->handler($synchronizer, self::refundBody('ORDER1', '7000000001', 'PENDING', 1));

        $handler(new NotifyPaymentRequest(self::HASH));

        self::assertSame(PaymentInterface::STATE_REFUNDED, $payment->getState());
        self::assertSame(1500, PaymentDetails::of($payment)->finalizedRefundAmount());
    }

    /**
     * @return array{NotifyPaymentRequestHandler, PaymentRequestInterface, Payment, PaymentMethod}
     */
    private function handler(
        PaymentSynchronizerInterface $synchronizer,
        string $body,
        string $secondKey = FakePayU::SECOND_KEY,
        ?PaymentMethod $method = null,
        ?Payment $payment = null,
        ?PaymentLockerInterface $locker = null,
        PaymentStateMachine $stateMachine = new PaymentStateMachine(),
        LoggerInterface $logger = new NullLogger(),
    ): array {
        $method ??= self::payuMethod();
        $payment ??= self::paidPayment($method);
        $paymentRequest = new PaymentRequest($payment, $method);
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_NOTIFY);
        $paymentRequest->setPayload(['http_request' => [
            'content' => $body,
            'headers' => ['openpayu-signature' => [FakePayU::signatureHeader($body, $secondKey)]],
        ]]);

        $paymentRequestProvider = self::createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $handler = new NotifyPaymentRequestHandler(
            $paymentRequestProvider,
            new CredentialsResolver(),
            new NotificationParser(new SignatureVerifier()),
            $synchronizer,
            $locker ?? self::createStub(PaymentLockerInterface::class),
            $stateMachine,
            $logger,
        );

        return [$handler, $paymentRequest, $payment, $method];
    }

    private static function payuMethod(): PaymentMethod
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName(PayUGateway::FACTORY_NAME);
        $gatewayConfig->setConfig([
            'environment' => 'sandbox',
            'pos_id' => FakePayU::POS_ID,
            'second_key' => FakePayU::SECOND_KEY,
            'oauth_client_id' => FakePayU::CLIENT_ID,
            'oauth_client_secret' => FakePayU::CLIENT_SECRET,
        ]);
        $method = new PaymentMethod();
        $method->setCode('PAYU');
        $method->setGatewayConfig($gatewayConfig);

        return $method;
    }

    private static function paidPayment(PaymentMethod $method): Payment
    {
        $payment = new class() extends Payment {
            public function getId(): int
            {
                return 42;
            }
        };
        $payment->setMethod($method);
        $payment->setAmount(1500);
        $payment->setCurrencyCode('PLN');
        $payment->setState(PaymentInterface::STATE_COMPLETED);
        $details = PaymentDetails::of($payment);
        $details->addOrder('ORDER1', '000000001-42-ORDER1', 1500, 'PLN');
        $details->addOrder('ORDER2', '000000001-42-ORDER2', 1500, 'PLN');
        $details->setCompletedOrderId('ORDER1');
        $details->save();

        return $payment;
    }

    private static function recordRefund(Payment $payment): void
    {
        $details = PaymentDetails::of($payment);
        $details->recordRefund('r-1', '5000000001', 'PENDING', 1500, 'PLN');
        $details->save();
    }

    private static function orderBody(string $orderId): string
    {
        return json_encode(['order' => ['orderId' => $orderId, 'extOrderId' => '000000001-42-' . $orderId, 'status' => 'COMPLETED']], JSON_THROW_ON_ERROR);
    }

    private static function refundBody(string $orderId, string $refundId, string $status, int $amount): string
    {
        return json_encode([
            'orderId' => $orderId,
            'extOrderId' => '000000001-42-' . $orderId,
            'refund' => ['refundId' => $refundId, 'extRefundId' => 'r-1', 'amount' => (string) $amount, 'currencyCode' => 'PLN', 'status' => $status],
        ], JSON_THROW_ON_ERROR);
    }
}
