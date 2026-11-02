<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\Payment;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Sylius\Component\Core\Model\Payment;

final class PaymentDetailsTest extends TestCase
{
    public function test_open_orders_are_listed_newest_first_without_final_ones(): void
    {
        $details = PaymentDetails::of(new Payment());
        $details->addOrder('FIRST', 'ext-1', 1500, 'PLN');
        $details->addOrder('SECOND', 'ext-2', 1500, 'PLN');
        $details->addOrder('THIRD', 'ext-3', 1500, 'PLN');
        $details->recordOrderStatus('SECOND', 'CANCELED');

        self::assertSame(['THIRD', 'FIRST'], $details->getOpenOrderIds());
        self::assertSame('THIRD', $details->getCurrentOrderId());
        self::assertSame('FIRST', $details->findOrderIdByExtOrderId('ext-1'));
    }

    public function test_final_order_status_is_never_overwritten(): void
    {
        $details = PaymentDetails::of(new Payment());
        $details->addOrder('ORDER', 'ext', 1500, 'PLN');
        $details->recordOrderStatus('ORDER', 'COMPLETED');
        $details->recordOrderStatus('ORDER', 'PENDING');
        $details->recordOrderStatus('UNKNOWN', 'COMPLETED');

        self::assertSame('COMPLETED', $details->getOrderStatus('ORDER'));
        self::assertNull($details->getOrderStatus('UNKNOWN'));
        self::assertFalse($details->hasOpenOrders());
    }

    public function test_warnings_are_deduplicated_by_type_and_order(): void
    {
        $details = PaymentDetails::of(new Payment());
        $details->addWarning('duplicate_payment', 'A');
        $details->addWarning('duplicate_payment', 'A');
        $details->addWarning('duplicate_payment', 'B');
        $details->addWarning('payment_completed_after_failure', 'A');

        self::assertSame([
            ['type' => 'duplicate_payment', 'order_id' => 'A'],
            ['type' => 'duplicate_payment', 'order_id' => 'B'],
            ['type' => 'payment_completed_after_failure', 'order_id' => 'A'],
        ], $details->warnings());
    }

    public function test_data_survives_saving_and_reloading(): void
    {
        $payment = new Payment();
        $payment->setDetails(['other_plugin' => 'kept']);

        $details = PaymentDetails::of($payment);
        $details->addOrder('ORDER', 'ext', 1500, 'PLN');
        $details->setCompletedOrderId('ORDER');
        $details->recordRefund('r-1', '501', 'PENDING', 1000, 'PLN');
        $details->recordRefund('r-2', '502', 'PENDING', 500, 'PLN');
        $details->save();

        $reloaded = PaymentDetails::of($payment);
        self::assertSame('kept', $payment->getDetails()['other_plugin']);
        self::assertSame('ORDER', $reloaded->getCompletedOrderId());
        self::assertCount(2, $reloaded->refunds());
        self::assertTrue($reloaded->hasActiveRefund());
    }

    public function test_refund_statuses_and_finalized_amount(): void
    {
        $details = PaymentDetails::of(new Payment());
        $details->recordRefund('r-1', '501', 'PENDING', 1000, 'PLN');
        $details->recordRefund('r-2', '502', 'PENDING', 500, 'PLN');

        self::assertTrue($details->updateRefundStatus('501', null, 'FINALIZED'));
        self::assertTrue($details->updateRefundStatus('unknown', 'r-2', 'CANCELED'));
        self::assertFalse($details->updateRefundStatus('999', 'r-9', 'FINALIZED'));
        // A final refund status is kept.
        self::assertTrue($details->updateRefundStatus('501', null, 'CANCELED'));

        self::assertSame(1000, $details->finalizedRefundAmount());
        self::assertTrue($details->hasActiveRefund());

        self::assertTrue($details->recordExternalRefund('700', 'FINALIZED', 300, 'PLN'));
        self::assertFalse($details->recordExternalRefund('700', 'FINALIZED', 300, 'PLN'));
        self::assertSame(1300, $details->finalizedRefundAmount());
    }

    public function test_only_canceled_refunds_are_inactive(): void
    {
        $details = PaymentDetails::of(new Payment());
        self::assertFalse($details->hasActiveRefund());

        $details->recordRefund('r-1', '501', 'PENDING', 1000, 'PLN');
        $details->updateRefundStatus('501', null, 'CANCELED');

        self::assertFalse($details->hasActiveRefund());
    }
}
