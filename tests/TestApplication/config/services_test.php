<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\Refberg\SyliusPayUPlugin\Support\FakePayU;
use Tests\Refberg\SyliusPayUPlugin\Support\TestHttpClientFactory;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    if (!str_starts_with((string) $container->env(), 'test')) {
        return;
    }

    $services = $container->services();

    // PayU traffic in tests goes to an in-memory fake of the PayU sandbox; FakePayU rejects any
    // other host. Only PAYU_SANDBOX=1 switches to the real sandbox (see tests/Sandbox).
    $services->set(FakePayU::class)->public();

    $services->set('refberg_payu.http_client', HttpClientInterface::class)
        ->factory([TestHttpClientFactory::class, 'create'])
        ->args([service(FakePayU::class)])
        ->public();
};
