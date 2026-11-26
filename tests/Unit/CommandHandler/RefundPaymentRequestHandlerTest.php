<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\CommandHandler;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPayUPlugin\Api\PayUClientInterface;
use Refberg\SyliusPayUPlugin\Command\RefundPaymentRequest;
use Refberg\SyliusPayUPlugin\CommandHandler\RefundPaymentRequestHandler;
use Refberg\SyliusPayUPlugin\Config\CredentialsResolver;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Refberg\SyliusPayUPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusPayUPlugin\PayUGateway;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Tests\Refberg\SyliusPayUPlugin\Support\FakePayU;
use Tests\Refberg\SyliusPayUPlugin\Support\MemoryLogger;
use Tests\Refberg\SyliusPayUPlugin\Support\PaymentStateMachine;

final class RefundPaymentRequestHandlerTest extends TestCase
{
    public function test_request_that_is_not_new_is_ignored(): void
    {
        $client = $this->createMock(PayUClientInterface::class);
        $client->expects(self::never())->method('createRefund');
        $locker = $this->createMock(PaymentLockerInterface::class);
        $locker->expects(self::never())->method('lock');

        [$payment, $request] = $this->handle($client, completedOrderId: 'ORDER1', requestState: PaymentRequestInterface::STATE_PROCESSING, locker: $locker);

        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $request->getState());
        self::assertSame([], $request->getResponseData());
        self::assertSame([], PaymentDetails::of($payment)->refunds());
    }

    public function test_payment_without_a_completed_payu_order_fails_the_request_without_calling_payu(): void
    {
        $client = $this->createMock(PayUClientInterface::class);
        $client->expects(self::never())->method('createRefund');
        $logger = new MemoryLogger();
        $locker = $this->createMock(PaymentLockerInterface::class);
        $locker->expects(self::once())->method('lock');

        [, $request] = $this->handle($client, completedOrderId: null, logger: $logger, locker: $locker);

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $request->getState());
        self::assertSame(['error' => 'No completed PayU order for this payment.'], $request->getResponseData());
        self::assertSame('error', $logger->records[0]['level'] ?? null);
        self::assertSame(['payment_id' => 42], $logger->records[0]['context']);
    }

    public function test_active_refund_completes_the_request_without_a_second_refund(): void
    {
        $client = $this->createMock(PayUClientInterface::class);
        $client->expects(self::never())->method('createRefund');

        [$payment, $request] = $this->handle($client, completedOrderId: 'ORDER1', activeRefund: true);

        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $request->getState());
        self::assertSame(['order_id' => 'ORDER1', 'already_requested' => true], $request->getResponseData());
        self::assertCount(1, PaymentDetails::of($payment)->refunds());
    }

    /**
     * @return array{Payment, PaymentRequestInterface}
     */
    private function handle(
        PayUClientInterface $client,
        ?string $completedOrderId,
        string $requestState = PaymentRequestInterface::STATE_NEW,
        bool $activeRefund = false,
        MemoryLogger $logger = new MemoryLogger(),
        ?PaymentLockerInterface $locker = null,
    ): array {
        $method = self::payuMethod();
        $payment = new class() extends Payment {
            public function getId(): int
            {
                return 42;
            }
        };
        $payment->setMethod($method);
        $payment->setAmount(1500);
        $payment->setCurrencyCode('PLN');
        $payment->setState(PaymentInterface::STATE_REFUNDED);
        $details = PaymentDetails::of($payment);
        $details->addOrder('ORDER1', '000000001-42-aaaaaaaaaaaa', 1500, 'PLN');
        if (null !== $completedOrderId) {
            $details->setCompletedOrderId($completedOrderId);
        }
        if ($activeRefund) {
            $details->recordRefund('3f2504e0-4f89-11d3-9a0c-0305e82c3300', '5000001', 'PENDING', 1500, 'PLN');
        }
        $details->save();

        $request = new PaymentRequest($payment, $method);
        $request->setAction(PaymentRequestInterface::ACTION_REFUND);
        $request->setState($requestState);

        $paymentRequestProvider = self::createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($request);

        $handler = new RefundPaymentRequestHandler(
            $paymentRequestProvider,
            new CredentialsResolver(),
            $client,
            $locker ?? self::createStub(PaymentLockerInterface::class),
            new PaymentStateMachine(),
            $logger,
        );

        $handler(new RefundPaymentRequest('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));

        return [$payment, $request];
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
}
