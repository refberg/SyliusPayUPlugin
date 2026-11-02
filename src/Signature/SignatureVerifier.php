<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Signature;

/**
 * Verifies the "OpenPayu-Signature" header of PayU notifications.
 *
 * Header format: "sender=checkout;signature=<hex>;algorithm=<MD5|SHA-256|...>;content=DOCUMENT".
 * The expected signature is hash(algorithm, rawBody . secondKey).
 */
final class SignatureVerifier implements SignatureVerifierInterface
{
    public const HEADER_NAMES = ['openpayu-signature', 'x-openpayu-signature'];

    private const ALGORITHMS = [
        'MD5' => 'md5',
        'SHA' => 'sha1',
        'SHA1' => 'sha1',
        'SHA-1' => 'sha1',
        'SHA256' => 'sha256',
        'SHA-256' => 'sha256',
        'SHA384' => 'sha384',
        'SHA-384' => 'sha384',
        'SHA512' => 'sha512',
        'SHA-512' => 'sha512',
    ];

    public function verify(string $rawBody, ?string $signatureHeader, #[\SensitiveParameter] string $secondKey): bool
    {
        if ('' === $rawBody || null === $signatureHeader || '' === $secondKey) {
            return false;
        }

        $parsed = $this->parseHeader($signatureHeader);
        if (null === $parsed) {
            return false;
        }

        $expected = hash($parsed['algorithm'], $rawBody . $secondKey);

        return hash_equals($expected, $parsed['signature']);
    }

    /**
     * @param array<array-key, mixed> $headers headers as returned by HeaderBag::all() (name => list of values)
     */
    public function extractHeader(array $headers): ?string
    {
        foreach ($headers as $name => $values) {
            if (!is_string($name) || !in_array(strtolower($name), self::HEADER_NAMES, true)) {
                continue;
            }

            $value = is_array($values) ? ($values[0] ?? null) : $values;

            return is_string($value) ? $value : null;
        }

        return null;
    }

    /**
     * @return array{signature: string, algorithm: string}|null
     */
    private function parseHeader(string $header): ?array
    {
        $parts = [];
        foreach (explode(';', $header) as $part) {
            $part = trim($part);
            if ('' === $part) {
                continue;
            }

            $pair = explode('=', $part, 2);
            if (2 !== count($pair)) {
                return null;
            }

            $parts[strtolower(trim($pair[0]))] = trim($pair[1]);
        }

        $signature = strtolower($parts['signature'] ?? '');
        $algorithm = self::ALGORITHMS[strtoupper($parts['algorithm'] ?? '')] ?? null;

        if (null === $algorithm || 1 !== preg_match('/^[0-9a-f]+$/', $signature)) {
            return null;
        }

        return ['signature' => $signature, 'algorithm' => $algorithm];
    }
}
