<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\DependencyInjection\Compiler;

use Refberg\SyliusPayUPlugin\OrderPay\StatusHttpResponseProvider;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Keeps the plugin usable in API-only applications without the Sylius shop bundle.
 */
final class RemoveShopServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $finalUrlProvider = 'sylius_shop.provider.order_pay.final_url';
        if (!$container->hasDefinition($finalUrlProvider) && !$container->hasAlias($finalUrlProvider)) {
            $container->removeDefinition(StatusHttpResponseProvider::class);
        }
    }
}
