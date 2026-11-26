<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Payment;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusPayUPlugin\Api\Model\PayUOrder;
use Refberg\SyliusPayUPlugin\Config\CredentialsResolverInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\PaymentTransitions;

/**
 * The only place where the plugin changes the state of a Sylius payment.
 *
 * Input must be a PayU order that was fetched from the PayU API and validated against the payment.
 * Rules are monotonic, so duplicated or out-of-order notifications cannot change the outcome:
 *  - COMPLETED completes the payment once; a second PayU order completing the same payment
 *    is recorded as a warning and never pays the order twice,
 *  - CANCELED fails the payment only when no other PayU order of this payment may still be paid
 *    and the payment still uses a PayU method,
 *  - PENDING and WAITING_FOR_CONFIRMATION move a new payment to processing while it still uses a PayU method,
 *  - NEW never changes the payment state.
 */
final readonly class PaymentStateApplier implements PaymentStateApplierInterface
{
    public function __construct(
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
        private ?CredentialsResolverInterface $credentialsResolver = null,
    ) {
    }

    public function apply(PaymentInterface $payment, PayUOrder $order): string
    {
        $details = PaymentDetails::of($payment);
        $details->recordOrderStatus($order->orderId, $order->status);

        $result = match ($order->status) {
            PayUOrder::STATUS_COMPLETED => $this->onCompleted($payment, $details, $order),
            PayUOrder::STATUS_CANCELED => $this->onCanceled($payment, $details),
            PayUOrder::STATUS_PENDING, PayUOrder::STATUS_WAITING_FOR_CONFIRMATION => $this->onInProgress($payment),
            default => self::RESULT_UNCHANGED,
        };

        $details->save();

        return $result;
    }

    private function onCompleted(PaymentInterface $payment, PaymentDetails $details, PayUOrder $order): string
    {
        $completedOrderId = $details->getCompletedOrderId();
        if ($completedOrderId === $order->orderId) {
            return self::RESULT_UNCHANGED;
        }

        if (null === $completedOrderId && $this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
            $details->setCompletedOrderId($order->orderId);

            $this->logger->info('PayU payment completed.', ['payment_id' => $payment->getId(), 'payu_order_id' => $order->orderId]);

            return self::RESULT_COMPLETED;
        }

        $warning = null !== $completedOrderId ? PaymentDetails::WARNING_DUPLICATE_PAYMENT : PaymentDetails::WARNING_LATE_PAYMENT;
        $details->addWarning($warning, $order->orderId);

        $this->logger->critical('PayU order was paid, but the Sylius payment cannot be completed. Manual refund may be needed.', ['payment_id' => $payment->getId(), 'payment_state' => $payment->getState(), 'payu_order_id' => $order->orderId, 'reason' => $warning]);

        return self::RESULT_WARNING;
    }

    private function onInProgress(PaymentInterface $payment): string
    {
        $method = $payment->getMethod();
        if ($this->credentialsResolver instanceof CredentialsResolverInterface && $method instanceof PaymentMethodInterface && !$this->credentialsResolver->supports($method)) {
            return self::RESULT_UNCHANGED;
        }

        if (!$this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_PROCESS)) {
            return self::RESULT_UNCHANGED;
        }

        $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_PROCESS);

        $this->logger->info('PayU payment is in progress.', ['payment_id' => $payment->getId()]);

        return self::RESULT_PROCESSING;
    }

    private function onCanceled(PaymentInterface $payment, PaymentDetails $details): string
    {
        if (null !== $details->getCompletedOrderId() || $details->hasOpenOrders()) {
            return self::RESULT_UNCHANGED;
        }

        if (!$this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_FAIL)) {
            return self::RESULT_UNCHANGED;
        }

        $method = $payment->getMethod();
        if ($this->credentialsResolver instanceof CredentialsResolverInterface && $method instanceof PaymentMethodInterface && !$this->credentialsResolver->supports($method)) {
            $this->logger->info('PayU order was canceled after the customer switched to another payment method; payment state not changed.', ['payment_id' => $payment->getId(), 'payment_method' => $method->getCode()]);

            return self::RESULT_UNCHANGED;
        }

        $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_FAIL);

        $this->logger->info('PayU payment failed.', ['payment_id' => $payment->getId()]);

        return self::RESULT_FAILED;
    }
}
