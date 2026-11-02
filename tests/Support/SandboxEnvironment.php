<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Support;

use function getenv;
use function is_string;

/**
 * Settings of the optional tests against the real PayU sandbox, read from environment variables.
 * Credentials are never stored in the repository.
 */
final class SandboxEnvironment
{
    private const CREDENTIAL_VARIABLES = [
        'pos_id' => 'PAYU_SANDBOX_POS_ID',
        'second_key' => 'PAYU_SANDBOX_SECOND_KEY',
        'oauth_client_id' => 'PAYU_SANDBOX_CLIENT_ID',
        'oauth_client_secret' => 'PAYU_SANDBOX_CLIENT_SECRET',
    ];

    public static function isEnabled(): bool
    {
        return '1' === self::value('PAYU_SANDBOX');
    }

    /**
     * @return array<string, string>|null
     */
    public static function credentials(): ?array
    {
        $credentials = [];
        foreach (self::CREDENTIAL_VARIABLES as $key => $variable) {
            $value = self::value($variable);
            if (null === $value) {
                return null;
            }

            $credentials[$key] = $value;
        }

        return $credentials;
    }

    private static function value(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($value) && '' !== $value ? $value : null;
    }
}
