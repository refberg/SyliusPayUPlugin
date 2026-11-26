<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusPayUPlugin\Api\Exception\PayUApiException;
use Refberg\SyliusPayUPlugin\Api\Model\RefundCreated;
use Refberg\SyliusPayUPlugin\Command\NotifyPaymentRequest;
use Refberg\SyliusPayUPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusPayUPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusPayUPlugin\Notification\Notification;
use Refberg\SyliusPayUPlugin\Notification\NotificationParserInterface;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Refberg\SyliusPayUPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusPayUPlugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusPayUPlugin\Payment\PaymentSynchronizerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use function in_array;

/**
 * Handles a PayU notification stored by Sylius' notify endpoint.
 *
 * The signature is verified again here (the notify payment provider already rejected bad ones),
 * then the order state is read from the PayU API, so a stale or replayed notification
 * cannot move the payment backwards.
 *
 * Transient PayU API failures, rejected plugin credentials and a final refund status not yet shown by the PayU API are
 * rethrown, so the notification is retried (by PayU after HTTP 5xx, or by Messenger with an asynchronous transport).
 */
final readonly class NotifyPaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    /**
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface>|null $paymentRequestRepository
     */
    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsResolverInterface $credentialsResolver,
        private NotificationParserInterface $notificationParser,
        private PaymentSynchronizerInterface $synchronizer,
        private PaymentLockerInterface $paymentLocker,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
        private ?PaymentRequestRepositoryInterface $paymentRequestRepository = null,
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
            try {
                $refund = null === $notification->refundId ? null : $this->synchronizer->retrieveRefund($payment, $notification->orderId, $notification->refundId, $credentials);
            } catch (PayUApiException $exception) {
                $this->failOrRethrow($paymentRequest, $exception);

                return;
            }

            if (!$refund instanceof RefundCreated) {
                $this->failRequest($paymentRequest, 'The PayU API does not list this refund for the order.');

                return;
            }

            // The body only decides to try later: PayU may notify before its API shows the final status.
            if (RefundCreated::isFinalStatus($notification->refundStatus) && !RefundCreated::isFinalStatus($refund->status)) {
                $this->logger->warning('PayU API does not show the notified final refund status yet; the notification will be retried.', [
                    'payment_id' => $payment->getId(),
                    'payu_order_id' => $notification->orderId,
                    'payu_refund_id' => $refund->refundId,
                    'notified_status' => $notification->refundStatus,
                    'api_status' => $refund->status,
                ]);

                throw new PayUApiException('PayU API does not show the notified final refund status yet.', 503);
            }

            $this->handleRefund($notification->orderId, $refund, $details, $payment);
            $this->completeRequest($paymentRequest, ['type' => Notification::TYPE_REFUND, 'refund_status' => $refund->status]);

            return;
        }

        try {
            $result = $this->synchronizer->synchronize($payment, $notification->orderId, $credentials);
        } catch (PayUApiException $exception) {
            $this->failOrRethrow($paymentRequest, $exception);

            return;
        }

        if (PaymentStateApplierInterface::RESULT_COMPLETED === $result) {
            $this->restorePaidMethod($payment, $method, $notification->orderId);
        }
        $this->closeCaptureRequests($this->paymentRequestRepository, $payment, $notification->orderId);

        $this->completeRequest($paymentRequest, [
            'type' => Notification::TYPE_ORDER,
            'order_id' => $notification->orderId,
            'result' => $result,
            'payment_state' => $payment->getState(),
        ]);
    }

    /**
     * @throws PayUApiException
     */
    private function failOrRethrow(PaymentRequestInterface $paymentRequest, PayUApiException $exception): void
    {
        // Rejected credentials are fixed by the merchant, so PayU should keep retrying until then.
        if ($exception->isTransient() || in_array($exception->getHttpStatusCode(), [401, 403], true)) {
            throw $exception;
        }

        $this->failRequest($paymentRequest, $exception->getMessage());
    }

    private function handleRefund(string $orderId, RefundCreated $refund, PaymentDetails $details, PaymentInterface $payment): void
    {
        if ($details->updateRefundStatus($refund->refundId, $refund->extRefundId, $refund->status)) {
            $details->save();

            if (RefundCreated::STATUS_CANCELED === $refund->status) {
                $this->logger->error('PayU canceled a refund; it has to be handled manually.', [
                    'payu_order_id' => $orderId,
                    'payu_refund_id' => $refund->refundId,
                ]);
            }
            $this->refundPaymentWhenCovered($details, $payment);

            return;
        }

        $this->handleExternalRefund($orderId, $refund, $details, $payment);
    }

    /**
     * A refund made outside Sylius, e.g. in the PayU panel. It is recorded, and once finalized
     * refunds cover the whole payment, the payment is moved to "refunded".
     * A partial refund does not change the payment state: Sylius has no state for it.
     */
    private function handleExternalRefund(string $orderId, RefundCreated $refund, PaymentDetails $details, PaymentInterface $payment): void
    {
        if (
            $orderId !== $details->getCompletedOrderId() ||
            $refund->amount <= 0 ||
            $refund->amount > (int) $payment->getAmount()
        ) {
            $this->logger->warning('Ignored PayU refund notification that does not match the paid order.', [
                'payment_id' => $payment->getId(),
                'payu_order_id' => $orderId,
                'payu_refund_id' => $refund->refundId,
            ]);

            return;
        }

        if (!$details->recordExternalRefund($refund->refundId, $refund->status, $refund->amount, $refund->currencyCode)) {
            return;
        }
        $details->save();

        $this->logger->info('Recorded a PayU refund made outside Sylius.', [
            'payment_id' => $payment->getId(),
            'payu_refund_id' => $refund->refundId,
            'status' => $refund->status,
        ]);

        $this->refundPaymentWhenCovered($details, $payment);
    }

    private function refundPaymentWhenCovered(PaymentDetails $details, PaymentInterface $payment): void
    {
        if (
            $details->finalizedRefundAmount() >= (int) $payment->getAmount() &&
            $this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND)
        ) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        }
    }
}
