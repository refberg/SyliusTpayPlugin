<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Notification;

use Refberg\SyliusTpayPlugin\Api\Json;
use Refberg\SyliusTpayPlugin\Config\TpayCredentials;
use Refberg\SyliusTpayPlugin\Signature\ChecksumVerifierInterface;
use Refberg\SyliusTpayPlugin\Signature\JwsVerifierInterface;
use function hash_equals;
use function in_array;
use function is_array;
use function is_string;
use function parse_str;
use function strtoupper;

/**
 * Verifies a Tpay transaction notification (application/x-www-form-urlencoded) and extracts its identifiers.
 *
 * Both signatures are required: md5sum (security code) and the X-JWS-Signature header (Tpay certificate).
 * The notification must also name the configured merchant ID.
 */
final readonly class NotificationParser implements NotificationParserInterface
{
    private const TYPES = [
        Notification::STATUS_TRUE => Notification::TYPE_PAYMENT,
        Notification::STATUS_PAID => Notification::TYPE_PAYMENT,
        Notification::STATUS_CHARGEBACK => Notification::TYPE_REFUND,
    ];

    public function __construct(
        private ChecksumVerifierInterface $checksumVerifier,
        private JwsVerifierInterface $jwsVerifier,
    ) {
    }

    public function parse(string $rawBody, array $headers, TpayCredentials $credentials): ?Notification
    {
        if ('' === $rawBody) {
            return null;
        }

        parse_str($rawBody, $fields);

        if (!$this->checksumVerifier->verify($fields, $credentials->securityCode)) {
            return null;
        }

        $merchantId = Json::string($fields, 'id');
        if (null === $merchantId || !hash_equals($credentials->merchantId, $merchantId)) {
            return null;
        }

        if (!$this->jwsVerifier->verify($rawBody, $this->jwsVerifier->extractHeader($headers), $credentials->environment)) {
            return null;
        }

        $title = Json::string($fields, 'tr_id');
        $crc = Json::string($fields, 'tr_crc');
        $status = strtoupper(Json::string($fields, 'tr_status') ?? '');
        $type = self::TYPES[$status] ?? null;
        if (in_array(null, [$title, $crc, $type], true)) {
            return null;
        }

        return new Notification(
            type: $type,
            merchantId: $merchantId,
            title: $title,
            crc: $crc,
            status: $status,
            amount: Json::amount($fields, 'tr_amount'),
            currency: Json::string($fields, 'tr_currency'),
        );
    }

    /**
     * Parses a notification stored in PaymentRequest::$payload by Sylius' NotifyPayloadProcessor.
     */
    public function parseStoredPayload(mixed $payload, TpayCredentials $credentials): ?Notification
    {
        $httpRequest = is_array($payload) ? ($payload['http_request'] ?? null) : null;
        if (!is_array($httpRequest)) {
            return null;
        }

        $content = $httpRequest['content'] ?? null;
        $headers = $httpRequest['headers'] ?? [];

        if (!is_string($content) || !is_array($headers)) {
            return null;
        }

        return $this->parse($content, $headers, $credentials);
    }
}
