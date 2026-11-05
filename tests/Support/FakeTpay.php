<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Support;

use LogicException;
use OpenSSLAsymmetricKey;
use OutOfBoundsException;
use Refberg\SyliusTpayPlugin\Api\Json;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;
use function array_filter;
use function array_key_last;
use function array_keys;
use function array_map;
use function array_merge;
use function array_shift;
use function array_values;
use function base64_encode;
use function count;
use function explode;
use function http_build_query;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function md5;
use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkey_new;
use function openssl_sign;
use function openssl_x509_export;
use function parse_url;
use function preg_match;
use function rawurldecode;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_pad;
use function str_starts_with;
use function strtolower;
use function strtr;
use function substr;
use function trim;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const OPENSSL_ALGO_SHA256;
use const OPENSSL_KEYTYPE_RSA;
use const STR_PAD_LEFT;

/**
 * In-memory imitation of the Tpay sandbox (OpenAPI and the certificate host), used as MockHttpClient callback.
 *
 * It signs notifications like Tpay: md5sum with the security code and a detached JWS whose certificate
 * is issued by a root CA generated for the test run. Any request to another host throws, so tests can never reach production.
 */
final class FakeTpay
{
    public const API_HOST = 'openapi.sandbox.tpay.com';

    public const SECURE_HOST = 'secure.sandbox.tpay.com';

    public const MERCHANT_ID = '1010';

    public const CLIENT_ID = '01JFAKECLIENT0000000000000-01JFAKE';

    public const CLIENT_SECRET = 'test-client-secret';

    public const SECURITY_CODE = 'test-security-code';

    public const CERTIFICATE_URL = 'https://' . self::SECURE_HOST . '/x509/notifications-jws.pem';

    public const ROOT_CERTIFICATE_URL = 'https://' . self::SECURE_HOST . '/x509/tpay-jws-root.pem';

    /** @var array{root: string, certificate: string, key: OpenSSLAsymmetricKey}|null */
    private static ?array $pki = null;

    /** @var array<string, array<string, mixed>> */
    private array $transactions = [];

    /** @var array<string, array<string, mixed>> refundId => refund (with "transactionId") */
    private array $refunds = [];

    /** @var list<array{method: string, path: string, body: string, headers: array<string, string>}> */
    private array $requests = [];

    /** @var list<array{int, array<string, mixed>}> */
    private array $queuedFailures = [];

    /** @var array<string, true> */
    private array $validTokens = [];

    private int $tokenCounter = 0;

    private int $counter = 0;

