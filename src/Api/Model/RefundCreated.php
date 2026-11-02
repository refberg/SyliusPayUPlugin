<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Api\Model;

final readonly class RefundCreated
{
    public function __construct(
        public string $refundId,
        public ?string $extRefundId,
        public string $status,
        public int $amount,
        public string $currencyCode,
    ) {
    }
}
