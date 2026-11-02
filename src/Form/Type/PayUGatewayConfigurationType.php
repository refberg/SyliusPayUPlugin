<?php

declare(strict_types=1);

namespace Refberg\SyliusPayUPlugin\Form\Type;

use Refberg\SyliusPayUPlugin\Config\Environment;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use function is_array;
use function is_string;
use function trim;

/**
 * Gateway configuration of the PayU payment method.
 *
 * Secret fields are never rendered back to the browser. Leaving them empty while editing
 * keeps the stored value. The whole configuration is encrypted at rest by Sylius.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class PayUGatewayConfigurationType extends AbstractType
{
    private const SECRET_FIELDS = ['second_key', 'oauth_client_secret'];

    private const GROUPS = ['sylius', 'refberg_payu'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('environment', ChoiceType::class, [
                'label' => 'refberg_payu.form.gateway_configuration.environment',
                'choices' => [
                    'refberg_payu.form.gateway_configuration.environment_sandbox' => Environment::Sandbox->value,
                    'refberg_payu.form.gateway_configuration.environment_production' => Environment::Production->value,
                ],
                'empty_data' => Environment::Sandbox->value,
                'constraints' => [new NotBlank(groups: self::GROUPS)],
            ])
            ->add('pos_id', TextType::class, [
                'label' => 'refberg_payu.form.gateway_configuration.pos_id',
                'constraints' => [
                    new NotBlank(groups: self::GROUPS),
                    new Regex(pattern: '/^\d+$/', message: 'refberg_payu.pos_id.invalid', groups: self::GROUPS),
                ],
            ])
            ->add('second_key', PasswordType::class, [
                'label' => 'refberg_payu.form.gateway_configuration.second_key',
                'help' => 'refberg_payu.form.gateway_configuration.secret_help',
                'always_empty' => true,
                'constraints' => [new NotBlank(groups: self::GROUPS)],
            ])
            ->add('oauth_client_id', TextType::class, [
                'label' => 'refberg_payu.form.gateway_configuration.oauth_client_id',
                'constraints' => [new NotBlank(groups: self::GROUPS)],
            ])
            ->add('oauth_client_secret', PasswordType::class, [
                'label' => 'refberg_payu.form.gateway_configuration.oauth_client_secret',
                'help' => 'refberg_payu.form.gateway_configuration.secret_help',
                'always_empty' => true,
                'constraints' => [new NotBlank(groups: self::GROUPS)],
            ])
            ->addEventListener(FormEvents::PRE_SUBMIT, self::keepStoredSecrets(...))
        ;
    }

    private static function keepStoredSecrets(FormEvent $event): void
    {
        $submitted = $event->getData();
        $stored = $event->getForm()->getData();
        if (!is_array($submitted) || !is_array($stored)) {
            return;
        }

        foreach (self::SECRET_FIELDS as $field) {
            $value = $submitted[$field] ?? '';
            if ((!is_string($value) || '' === trim($value)) && is_string($stored[$field] ?? null) && '' !== $stored[$field]) {
                $submitted[$field] = $stored[$field];
            }
        }

        $event->setData($submitted);
    }
}
