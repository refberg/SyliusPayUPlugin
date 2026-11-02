<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusPayUPlugin\Api\Exception\PayUApiException;
use Refberg\SyliusPayUPlugin\Command\NotifyPaymentRequest;
use Refberg\SyliusPayUPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusPayUPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusPayUPlugin\Notification\Notification;
use Refberg\SyliusPayUPlugin\Notification\NotificationParser;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Refberg\SyliusPayUPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusPayUPlugin\Payment\PaymentSynchronizer;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;

/**
 * Handles a PayU notification stored by Sylius' notify endpoint.
 *
 * The signature is verified again here (the notify payment provider already rejected bad ones),
 * then the order state is read from the PayU API, so a stale or replayed notification
 * cannot move the payment backwards.
 *
 * Transient PayU API failures are rethrown: the endpoint answers 5xx and PayU retries.
 */
final readonly class NotifyPaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsResolverInterface $credentialsResolver,
        private NotificationParser $notificationParser,
        private PaymentSynchronizer $synchronizer,
        private PaymentLockerInterface $paymentLocker,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(NotifyPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        $payment = self::corePayment($paymentRequest);

        try {
            $method = $paymentRequest->getMethod();
            $credentials = $this->credentialsResolver->resolve($method);
        } catch (InvalidConfigurationException $exception) {
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $notification = $this->notificationParser->parseStoredPayload($paymentRequest->getPayload(), $credentials);
        if (!$notification instanceof Notification) {
            $this->logger->warning('Rejected PayU notification with an invalid signature or body.', ['payment_id' => $payment->getId()]);
            $this->failRequest($paymentRequest, 'Invalid notification signature or body.');

            return;
        }

        $this->paymentLocker->lock($payment);
        $details = PaymentDetails::of($payment);

        if (!$details->hasOrder($notification->orderId)) {
            $this->failRequest($paymentRequest, 'PayU order is not linked to this payment.');

            return;
        }

        if (Notification::TYPE_REFUND === $notification->type) {
            $this->handleRefund($notification, $details, $payment);
            $this->completeRequest($paymentRequest, ['type' => 'refund', 'refund_status' => $notification->refundStatus]);

            return;
        }

        try {
            $result = $this->synchronizer->synchronize($payment, $notification->orderId, $credentials);
        } catch (PayUApiException $exception) {
            if ($exception->isTransient()) {
                throw $exception;
            }

            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $this->completeRequest($paymentRequest, [
            'type' => 'order',
            'order_id' => $notification->orderId,
            'result' => $result,
            'payment_state' => $payment->getState(),
        ]);
    }

    private function handleRefund(Notification $notification, PaymentDetails $details, PaymentInterface $payment): void
    {
        if (null === $notification->refundId || null === $notification->refundStatus) {
            return;
        }

        if ($details->updateRefundStatus($notification->refundId, $notification->extRefundId, $notification->refundStatus)) {
            $details->save();

            if ('CANCELED' === $notification->refundStatus) {
                $this->logger->error('PayU canceled a refund; it has to be handled manually.', [
                    'payu_order_id' => $notification->orderId,
                    'payu_refund_id' => $notification->refundId,
                ]);
            }

            return;
        }

        $this->handleExternalRefund($notification, $details, $payment);
    }

    /**
     * A refund made outside Sylius, e.g. in the PayU panel. It is recorded, and once finalized
     * refunds cover the whole payment, the payment is moved to "refunded".
     * A partial refund does not change the payment state: Sylius has no state for it.
     */
    private function handleExternalRefund(Notification $notification, PaymentDetails $details, PaymentInterface $payment): void
    {
        if (
            $notification->orderId !== $details->getCompletedOrderId() ||
            null === $notification->refundAmount ||
            null === $notification->refundCurrency ||
            $notification->refundCurrency !== $payment->getCurrencyCode()
        ) {
            $this->logger->warning('Ignored PayU refund notification that does not match the paid order.', [
                'payment_id' => $payment->getId(),
                'payu_order_id' => $notification->orderId,
                'payu_refund_id' => $notification->refundId,
            ]);

            return;
        }

        if (!$details->recordExternalRefund((string) $notification->refundId, (string) $notification->refundStatus, $notification->refundAmount, $notification->refundCurrency)) {
            return;
        }
        $details->save();

        $this->logger->info('Recorded a PayU refund made outside Sylius.', [
            'payment_id' => $payment->getId(),
            'payu_refund_id' => $notification->refundId,
            'status' => $notification->refundStatus,
        ]);

        if (
            $details->finalizedRefundAmount() >= (int) $payment->getAmount() &&
            $this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND)
        ) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        }
    }
}
