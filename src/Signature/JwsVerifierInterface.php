<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Signature;

use Refberg\SyliusTpayPlugin\Api\Exception\TpayTransportException;
use Refberg\SyliusTpayPlugin\Config\Environment;

interface JwsVerifierInterface
{
    /**
     * Verifies the detached JWS signature (RFC 7515) of a notification body.
     *
     * @throws TpayTransportException when the Tpay certificates cannot be downloaded
     */
    public function verify(string $rawBody, ?string $jws, Environment $environment): bool;

    /**
     * @param array<array-key, mixed> $headers headers as returned by HeaderBag::all() (name => list of values)
     */
    public function extractHeader(array $headers): ?string;
}
