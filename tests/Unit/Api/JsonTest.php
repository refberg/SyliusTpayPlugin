<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Unit\Api;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusTpayPlugin\Api\Json;

final class JsonTest extends TestCase
{
    public function test_strings_are_returned_as_they_are(): void
    {
        self::assertSame('ORDER1', Json::string(['orderId' => 'ORDER1'], 'orderId'));
    }

    public function test_integers_are_returned_as_strings(): void
    {
        self::assertSame('123', Json::string(['refundId' => 123], 'refundId'));
    }

    #[TestWith([''])]
    #[TestWith([['ORDER1']])]
    #[TestWith([true])]
    #[TestWith([null])]
    public function test_empty_and_non_scalar_values_are_null(mixed $value): void
    {
        self::assertNull(Json::string(['orderId' => $value], 'orderId'));
    }

    public function test_missing_key_is_null(): void
    {
        self::assertNull(Json::string(['orderId' => 'ORDER1'], 'extOrderId'));
    }

    #[TestWith(['12.34', 1234])]
    #[TestWith(['0.29', 29])]
    #[TestWith(['0.1', 10])]
    #[TestWith(['15', 1500])]
    #[TestWith(['-1.05', -105])]
    #[TestWith([12.34, 1234])]
    #[TestWith([0.29, 29])]
    #[TestWith([15.0, 1500])]
    #[TestWith([15, 1500])]
    public function test_amounts_are_read_in_minor_units(mixed $value, int $expected): void
    {
        self::assertSame($expected, Json::amount(['amount' => $value], 'amount'));
    }

    #[TestWith(['12.345'])]
    #[TestWith(['1e3'])]
    #[TestWith(['abc'])]
    #[TestWith([''])]
    #[TestWith([null])]
    #[TestWith([true])]
    public function test_invalid_amounts_are_null(mixed $value): void
    {
        self::assertNull(Json::amount(['amount' => $value], 'amount'));
    }

    #[TestWith([1234, '12.34'])]
    #[TestWith([1, '0.01'])]
    #[TestWith([10, '0.10'])]
    #[TestWith([1500, '15.00'])]
    #[TestWith([0, '0.00'])]
    #[TestWith([-105, '-1.05'])]
    public function test_amounts_are_formatted_in_major_units(int $amount, string $expected): void
    {
        self::assertSame($expected, Json::toMajorUnits($amount));
    }
}
