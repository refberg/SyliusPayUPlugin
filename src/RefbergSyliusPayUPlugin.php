<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin;

use Refberg\SyliusPayUPlugin\DependencyInjection\RefbergSyliusPayUExtension;
use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use function dirname;

final class RefbergSyliusPayUPlugin extends Bundle
{
    use SyliusPluginTrait;

    public function getContainerExtension(): ExtensionInterface
    {
        return new RefbergSyliusPayUExtension();
    }

    public function getPath(): string
    {
        return dirname(__DIR__);
    }
}
