<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\CommandHandler;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPayUPlugin\Api\AccessTokenProvider;
use Refberg\SyliusPayUPlugin\Api\PayUClient;
use Refberg\SyliusPayUPlugin\Command\CapturePaymentRequest;
use Refberg\SyliusPayUPlugin\CommandHandler\CapturePaymentRequestHandler;
use Refberg\SyliusPayUPlugin\Config\CredentialsResolver;
use Refberg\SyliusPayUPlugin\Config\PayUCredentials;
use Refberg\SyliusPayUPlugin\Order\ExtOrderIdGenerator;
use Refberg\SyliusPayUPlugin\Order\OrderRequestFactoryInterface;
use Refberg\SyliusPayUPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusPayUPlugin\PayUGateway;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Tests\Refberg\SyliusPayUPlugin\Support\FakePayU;
use Tests\Refberg\SyliusPayUPlugin\Support\PaymentStateMachine;

final class CapturePaymentRequestHandlerTest extends TestCase
{
    public function test_payment_completed_while_waiting_for_the_lock_gets_no_new_payu_order(): void
    {
        $payu = new FakePayU();
        $httpClient = new MockHttpClient($payu);

        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName(PayUGateway::FACTORY_NAME);
        $gatewayConfig->setGatewayName('payu');
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

        $payment = new class() extends Payment {
            public function getId(): int
            {
                return 42;
            }
        };
        $payment->setMethod($method);
        $payment->setAmount(1500);
        $payment->setCurrencyCode('PLN');
        $payment->setState(PaymentInterface::STATE_NEW);

        $paymentRequest = new class($payment, $method) extends PaymentRequest {
            public function getId(): string
            {
                return '3f2504e0-4f89-11d3-9a0c-0305e82c3301';
            }
        };

        $paymentRequestProvider = self::createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $orderRequestFactory = self::createStub(OrderRequestFactoryInterface::class);
        $orderRequestFactory->method('create')->willReturnCallback(static fn (PaymentRequestInterface $request, PaymentInterface $payment, PayUCredentials $credentials, string $extOrderId): array => [
            'notifyUrl' => 'https://shop.example/payment-methods/PAYU',
            'customerIp' => '127.0.0.1',
            'merchantPosId' => FakePayU::POS_ID,
            'description' => 'Order',
            'currencyCode' => 'PLN',
            'totalAmount' => '1500',
            'extOrderId' => $extOrderId,
            'products' => [['name' => 'Order', 'unitPrice' => '1500', 'quantity' => '1']],
        ]);

        // A notification completes the payment while the capture waits for the lock.
        $paymentLocker = self::createStub(PaymentLockerInterface::class);
        $paymentLocker->method('lock')->willReturnCallback(static function (PaymentInterface $locked): void {
            $locked->setState(PaymentInterface::STATE_COMPLETED);
        });

        $handler = new CapturePaymentRequestHandler(
            $paymentRequestProvider,
            new CredentialsResolver(),
            new PayUClient($httpClient, new AccessTokenProvider($httpClient, new ArrayAdapter())),
            $orderRequestFactory,
            new ExtOrderIdGenerator(),
            $paymentLocker,
            new PaymentStateMachine(),
        );

        $handler(new CapturePaymentRequest('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));

        self::assertSame([], $payu->requests('POST', '#^/api/v2_1/orders$#'));
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(['error' => 'Payment in state "completed" cannot be paid.'], $paymentRequest->getResponseData());
    }
}
