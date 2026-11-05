<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Unit\Signature;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusTpayPlugin\Signature\ChecksumVerifier;
use function md5;
use function strtoupper;

final class ChecksumVerifierTest extends TestCase
{
    private const CODE = 'security-code';

    public function test_checksum_of_the_documented_fields_is_accepted(): void
    {
        self::assertTrue((new ChecksumVerifier())->verify(self::fields(), self::CODE));
    }

    public function test_upper_case_checksum_is_accepted(): void
    {
        $fields = self::fields();
        $fields['md5sum'] = strtoupper($fields['md5sum']);

        self::assertTrue((new ChecksumVerifier())->verify($fields, self::CODE));
    }

    #[TestWith(['id', '1011'])]
    #[TestWith(['tr_id', 'TR-OTHER'])]
    #[TestWith(['tr_amount', '15.01'])]
    #[TestWith(['tr_amount', '15.0'])]
    #[TestWith(['tr_crc', 'other'])]
    #[TestWith(['md5sum', 'abc'])]
    public function test_any_changed_field_is_rejected(string $field, string $value): void
    {
        $fields = self::fields();
        $fields[$field] = $value;

        self::assertFalse((new ChecksumVerifier())->verify($fields, self::CODE));
    }

    public function test_other_security_code_is_rejected(): void
    {
        self::assertFalse((new ChecksumVerifier())->verify(self::fields(), 'other-code'));
    }

    public function test_empty_security_code_is_rejected_even_if_the_checksum_matches(): void
    {
        $fields = self::fields('');

        self::assertFalse((new ChecksumVerifier())->verify($fields, ''));
    }

    #[TestWith(['id'])]
    #[TestWith(['tr_id'])]
    #[TestWith(['tr_amount'])]
    #[TestWith(['tr_crc'])]
    #[TestWith(['md5sum'])]
    public function test_missing_or_non_string_field_is_rejected(string $field): void
    {
        $fields = self::fields();
        unset($fields[$field]);
        self::assertFalse((new ChecksumVerifier())->verify($fields, self::CODE));

        $fields[$field] = ['array'];
        self::assertFalse((new ChecksumVerifier())->verify($fields, self::CODE));
    }

    /**
     * @return array<string, string>
     */
    private static function fields(string $code = self::CODE): array
    {
        $fields = ['id' => '1010', 'tr_id' => 'TR-ABC-123X', 'tr_amount' => '15.00', 'tr_crc' => '000000001-1-abcdef012345'];
        $fields['md5sum'] = md5($fields['id'] . $fields['tr_id'] . $fields['tr_amount'] . $fields['tr_crc'] . $code);

        return $fields;
    }
}
