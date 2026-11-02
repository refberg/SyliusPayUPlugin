<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\Payment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusPayUPlugin\Api\Model\PayUOrder;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Refberg\SyliusPayUPlugin\Payment\PaymentStateApplier;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Payment\PaymentTransitions;
use Tests\Refberg\SyliusPayUPlugin\Support\PaymentStateMachine;

final class PaymentStateApplierTest extends TestCase
{
    private PaymentStateMachine $stateMachine;

    private PaymentStateApplier $applier;

    protected function setUp(): void
    {
        $this->stateMachine = new PaymentStateMachine();
        $this->applier = new PaymentStateApplier($this->stateMachine);
    }

    public function test_completed_order_completes_a_new_payment(): void
    {
        $payment = self::payment(['ORDER1']);

        self::assertSame(PaymentStateApplier::RESULT_COMPLETED, $this->applier->apply($payment, self::order('ORDER1', 'COMPLETED')));
        self::assertSame('completed', $payment->getState());
        self::assertSame('ORDER1', PaymentDetails::of($payment)->getCompletedOrderId());
    }

    public function test_duplicated_completed_notification_changes_nothing(): void
    {
        $payment = self::payment(['ORDER1']);
        $this->applier->apply($payment, self::order('ORDER1', 'COMPLETED'));

        self::assertSame(PaymentStateApplier::RESULT_UNCHANGED, $this->applier->apply($payment, self::order('ORDER1', 'COMPLETED')));
        self::assertSame(PaymentStateApplier::RESULT_UNCHANGED, $this->applier->apply($payment, self::order('ORDER1', 'COMPLETED')));
        self::assertSame([PaymentTransitions::TRANSITION_COMPLETE], $this->stateMachine->appliedTransitions(PaymentTransitions::GRAPH));
        self::assertSame([], PaymentDetails::of($payment)->warnings());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function laterStatuses(): iterable
    {
        yield 'pending after completed' => ['PENDING'];
        yield 'new after completed' => ['NEW'];
        yield 'canceled after completed' => ['CANCELED'];
        yield 'waiting after completed' => ['WAITING_FOR_CONFIRMATION'];
    }

    #[DataProvider('laterStatuses')]
    public function test_out_of_order_statuses_never_undo_a_completed_payment(string $status): void
    {
        $payment = self::payment(['ORDER1']);
        $this->applier->apply($payment, self::order('ORDER1', 'COMPLETED'));

        self::assertSame(PaymentStateApplier::RESULT_UNCHANGED, $this->applier->apply($payment, self::order('ORDER1', $status)));
        self::assertSame('completed', $payment->getState());
        self::assertSame('COMPLETED', PaymentDetails::of($payment)->getOrderStatus('ORDER1'));
    }

    public function test_pending_statuses_keep_the_payment_new(): void
    {
        $payment = self::payment(['ORDER1']);

        foreach (['NEW', 'PENDING', 'WAITING_FOR_CONFIRMATION'] as $status) {
            self::assertSame(PaymentStateApplier::RESULT_UNCHANGED, $this->applier->apply($payment, self::order('ORDER1', $status)));
            self::assertSame('new', $payment->getState());
        }
        self::assertSame('WAITING_FOR_CONFIRMATION', PaymentDetails::of($payment)->getOrderStatus('ORDER1'));
    }

    public function test_canceled_order_fails_the_payment_when_nothing_else_is_open(): void
    {
        $payment = self::payment(['ORDER1']);

        self::assertSame(PaymentStateApplier::RESULT_FAILED, $this->applier->apply($payment, self::order('ORDER1', 'CANCELED')));
        self::assertSame('failed', $payment->getState());
    }

    public function test_canceled_order_does_not_fail_while_another_order_may_still_be_paid(): void
    {
        $payment = self::payment(['ORDER1', 'ORDER2']);

        self::assertSame(PaymentStateApplier::RESULT_UNCHANGED, $this->applier->apply($payment, self::order('ORDER1', 'CANCELED')));
        self::assertSame('new', $payment->getState());

        self::assertSame(PaymentStateApplier::RESULT_COMPLETED, $this->applier->apply($payment, self::order('ORDER2', 'COMPLETED')));
        self::assertSame('completed', $payment->getState());
    }

    public function test_a_second_paid_order_never_pays_the_payment_twice(): void
    {
        $payment = self::payment(['ORDER1', 'ORDER2']);
        $this->applier->apply($payment, self::order('ORDER2', 'COMPLETED'));

        self::assertSame(PaymentStateApplier::RESULT_WARNING, $this->applier->apply($payment, self::order('ORDER1', 'COMPLETED')));
        self::assertSame('completed', $payment->getState());
        self::assertSame('ORDER2', PaymentDetails::of($payment)->getCompletedOrderId());
        self::assertSame([['type' => PaymentDetails::WARNING_DUPLICATE_PAYMENT, 'order_id' => 'ORDER1']], PaymentDetails::of($payment)->warnings());
        self::assertSame([PaymentTransitions::TRANSITION_COMPLETE], $this->stateMachine->appliedTransitions(PaymentTransitions::GRAPH));

        // Repeated notification about the second payment does not add another warning.
        $this->applier->apply($payment, self::order('ORDER1', 'COMPLETED'));
        self::assertSame([['type' => PaymentDetails::WARNING_DUPLICATE_PAYMENT, 'order_id' => 'ORDER1']], PaymentDetails::of($payment)->warnings());
    }

    public function test_payment_completed_after_failure_is_flagged_not_applied(): void
    {
        $payment = self::payment(['ORDER1']);
        $payment->setState('failed');

        self::assertSame(PaymentStateApplier::RESULT_WARNING, $this->applier->apply($payment, self::order('ORDER1', 'COMPLETED')));
        self::assertSame('failed', $payment->getState());
        self::assertSame(PaymentDetails::WARNING_LATE_PAYMENT, PaymentDetails::of($payment)->warnings()[0]['type']);
    }

    public function test_refunded_payment_is_not_touched(): void
    {
        $payment = self::payment(['ORDER1']);
        $this->applier->apply($payment, self::order('ORDER1', 'COMPLETED'));
        $payment->setState('refunded');

        self::assertSame(PaymentStateApplier::RESULT_UNCHANGED, $this->applier->apply($payment, self::order('ORDER1', 'COMPLETED')));
        self::assertSame(PaymentStateApplier::RESULT_UNCHANGED, $this->applier->apply($payment, self::order('ORDER1', 'CANCELED')));
        self::assertSame('refunded', $payment->getState());
    }

    /**
     * @param list<string> $orderIds
     */
    private static function payment(array $orderIds): Payment
    {
        $payment = new Payment();
        $payment->setState('new');
        $payment->setAmount(1500);
        $payment->setCurrencyCode('PLN');

        $details = PaymentDetails::of($payment);
        foreach ($orderIds as $index => $orderId) {
            $details->addOrder($orderId, sprintf('000001-1-%012d', $index), 1500, 'PLN');
        }
        $details->save();

        return $payment;
    }

    private static function order(string $orderId, string $status): PayUOrder
    {
        return new PayUOrder($orderId, null, $status, 1500, 'PLN', '300046');
    }
}
