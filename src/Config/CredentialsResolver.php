<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Config;

use Refberg\SyliusPayUPlugin\PayUGateway;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use function is_scalar;
use function sprintf;
use function trim;

final readonly class CredentialsResolver implements CredentialsResolverInterface
{
    public function supports(PaymentMethodInterface $paymentMethod): bool
    {
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        if (!$gatewayConfig instanceof GatewayConfigInterface) {
            return false;
        }

        $factory = $gatewayConfig->getConfig()['factory'] ?? $gatewayConfig->getFactoryName();

        return PayUGateway::FACTORY_NAME === $factory;
    }

    public function resolve(PaymentMethodInterface $paymentMethod): PayUCredentials
    {
        if (!$this->supports($paymentMethod)) {
            throw new InvalidConfigurationException(sprintf(
                'Payment method "%s" is not a PayU payment method.',
                (string) $paymentMethod->getCode(),
            ));
        }

        /** @var array<string, mixed> $config */
        $config = $paymentMethod->getGatewayConfig()?->getConfig() ?? [];

        $environment = Environment::tryFrom(self::string($config, PayUGateway::CONFIG_ENVIRONMENT));
        if (null === $environment) {
            throw new InvalidConfigurationException(sprintf(
                'Payment method "%s" has no valid PayU environment.',
                (string) $paymentMethod->getCode(),
            ));
        }

        $credentials = new PayUCredentials(
            posId: self::string($config, PayUGateway::CONFIG_POS_ID),
            secondKey: self::string($config, PayUGateway::CONFIG_SECOND_KEY),
            clientId: self::string($config, PayUGateway::CONFIG_OAUTH_CLIENT_ID),
            clientSecret: self::string($config, PayUGateway::CONFIG_OAUTH_CLIENT_SECRET),
            environment: $environment,
        );

        foreach (['posId', 'secondKey', 'clientId', 'clientSecret'] as $field) {
            if ('' === $credentials->{$field}) {
                throw new InvalidConfigurationException(sprintf(
                    'Payment method "%s" is missing the PayU "%s" setting.',
                    (string) $paymentMethod->getCode(),
                    $field,
                ));
            }
        }

        return $credentials;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function string(array $config, string $key): string
    {
        $value = $config[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
