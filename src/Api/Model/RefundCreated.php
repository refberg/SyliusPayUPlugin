<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Api\Model;

final readonly class RefundCreated
{
    public const STATUS_FINALIZED = 'FINALIZED';

    public const STATUS_CANCELED = 'CANCELED';

    public function __construct(
        public string $refundId,
        public ?string $extRefundId,
        public string $status,
        public int $amount,
        public string $currencyCode,
    ) {
    }

    public static function isFinalStatus(?string $status): bool
    {
        return self::STATUS_FINALIZED === $status || self::STATUS_CANCELED === $status;
    }
}
