<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Api;

use Refberg\SyliusPayUPlugin\Api\Exception\PayUException;
use Refberg\SyliusPayUPlugin\Config\PayUCredentials;

interface AccessTokenProviderInterface
{
    /**
     * @throws PayUException
     */
    public function getToken(PayUCredentials $credentials): string;

    public function invalidate(PayUCredentials $credentials): void;
}
