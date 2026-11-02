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
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * Requests a full refund of the PayU order that paid the payment.
 *
 * extRefundId is the payment request hash, so PayU deduplicates retries of the same request.
 * The final refund status (FINALIZED / CANCELED) arrives later as a signed notification.
 */
final class RefundPaymentRequestHandler
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

        $order = $payment->getOrder();
        $extRefundId = (string) $paymentRequest->getId();

        try {
            $refund = $this->client->createRefund($credentials, $orderId, [
                'description' => sprintf('Refund for order %s', $order instanceof OrderInterface ? (string) $order->getNumber() : ''),
                'amount' => (int) $payment->getAmount(),
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
