<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Signature;

use Refberg\SyliusTpayPlugin\Api\Exception\TpayTransportException;

interface CertificateProviderInterface
{
    /**
     * Returns the PEM certificate published at the given Tpay URL.
     *
     * @throws TpayTransportException when the certificate cannot be downloaded
     */
    public function get(string $url): string;

    public function forget(string $url): void;
}
