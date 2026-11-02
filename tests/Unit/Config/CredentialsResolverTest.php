<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\Config;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPayUPlugin\Config\CredentialsResolver;
use Refberg\SyliusPayUPlugin\Config\Environment;
use Refberg\SyliusPayUPlugin\Config\InvalidConfigurationException;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\PaymentMethod;

final class CredentialsResolverTest extends TestCase
{
    public function test_it_resolves_credentials(): void
    {
        $credentials = (new CredentialsResolver())->resolve(self::method('refberg_payu', self::config()));

        self::assertSame('300046', $credentials->posId);
        self::assertSame('second', $credentials->secondKey);
        self::assertSame('client', $credentials->clientId);
        self::assertSame('secret', $credentials->clientSecret);
        self::assertSame(Environment::Sandbox, $credentials->environment);
    }

    public function test_it_supports_only_the_payu_factory(): void
    {
        $resolver = new CredentialsResolver();

        self::assertTrue($resolver->supports(self::method('refberg_payu', [])));
        self::assertFalse($resolver->supports(self::method('offline', [])));
        self::assertFalse($resolver->supports(new PaymentMethod()));
    }

    public function test_it_rejects_incomplete_configuration(): void
    {
        $config = self::config();
        $config['oauth_client_secret'] = '';

        $this->expectException(InvalidConfigurationException::class);

        (new CredentialsResolver())->resolve(self::method('refberg_payu', $config));
    }

    public function test_it_rejects_an_unknown_environment(): void
    {
        $config = self::config();
        $config['environment'] = 'staging';

        $this->expectException(InvalidConfigurationException::class);

        (new CredentialsResolver())->resolve(self::method('refberg_payu', $config));
    }

    public function test_debug_output_hides_secrets(): void
    {
        $credentials = (new CredentialsResolver())->resolve(self::method('refberg_payu', self::config()));

        $dump = print_r($credentials, true);

        self::assertStringNotContainsString('second', str_replace('secondKey', '', $dump));
        self::assertStringNotContainsString('secret', str_replace('clientSecret', '', $dump));
    }

    /**
     * @return array<string, string>
     */
    private static function config(): array
    {
        return [
            'environment' => 'sandbox',
            'pos_id' => '300046',
            'second_key' => 'second',
            'oauth_client_id' => 'client',
            'oauth_client_secret' => 'secret',
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function method(string $factory, array $config): PaymentMethod
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName($factory);
        $gatewayConfig->setGatewayName($factory);
        $gatewayConfig->setConfig($config);

        $method = new PaymentMethod();
        $method->setCode('PAYU');
        $method->setGatewayConfig($gatewayConfig);

        return $method;
    }
}
