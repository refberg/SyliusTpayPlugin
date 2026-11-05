<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Unit\Config;

use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusTpayPlugin\Config\CredentialsResolver;
use Refberg\SyliusTpayPlugin\Config\Environment;
use Refberg\SyliusTpayPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusTpayPlugin\TpayGateway;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\PaymentMethod;
use function print_r;
use function serialize;

final class CredentialsResolverTest extends TestCase
{
    public function test_configuration_is_resolved_and_trimmed(): void
    {
        $credentials = (new CredentialsResolver())->resolve(self::method(['merchant_id' => ' 1010 ']));

        self::assertSame('1010', $credentials->merchantId);
        self::assertSame('client', $credentials->clientId);
        self::assertSame('secret', $credentials->clientSecret);
        self::assertSame('code', $credentials->securityCode);
        self::assertSame(Environment::Sandbox, $credentials->environment);
        self::assertSame('https://openapi.sandbox.tpay.com', $credentials->environment->apiUrl());
        self::assertSame('https://api.tpay.com', Environment::Production->apiUrl());
        self::assertSame('https://secure.tpay.com', Environment::Production->secureUrl());
    }

    #[TestWith(['merchant_id'])]
    #[TestWith(['client_id'])]
    #[TestWith(['client_secret'])]
    #[TestWith(['security_code'])]
    public function test_missing_setting_is_rejected(string $field): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new CredentialsResolver())->resolve(self::method([$field => ' ']));
    }

    public function test_unknown_environment_is_rejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new CredentialsResolver())->resolve(self::method(['environment' => 'staging']));
    }

    public function test_other_gateways_are_not_supported(): void
    {
        $method = self::method([], 'offline');
        self::assertFalse((new CredentialsResolver())->supports($method));
        self::assertFalse((new CredentialsResolver())->supports(new PaymentMethod()));

        $this->expectException(InvalidConfigurationException::class);
        (new CredentialsResolver())->resolve($method);
    }

    public function test_secrets_never_appear_in_debug_output_and_credentials_cannot_be_serialized(): void
    {
        $credentials = (new CredentialsResolver())->resolve(self::method());

        $dump = print_r($credentials, true);
        self::assertStringNotContainsString('secret', $dump);
        self::assertStringNotContainsString('code', $dump);

        $this->expectException(LogicException::class);
        serialize($credentials);
    }

    /**
     * @param array<string, string> $config
     */
    private static function method(array $config = [], string $factory = TpayGateway::FACTORY_NAME): PaymentMethod
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName($factory);
        $gatewayConfig->setConfig($config + ['environment' => 'sandbox', 'merchant_id' => '1010', 'client_id' => 'client', 'client_secret' => 'secret', 'security_code' => 'code']);
        $method = new PaymentMethod();
        $method->setCode('TPAY');
        $method->setGatewayConfig($gatewayConfig);

        return $method;
    }
}