    private bool $certificatesAvailable = true;

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): ResponseInterface
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $path = is_array($parts) ? ($parts['path'] ?? '/') : '/';
        $query = is_array($parts) ? ($parts['query'] ?? '') : '';

        if (self::SECURE_HOST === $host) {
            return $this->certificate($path);
        }

        if (self::API_HOST !== $host) {
            throw new LogicException(sprintf('Tests must not call "%s". Only the Tpay sandbox fake is available.', $host));
        }

        $body = is_string($options['body'] ?? null) ? $options['body'] : '';
        $headers = [];
        foreach ((array) ($options['headers'] ?? []) as $header) {
            if (is_string($header) && str_contains($header, ':')) {
                [$name, $value] = explode(':', $header, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        $this->requests[] = ['method' => $method, 'path' => $path . ('' !== $query ? '?' . $query : ''), 'body' => $body, 'headers' => $headers];

        if ('POST' === $method && '/oauth/auth' === $path) {
            return $this->authorize($body);
        }

        $authorization = $headers['authorization'] ?? '';
        if (!str_starts_with($authorization, 'Bearer ') || !isset($this->validTokens[substr($authorization, 7)])) {
            return self::json(401, ['result' => 'failed', 'errors' => [['errorCode' => 'unauthorized']]]);
        }

        if ([] !== $this->queuedFailures) {
            [$status, $data] = array_shift($this->queuedFailures);

            return self::json($status, $data);
        }

        if ('POST' === $method && '/transactions' === $path) {
            return $this->createTransaction($body);
        }

        if (1 === preg_match('#^/transactions/([^/]+)/refunds$#', $path, $matches)) {
            $transactionId = rawurldecode($matches[1]);

            return 'POST' === $method ? $this->createRefund($transactionId, $body) : $this->listRefunds($transactionId);
        }

        if (1 === preg_match('#^/transactions/([^/]+)/cancel$#', $path, $matches) && 'POST' === $method) {
            return $this->cancelTransaction(rawurldecode($matches[1]));
        }

        if (1 === preg_match('#^/transactions/([^/]+)$#', $path, $matches) && 'GET' === $method) {
            return $this->getTransaction(rawurldecode($matches[1]));
        }

        return self::json(404, ['result' => 'failed', 'errors' => [['errorCode' => 'not_found']]]);
    }

    public function setTransactionStatus(string $transactionId, string $status): void
    {
        $this->transaction($transactionId);
        $this->transactions[$transactionId]['status'] = $status;
    }

    /**
     * Changes what Tpay reports for the transaction, to simulate tampering or a mismatch.
     */
    public function overrideTransaction(string $transactionId, string $field, mixed $value): void
    {
        $this->transaction($transactionId);
        $this->transactions[$transactionId][$field] = $value;
    }

    /**
     * Simulates a refund created by the merchant in the Tpay panel, outside Sylius.
     */
    public function createPanelRefund(string $transactionId, ?int $amount = null, string $status = 'done'): string
    {
        $transaction = $this->transaction($transactionId);
        $full = null === $amount;
        $refundId = $this->addRefund($transactionId, $amount ?? self::minor($transaction['amount']), $status);
        if ($full) {
            $this->transactions[$transactionId]['status'] = 'refund';
        }

        return $refundId;
    }

    public function setRefundStatus(string $refundId, string $status): void
    {
        if (!isset($this->refunds[$refundId])) {
            throw new OutOfBoundsException(sprintf('Unknown fake Tpay refund "%s".', $refundId));
        }

        $this->refunds[$refundId]['status'] = $status;
    }

    public function failNextRequest(int $httpStatus, string $errorCode = 'internal_error'): void
    {
        $this->queuedFailures[] = [$httpStatus, ['result' => 'failed', 'requestId' => 'fake', 'errors' => [['errorCode' => $errorCode, 'errorMessage' => 'Simulated failure']]]];
    }

    public function makeCertificatesUnavailable(): void
    {
        $this->certificatesAvailable = false;
    }

    /**
     * @return array<string, mixed>
     */
    public function transaction(string $transactionId): array
    {
        return $this->transactions[$transactionId] ?? throw new OutOfBoundsException(sprintf('Unknown fake Tpay transaction "%s".', $transactionId));
    }

    public function lastTransactionId(): string
    {
        $last = array_key_last($this->transactions);

        return null !== $last ? $last : throw new LogicException('No Tpay transaction has been created.');
    }

    /**
     * @return list<string>
     */
    public function transactionIds(): array
    {
        return array_keys($this->transactions);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function refunds(?string $transactionId = null): array
    {
        return array_values(array_filter(
            $this->refunds,
            static fn (array $refund): bool => null === $transactionId || $refund['transactionId'] === $transactionId,
        ));
    }

    /**
     * @return list<array{method: string, path: string, body: string, headers: array<string, string>}>
     */
    public function requests(?string $method = null, ?string $pathPattern = null): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $request): bool => (null === $method || $request['method'] === $method) &&
                (null === $pathPattern || 1 === preg_match($pathPattern, $request['path'])),
        ));
    }

    /**
     * Builds a transaction notification body (application/x-www-form-urlencoded) as Tpay sends it.
     *
     * @param array<string, string> $override
     */
    public function notificationBody(string $transactionId, string $status = 'TRUE', array $override = [], string $securityCode = self::SECURITY_CODE): string
    {
        $transaction = $this->transaction($transactionId);
        $fields = array_merge([
            'id' => self::MERCHANT_ID,
            'tr_id' => self::str($transaction['title']),
            'tr_date' => '2026-01-01 12:00:00',
            'tr_crc' => self::str($transaction['hiddenDescription']),
            'tr_amount' => self::major(self::minor($transaction['amount'])),
            'tr_paid' => self::major(self::minor($transaction['amount'])),
            'tr_desc' => self::str($transaction['description']),
            'tr_status' => $status,
            'tr_error' => 'none',
            'tr_email' => 'buyer@example.com',
            'test_mode' => '1',
        ], $override);
        $fields['md5sum'] = $override['md5sum'] ?? md5($fields['id'] . $fields['tr_id'] . $fields['tr_amount'] . $fields['tr_crc'] . $securityCode);

        return http_build_query($fields);
    }

    /**
     * Signs a body like Tpay: a detached RS256 JWS with the test certificate in "x5u".
     */
    public static function jws(string $body, ?OpenSSLAsymmetricKey $key = null, string $certificateUrl = self::CERTIFICATE_URL): string
    {
        $header = self::base64Url(json_encode(['alg' => 'RS256', 'x5u' => $certificateUrl], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        openssl_sign($header . '.' . self::base64Url($body), $signature, $key ?? self::pki()['key'], OPENSSL_ALGO_SHA256);

        return $header . '..' . self::base64Url(self::str($signature));
    }

    /**
     * A key that is not certified by the test root CA.
     */
    public static function foreignKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (false === $key) {
            throw new LogicException('Could not generate a key.');
        }

        return $key;
    }

    public static function certificatePem(): string
    {
        return self::pki()['certificate'];
    }

    public static function rootCertificatePem(): string
    {
        return self::pki()['root'];
    }

    /**
     * @return array{root: string, certificate: string, key: OpenSSLAsymmetricKey}
     */
    private static function pki(): array
    {
        if (null !== self::$pki) {
            return self::$pki;
        }

        $rootKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (false === $rootKey || false === $key) {
            throw new LogicException('Could not generate test keys.');
        }

        $rootCsr = openssl_csr_new(['commonName' => 'Fake Tpay JWS Root CA'], $rootKey);
        $csr = openssl_csr_new(['commonName' => 'Fake Tpay notifications'], $key);
        if (false === $rootCsr || true === $rootCsr || false === $csr || true === $csr) {
            throw new LogicException('Could not create test certificate requests.');
        }

        $root = openssl_csr_sign($rootCsr, null, $rootKey, 30, ['x509_extensions' => 'v3_ca'], 1);
        if (false === $root) {
            throw new LogicException('Could not sign the test root certificate.');
        }
        $certificate = openssl_csr_sign($csr, $root, $rootKey, 30, [], 2);
        if (false === $certificate) {
            throw new LogicException('Could not sign the test certificate.');
        }

        openssl_x509_export($root, $rootPem);
        openssl_x509_export($certificate, $certificatePem);

        return self::$pki = ['root' => self::str($rootPem), 'certificate' => self::str($certificatePem), 'key' => $key];
    }

    private function certificate(string $path): ResponseInterface
    {
        if (!$this->certificatesAvailable) {
            return new MockResponse('', ['http_code' => 503]);
        }

        return match ($path) {
            '/x509/notifications-jws.pem' => new MockResponse(self::certificatePem(), ['http_code' => 200]),
            '/x509/tpay-jws-root.pem' => new MockResponse(self::rootCertificatePem(), ['http_code' => 200]),
            default => new MockResponse('', ['http_code' => 404]),
        };
    }

    private function authorize(string $body): ResponseInterface
    {
        $data = '' !== $body ? json_decode($body, true, 8, JSON_THROW_ON_ERROR) : [];
        if (
            !is_array($data) ||
            ($data['client_id'] ?? null) !== self::CLIENT_ID ||
            ($data['client_secret'] ?? null) !== self::CLIENT_SECRET
        ) {
            return self::json(401, ['result' => 'failed', 'errors' => [['errorCode' => 'invalid_client']]]);
        }

        $token = sprintf('fake-token-%d', ++$this->tokenCounter);
        $this->validTokens[$token] = true;

        return self::json(200, ['issued_at' => 1700000000, 'scope' => 'read', 'token_type' => 'Bearer', 'expires_in' => 7200, 'client_id' => self::CLIENT_ID, 'access_token' => $token]);
    }

    private function createTransaction(string $body): ResponseInterface
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);

        foreach (['amount', 'description', 'payer'] as $field) {
            if (!isset($data[$field])) {
                return self::json(400, ['result' => 'failed', 'errors' => [['errorCode' => 'field_required', 'fieldName' => $field]]]);
            }
        }
        if (!is_float($data['amount']) && !is_int($data['amount'])) {
            return self::json(400, ['result' => 'failed', 'errors' => [['errorCode' => 'invalid_type', 'fieldName' => 'amount']]]);
        }
        if (($data['currency'] ?? 'PLN') !== 'PLN') {
            return self::json(400, ['result' => 'failed', 'errors' => [['errorCode' => 'invalid_currency', 'fieldName' => 'currency']]]);
        }

        $number = ++$this->counter;
        $transactionId = sprintf('01JFAKETRANSACTION%08d', $number);
        $title = sprintf('TR-FAK-%04dX', $number);
        $this->transactions[$transactionId] = [
            'transactionId' => $transactionId,
            'title' => $title,
            'posId' => 'ps_FakePos00000000',
            'status' => 'pending',
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'PLN',
            'description' => $data['description'],
            'hiddenDescription' => $data['hiddenDescription'] ?? '',
            'request' => $data,
        ];

        return self::json(200, array_merge($this->publicTransaction($transactionId), [
            'transactionPaymentUrl' => 'https://' . self::SECURE_HOST . '/?title=' . $title . '&uid=fake',
            'lang' => $data['lang'] ?? 'pl',
            'payer' => $data['payer'],
        ]));
    }

    private function getTransaction(string $transactionId): ResponseInterface
    {
        if (!isset($this->transactions[$transactionId])) {
            return self::json(404, ['result' => 'failed', 'errors' => [['errorCode' => 'transaction_not_found']]]);
        }

        return self::json(200, $this->publicTransaction($transactionId));
    }

    private function cancelTransaction(string $transactionId): ResponseInterface
    {
        if (!isset($this->transactions[$transactionId])) {
            return self::json(404, ['result' => 'failed', 'errors' => [['errorCode' => 'transaction_not_found']]]);
        }

        if ('pending' !== $this->transactions[$transactionId]['status']) {
            return self::json(409, ['result' => 'failed', 'errors' => [['errorCode' => 'transaction_not_pending', 'errorMessage' => 'Transaction is not pending']]]);
        }

        $this->transactions[$transactionId]['status'] = 'canceled';

        return self::json(200, ['result' => 'success', 'requestId' => 'fake']);
    }

    private function createRefund(string $transactionId, string $body): ResponseInterface
    {
        $transaction = $this->transactions[$transactionId] ?? null;
        if (null === $transaction || !in_array($transaction['status'], ['paid', 'correct'], true)) {
            return self::json(400, ['result' => 'failed', 'errors' => [['errorCode' => 'refund_not_allowed']]]);
        }

        $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            return self::json(400, ['result' => 'failed', 'errors' => [['errorCode' => 'invalid_body']]]);
        }

        $transactionAmount = self::minor($transaction['amount']);
        $refunded = 0;
        foreach ($this->refunds($transactionId) as $refund) {
            if ('cancel' !== $refund['status']) {
                $refunded += self::minor($refund['amount']);
            }
        }

        $amount = isset($data['amount']) ? Json::toMinorUnits($data['amount']) : $transactionAmount;
        if (null === $amount || $amount <= 0 || $amount > $transactionAmount - $refunded) {
            return self::json(400, ['result' => 'failed', 'errors' => [['errorCode' => 'refund_amount_exceeded']]]);
        }

        $this->addRefund($transactionId, $amount, 'new');
        if (!isset($data['amount'])) {
            $this->transactions[$transactionId]['status'] = 'refund';
        }

        return $this->listRefunds($transactionId);
    }

    private function listRefunds(string $transactionId): ResponseInterface
    {
        if (!isset($this->transactions[$transactionId])) {
            return self::json(404, ['result' => 'failed', 'errors' => [['errorCode' => 'transaction_not_found']]]);
        }

        $list = array_map(static fn (array $refund): array => [
            'refundId' => $refund['refundId'],
            'status' => $refund['status'],
            'amount' => $refund['amount'],
            'currency' => $refund['currency'],
            'date' => ['schedule' => '2026.01.01 12:00:00'],
        ], $this->refunds($transactionId));

        return self::json(200, array_merge($this->publicTransaction($transactionId), [
            'refundsList' => $list,
            'refundsNoAccountList' => [],
            'refundsCardsList' => [],
            'page' => 1,
            'limit' => 35,
            'total' => count($list),
        ]));
    }

    private function addRefund(string $transactionId, int $amount, string $status): string
    {
        $transaction = $this->transaction($transactionId);
        $refundId = sprintf('rf_Fake%012d', count($this->refunds) + 1);
        $this->refunds[$refundId] = [
            'refundId' => $refundId,
            'transactionId' => $transactionId,
            'status' => $status,
            'amount' => (float) self::major($amount),
            'currency' => $transaction['currency'],
        ];

        return $refundId;
    }

    /**
     * @return array<string, mixed>
     */
    private function publicTransaction(string $transactionId): array
    {
        $transaction = $this->transactions[$transactionId];

        return [
            'result' => 'success',
            'requestId' => 'fake',
            'transactionId' => $transaction['transactionId'],
            'title' => $transaction['title'],
            'posId' => $transaction['posId'],
            'status' => $transaction['status'],
            'amount' => $transaction['amount'],
            'currency' => $transaction['currency'],
            'description' => $transaction['description'],
            'hiddenDescription' => $transaction['hiddenDescription'],
            'date' => ['creation' => '2026-01-01 12:00:00'],
            'payments' => ['status' => 'pending', 'method' => null, 'amountPaid' => 0, 'date' => []],
            'lock' => [],
        ];
    }

    private static function minor(mixed $amount): int
    {
        return Json::toMinorUnits($amount) ?? throw new LogicException('Invalid fake amount.');
    }

    private static function major(int $amount): string
    {
        $digits = str_pad((string) $amount, 3, '0', STR_PAD_LEFT);

        return substr($digits, 0, -2) . '.' . substr($digits, -2);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(int $status, array $data): MockResponse
    {
        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['Content-Type' => 'application/json'],
        ]);
    }
}
