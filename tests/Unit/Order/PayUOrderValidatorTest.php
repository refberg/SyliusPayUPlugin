<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\Order;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPayUPlugin\Api\Model\PayUOrder;
use Refberg\SyliusPayUPlugin\Config\Environment;
use Refberg\SyliusPayUPlugin\Config\PayUCredentials;
use Refberg\SyliusPayUPlugin\Order\PayUOrderValidator;
use Refberg\SyliusPayUPlugin\Payment\PaymentDetails;
use Sylius\Component\Core\Model\Payment;

final class PayUOrderValidatorTest extends TestCase
{
    private const EXT_ORDER_ID = '000001-7-abcdef012345';

    public function test_matching_order_is_valid(): void
    {
        self::assertSame([], $this->validate(new PayUOrder('ORDER1', self::EXT_ORDER_ID, 'COMPLETED', 1500, 'PLN', '300046')));
    }

    public function test_missing_pos_id_in_response_is_accepted(): void
    {
        self::assertSame([], $this->validate(new PayUOrder('ORDER1', self::EXT_ORDER_ID, 'COMPLETED', 1500, 'PLN', null)));
    }

    public function test_it_reports_a_wrong_amount(): void
    {
        self::assertSame(['amount does not match'], $this->validate(new PayUOrder('ORDER1', self::EXT_ORDER_ID, 'COMPLETED', 1, 'PLN', '300046')));
    }

    public function test_it_reports_a_wrong_currency(): void
    {
        self::assertSame(['currency does not match'], $this->validate(new PayUOrder('ORDER1', self::EXT_ORDER_ID, 'COMPLETED', 1500, 'EUR', '300046')));
    }

    public function test_it_reports_a_wrong_ext_order_id(): void
    {
        self::assertSame(['extOrderId does not match'], $this->validate(new PayUOrder('ORDER1', '000002-8-abcdef012345', 'COMPLETED', 1500, 'PLN', '300046')));
    }

    public function test_it_reports_a_foreign_pos(): void
    {
        self::assertSame(['merchantPosId does not match'], $this->validate(new PayUOrder('ORDER1', self::EXT_ORDER_ID, 'COMPLETED', 1500, 'PLN', '999999')));
    }

    public function test_it_reports_an_order_not_linked_to_the_payment(): void
    {
        $errors = $this->validate(new PayUOrder('OTHER', self::EXT_ORDER_ID, 'COMPLETED', 1500, 'PLN', '300046'));

        self::assertContains('PayU order is not linked to this payment', $errors);
    }

    /**
     * @return list<string>
     */
    private function validate(PayUOrder $order): array
    {
        $payment = new Payment();
        $payment->setAmount(1500);
        $payment->setCurrencyCode('PLN');
        $details = PaymentDetails::of($payment);
        $details->addOrder('ORDER1', self::EXT_ORDER_ID, 1500, 'PLN');
        $details->save();

        return (new PayUOrderValidator())->validate($order, $payment, new PayUCredentials('300046', 'k', 'c', 's', Environment::Sandbox));
    }
}
