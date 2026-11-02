<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Config;

final readonly class PayUCredentials
{
    public function __construct(
        public string $posId,
        #[\SensitiveParameter]
        public string $secondKey,
        public string $clientId,
        #[\SensitiveParameter]
        public string $clientSecret,
        public Environment $environment,
    ) {
    }

    /**
     * Keeps secrets out of var_dump(), dump() and debug output.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'posId' => $this->posId,
            'secondKey' => '***',
            'clientId' => $this->clientId,
            'clientSecret' => '***',
            'environment' => $this->environment->value,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new \LogicException('PayU credentials must not be serialized.');
    }
}
