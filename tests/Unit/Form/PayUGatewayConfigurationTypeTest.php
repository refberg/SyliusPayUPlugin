<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPayUPlugin\Unit\Form;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusPayUPlugin\Form\Type\PayUGatewayConfigurationType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
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

    public function test_complete_configuration_is_valid_in_the_admin_validation_group(): void
    {
        $form = $this->adminForm(null);

        $form->submit(self::STORED);

        self::assertTrue($form->isValid());
    }

    public function test_empty_secret_fields_are_valid_when_secrets_are_stored(): void
    {
        $form = $this->adminForm(self::STORED);

        $form->submit(array_merge(self::STORED, ['second_key' => '', 'oauth_client_secret' => '']));

        self::assertTrue($form->isValid());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidFields(): iterable
    {
        yield 'empty POS ID' => ['pos_id', ''];
        yield 'non-numeric POS ID' => ['pos_id', '30004a'];
        yield 'empty second key' => ['second_key', ''];
        yield 'empty OAuth client ID' => ['oauth_client_id', ''];
        yield 'empty OAuth client secret' => ['oauth_client_secret', ''];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_field_is_rejected_in_the_admin_validation_group(string $field, string $value): void
    {
        $form = $this->adminForm(null);

        $form->submit(array_merge(self::STORED, [$field => $value]));

        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get($field)->getErrors());
    }

    public function test_constraints_apply_only_to_the_sylius_validation_group(): void
    {
        $form = $this->factory->create(PayUGatewayConfigurationType::class);

        $form->submit(array_merge(self::STORED, ['pos_id' => '', 'second_key' => '']));

        self::assertTrue($form->isValid());
    }

    /**
     * @param array<string, string>|null $data
     *
     * @return FormInterface<array<string, mixed>|null>
     */
    private function adminForm(?array $data): FormInterface
    {
        return $this->factory->create(PayUGatewayConfigurationType::class, $data, ['validation_groups' => ['sylius']]);
    }
}
