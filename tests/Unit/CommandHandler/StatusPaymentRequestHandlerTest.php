<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\CommandHandler;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusPayUPlugin\Command\StatusPaymentRequest;
use Refberg\SyliusPayUPlugin\CommandHandler\StatusPaymentRequestHandler;
use Refberg\SyliusPayUPlugin\Config\CredentialsResolver;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Refberg\SyliusPayUPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusPayUPlugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusPayUPlugin\Payment\PaymentSynchronizerInterface;
use Refberg\SyliusPayUPlugin\PayUGateway;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Tests\Refberg\SyliusPayUPlugin\Support\FakePayU;
use Tests\Refberg\SyliusPayUPlugin\Support\PaymentStateMachine;
use function array_filter;
use function array_values;
use function in_array;

final class StatusPaymentRequestHandlerTest extends TestCase
{
    #[TestWith(['COMPLETED', PaymentRequestInterface::STATE_COMPLETED])]
    #[TestWith(['CANCELED', PaymentRequestInterface::STATE_FAILED])]
    #[TestWith(['PENDING', PaymentRequestInterface::STATE_PROCESSING])]
    public function test_capture_request_of_the_checked_payu_order_is_closed_by_its_final_status(string $payuStatus, string $expectedState): void
    {
        $method = self::payuMethod();
        $payment = self::payment($method, ['ORDER1']);

        $capture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_PROCESSING, 'ORDER1');
        $otherOrderCapture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_PROCESSING, 'ORDER2');
        $cancelledCapture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_CANCELLED, 'ORDER1');
        $statusRequest = self::request($payment, $method, PaymentRequestInterface::ACTION_STATUS, PaymentRequestInterface::STATE_NEW, null);
        $requests = [$capture, $otherOrderCapture, $cancelledCapture, $statusRequest];

        $this->handle($statusRequest, $requests, $payuStatus);

        self::assertSame($expectedState, $capture->getState());
        self::assertSame(['redirect_url' => 'https://merch-prod.snd.payu.com/pay/?orderId=ORDER1', 'order_id' => 'ORDER1'], $capture->getResponseData());
        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $otherOrderCapture->getState());
        self::assertSame(PaymentRequestInterface::STATE_CANCELLED, $cancelledCapture->getState());
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $statusRequest->getState());
    }

    #[TestWith(['COMPLETED', PaymentRequestInterface::STATE_COMPLETED])]
    #[TestWith(['CANCELED', PaymentRequestInterface::STATE_FAILED])]
    public function test_capture_request_left_processing_for_an_already_finished_payu_order_is_closed(string $payuStatus, string $expectedState): void
    {
        $method = self::payuMethod();
        $payment = self::payment($method, ['ORDER1']);
        $details = PaymentDetails::of($payment);
        $details->recordOrderStatus('ORDER1', $payuStatus);
        $details->save();

        $capture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_PROCESSING, 'ORDER1');
        $statusRequest = self::request($payment, $method, PaymentRequestInterface::ACTION_STATUS, PaymentRequestInterface::STATE_NEW, null);

        $this->handle($statusRequest, [$capture, $statusRequest], 'NEW');

        self::assertSame($expectedState, $capture->getState());
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $statusRequest->getState());
    }

    /**
     * @param list<PaymentRequestInterface> $requests
     */
    private function handle(PaymentRequestInterface $statusRequest, array $requests, string $payuStatus): void
    {
        $paymentRequestProvider = self::createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($statusRequest);

        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('synchronize')->willReturnCallback(static function (PaymentInterface $synchronized, string $orderId) use ($payuStatus): string {
            $details = PaymentDetails::of($synchronized);
            $details->recordOrderStatus($orderId, $payuStatus);
            $details->save();

            return PaymentStateApplierInterface::RESULT_UNCHANGED;
        });

        $paymentRequestRepository = self::createStub(PaymentRequestRepositoryInterface::class);
        $paymentRequestRepository->method('findByPaymentIdAndStates')->willReturnCallback(
            static fn (mixed $paymentId, array $states): array => array_values(array_filter($requests, static fn (PaymentRequestInterface $request): bool => in_array($request->getState(), $states, true))),
        );

        $handler = new StatusPaymentRequestHandler(
            $paymentRequestProvider,
            new CredentialsResolver(),
            $synchronizer,
            self::createStub(PaymentLockerInterface::class),
            new PaymentStateMachine(),
            paymentRequestRepository: $paymentRequestRepository,
        );

        $handler(new StatusPaymentRequest('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));
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

    /**
     * @param list<string> $orderIds
     */
    private static function payment(PaymentMethod $method, array $orderIds): Payment
    {
        $payment = new class() extends Payment {
            public function getId(): int
            {
                return 42;
            }
        };
        $payment->setMethod($method);
        $payment->setState(PaymentInterface::STATE_NEW);
        $details = PaymentDetails::of($payment);
        foreach ($orderIds as $orderId) {
            $details->addOrder($orderId, '000000001-42-' . $orderId, 1500, 'PLN');
        }
        $details->save();

        return $payment;
    }

    private static function request(PaymentInterface $payment, PaymentMethod $method, string $action, string $state, ?string $orderId): PaymentRequestInterface
    {
        $request = new PaymentRequest($payment, $method);
        $request->setAction($action);
        $request->setState($state);
        if (null !== $orderId) {
            $request->setResponseData(['redirect_url' => 'https://merch-prod.snd.payu.com/pay/?orderId=' . $orderId, 'order_id' => $orderId]);
        }

        return $request;
    }
}
