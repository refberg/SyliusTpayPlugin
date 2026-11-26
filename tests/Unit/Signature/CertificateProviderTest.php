<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Unit\Signature;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusTpayPlugin\Api\Exception\TpayTransportException;
use Refberg\SyliusTpayPlugin\Signature\CertificateProvider;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Refberg\SyliusTpayPlugin\Support\FakeTpay;

final class CertificateProviderTest extends TestCase
{
    public function test_certificate_is_cached_until_it_is_forgotten(): void
    {
        $downloads = 0;
        $httpClient = new MockHttpClient(static function () use (&$downloads): MockResponse {
            ++$downloads;

            return new MockResponse(FakeTpay::certificatePem());
        });
        $provider = new CertificateProvider($httpClient, new ArrayAdapter());

        $provider->get(FakeTpay::CERTIFICATE_URL);
        $provider->get(FakeTpay::CERTIFICATE_URL);
        $provider->forget(FakeTpay::CERTIFICATE_URL);

        self::assertSame(FakeTpay::certificatePem(), $provider->get(FakeTpay::CERTIFICATE_URL));
        self::assertSame(2, $downloads);
    }

    public function test_download_disables_redirects_and_sets_the_timeout(): void
    {
        $requestOptions = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requestOptions): MockResponse {
            $requestOptions = $options;

            return new MockResponse(FakeTpay::certificatePem());
        });

        (new CertificateProvider($httpClient, new ArrayAdapter(), 7.5))->get(FakeTpay::CERTIFICATE_URL);

        self::assertSame(0, $requestOptions['max_redirects'] ?? null);
        self::assertSame(7.5, $requestOptions['timeout'] ?? null);
    }

    #[TestWith([503, ''])]
    #[TestWith([200, '<html>Maintenance</html>'])]
    public function test_response_without_a_certificate_is_a_transport_error(int $httpStatus, string $body): void
    {
        $httpClient = new MockHttpClient(new MockResponse($body, ['http_code' => $httpStatus]));

        $this->expectException(TpayTransportException::class);
        $this->expectExceptionMessage('Tpay notification certificate is unavailable (HTTP ' . $httpStatus . ').');

        (new CertificateProvider($httpClient, new ArrayAdapter()))->get(FakeTpay::CERTIFICATE_URL);
    }
}
