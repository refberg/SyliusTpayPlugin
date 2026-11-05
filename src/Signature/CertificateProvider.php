<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Signature;

use Refberg\SyliusTpayPlugin\Api\Exception\TpayTransportException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function hash;
use function sprintf;
use function str_contains;

/**
 * Downloads the notification signing certificates from Tpay and caches them.
 *
 * Only URLs already checked by JwsVerifier (the Tpay "secure" host of the configured environment) are passed in.
 */
final readonly class CertificateProvider implements CertificateProviderInterface
{
    private const CACHE_SECONDS = 86400;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private float $timeout = 15.0,
    ) {
    }

    public function get(string $url): string
    {
        /** @var string $certificate */
        $certificate = $this->cache->get(self::cacheKey($url), function (ItemInterface $item) use ($url): string {
            $item->expiresAfter(self::CACHE_SECONDS);

            return $this->download($url);
        });

        return $certificate;
    }

    public function forget(string $url): void
    {
        $this->cache->delete(self::cacheKey($url));
    }

    private function download(string $url): string
    {
        try {
            $response = $this->httpClient->request('GET', $url, ['max_redirects' => 0, 'timeout' => $this->timeout]);
            $statusCode = $response->getStatusCode();
            $content = 200 === $statusCode ? $response->getContent(false) : '';
        } catch (HttpClientExceptionInterface) {
            throw new TpayTransportException('Could not download the Tpay notification certificate.');
        }

        if (200 !== $statusCode || !str_contains($content, '-----BEGIN CERTIFICATE-----')) {
            throw new TpayTransportException(sprintf('Tpay notification certificate is unavailable (HTTP %d).', $statusCode));
        }

        return $content;
    }

    private static function cacheKey(string $url): string
    {
        return 'refberg_tpay_certificate_' . hash('sha256', $url);
    }
}
