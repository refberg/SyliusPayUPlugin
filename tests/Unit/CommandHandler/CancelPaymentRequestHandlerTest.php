<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\CommandHandler;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPayUPlugin\Api\Exception\PayUApiException;
use Refberg\SyliusPayUPlugin\Api\Model\PayUOrder;
use Refberg\SyliusPayUPlugin\Api\PayUClientInterface;
use Refberg\SyliusPayUPlugin\Command\CancelPaymentRequest;
use Refberg\SyliusPayUPlugin\CommandHandler\CancelPaymentRequestHandler;
use Refberg\SyliusPayUPlugin\Config\CredentialsResolver;
use Refberg\SyliusPayUPlugin\Config\PayUCredentials;
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

final class CancelPaymentRequestHandlerTest extends TestCase
{
    public function test_order_that_cannot_be_read_after_the_cancel_fails_the_request(): void
    {
        $client = self::createStub(PayUClientInterface::class);
        $client->method('retrieveOrder')->willThrowException(new PayUApiException('Retrieving PayU order failed (HTTP 503).', 503));
        $logger = new MemoryLogger();

        [$payment, $request] = $this->handle($client, ['ORDER1'], $logger);

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $request->getState());
        self::assertSame(['error' => 'Retrieving PayU order failed (HTTP 503).'], $request->getResponseData());
        self::assertSame(PayUOrder::STATUS_NEW, PaymentDetails::of($payment)->getOrderStatus('ORDER1'));
        self::assertSame('error', $logger->records[0]['level'] ?? null);
    }

    public function test_order_that_is_not_canceled_after_the_cancel_fails_the_request(): void
    {
        $client = self::createStub(PayUClientInterface::class);
        $client->method('retrieveOrder')->willReturn(self::order('ORDER1', PayUOrder::STATUS_NEW));
        $logger = new MemoryLogger();

        [$payment, $request] = $this->handle($client, ['ORDER1'], $logger);

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $request->getState());
        self::assertSame(['error' => 'PayU order "ORDER1" is "NEW" after the cancel request.'], $request->getResponseData());
        self::assertSame(PayUOrder::STATUS_NEW, PaymentDetails::of($payment)->getOrderStatus('ORDER1'));
        self::assertSame('error', $logger->records[0]['level'] ?? null);
        self::assertSame(['payment_id' => 42, 'payu_order_id' => 'ORDER1', 'reason' => 'PayU order "ORDER1" is "NEW" after the cancel request.'], $logger->records[0]['context']);
    }

    public function test_canceled_order_is_recorded_when_another_order_of_the_payment_stays_completed(): void
    {
        $client = self::createStub(PayUClientInterface::class);
        $client->method('retrieveOrder')->willReturnCallback(static fn (PayUCredentials $credentials, string $orderId): PayUOrder => self::order(
            $orderId,
            'ORDER1' === $orderId ? PayUOrder::STATUS_CANCELED : PayUOrder::STATUS_COMPLETED,
        ));

        [$payment, $request] = $this->handle($client, ['ORDER1', 'ORDER2']);

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $request->getState());
        self::assertSame(['error' => 'PayU order "ORDER2" is "COMPLETED" after the cancel request.'], $request->getResponseData());
        $details = PaymentDetails::of($payment);
        self::assertSame(PayUOrder::STATUS_CANCELED, $details->getOrderStatus('ORDER1'));
        self::assertSame(PayUOrder::STATUS_NEW, $details->getOrderStatus('ORDER2'));
    }

    /**
     * @param list<string> $orderIds
     *
     * @return array{Payment, PaymentRequestInterface}
     */
    private function handle(PayUClientInterface $client, array $orderIds, MemoryLogger $logger = new MemoryLogger()): array
    {
        $method = self::payuMethod();
        $payment = new class() extends Payment {
            public function getId(): int
            {
                return 42;
            }
        };
        $payment->setMethod($method);
        $payment->setState(PaymentInterface::STATE_CANCELLED);
        $details = PaymentDetails::of($payment);
        foreach ($orderIds as $orderId) {
            $details->addOrder($orderId, '000000001-42-' . $orderId, 1500, 'PLN');
        }
        $details->save();

        $request = new PaymentRequest($payment, $method);
        $request->setAction(PaymentRequestInterface::ACTION_CANCEL);

        $paymentRequestProvider = self::createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($request);

        $handler = new CancelPaymentRequestHandler(
            $paymentRequestProvider,
            new CredentialsResolver(),
            $client,
            self::createStub(PaymentLockerInterface::class),
            new PaymentStateMachine(),
            $logger,
        );

        $handler(new CancelPaymentRequest('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));

        return [$payment, $request];
    }

    private static function order(string $orderId, string $status): PayUOrder
    {
        return new PayUOrder($orderId, '000000001-42-' . $orderId, $status, 1500, 'PLN', FakePayU::POS_ID);
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
