<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Payment;

use Refberg\SyliusPayUPlugin\Api\Exception\PayUException;
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
}
