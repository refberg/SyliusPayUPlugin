<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Api\Exception;

use function in_array;

/**
 * PayU answered, but with an error (4xx/5xx or a non-SUCCESS status code).
 */
final class PayUApiException extends PayUException
{
    public function __construct(
        string $message,
        private readonly int $httpStatusCode,
        private readonly ?string $payuStatusCode = null,
    ) {
        parent::__construct($message);
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function getPayUStatusCode(): ?string
    {
        return $this->payuStatusCode;
    }

    public function isTransient(): bool
    {
        // 408 TIMEOUT and 429 (sandbox throttling) are documented by PayU as temporary.
        return $this->httpStatusCode >= 500 || in_array($this->httpStatusCode, [408, 429], true);
    }
}
