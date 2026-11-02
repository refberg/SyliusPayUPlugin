<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Api\Exception;

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
        return $this->httpStatusCode >= 500 || 429 === $this->httpStatusCode;
    }
}
