<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin;

use Refberg\SyliusPayUPlugin\DependencyInjection\Compiler\RemoveShopServicesPass;
use Refberg\SyliusPayUPlugin\DependencyInjection\RefbergSyliusPayUExtension;
use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use function dirname;

final class RefbergSyliusPayUPlugin extends Bundle
{
    use SyliusPluginTrait;

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new RemoveShopServicesPass());
    }

    public function getContainerExtension(): ExtensionInterface
    {
        return new RefbergSyliusPayUExtension();
    }

    public function getPath(): string
    {
        return dirname(__DIR__);
    }
}
