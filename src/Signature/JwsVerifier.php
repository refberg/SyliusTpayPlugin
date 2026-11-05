<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Signature;

use JsonException;
use Refberg\SyliusTpayPlugin\Config\Environment;
use function base64_decode;
use function base64_encode;
use function count;
use function explode;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function openssl_pkey_get_public;
use function openssl_verify;
use function openssl_x509_parse;
use function openssl_x509_verify;
use function preg_match;
use function preg_quote;
use function rtrim;
use function strtolower;
use function strtr;
use function time;
use const JSON_THROW_ON_ERROR;
use const OPENSSL_ALGO_SHA256;

/**
 * Verifies the "X-JWS-Signature" header of Tpay notifications.
 *
 * The header is a JWS with a detached payload: "<base64url header>..<base64url signature>".
 * The JWS header names the signing certificate in "x5u". It must be served by the Tpay "secure" host
 * of the configured environment and be signed by the Tpay root CA from the same host.
 */
final readonly class JwsVerifier implements JwsVerifierInterface
{
    public const HEADER_NAME = 'x-jws-signature';

    public const ROOT_CERTIFICATE_PATH = '/x509/tpay-jws-root.pem';

    public function __construct(private CertificateProviderInterface $certificateProvider)
    {
    }

    public function verify(string $rawBody, ?string $jws, Environment $environment): bool
    {
        if ('' === $rawBody || null === $jws || '' === $jws) {
            return false;
        }

        $parts = explode('.', $jws);
        if (3 !== count($parts) || '' === $parts[0] || '' === $parts[2]) {
            return false;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $signingInput = $encodedHeader . '.' . self::base64UrlEncode($rawBody);

        // The payload is detached; an attached one must be the notification body itself.
        if ('' !== $encodedPayload && $encodedPayload !== self::base64UrlEncode($rawBody)) {
            return false;
        }

        $header = self::decodeHeader($encodedHeader);
        $signature = self::base64UrlDecode($encodedSignature);
        if (null === $header || null === $signature || 'RS256' !== ($header['alg'] ?? null)) {
            return false;
        }

        $certificateUrl = $header['x5u'] ?? null;
        if (!is_string($certificateUrl) || !self::isTrustedUrl($certificateUrl, $environment)) {
            return false;
        }

        $rootUrl = $environment->secureUrl() . self::ROOT_CERTIFICATE_PATH;
        $certificate = $this->certificateProvider->get($certificateUrl);
        $root = $this->certificateProvider->get($rootUrl);

        if (self::verifySignature($signingInput, $signature, $certificate, $root)) {
            return true;
        }

        // Tpay may have rotated the certificate: the next delivery downloads it again.
        $this->certificateProvider->forget($certificateUrl);
        $this->certificateProvider->forget($rootUrl);

        return false;
    }

    public function extractHeader(array $headers): ?string
    {
        foreach ($headers as $name => $values) {
            if (!is_string($name) || self::HEADER_NAME !== strtolower($name)) {
                continue;
            }

            $value = is_array($values) ? ($values[0] ?? null) : $values;

            return is_string($value) ? $value : null;
        }

        return null;
    }

    private static function verifySignature(string $signingInput, string $signature, string $certificate, string $root): bool
    {
        if (1 !== @openssl_x509_verify($certificate, $root)) {
            return false;
        }

        $info = @openssl_x509_parse($certificate);
        $validFrom = is_array($info) ? ($info['validFrom_time_t'] ?? null) : null;
        $validTo = is_array($info) ? ($info['validTo_time_t'] ?? null) : null;
        $now = time();
        if (!is_int($validFrom) || !is_int($validTo) || $validFrom > $now || $validTo < $now) {
            return false;
        }

        $publicKey = @openssl_pkey_get_public($certificate);
        if (false === $publicKey) {
            return false;
        }

        return 1 === openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);
    }

    /**
     * Only a ".pem" file under /x509/ on the Tpay "secure" host of the configured environment is trusted.
     */
    private static function isTrustedUrl(string $url, Environment $environment): bool
    {
        return 1 === preg_match('#^' . preg_quote($environment->secureUrl(), '#') . '/x509/[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\.pem$#D', $url);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function decodeHeader(string $encodedHeader): ?array
    {
        $json = self::base64UrlDecode($encodedHeader);
        if (null === $json) {
            return null;
        }

        try {
            $header = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($header) ? $header : null;
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return false === $decoded || '' === $decoded ? null : $decoded;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
