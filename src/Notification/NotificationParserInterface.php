<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Notification;

use Refberg\SyliusPayUPlugin\Config\PayUCredentials;

/**
 * parse() and parseStoredPayload() return null for anything that is not a correctly signed, well-formed notification.
 */
interface NotificationParserInterface
{
    /**
     * @param array<array-key, mixed> $headers
     */
    public function parse(string $rawBody, array $headers, PayUCredentials $credentials): ?Notification;

    public function parseStoredPayload(mixed $payload, PayUCredentials $credentials): ?Notification;
}
