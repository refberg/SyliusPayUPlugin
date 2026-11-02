<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Payment;

use Sylius\Component\Payment\Model\PaymentInterface;

interface PaymentLockerInterface
{
    public function lock(PaymentInterface $payment): void;
}
