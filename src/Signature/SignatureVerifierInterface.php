<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Signature;

interface SignatureVerifierInterface
{
    public function verify(string $rawBody, ?string $signatureHeader, #[\SensitiveParameter] string $secondKey): bool;

    /**
     * @param array<array-key, mixed> $headers
     */
    public function extractHeader(array $headers): ?string;
}
