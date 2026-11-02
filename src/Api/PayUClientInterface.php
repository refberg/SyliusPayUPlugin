<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Api;

use Refberg\SyliusPayUPlugin\Api\Exception\PayUException;
use Refberg\SyliusPayUPlugin\Api\Model\OrderCreated;
use Refberg\SyliusPayUPlugin\Api\Model\PayUOrder;
use Refberg\SyliusPayUPlugin\Api\Model\RefundCreated;
use Refberg\SyliusPayUPlugin\Config\PayUCredentials;

interface PayUClientInterface
{
    /**
     * @param array<string, mixed> $order body of POST /api/v2_1/orders
     *
     * @throws PayUException
     */
    public function createOrder(PayUCredentials $credentials, array $order): OrderCreated;

    /**
     * @throws PayUException
     */
    public function retrieveOrder(PayUCredentials $credentials, string $orderId): PayUOrder;

    /**
     * Cancels an order that is not COMPLETED (DELETE /api/v2_1/orders/{orderId}).
     *
     * @throws PayUException
     */
    public function cancelOrder(PayUCredentials $credentials, string $orderId): void;

    /**
     * @param array<string, mixed> $refund content of the "refund" object
     *
     * @throws PayUException
     */
    public function createRefund(PayUCredentials $credentials, string $orderId, array $refund): RefundCreated;
}
