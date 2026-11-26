<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Unit\Api;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusTpayPlugin\Api\AccessTokenProvider;
use Refberg\SyliusTpayPlugin\Api\Exception\TpayApiException;
use Refberg\SyliusTpayPlugin\Api\Exception\TpayAuthenticationException;
use Refberg\SyliusTpayPlugin\Api\Exception\TpayTransportException;
use Refberg\SyliusTpayPlugin\Api\Model\TpayRefund;
use Refberg\SyliusTpayPlugin\Api\TpayClient;
use Refberg\SyliusTpayPlugin\Config\Environment;
use Refberg\SyliusTpayPlugin\Config\TpayCredentials;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Tests\Refberg\SyliusTpayPlugin\Support\FakeTpay;
use Tests\Refberg\SyliusTpayPlugin\Support\MemoryLogger;
use function array_column;
use function array_map;
use function count;
use function json_encode;
use function range;
use function serialize;
use function str_contains;
use function strstr;
use function substr;
use const JSON_THROW_ON_ERROR;

final class TpayClientTest extends TestCase
{
    private FakeTpay $tpay;

    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->tpay = new FakeTpay();
        $this->logger = new MemoryLogger();
    }

    public function test_transaction_is_created_with_a_cached_token(): void
    {
        $client = $this->client();

        $created = $client->createTransaction(self::credentials(), self::transaction());
        $client->createTransaction(self::credentials(), self::transaction());

        self::assertSame($this->tpay->transactionIds()[0], $created->transactionId);
        self::assertSame('TR-FAK-0001X', $created->title);
        self::assertStringStartsWith('https://secure.sandbox.tpay.com/', $created->paymentUrl);
        self::assertSame('pending', $created->status);
        self::assertCount(1, $this->tpay->requests('POST', '#^/oauth/auth$#'));
        self::assertSame('{"amount":15.0,"description":"Order 1","hiddenDescription":"1-1-abcdef012345","payer":{"email":"buyer@example.com","name":"Buyer"}}', $this->tpay->requests('POST', '#^/transactions$#')[0]['body']);
    }

    public function test_transaction_is_read_in_minor_units(): void
    {
        $client = $this->client();
        $id = $client->createTransaction(self::credentials(), self::transaction())->transactionId;
        $this->tpay->setTransactionStatus($id, 'correct');

        $transaction = $client->getTransaction(self::credentials(), $id);

        self::assertSame([$id, 'TR-FAK-0001X', 'correct', 1500, 'PLN', '1-1-abcdef012345'], [
            $transaction->transactionId, $transaction->title, $transaction->status, $transaction->amount, $transaction->currency, $transaction->hiddenDescription,
        ]);
    }

    public function test_response_for_another_transaction_is_rejected(): void
    {
        $httpClient = new MockHttpClient([
            self::token(),
            new MockResponse(json_encode(['result' => 'success', 'transactionId' => 'OTHER', 'title' => 'TR-X', 'status' => 'correct', 'amount' => 15, 'currency' => 'PLN'], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(TpayApiException::class);
        $this->client($httpClient)->getTransaction(self::credentials(), 'MINE');
    }

    public function test_incomplete_transaction_is_rejected(): void
    {
        $httpClient = new MockHttpClient([
            self::token(),
            new MockResponse(json_encode(['result' => 'success', 'transactionId' => 'MINE', 'title' => 'TR-X', 'status' => 'correct', 'currency' => 'PLN'], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(TpayApiException::class);
        $this->client($httpClient)->getTransaction(self::credentials(), 'MINE');
    }

    public function test_payment_url_other_than_https_is_rejected(): void
    {
        $httpClient = new MockHttpClient([
            self::token(),
            new MockResponse(json_encode(['result' => 'success', 'transactionId' => 'T', 'title' => 'TR-X', 'transactionPaymentUrl' => 'http://pay.example'], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(TpayApiException::class);
        $this->client($httpClient)->createTransaction(self::credentials(), self::transaction());
    }

    public function test_api_error_carries_status_and_code_but_no_secrets(): void
    {
        $this->tpay->failNextRequest(400, 'invalid_amount');

        try {
            $this->client()->createTransaction(self::credentials(), self::transaction());
            self::fail('Expected an exception.');
        } catch (TpayApiException $exception) {
            self::assertSame(400, $exception->getHttpStatusCode());
            self::assertSame('invalid_amount', $exception->getErrorCode());
            self::assertFalse($exception->isTransient());
            self::assertStringContainsString('HTTP 400, invalid_amount: Simulated failure', $exception->getMessage());
            self::assertStringNotContainsString(FakeTpay::CLIENT_SECRET, $exception->getMessage());
        }

        self::assertStringNotContainsString(FakeTpay::CLIENT_SECRET, serialize($this->logger->records));
        self::assertStringNotContainsString('fake-token', serialize($this->logger->records));
    }

    public function test_server_errors_are_transient_and_401_403_are_authorization_errors(): void
    {
        self::assertTrue((new TpayApiException('x', 500))->isTransient());
        self::assertTrue((new TpayApiException('x', 503))->isTransient());
        self::assertTrue((new TpayApiException('x', 429))->isTransient());
        self::assertTrue((new TpayApiException('x', 408))->isTransient());
        self::assertFalse((new TpayApiException('x', 409))->isTransient());
        self::assertTrue((new TpayApiException('x', 401))->isAuthorizationError());
        self::assertTrue((new TpayApiException('x', 403))->isAuthorizationError());
        self::assertFalse((new TpayApiException('x', 404))->isAuthorizationError());
    }

    public function test_expired_token_is_renewed_once(): void
    {
        $client = $this->client();
        $id = $client->createTransaction(self::credentials(), self::transaction())->transactionId;
        $this->tpay->failNextRequest(401, 'token_expired');

        $client->getTransaction(self::credentials(), $id);

        self::assertCount(2, $this->tpay->requests('POST', '#^/oauth/auth$#'));
    }

    public function test_wrong_client_credentials_are_an_authentication_error(): void
    {
        $credentials = new TpayCredentials(FakeTpay::MERCHANT_ID, FakeTpay::CLIENT_ID, 'wrong', FakeTpay::SECURITY_CODE, Environment::Sandbox);

        $this->expectException(TpayAuthenticationException::class);
        $this->client()->getTransaction($credentials, 'T');
    }

    public function test_unreachable_api_is_a_transport_error(): void
    {
        $httpClient = new MockHttpClient([self::token(), new MockResponse('', ['error' => 'timeout'])]);

        $this->expectException(TpayTransportException::class);
        $this->client($httpClient)->getTransaction(self::credentials(), 'T');
    }

    public function test_oauth_server_error_is_a_transport_error(): void
    {
        $httpClient = new MockHttpClient([new MockResponse('', ['http_code' => 502])]);

        $this->expectException(TpayTransportException::class);
        $this->client($httpClient)->getTransaction(self::credentials(), 'T');
    }

    public function test_full_and_partial_refunds(): void
    {
        $client = $this->client();
        $id = $client->createTransaction(self::credentials(), self::transaction())->transactionId;
        $this->tpay->setTransactionStatus($id, 'correct');

        $partial = $client->createRefund(self::credentials(), $id, 500);
        $all = $client->createRefund(self::credentials(), $id, 1000);

        $bodies = array_column($this->tpay->requests('POST', '#/refunds$#'), 'body');
        self::assertSame(['{"amount":5.0}', '{"amount":10.0}'], $bodies);
        self::assertCount(1, $partial);
        self::assertCount(2, $all);
        self::assertSame([1000, 'PLN', 'new'], [$all[1]->amount, $all[1]->currency, $all[1]->status]);
        self::assertEquals($all, $client->getRefunds(self::credentials(), $id));
    }

    public function test_refund_lists_without_status_get_a_default_one(): void
    {
        $httpClient = new MockHttpClient([
            self::token(),
            new MockResponse(json_encode([
                'result' => 'success',
                'refundsList' => [['refundId' => 'rf_1', 'status' => 'Done', 'amount' => 1.5, 'currency' => 'PLN'], ['refundId' => 'broken']],
                'refundsNoAccountList' => [['refundId' => 'rf_2', 'amount' => '2.00', 'currency' => 'PLN']],
                'refundsCardsList' => [['refundId' => 'rf_3', 'amount' => 3, 'currency' => 'PLN']],
                'total' => 3,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $refunds = $this->client($httpClient)->getRefunds(self::credentials(), 'T');

        self::assertSame([['rf_1', 'done', 150], ['rf_2', 'to_complete', 200], ['rf_3', 'pending', 300]], array_map(
            static fn (TpayRefund $refund): array => [$refund->refundId, $refund->status, $refund->amount],
            $refunds,
        ));
    }

    public function test_cancel_of_a_paid_transaction_fails(): void
    {
        $client = $this->client();
        $id = $client->createTransaction(self::credentials(), self::transaction())->transactionId;
        $client->cancelTransaction(self::credentials(), $id);
        self::assertSame('canceled', $this->tpay->transaction($id)['status']);

        $this->expectException(TpayApiException::class);
        $client->cancelTransaction(self::credentials(), $id);
    }

    public function test_requests_disable_redirects_and_set_timeout_and_accept(): void
    {
        $apiOptions = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$apiOptions): ResponseInterface {
            /** @var array<string, mixed> $options */
            if (str_contains($url, '/transactions')) {
                $apiOptions[] = $options;
            }

            return ($this->tpay)($method, $url, $options);
        });
        $client = new TpayClient($httpClient, new AccessTokenProvider($httpClient, new ArrayAdapter()), $this->logger, 7.5);

        $client->createTransaction(self::credentials(), self::transaction());

        self::assertCount(1, $apiOptions);
        self::assertSame(0, $apiOptions[0]['max_redirects'] ?? null);
        self::assertSame(7.5, $apiOptions[0]['timeout'] ?? null);
        self::assertSame('application/json', $this->tpay->requests('POST', '#^/transactions$#')[0]['headers']['accept'] ?? null);
    }

    /**
     * @return iterable<string, array{Closure(TpayClient, TpayCredentials): void}>
     */
    public static function apiCalls(): iterable
    {
        yield 'create transaction' => [static function (TpayClient $client, TpayCredentials $credentials): void {
            $client->createTransaction($credentials, self::transaction());
        }];
        yield 'get transaction' => [static function (TpayClient $client, TpayCredentials $credentials): void {
            $client->getTransaction($credentials, 'T');
        }];
        yield 'cancel transaction' => [static function (TpayClient $client, TpayCredentials $credentials): void {
            $client->cancelTransaction($credentials, 'T');
        }];
        yield 'create refund' => [static function (TpayClient $client, TpayCredentials $credentials): void {
            $client->createRefund($credentials, 'T', null);
        }];
        yield 'get refunds' => [static function (TpayClient $client, TpayCredentials $credentials): void {
            $client->getRefunds($credentials, 'T');
        }];
    }

    /**
     * @param Closure(TpayClient, TpayCredentials): void $call
     */
    #[DataProvider('apiCalls')]
    public function test_http_200_with_a_failed_result_is_an_api_error(Closure $call): void
    {
        $httpClient = new MockHttpClient([self::token(), new MockResponse(json_encode(['result' => 'failed'], JSON_THROW_ON_ERROR))]);

        $this->expectException(TpayApiException::class);
        $this->expectExceptionMessage(' failed (HTTP 200).');
        $call($this->client($httpClient), self::credentials());
    }

    /**
     * @return iterable<string, array{list<int>, int, list<string>, int}>
     */
    public static function refundPages(): iterable
    {
        yield 'next page while the total is not reached' => [[1, 1], 2, ['?page=1', '?page=2'], 2];
        yield 'empty page ends the list' => [[1, 0], 3, ['?page=1', '?page=2'], 1];
        yield 'at most ten pages' => [[], 100, ['?page=1', '?page=2', '?page=3', '?page=4', '?page=5', '?page=6', '?page=7', '?page=8', '?page=9', '?page=10'], 10];
    }

    /**
     * @param list<int> $pageSizes
     * @param list<string> $expectedQueries
     */
    #[DataProvider('refundPages')]
    public function test_refund_list_is_read_page_by_page(array $pageSizes, int $total, array $expectedQueries, int $expectedRefunds): void
    {
        $queries = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url) use (&$queries, $pageSizes, $total): MockResponse {
            if (str_contains($url, '/oauth/auth')) {
                return self::token();
            }

            $query = (string) strstr($url, '?');
            $queries[] = $query;
            $size = $pageSizes[(int) substr($query, 6) - 1] ?? 1;
            $refunds = array_map(static fn (int $index): array => ['refundId' => 'rf_' . count($queries) . '_' . $index, 'status' => 'done', 'amount' => 1, 'currency' => 'PLN'], $size > 0 ? range(1, $size) : []);

            return new MockResponse(json_encode(['result' => 'success', 'refundsList' => $refunds, 'total' => $total], JSON_THROW_ON_ERROR));
        });

        $refunds = $this->client($httpClient)->getRefunds(self::credentials(), 'T');

        self::assertSame($expectedQueries, $queries);
        self::assertCount($expectedRefunds, $refunds);
    }

    public function test_oauth_request_disables_redirects_and_sets_accept_and_timeout(): void
    {
        $oauthOptions = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$oauthOptions): ResponseInterface {
            /** @var array<string, mixed> $options */
            if (str_contains($url, '/oauth/auth')) {
                $oauthOptions[] = $options;
            }

            return ($this->tpay)($method, $url, $options);
        });

        (new AccessTokenProvider($httpClient, new ArrayAdapter(), 7.5))->getToken(self::credentials());

        self::assertCount(1, $oauthOptions);
        self::assertSame(0, $oauthOptions[0]['max_redirects'] ?? null);
        self::assertSame(7.5, $oauthOptions[0]['timeout'] ?? null);
        self::assertSame('application/json', $this->tpay->requests('POST', '#^/oauth/auth$#')[0]['headers']['accept'] ?? null);
    }

    public function test_tokens_are_cached_per_environment_client_and_secret(): void
    {
        $authorizations = 0;
        $httpClient = new MockHttpClient(static function () use (&$authorizations): MockResponse {
            return new MockResponse(json_encode(['access_token' => 'token-' . ++$authorizations, 'token_type' => 'Bearer', 'expires_in' => 7200], JSON_THROW_ON_ERROR));
        });
        $provider = new AccessTokenProvider($httpClient, new ArrayAdapter());

        self::assertSame('token-1', $provider->getToken(self::credentials()));
        self::assertSame('token-2', $provider->getToken(new TpayCredentials(FakeTpay::MERCHANT_ID, FakeTpay::CLIENT_ID, FakeTpay::CLIENT_SECRET, FakeTpay::SECURITY_CODE, Environment::Production)));
        self::assertSame('token-3', $provider->getToken(new TpayCredentials(FakeTpay::MERCHANT_ID, 'other-client', FakeTpay::CLIENT_SECRET, FakeTpay::SECURITY_CODE, Environment::Sandbox)));
        self::assertSame('token-4', $provider->getToken(new TpayCredentials(FakeTpay::MERCHANT_ID, FakeTpay::CLIENT_ID, 'rotated-secret', FakeTpay::SECURITY_CODE, Environment::Sandbox)));
        self::assertSame('token-1', $provider->getToken(self::credentials()));
        self::assertSame(4, $authorizations);
    }

    public function test_token_expires_60_seconds_before_expires_in(): void
    {
        $this->assertTokenExpiresAfter(['access_token' => 'token', 'expires_in' => 300], 240);
    }

    public function test_token_without_expires_in_expires_after_one_second(): void
    {
        $this->assertTokenExpiresAfter(['access_token' => 'token'], 1);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidTokenResponses(): iterable
    {
        yield 'missing token' => [['token_type' => 'Bearer'], 'Tpay OAuth response did not contain an access token.'];
        yield 'non-string token' => [['access_token' => 123], 'Tpay OAuth response did not contain an access token.'];
        yield 'unsupported token type' => [['access_token' => 'token', 'token_type' => 'MAC'], 'Tpay OAuth response has an unsupported token type.'];
        yield 'non-string token type' => [['access_token' => 'token', 'token_type' => 1], 'Tpay OAuth response has an unsupported token type.'];
    }

    /**
     * @param array<string, mixed> $oauthResponse
     */
    #[DataProvider('invalidTokenResponses')]
    public function test_oauth_response_without_a_usable_bearer_token_is_an_authentication_error(array $oauthResponse, string $message): void
    {
        $httpClient = new MockHttpClient(new MockResponse(json_encode($oauthResponse, JSON_THROW_ON_ERROR)));

        $this->expectException(TpayAuthenticationException::class);
        $this->expectExceptionMessage($message);

        (new AccessTokenProvider($httpClient, new ArrayAdapter()))->getToken(self::credentials());
    }

    private function client(?MockHttpClient $httpClient = null): TpayClient
    {
        $httpClient ??= new MockHttpClient($this->tpay);

        return new TpayClient($httpClient, new AccessTokenProvider($httpClient, new ArrayAdapter()), $this->logger);
    }

    /**
     * @param array<string, mixed> $oauthResponse
     */
    private function assertTokenExpiresAfter(array $oauthResponse, int $expectedSeconds): void
    {
        $item = $this->createMock(ItemInterface::class);
        $item->expects(self::once())->method('expiresAfter')->with($expectedSeconds);
        $cache = self::createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(static fn (string $key, callable $callback): mixed => $callback($item));

        $token = (new AccessTokenProvider(new MockHttpClient(new MockResponse(json_encode($oauthResponse, JSON_THROW_ON_ERROR))), $cache))->getToken(self::credentials());

        self::assertSame('token', $token);
    }

    private static function token(): MockResponse
    {
        return new MockResponse(json_encode(['access_token' => 'token', 'token_type' => 'Bearer', 'expires_in' => 7200], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private static function transaction(): array
    {
        return [
            'amount' => 15.0,
            'description' => 'Order 1',
            'hiddenDescription' => '1-1-abcdef012345',
            'payer' => ['email' => 'buyer@example.com', 'name' => 'Buyer'],
        ];
    }

    private static function credentials(): TpayCredentials
    {
        return new TpayCredentials(FakeTpay::MERCHANT_ID, FakeTpay::CLIENT_ID, FakeTpay::CLIENT_SECRET, FakeTpay::SECURITY_CODE, Environment::Sandbox);
    }
}
