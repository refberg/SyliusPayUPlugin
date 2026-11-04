<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Api;

use function is_int;
use function is_string;

/**
 * @internal
 */
final class Json
{
    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && '' !== $value ? $value : null;
    }
}
