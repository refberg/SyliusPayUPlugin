<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Api;

use Refberg\SyliusPayUPlugin\Api\Exception\PayUException;
use Refberg\SyliusPayUPlugin\Api\Model\RefundCreated;
use Refberg\SyliusPayUPlugin\Config\PayUCredentials;

interface PayURefundReaderInterface
{
    /**
     * Refunds of an order (GET /api/v2_1/orders/{orderId}/refunds).
     *
     * @throws PayUException
     *
     * @return list<RefundCreated>
     */
    public function retrieveRefunds(PayUCredentials $credentials, string $orderId): array;
}
