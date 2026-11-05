<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Order;

use Refberg\SyliusPayUPlugin\Api\Model\PayUOrder;
use Refberg\SyliusPayUPlugin\Config\PayUCredentials;
use Sylius\Component\Payment\Model\PaymentInterface;

interface PayUOrderValidatorInterface
{
    /**
     * @return list<string> problems found, empty when the order matches
     */
    public function validate(PayUOrder $order, PaymentInterface $payment, PayUCredentials $credentials): array;
}
