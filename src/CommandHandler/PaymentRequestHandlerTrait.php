<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\CommandHandler;

use LogicException;
use Psr\Log\LoggerInterface;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;

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
