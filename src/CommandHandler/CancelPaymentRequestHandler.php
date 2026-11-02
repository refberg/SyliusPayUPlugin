<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusPayUPlugin\Api\Exception\PayUException;
use Refberg\SyliusPayUPlugin\Api\Model\PayUOrder;
use Refberg\SyliusPayUPlugin\Api\PayUClientInterface;
use Refberg\SyliusPayUPlugin\Command\CancelPaymentRequest;
use Refberg\SyliusPayUPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusPayUPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Refberg\SyliusPayUPlugin\Payment\PaymentLockerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * Cancels every PayU order of the payment that may still be paid, after the payment
 * was cancelled in Sylius (e.g. by an administrator). Without it the customer could still
 * pay a cancelled order.
 */
final class CancelPaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly CredentialsResolverInterface $credentialsResolver,
        private readonly PayUClientInterface $client,
        private readonly PaymentLockerInterface $paymentLocker,
        private readonly StateMachineInterface $stateMachine,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(CancelPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        if (PaymentRequestInterface::STATE_NEW !== $paymentRequest->getState()) {
            return;
        }

        $payment = self::corePayment($paymentRequest);

        try {
            $credentials = $this->credentialsResolver->resolve($paymentRequest->getMethod());
        } catch (InvalidConfigurationException $exception) {
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $this->paymentLocker->lock($payment);
        $details = PaymentDetails::of($payment);

        $canceled = [];
        $errors = [];
        foreach ($details->getOpenOrderIds() as $orderId) {
            try {
                $this->client->cancelOrder($credentials, $orderId);
                $details->recordOrderStatus($orderId, PayUOrder::STATUS_CANCELED);
                $canceled[] = $orderId;
            } catch (PayUException $exception) {
                $errors[] = $exception->getMessage();
                $this->logger->error('Could not cancel PayU order; check it in the PayU panel.', [
                    'payment_id' => $payment->getId(),
                    'payu_order_id' => $orderId,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
        $details->save();

        if ([] !== $errors) {
            $this->failRequest($paymentRequest, implode(' ', $errors));

            return;
        }

        $this->completeRequest($paymentRequest, ['canceled_order_ids' => $canceled]);
    }
}
