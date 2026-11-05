<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Signature;

use SensitiveParameter;
use function hash_equals;
use function is_string;
use function md5;
use function strtolower;

/**
 * md5sum = md5(id . tr_id . tr_amount . tr_crc . security code), with the values exactly as Tpay sent them.
 */
final readonly class ChecksumVerifier implements ChecksumVerifierInterface
{
    public function verify(array $fields, #[SensitiveParameter] string $securityCode): bool
    {
        $values = [];
        foreach (['id', 'tr_id', 'tr_amount', 'tr_crc', 'md5sum'] as $key) {
            $value = $fields[$key] ?? null;
            if (!is_string($value)) {
                return false;
            }
            $values[$key] = $value;
        }

        if ('' === $securityCode || '' === $values['md5sum']) {
            return false;
        }

        $expected = md5($values['id'] . $values['tr_id'] . $values['tr_amount'] . $values['tr_crc'] . $securityCode);

        return hash_equals($expected, strtolower($values['md5sum']));
    }
}
