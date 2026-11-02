<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Api\Model;

/**
 * Order state as returned by GET /api/v2_1/orders/{orderId}.
 */
final readonly class PayUOrder
{
    public const STATUS_NEW = 'NEW';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_WAITING_FOR_CONFIRMATION = 'WAITING_FOR_CONFIRMATION';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_CANCELED = 'CANCELED';

    public function __construct(
        public string $orderId,
        public ?string $extOrderId,
        public string $status,
        public int $totalAmount,
        public string $currencyCode,
        public ?string $merchantPosId,
    ) {
    }

    public function isFinal(): bool
    {
        return self::isFinalStatus($this->status);
    }

    public static function isFinalStatus(?string $status): bool
    {
        return self::STATUS_COMPLETED === $status || self::STATUS_CANCELED === $status;
    }
}
