<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Config;

enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    /**
     * Base URL of the Tpay OpenAPI.
     */
    public function apiUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://openapi.sandbox.tpay.com',
            self::Production => 'https://api.tpay.com',
        };
    }

    /**
     * Base URL of the Tpay payment panel, which also serves the notification signing certificates.
     */
    public function secureUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://secure.sandbox.tpay.com',
            self::Production => 'https://secure.tpay.com',
        };
    }
}
