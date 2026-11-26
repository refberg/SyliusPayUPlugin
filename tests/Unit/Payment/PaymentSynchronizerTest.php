<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\Payment;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPayUPlugin\Api\Model\RefundCreated;
use Refberg\SyliusPayUPlugin\Api\PayUClientInterface;
use Refberg\SyliusPayUPlugin\Api\PayURefundReaderInterface;
use Refberg\SyliusPayUPlugin\Config\Environment;
use Refberg\SyliusPayUPlugin\Config\PayUCredentials;
use Refberg\SyliusPayUPlugin\Order\PayUOrderValidatorInterface;
use Refberg\SyliusPayUPlugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusPayUPlugin\Payment\PaymentSynchronizer;
use Sylius\Component\Core\Model\Payment;
use Tests\Refberg\SyliusPayUPlugin\Support\FakePayU;
use Tests\Refberg\SyliusPayUPlugin\Support\MemoryLogger;

final class PaymentSynchronizerTest extends TestCase
{
    public function test_it_returns_the_listed_refund(): void
    {
        $refund = new RefundCreated('5000000002', 'r-2', 'FINALIZED', 1500, 'PLN');

        self::assertSame($refund, self::synchronizer([new RefundCreated('5000000001', 'r-1', 'PENDING', 500, 'PLN'), $refund])->retrieveRefund(self::payment(), 'ORDER1', '5000000002', self::credentials()));
    }

    public function test_refund_missing_from_the_list_is_null(): void
    {
        self::assertNull(self::synchronizer([new RefundCreated('5000000001', 'r-1', 'FINALIZED', 1500, 'PLN')])->retrieveRefund(self::payment(), 'ORDER1', '5000000002', self::credentials()));
    }

    public function test_refund_in_another_currency_is_null(): void
    {
        self::assertNull(self::synchronizer([new RefundCreated('5000000001', 'r-1', 'FINALIZED', 1500, 'EUR')])->retrieveRefund(self::payment(), 'ORDER1', '5000000001', self::credentials()));
    }

    public function test_without_a_refund_reader_no_refund_is_confirmed(): void
    {
        $logger = new MemoryLogger();
        $synchronizer = new PaymentSynchronizer(
            self::createStub(PayUClientInterface::class),
            self::createStub(PayUOrderValidatorInterface::class),
            self::createStub(PaymentStateApplierInterface::class),
            $logger,
        );

        self::assertNull($synchronizer->retrieveRefund(self::payment(), 'ORDER1', '5000000001', self::credentials()));
        self::assertSame('error', $logger->records[0]['level'] ?? null);
    }

    /**
     * @param list<RefundCreated> $refunds
     */
    private static function synchronizer(array $refunds): PaymentSynchronizer
    {
        $reader = self::createStub(PayURefundReaderInterface::class);
        $reader->method('retrieveRefunds')->willReturn($refunds);

        return new PaymentSynchronizer(
            self::createStub(PayUClientInterface::class),
            self::createStub(PayUOrderValidatorInterface::class),
            self::createStub(PaymentStateApplierInterface::class),
            refundReader: $reader,
        );
    }

    private static function payment(): Payment
    {
        $payment = new Payment();
        $payment->setCurrencyCode('PLN');
        $payment->setAmount(1500);

        return $payment;
    }

    private static function credentials(): PayUCredentials
    {
        return new PayUCredentials(FakePayU::POS_ID, FakePayU::SECOND_KEY, FakePayU::CLIENT_ID, FakePayU::CLIENT_SECRET, Environment::Sandbox);
    }
}
