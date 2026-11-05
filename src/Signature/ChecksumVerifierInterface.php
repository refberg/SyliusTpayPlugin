<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Signature;

use SensitiveParameter;

interface ChecksumVerifierInterface
{
    /**
     * Verifies the "md5sum" field of a transaction notification.
     *
     * @param array<array-key, mixed> $fields decoded notification body
     */
    public function verify(array $fields, #[SensitiveParameter] string $securityCode): bool;
}
