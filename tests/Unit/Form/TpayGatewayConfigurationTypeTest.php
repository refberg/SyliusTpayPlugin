<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Unit\Form;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusTpayPlugin\Form\Type\TpayGatewayConfigurationType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

final class TpayGatewayConfigurationTypeTest extends TestCase
{
    private const STORED = [
        'environment' => 'production',
        'merchant_id' => '1010',
        'client_id' => 'client',
        'client_secret' => 'stored-client-secret',
        'security_code' => 'stored-security-code',
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
        $view = $this->factory->create(TpayGatewayConfigurationType::class, self::STORED)->createView();

        self::assertSame('', $view['client_secret']->vars['value']);
        self::assertSame('', $view['security_code']->vars['value']);
        self::assertSame('1010', $view['merchant_id']->vars['value']);
    }

    public function test_empty_secret_fields_keep_stored_values(): void
    {
        $form = $this->submit(['client_secret' => '', 'security_code' => '   '], self::STORED);

        self::assertTrue($form->isValid());
        self::assertSame('2020', $form->getData()['merchant_id'] ?? null);
        self::assertSame('stored-client-secret', $form->getData()['client_secret'] ?? null);
        self::assertSame('stored-security-code', $form->getData()['security_code'] ?? null);
    }

    public function test_new_secrets_replace_stored_ones(): void
    {
        $form = $this->submit(['client_secret' => 'new-secret', 'security_code' => 'new-code'], self::STORED);

        self::assertSame('new-secret', $form->getData()['client_secret'] ?? null);
        self::assertSame('new-code', $form->getData()['security_code'] ?? null);
    }

    #[TestWith(['merchant_id', ''])]
    #[TestWith(['merchant_id', '10a'])]
    #[TestWith(['client_id', ''])]
    #[TestWith(['client_secret', ''])]
    #[TestWith(['security_code', ''])]
    public function test_missing_or_invalid_settings_are_rejected_for_a_new_method(string $field, string $value): void
    {
        $form = $this->submit([$field => $value]);

        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, $form->get($field)->getErrors()->count());
    }

    /**
     * @param array<string, string> $data
     * @param array<string, string>|null $stored
     *
     * @return FormInterface<array<string, mixed>|null>
     */
    private function submit(array $data, ?array $stored = null): FormInterface
    {
        $form = $this->factory->create(TpayGatewayConfigurationType::class, $stored, ['validation_groups' => ['refberg_tpay']]);
        $form->submit($data + [
            'environment' => 'sandbox',
            'merchant_id' => '2020',
            'client_id' => 'client2',
            'client_secret' => 'secret',
            'security_code' => 'code',
        ]);

        return $form;
    }
}
