<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\CommandHandler;

use LogicException;
use Psr\Log\LoggerInterface;
use Refberg\SyliusPayUPlugin\Api\Model\PayUOrder;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use function in_array;
use function is_string;

/**
 * @property StateMachineInterface $stateMachine
 * @property LoggerInterface $logger
 */
trait PaymentRequestHandlerTrait
{
    /**
     * @param array<string, mixed> $responseData
     */
    private function completeRequest(PaymentRequestInterface $paymentRequest, array $responseData): void
    {
        $paymentRequest->setResponseData($responseData);
        $this->transitionRequest($paymentRequest, PaymentRequestTransitions::TRANSITION_COMPLETE);
    }

    private function failRequest(PaymentRequestInterface $paymentRequest, string $reason): void
    {
        $paymentRequest->setResponseData(['error' => $reason]);
        $this->transitionRequest($paymentRequest, PaymentRequestTransitions::TRANSITION_FAIL);
    }

    private function transitionRequest(PaymentRequestInterface $paymentRequest, string $transition): void
    {
        if ($this->stateMachine->can($paymentRequest, PaymentRequestTransitions::GRAPH, $transition)) {
            $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, $transition);
        }
    }

    /**
     * Sylius reuses a capture request that is not final, so a finished PayU order would send the customer back to a dead redirect URL.
     *
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface>|null $paymentRequestRepository
     * @param string|null $orderId null closes the capture requests of every PayU order with a final status
     */
    private function closeCaptureRequests(?PaymentRequestRepositoryInterface $paymentRequestRepository, PaymentInterface $payment, ?string $orderId = null): void
    {
        if (!$paymentRequestRepository instanceof PaymentRequestRepositoryInterface) {
            return;
        }

        $details = PaymentDetails::of($payment);
        foreach ($paymentRequestRepository->findByPaymentIdAndStates($payment->getId(), [PaymentRequestInterface::STATE_PROCESSING]) as $captureRequest) {
            $captureOrderId = $captureRequest->getResponseData()['order_id'] ?? null;
            if (
                !is_string($captureOrderId) ||
                (null !== $orderId && $orderId !== $captureOrderId) ||
                !in_array($captureRequest->getAction(), [PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::ACTION_AUTHORIZE], true)
            ) {
                continue;
            }

            $transition = match ($details->getOrderStatus($captureOrderId)) {
                PayUOrder::STATUS_COMPLETED => PaymentRequestTransitions::TRANSITION_COMPLETE,
                PayUOrder::STATUS_CANCELED => PaymentRequestTransitions::TRANSITION_FAIL,
                default => null,
            };
            if (null !== $transition) {
                $this->transitionRequest($captureRequest, $transition);
            }
        }
    }

    // Refunds from Sylius use the payment's method, so it must be the PayU method that was paid.
    private function restorePaidMethod(PaymentInterface $payment, PaymentMethodInterface $method, string $orderId): void
    {
        if ($payment->getMethod() === $method) {
            return;
        }

        $previousMethod = $payment->getMethod();
        $payment->setMethod($method);

        // The applier saved its own details; a fresh read keeps completed_order_id.
        $completedDetails = PaymentDetails::of($payment);
        $completedDetails->addWarning(PaymentDetails::WARNING_PAYMENT_METHOD_CHANGED, $orderId);
        $completedDetails->save();
        $this->logger->warning('PayU paid the payment after the customer switched to another payment method; the PayU method was restored.', [
            'payment_id' => $payment->getId(),
            'payu_order_id' => $orderId,
            'switched_to_method' => $previousMethod?->getCode(),
        ]);
    }

    private static function corePayment(PaymentRequestInterface $paymentRequest): PaymentInterface
    {
        $payment = $paymentRequest->getPayment();
        if (!$payment instanceof PaymentInterface) {
            throw new LogicException('PayU payment requests require a Sylius Core payment.');
        }

        return $payment;
    }
}
