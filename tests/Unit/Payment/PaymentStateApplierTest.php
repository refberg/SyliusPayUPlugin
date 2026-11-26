<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\Payment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusPayUPlugin\Api\Model\PayUOrder;
use Refberg\SyliusPayUPlugin\Config\CredentialsResolver;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Refberg\SyliusPayUPlugin\Payment\PaymentStateApplier;
use Refberg\SyliusPayUPlugin\PayUGateway;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\PaymentTransitions;
use Tests\Refberg\SyliusPayUPlugin\Support\PaymentStateMachine;
use function sprintf;

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

    public function test_new_order_keeps_the_payment_new(): void
    {
        $payment = self::payment(['ORDER1']);

        self::assertSame(PaymentStateApplier::RESULT_UNCHANGED, $this->applier->apply($payment, self::order('ORDER1', 'NEW')));
        self::assertSame('new', $payment->getState());
        self::assertSame('NEW', PaymentDetails::of($payment)->getOrderStatus('ORDER1'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function inProgressStatuses(): iterable
    {
        yield 'pending' => ['PENDING'];
        yield 'waiting for confirmation' => ['WAITING_FOR_CONFIRMATION'];
    }

    #[DataProvider('inProgressStatuses')]
    public function test_order_in_progress_moves_the_payment_to_processing(string $status): void
    {
        $payment = self::payment(['ORDER1']);

        self::assertSame(PaymentStateApplier::RESULT_PROCESSING, $this->applier->apply($payment, self::order('ORDER1', $status)));
        self::assertSame('processing', $payment->getState());
        self::assertSame($status, PaymentDetails::of($payment)->getOrderStatus('ORDER1'));
    }

    public function test_duplicated_in_progress_notification_changes_nothing(): void
    {
        $payment = self::payment(['ORDER1']);
        $this->applier->apply($payment, self::order('ORDER1', 'PENDING'));

        self::assertSame(PaymentStateApplier::RESULT_UNCHANGED, $this->applier->apply($payment, self::order('ORDER1', 'PENDING')));
        self::assertSame(PaymentStateApplier::RESULT_UNCHANGED, $this->applier->apply($payment, self::order('ORDER1', 'WAITING_FOR_CONFIRMATION')));
        self::assertSame('processing', $payment->getState());
        self::assertSame([PaymentTransitions::TRANSITION_PROCESS], $this->stateMachine->appliedTransitions(PaymentTransitions::GRAPH));
    }

    public function test_order_in_progress_keeps_a_payment_switched_to_another_payment_method_new(): void
    {
        $applier = new PaymentStateApplier($this->stateMachine, credentialsResolver: new CredentialsResolver());
        $payment = self::payment(['ORDER1']);
        $payment->setMethod(self::method('BANK_TRANSFER', 'offline'));

        self::assertSame(PaymentStateApplier::RESULT_UNCHANGED, $applier->apply($payment, self::order('ORDER1', 'PENDING')));
        self::assertSame('new', $payment->getState());
        self::assertSame('PENDING', PaymentDetails::of($payment)->getOrderStatus('ORDER1'));
    }

    public function test_order_in_progress_moves_a_switched_payment_to_processing_when_no_credentials_resolver_is_given(): void
    {
        $payment = self::payment(['ORDER1']);
        $payment->setMethod(self::method('BANK_TRANSFER', 'offline'));

        self::assertSame(PaymentStateApplier::RESULT_PROCESSING, $this->applier->apply($payment, self::order('ORDER1', 'PENDING')));
        self::assertSame('processing', $payment->getState());
    }

    public function test_order_in_progress_moves_a_payment_that_still_uses_a_payu_method_to_processing(): void
    {
        $applier = new PaymentStateApplier($this->stateMachine, credentialsResolver: new CredentialsResolver());
        $payment = self::payment(['ORDER1']);
        $payment->setMethod(self::method('PAYU', PayUGateway::FACTORY_NAME));

        self::assertSame(PaymentStateApplier::RESULT_PROCESSING, $applier->apply($payment, self::order('ORDER1', 'WAITING_FOR_CONFIRMATION')));
        self::assertSame('processing', $payment->getState());
    }

    public function test_completed_order_completes_a_processing_payment(): void
    {
        $payment = self::payment(['ORDER1']);
        $this->applier->apply($payment, self::order('ORDER1', 'PENDING'));

        self::assertSame(PaymentStateApplier::RESULT_COMPLETED, $this->applier->apply($payment, self::order('ORDER1', 'COMPLETED')));
        self::assertSame('completed', $payment->getState());
    }

    public function test_canceled_order_fails_a_processing_payment(): void
    {
        $payment = self::payment(['ORDER1']);
        $this->applier->apply($payment, self::order('ORDER1', 'PENDING'));

        self::assertSame(PaymentStateApplier::RESULT_FAILED, $this->applier->apply($payment, self::order('ORDER1', 'CANCELED')));
        self::assertSame('failed', $payment->getState());
    }

    public function test_canceled_order_fails_the_payment_when_nothing_else_is_open(): void
    {
        $payment = self::payment(['ORDER1']);

        self::assertSame(PaymentStateApplier::RESULT_FAILED, $this->applier->apply($payment, self::order('ORDER1', 'CANCELED')));
        self::assertSame('failed', $payment->getState());
    }

    public function test_canceled_order_does_not_fail_a_payment_switched_to_another_payment_method(): void
    {
        $applier = new PaymentStateApplier($this->stateMachine, credentialsResolver: new CredentialsResolver());
        $payment = self::payment(['ORDER1']);
        $payment->setMethod(self::method('BANK_TRANSFER', 'offline'));

        self::assertSame(PaymentStateApplier::RESULT_UNCHANGED, $applier->apply($payment, self::order('ORDER1', 'CANCELED')));
        self::assertSame('new', $payment->getState());
        self::assertSame('CANCELED', PaymentDetails::of($payment)->getOrderStatus('ORDER1'));
    }

    public function test_canceled_order_fails_a_switched_payment_when_no_credentials_resolver_is_given(): void
    {
        $payment = self::payment(['ORDER1']);
        $payment->setMethod(self::method('BANK_TRANSFER', 'offline'));

        self::assertSame(PaymentStateApplier::RESULT_FAILED, $this->applier->apply($payment, self::order('ORDER1', 'CANCELED')));
        self::assertSame('failed', $payment->getState());
    }

    public function test_canceled_order_fails_a_payment_that_still_uses_a_payu_method(): void
    {
        $applier = new PaymentStateApplier($this->stateMachine, credentialsResolver: new CredentialsResolver());
        $payment = self::payment(['ORDER1']);
        $payment->setMethod(self::method('PAYU', PayUGateway::FACTORY_NAME));

        self::assertSame(PaymentStateApplier::RESULT_FAILED, $applier->apply($payment, self::order('ORDER1', 'CANCELED')));
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

    private static function method(string $code, string $factoryName): PaymentMethod
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName($factoryName);

        $method = new PaymentMethod();
        $method->setCode($code);
        $method->setGatewayConfig($gatewayConfig);

        return $method;
    }

    private static function order(string $orderId, string $status): PayUOrder
    {
        return new PayUOrder($orderId, null, $status, 1500, 'PLN', '300046');
    }
}
