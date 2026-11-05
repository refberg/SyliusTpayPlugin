<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Api;

use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function preg_match;
use function round;
use function str_pad;
use function strlen;
use function substr;
use const STR_PAD_LEFT;

/**
 * @internal
 */
final class Json
{
    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Reads an amount in major units ("12.34", 12.34 or 12) and returns it in minor units (1234).
     *
     * @param array<array-key, mixed> $data
     */
    public static function amount(array $data, string $key): ?int
    {
        return self::toMinorUnits($data[$key] ?? null);
    }

    public static function toMinorUnits(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value * 100;
        }

        if (is_float($value)) {
            return (int) round($value * 100);
        }

        // Strings are parsed without floating point, so "0.29" is always 29.
        if (!is_string($value) || !is_numeric($value) || 1 !== preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $value, $matches)) {
            return null;
        }

        $minor = (int) $matches[2] * 100 + (int) str_pad($matches[3] ?? '', 2, '0');

        return '-' === $matches[1] ? -$minor : $minor;
    }

    /**
     * Formats an amount in minor units (1234) as Tpay expects it in request bodies ("12.34").
     */
    public static function toMajorUnits(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $digits = str_pad((string) ($amount < 0 ? -$amount : $amount), 3, '0', STR_PAD_LEFT);

        return $sign . substr($digits, 0, -2) . '.' . substr($digits, strlen($digits) - 2);
    }
}
