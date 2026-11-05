<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Support;

use function getenv;
use function is_string;

/**
 * Settings of the optional tests against the real Tpay sandbox, read from environment variables.
 * Credentials are never stored in the repository.
 */
final class SandboxEnvironment
{
    private const CREDENTIAL_VARIABLES = [
        'merchant_id' => 'TPAY_SANDBOX_MERCHANT_ID',
        'client_id' => 'TPAY_SANDBOX_CLIENT_ID',
        'client_secret' => 'TPAY_SANDBOX_CLIENT_SECRET',
        'security_code' => 'TPAY_SANDBOX_SECURITY_CODE',
    ];

    public static function isEnabled(): bool
    {
        return '1' === self::value('TPAY_SANDBOX');
    }

    /**
     * Tests that wait for a person to pay or reject the transaction on the sandbox payment page.
     */
    public static function isManual(): bool
    {
        return self::isEnabled() && '1' === self::value('TPAY_SANDBOX_MANUAL');
    }

    /**
     * @return array<string, string>|null
     */
    public static function credentials(): ?array
    {
        $credentials = [];
        foreach (self::CREDENTIAL_VARIABLES as $key => $variable) {
            $value = self::value($variable);
            if (null === $value) {
                return null;
            }

            $credentials[$key] = $value;
        }

        return $credentials;
    }

    private static function value(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($value) && '' !== $value ? $value : null;
    }
}
