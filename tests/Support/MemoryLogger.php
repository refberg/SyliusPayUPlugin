<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Support;

use Psr\Log\AbstractLogger;

final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_scalar($level) ? (string) $level : 'unknown',
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
