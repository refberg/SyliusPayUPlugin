<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\Form;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusPayUPlugin\Form\Type\PayUGatewayConfigurationType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;
use function array_merge;

final class PayUGatewayConfigurationTypeTest extends TestCase
{
    private const STORED = [
        'environment' => 'production',
        'pos_id' => '300046',
        'second_key' => 'stored-second-key',
        'oauth_client_id' => '300046',
        'oauth_client_secret' => 'stored-client-secret',
    ];

    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        $this->factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();
    }

    public function test_secrets_are_not_rendered(): void
    {
        $view = $this->factory->create(PayUGatewayConfigurationType::class, self::STORED)->createView();

        self::assertSame('', $view['second_key']->vars['value']);
        self::assertSame('', $view['oauth_client_secret']->vars['value']);
        self::assertSame('300046', $view['pos_id']->vars['value']);
    }

    public function test_empty_secret_fields_keep_stored_values(): void
    {
        $form = $this->factory->create(PayUGatewayConfigurationType::class, self::STORED);

        $form->submit([
            'environment' => 'sandbox',
            'pos_id' => '145227',
            'second_key' => '',
            'oauth_client_id' => '145227',
            'oauth_client_secret' => '   ',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame([
            'environment' => 'sandbox',
            'pos_id' => '145227',
            'second_key' => 'stored-second-key',
            'oauth_client_id' => '145227',
            'oauth_client_secret' => 'stored-client-secret',
        ], $form->getData());
    }

    public function test_new_secrets_replace_stored_values(): void
    {
        $form = $this->factory->create(PayUGatewayConfigurationType::class, self::STORED);

        $form->submit(array_merge(self::STORED, ['second_key' => 'new-key', 'oauth_client_secret' => 'new-secret']));

        $data = $form->getData();
        self::assertSame('new-key', $data['second_key']);
        self::assertSame('new-secret', $data['oauth_client_secret']);
    }
}
