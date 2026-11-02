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

/**
 * Handles a PayU notification stored by Sylius' notify endpoint.
 *
 * The signature is verified again here (the notify payment provider already rejected bad ones),
 * then the order state is read from the PayU API, so a stale or replayed notification
 * cannot move the payment backwards.
 *
 * Transient PayU API failures are rethrown: the endpoint answers 5xx and PayU retries.
 */
final class NotifyPaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly CredentialsResolverInterface $credentialsResolver,
        private readonly NotificationParser $notificationParser,
        private readonly PaymentSynchronizer $synchronizer,
        private readonly PaymentLockerInterface $paymentLocker,
        private readonly StateMachineInterface $stateMachine,
        private readonly LoggerInterface $logger = new NullLogger(),
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
        if (null === $notification) {
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
            $this->handleRefund($notification, $details);
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

    private function handleRefund(Notification $notification, PaymentDetails $details): void
    {
        if (null === $notification->refundId || null === $notification->refundStatus) {
            return;
        }

        if (!$details->updateRefundStatus($notification->refundId, $notification->extRefundId, $notification->refundStatus)) {
            $this->logger->warning('PayU refund notification for an unknown refund.', [
                'payu_order_id' => $notification->orderId,
                'payu_refund_id' => $notification->refundId,
            ]);

            return;
        }

        $details->save();

        if ('CANCELED' === $notification->refundStatus) {
            $this->logger->error('PayU canceled a refund; it has to be handled manually.', [
                'payu_order_id' => $notification->orderId,
                'payu_refund_id' => $notification->refundId,
            ]);
        }
    }
}
