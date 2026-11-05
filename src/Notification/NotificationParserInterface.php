<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Notification;

use Refberg\SyliusTpayPlugin\Api\Exception\TpayTransportException;
use Refberg\SyliusTpayPlugin\Config\TpayCredentials;

/**
 * parse() and parseStoredPayload() return null for anything that is not a correctly signed, well-formed
 * transaction notification of the configured merchant.
 */
interface NotificationParserInterface
{
    /**
     * @param array<array-key, mixed> $headers
     *
     * @throws TpayTransportException when the signing certificates cannot be downloaded
     */
    public function parse(string $rawBody, array $headers, TpayCredentials $credentials): ?Notification;

    /**
     * @throws TpayTransportException when the signing certificates cannot be downloaded
     */
    public function parseStoredPayload(mixed $payload, TpayCredentials $credentials): ?Notification;
}
