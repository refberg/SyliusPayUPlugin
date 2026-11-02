<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Config;

enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function baseUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://secure.snd.payu.com',
            self::Production => 'https://secure.payu.com',
        };
    }
}
