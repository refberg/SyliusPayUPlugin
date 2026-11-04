<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusPayUPlugin\Api\Exception\PayUException;
use Refberg\SyliusPayUPlugin\Api\PayUClientInterface;
use Refberg\SyliusPayUPlugin\Command\RefundPaymentRequest;
use Refberg\SyliusPayUPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusPayUPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Refberg\SyliusPayUPlugin\Payment\PaymentLockerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use function sprintf;

/**
 * Requests a full refund of the PayU order that paid the payment, less the refunds already made in the PayU panel.
 *
 * extRefundId is the payment request hash, so a redelivered request does not create a second refund in PayU.
 * The final refund status (FINALIZED / CANCELED) arrives later as a signed notification.
 */
final readonly class RefundPaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsResolverInterface $credentialsResolver,
        private PayUClientInterface $client,
        private PaymentLockerInterface $paymentLocker,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(RefundPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        if (PaymentRequestInterface::STATE_NEW !== $paymentRequest->getState()) {
            return;
        }

        $payment = self::corePayment($paymentRequest);

        try {
            $method = $paymentRequest->getMethod();
            $credentials = $this->credentialsResolver->resolve($method);
        } catch (InvalidConfigurationException $exception) {
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $this->paymentLocker->lock($payment);
        if (PaymentInterface::STATE_REFUNDED !== $payment->getState()) {
            $this->failRequest($paymentRequest, sprintf('Payment in state "%s" cannot be refunded.', (string) $payment->getState()));

            return;
        }

        $details = PaymentDetails::of($payment);

        $orderId = $details->getCompletedOrderId();
        if (null === $orderId) {
            $this->logger->error('PayU refund not requested: the payment was not completed through PayU.', ['payment_id' => $payment->getId()]);
            $this->failRequest($paymentRequest, 'No completed PayU order for this payment.');

            return;
        }

        if ($details->hasActiveRefund()) {
            $this->completeRequest($paymentRequest, ['order_id' => $orderId, 'already_requested' => true]);

            return;
        }

        $amount = (int) $payment->getAmount() - $details->activeRefundAmount();
        if ($amount <= 0) {
            $this->logger->info('PayU refund not requested: refunds made in the PayU panel already cover the payment.', [
                'payment_id' => $payment->getId(),
                'payu_order_id' => $orderId,
            ]);
            $this->completeRequest($paymentRequest, ['order_id' => $orderId, 'covered_by_payu_panel_refunds' => true]);

            return;
        }

        $order = $payment->getOrder();
        $extRefundId = (string) $paymentRequest->getId();

        try {
            $refund = $this->client->createRefund($credentials, $orderId, [
                'description' => sprintf('Refund for order %s', $order instanceof OrderInterface ? (string) $order->getNumber() : ''),
                'amount' => $amount,
                'currencyCode' => (string) $payment->getCurrencyCode(),
                'extRefundId' => $extRefundId,
            ]);
        } catch (PayUException $exception) {
            $this->logger->error('PayU refund request failed; refund has to be handled manually.', [
                'payment_id' => $payment->getId(),
                'payu_order_id' => $orderId,
                'reason' => $exception->getMessage(),
            ]);
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $details->recordRefund($extRefundId, $refund->refundId, $refund->status, $refund->amount, $refund->currencyCode);
        $details->save();

        $this->completeRequest($paymentRequest, [
            'order_id' => $orderId,
            'refund_id' => $refund->refundId,
            'refund_status' => $refund->status,
        ]);
    }
}
