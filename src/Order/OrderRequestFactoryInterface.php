<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Order;

use InvalidArgumentException;
use Refberg\SyliusPayUPlugin\Config\PayUCredentials;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

interface OrderRequestFactoryInterface
{
    /**
     * @throws InvalidArgumentException
     *
     * @return array<string, mixed>
     */
    public function create(
        PaymentRequestInterface $paymentRequest,
        PaymentInterface $payment,
        PayUCredentials $credentials,
        string $extOrderId,
    ): array;
}
