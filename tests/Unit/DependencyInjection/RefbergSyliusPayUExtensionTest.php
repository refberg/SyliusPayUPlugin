<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPayUPlugin\DependencyInjection\RefbergSyliusPayUExtension;
use Refberg\SyliusPayUPlugin\Order\OrderRequestFactoryInterface;
use Refberg\SyliusPayUPlugin\OrderPay\StatusHttpResponseProvider;
use Sylius\Bundle\ShopBundle\SyliusShopBundle;
use Sylius\TwigHooks\DependencyInjection\SyliusTwigHooksExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class RefbergSyliusPayUExtensionTest extends TestCase
{
    public function test_shop_services_are_registered_with_the_shop_bundle(): void
    {
        $container = self::load(['SyliusShopBundle' => SyliusShopBundle::class]);

        self::assertTrue($container->hasDefinition(StatusHttpResponseProvider::class));
        $afterPayUrlProvider = $container->getDefinition(OrderRequestFactoryInterface::class)->getArgument(1);
        self::assertInstanceOf(Reference::class, $afterPayUrlProvider);
        self::assertSame('sylius_shop.provider.order_pay.after_pay_url', (string) $afterPayUrlProvider);
    }

    public function test_shop_services_are_not_registered_without_the_shop_bundle(): void
    {
        $container = self::load([]);

        self::assertFalse($container->hasDefinition(StatusHttpResponseProvider::class));
        self::assertNull($container->getDefinition(OrderRequestFactoryInterface::class)->getArgument(1));
    }

    public function test_admin_twig_hooks_are_prepended_when_twig_hooks_are_enabled(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new SyliusTwigHooksExtension());

        (new RefbergSyliusPayUExtension())->prepend($container);

        $gatewayConfiguration = [
            'refberg_payu' => [
                'template' => '@RefbergSyliusPayUPlugin/admin/payment_method/gateway_configuration.html.twig',
                'priority' => 0,
            ],
        ];
        self::assertSame([['hooks' => [
            'sylius_admin.payment_method.create.content.form.sections.gateway_configuration.refberg_payu' => $gatewayConfiguration,
            'sylius_admin.payment_method.update.content.form.sections.gateway_configuration.refberg_payu' => $gatewayConfiguration,
        ]]], $container->getExtensionConfig('sylius_twig_hooks'));
    }

    public function test_no_twig_hooks_are_configured_without_twig_hooks(): void
    {
        $container = new ContainerBuilder();

        (new RefbergSyliusPayUExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('sylius_twig_hooks'));
    }

    /**
     * @param array<string, class-string> $bundles
     */
    private static function load(array $bundles): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', $bundles);

        (new RefbergSyliusPayUExtension())->load([], $container);

        return $container;
    }
}
