<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Support;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP client used by the plugin in tests.
 *
 * By default every request goes to FakeTpay. Only with TPAY_SANDBOX=1 (manual sandbox tests)
 * a real client is used, restricted to the Tpay sandbox hosts: production is never reachable from tests.
 */
final class TestHttpClientFactory
{
    public static function create(FakeTpay $fakeTpay): HttpClientInterface
    {
        if (!SandboxEnvironment::isEnabled()) {
            return new MockHttpClient($fakeTpay, 'https://' . FakeTpay::API_HOST);
        }

        return new SandboxOnlyHttpClient(HttpClient::create());
    }
}
