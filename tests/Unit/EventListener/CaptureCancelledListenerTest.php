<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\EventListener;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusPayUPlugin\Api\Exception\PayUApiException;
use Refberg\SyliusPayUPlugin\Api\PayUClientInterface;
use Refberg\SyliusPayUPlugin\Config\CredentialsResolver;
use Refberg\SyliusPayUPlugin\EventListener\CaptureCancelledListener;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Refberg\SyliusPayUPlugin\PayUGateway;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;
use Tests\Refberg\SyliusPayUPlugin\Support\FakePayU;
use Tests\Refberg\SyliusPayUPlugin\Support\MemoryLogger;

final class CaptureCancelledListenerTest extends TestCase
{
    public function test_new_payu_order_of_a_cancelled_capture_is_cancelled_in_payu(): void
    {
        $paymentRequest = self::cancelledRequest(PaymentRequestInterface::ACTION_CAPTURE, 'NEW', PayUGateway::FACTORY_NAME);
        $client = $this->createMock(PayUClientInterface::class);
        $client->expects(self::once())->method('cancelOrder')->with(self::anything(), 'ORDER1');
        $logger = new MemoryLogger();

        (new CaptureCancelledListener(new CredentialsResolver(), $client, $logger))(new CompletedEvent($paymentRequest, new Marking()));

        self::assertSame('info', $logger->records[0]['level'] ?? null);
        self::assertSame('NEW', PaymentDetails::of($paymentRequest->getPayment())->getOrderStatus('ORDER1'));
    }

    public function test_payu_error_is_only_logged(): void
    {
        $paymentRequest = self::cancelledRequest(PaymentRequestInterface::ACTION_CAPTURE, 'NEW', PayUGateway::FACTORY_NAME);
        $details = $paymentRequest->getPayment()->getDetails();
        $client = self::createStub(PayUClientInterface::class);
        $client->method('cancelOrder')->willThrowException(new PayUApiException('Canceling PayU order failed (HTTP 503).', 503));
        $logger = new MemoryLogger();

        (new CaptureCancelledListener(new CredentialsResolver(), $client, $logger))(new CompletedEvent($paymentRequest, new Marking()));

        self::assertSame('warning', $logger->records[0]['level'] ?? null);
        self::assertSame(PaymentRequestInterface::STATE_CANCELLED, $paymentRequest->getState());
        self::assertSame(['redirect_url' => 'https://merch-prod.snd.payu.com/pay/?orderId=ORDER1', 'order_id' => 'ORDER1'], $paymentRequest->getResponseData());
        self::assertSame($details, $paymentRequest->getPayment()->getDetails());
    }

    #[TestWith([PaymentRequestInterface::ACTION_CAPTURE, 'PENDING', PayUGateway::FACTORY_NAME])]
    #[TestWith([PaymentRequestInterface::ACTION_CAPTURE, 'WAITING_FOR_CONFIRMATION', PayUGateway::FACTORY_NAME])]
    #[TestWith([PaymentRequestInterface::ACTION_CAPTURE, 'COMPLETED', PayUGateway::FACTORY_NAME])]
    #[TestWith([PaymentRequestInterface::ACTION_STATUS, 'NEW', PayUGateway::FACTORY_NAME])]
    #[TestWith([PaymentRequestInterface::ACTION_CAPTURE, 'NEW', 'offline'])]
    public function test_other_requests_do_not_reach_payu(string $action, string $payuStatus, string $factoryName): void
    {
        $client = $this->createMock(PayUClientInterface::class);
        $client->expects(self::never())->method('cancelOrder');

        (new CaptureCancelledListener(new CredentialsResolver(), $client))(new CompletedEvent(self::cancelledRequest($action, $payuStatus, $factoryName), new Marking()));
    }

    private static function cancelledRequest(string $action, string $payuStatus, string $factoryName): PaymentRequestInterface
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName($factoryName);
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

        $payment = new Payment();
        $payment->setMethod($method);
        $details = PaymentDetails::of($payment);
        $details->addOrder('ORDER1', '000000001-1-000000000001', 1500, 'PLN');
        $details->recordOrderStatus('ORDER1', $payuStatus);
        $details->save();

        $paymentRequest = new PaymentRequest($payment, $method);
        $paymentRequest->setAction($action);
        $paymentRequest->setState(PaymentRequestInterface::STATE_CANCELLED);
        $paymentRequest->setResponseData(['redirect_url' => 'https://merch-prod.snd.payu.com/pay/?orderId=ORDER1', 'order_id' => 'ORDER1']);

        return $paymentRequest;
    }
}
