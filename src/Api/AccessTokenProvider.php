<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Api;

use Refberg\SyliusTpayPlugin\Api\Exception\TpayAuthenticationException;
use Refberg\SyliusTpayPlugin\Api\Exception\TpayTransportException;
use Refberg\SyliusTpayPlugin\Config\TpayCredentials;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function hash;
use function implode;
use function in_array;
use function is_numeric;
use function is_string;
use function max;
use function sprintf;

/**
 * Obtains OAuth access tokens (POST /oauth/auth) and caches them until shortly before expiry.
 */
final readonly class AccessTokenProvider implements AccessTokenProviderInterface
{
    private const EXPIRY_MARGIN_SECONDS = 60;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private float $timeout = 15.0,
    ) {
    }

    public function getToken(TpayCredentials $credentials): string
    {
        /** @var string $token */
        $token = $this->cache->get(
            $this->cacheKey($credentials),
            fn (ItemInterface $item): string => $this->fetchToken($credentials, $item),
        );

        return $token;
    }

    public function invalidate(TpayCredentials $credentials): void
    {
        $this->cache->delete($this->cacheKey($credentials));
    }

    private function fetchToken(TpayCredentials $credentials, ItemInterface $item): string
    {
        try {
            $response = $this->httpClient->request('POST', $credentials->environment->apiUrl() . '/oauth/auth', [
                'headers' => ['Accept' => 'application/json'],
                'json' => [
                    'client_id' => $credentials->clientId,
                    'client_secret' => $credentials->clientSecret,
                ],
                'max_redirects' => 0,
                'timeout' => $this->timeout,
            ]);

            $statusCode = $response->getStatusCode();
            $data = 200 === $statusCode ? $response->toArray(false) : [];
        } catch (TransportExceptionInterface) {
            throw new TpayTransportException('Could not reach the Tpay OAuth endpoint.');
        } catch (HttpClientExceptionInterface) {
            throw new TpayTransportException('Tpay OAuth endpoint returned an unreadable response.');
        }

        if ($statusCode >= 500) {
            throw new TpayTransportException(sprintf('Tpay OAuth endpoint is unavailable (HTTP %d).', $statusCode));
        }

        if (200 !== $statusCode) {
            throw new TpayAuthenticationException(sprintf('Tpay OAuth authorization failed (HTTP %d). Check the client ID and secret.', $statusCode));
        }

        $token = $data['access_token'] ?? null;
        if (!is_string($token) || '' === $token) {
            throw new TpayAuthenticationException('Tpay OAuth response did not contain an access token.');
        }

        $tokenType = $data['token_type'] ?? 'Bearer';
        if (!is_string($tokenType) || !in_array($tokenType, ['Bearer', 'bearer'], true)) {
            throw new TpayAuthenticationException('Tpay OAuth response has an unsupported token type.');
        }

        $expiresIn = $data['expires_in'] ?? 0;
        $expiresIn = is_numeric($expiresIn) ? (int) $expiresIn : 0;
        $item->expiresAfter(max(1, $expiresIn - self::EXPIRY_MARGIN_SECONDS));

        return $token;
    }

    private function cacheKey(TpayCredentials $credentials): string
    {
        // The secret is part of the key (hashed), so rotating it invalidates the cached token.
        return 'refberg_tpay_token_' . hash('sha256', implode("\0", [
            $credentials->environment->value,
            $credentials->clientId,
            $credentials->clientSecret,
        ]));
    }
}
