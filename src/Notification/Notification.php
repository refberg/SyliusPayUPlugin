<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Notification;

/**
 * A PayU notification whose signature has been verified.
 *
 * For order notifications only the identifiers are used: the order state itself
 * is always read again from the PayU API.
 */
final readonly class Notification
{
    public const TYPE_ORDER = 'order';

    public const TYPE_REFUND = 'refund';

    public function __construct(
        public string $type,
        public string $orderId,
        public ?string $extOrderId,
        public ?string $refundId = null,
        public ?string $extRefundId = null,
        public ?string $refundStatus = null,
    ) {
    }
}
