<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Payment;

use Refberg\SyliusPayUPlugin\Api\Exception\PayUException;
use Refberg\SyliusPayUPlugin\Api\Model\RefundCreated;
use Refberg\SyliusPayUPlugin\Config\PayUCredentials;
use Sylius\Component\Payment\Model\PaymentInterface;

interface PaymentSynchronizerInterface
{
    public const RESULT_INVALID = 'invalid';

    /**
     * @throws PayUException
     *
     * @return string one of PaymentStateApplierInterface::RESULT_* or self::RESULT_INVALID
     */
    public function synchronize(PaymentInterface $payment, string $orderId, PayUCredentials $credentials): string;

    /**
     * The refund as the PayU API lists it for the order, or null when the order has no such refund
     * in the payment currency.
     *
     * @throws PayUException
     */
    public function retrieveRefund(PaymentInterface $payment, string $orderId, string $refundId, PayUCredentials $credentials): ?RefundCreated;
}
