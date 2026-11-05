<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin;

use Refberg\SyliusPayUPlugin\DependencyInjection\RefbergSyliusPayUExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use function dirname;

final class RefbergSyliusPayUPlugin extends Bundle
{
    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new RefbergSyliusPayUExtension();
        }

        return $this->extension;
    }

    public function getPath(): string
    {
        return dirname(__DIR__);
    }
}
