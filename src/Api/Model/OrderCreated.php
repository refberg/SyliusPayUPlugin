<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Api\Model;

final readonly class OrderCreated
{
    public function __construct(
        public string $orderId,
        public string $redirectUri,
        public ?string $extOrderId,
    ) {
    }
}
