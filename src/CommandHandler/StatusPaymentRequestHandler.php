<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusPayUPlugin\Api\Exception\PayUException;
use Refberg\SyliusPayUPlugin\Command\StatusPaymentRequest;
use Refberg\SyliusPayUPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusPayUPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Refberg\SyliusPayUPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusPayUPlugin\Payment\PaymentSynchronizer;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use function array_slice;

/**
 * Runs when the customer comes back from PayU (after-pay) or when an API client asks for the status.
 *
 * The state is taken from GET /orders/{orderId}; query parameters of the return URL are ignored.
 */
final readonly class StatusPaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsResolverInterface $credentialsResolver,
        private PaymentSynchronizer $synchronizer,
        private PaymentLockerInterface $paymentLocker,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(StatusPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        $payment = self::corePayment($paymentRequest);

        try {
            $method = $paymentRequest->getMethod();
            $credentials = $this->credentialsResolver->resolve($method);
            $this->paymentLocker->lock($payment);

            $details = PaymentDetails::of($payment);
            foreach (array_slice($details->getOpenOrderIds(), 0, 3) as $orderId) {
                $this->synchronizer->synchronize($payment, $orderId, $credentials);
            }
        } catch (PayUException|InvalidConfigurationException $exception) {
            $this->logger->warning('Could not check PayU order status.', [
                'payment_id' => $payment->getId(),
                'reason' => $exception->getMessage(),
            ]);
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $details = PaymentDetails::of($payment);
        $currentOrderId = $details->getCurrentOrderId();

        $this->completeRequest($paymentRequest, [
            'payment_state' => $payment->getState(),
            'order_id' => $currentOrderId,
            'payu_status' => null !== $currentOrderId ? $details->getOrderStatus($currentOrderId) : null,
        ]);
    }
}
