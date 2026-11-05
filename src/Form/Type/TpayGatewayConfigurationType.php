<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Form\Type;

use Refberg\SyliusTpayPlugin\Config\Environment;
use Refberg\SyliusTpayPlugin\TpayGateway;
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
 * Gateway configuration of the Tpay payment method.
 *
 * Secret fields are never rendered back to the browser. Leaving them empty while editing
 * keeps the stored value. The whole configuration is encrypted at rest by Sylius.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class TpayGatewayConfigurationType extends AbstractType
{
    private const SECRET_FIELDS = [TpayGateway::CONFIG_CLIENT_SECRET, TpayGateway::CONFIG_SECURITY_CODE];

    private const GROUPS = ['sylius', TpayGateway::FACTORY_NAME];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(TpayGateway::CONFIG_ENVIRONMENT, ChoiceType::class, [
                'label' => 'refberg_tpay.form.gateway_configuration.environment',
                'choices' => [
                    'refberg_tpay.form.gateway_configuration.environment_sandbox' => Environment::Sandbox->value,
                    'refberg_tpay.form.gateway_configuration.environment_production' => Environment::Production->value,
                ],
                'empty_data' => Environment::Sandbox->value,
                'constraints' => [new NotBlank(groups: self::GROUPS)],
            ])
            ->add(TpayGateway::CONFIG_MERCHANT_ID, TextType::class, [
                'label' => 'refberg_tpay.form.gateway_configuration.merchant_id',
                'constraints' => [
                    new NotBlank(groups: self::GROUPS),
                    new Regex(pattern: '/^\d+$/', message: 'refberg_tpay.merchant_id.invalid', groups: self::GROUPS),
                ],
            ])
            ->add(TpayGateway::CONFIG_CLIENT_ID, TextType::class, [
                'label' => 'refberg_tpay.form.gateway_configuration.client_id',
                'constraints' => [new NotBlank(groups: self::GROUPS)],
            ])
            ->add(TpayGateway::CONFIG_CLIENT_SECRET, PasswordType::class, [
                'label' => 'refberg_tpay.form.gateway_configuration.client_secret',
                'help' => 'refberg_tpay.form.gateway_configuration.secret_help',
                'always_empty' => true,
                'constraints' => [new NotBlank(groups: self::GROUPS)],
            ])
            ->add(TpayGateway::CONFIG_SECURITY_CODE, PasswordType::class, [
                'label' => 'refberg_tpay.form.gateway_configuration.security_code',
                'help' => 'refberg_tpay.form.gateway_configuration.secret_help',
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
