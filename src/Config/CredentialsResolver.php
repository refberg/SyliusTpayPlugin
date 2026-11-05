<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Config;

use Refberg\SyliusTpayPlugin\TpayGateway;
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

        return TpayGateway::FACTORY_NAME === $factory;
    }

    public function resolve(PaymentMethodInterface $paymentMethod): TpayCredentials
    {
        if (!$this->supports($paymentMethod)) {
            throw new InvalidConfigurationException(sprintf(
                'Payment method "%s" is not a Tpay payment method.',
                (string) $paymentMethod->getCode(),
            ));
        }

        /** @var array<string, mixed> $config */
        $config = $paymentMethod->getGatewayConfig()?->getConfig() ?? [];

        $environment = Environment::tryFrom(self::string($config, TpayGateway::CONFIG_ENVIRONMENT));
        if (null === $environment) {
            throw new InvalidConfigurationException(sprintf(
                'Payment method "%s" has no valid Tpay environment.',
                (string) $paymentMethod->getCode(),
            ));
        }

        $credentials = new TpayCredentials(
            merchantId: self::string($config, TpayGateway::CONFIG_MERCHANT_ID),
            clientId: self::string($config, TpayGateway::CONFIG_CLIENT_ID),
            clientSecret: self::string($config, TpayGateway::CONFIG_CLIENT_SECRET),
            securityCode: self::string($config, TpayGateway::CONFIG_SECURITY_CODE),
            environment: $environment,
        );

        foreach (['merchantId', 'clientId', 'clientSecret', 'securityCode'] as $field) {
            if ('' === $credentials->{$field}) {
                throw new InvalidConfigurationException(sprintf(
                    'Payment method "%s" is missing the Tpay "%s" setting.',
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
