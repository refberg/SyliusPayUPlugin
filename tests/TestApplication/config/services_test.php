<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use Symfony\Component\HttpClient\MockHttpClient;
use Tests\Refberg\SyliusPayUPlugin\Support\FakePayU;

return static function (ContainerConfigurator $container): void {
    if (!str_starts_with((string) $container->env(), 'test')) {
        return;
    }

    $services = $container->services();

    // All PayU traffic in tests goes to an in-memory fake of the PayU sandbox.
    // FakePayU rejects any other host, so the production API can never be reached.
    $services->set(FakePayU::class)->public();

    $services->set('refberg_payu.http_client', MockHttpClient::class)
        ->args([service(FakePayU::class), 'https://secure.snd.payu.com'])
        ->public();
};
