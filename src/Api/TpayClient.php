<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Api;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusTpayPlugin\Api\Exception\TpayApiException;
use Refberg\SyliusTpayPlugin\Api\Exception\TpayTransportException;
use Refberg\SyliusTpayPlugin\Api\Model\TpayRefund;
use Refberg\SyliusTpayPlugin\Api\Model\TpayTransaction;
use Refberg\SyliusTpayPlugin\Api\Model\TransactionCreated;
use Refberg\SyliusTpayPlugin\Config\TpayCredentials;
use stdClass;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function count;
use function is_array;
use function is_int;
use function json_encode;
use function mb_substr;
use function rawurlencode;
use function sprintf;
use function str_starts_with;
use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Minimal Tpay OpenAPI client.
 *
 * Logs only HTTP status codes, Tpay error codes and identifiers. Request bodies, tokens and
 * credentials are never logged nor put into exception messages.
 */
final readonly class TpayClient implements TpayClientInterface
{
    private const RESULT_FAILED = 'failed';

    /** GET /transactions/{id}/refunds is paginated; more refunds per transaction are not expected. */
    private const MAX_REFUND_PAGES = 10;

    public function __construct(
        private HttpClientInterface $httpClient,
        private AccessTokenProviderInterface $accessTokenProvider,
        private LoggerInterface $logger = new NullLogger(),
        private float $timeout = 15.0,
    ) {
    }

    public function createTransaction(TpayCredentials $credentials, array $transaction): TransactionCreated
    {
        [$statusCode, $data] = $this->request($credentials, 'POST', '/transactions', $transaction);

        if (200 !== $statusCode || self::RESULT_FAILED === Json::string($data, 'result')) {
            throw $this->apiError('Creating Tpay transaction failed', $statusCode, $data);
        }

        $transactionId = Json::string($data, 'transactionId');
        $title = Json::string($data, 'title');
        $paymentUrl = Json::string($data, 'transactionPaymentUrl');
        if (null === $transactionId || null === $title || null === $paymentUrl || !str_starts_with($paymentUrl, 'https://')) {
            throw new TpayApiException('Tpay transaction response is missing transactionId, title or transactionPaymentUrl.', $statusCode);
        }

        $this->logger->info('Tpay transaction created.', [
            'tpay_transaction_id' => $transactionId,
            'tpay_title' => $title,
            'hidden_description' => $transaction['hiddenDescription'] ?? null,
        ]);

        return new TransactionCreated($transactionId, $title, $paymentUrl, Json::string($data, 'status') ?? TpayTransaction::STATUS_PENDING);
    }

    public function getTransaction(TpayCredentials $credentials, string $transactionId): TpayTransaction
    {
        [$statusCode, $data] = $this->request($credentials, 'GET', '/transactions/' . rawurlencode($transactionId));

        if (200 !== $statusCode || self::RESULT_FAILED === Json::string($data, 'result')) {
            throw $this->apiError('Retrieving Tpay transaction failed', $statusCode, $data);
        }

        $id = Json::string($data, 'transactionId');
        $title = Json::string($data, 'title');
        $status = Json::string($data, 'status');
        $amount = Json::amount($data, 'amount');
        $currency = Json::string($data, 'currency');
        if (null === $id || null === $title || null === $status || null === $amount || null === $currency) {
            throw new TpayApiException('Tpay transaction response is incomplete.', $statusCode);
        }

        if ($id !== $transactionId) {
            throw new TpayApiException('Tpay transaction response does not contain the requested transaction.', $statusCode);
        }

        return new TpayTransaction(
            transactionId: $id,
            title: $title,
            status: $status,
            amount: $amount,
            currency: $currency,
            hiddenDescription: Json::string($data, 'hiddenDescription'),
        );
    }

    public function cancelTransaction(TpayCredentials $credentials, string $transactionId): void
    {
        [$statusCode, $data] = $this->request($credentials, 'POST', '/transactions/' . rawurlencode($transactionId) . '/cancel', []);

        if (200 !== $statusCode || self::RESULT_FAILED === Json::string($data, 'result')) {
            throw $this->apiError('Canceling Tpay transaction failed', $statusCode, $data);
        }

        $this->logger->info('Tpay transaction canceled.', ['tpay_transaction_id' => $transactionId]);
    }

    public function createRefund(TpayCredentials $credentials, string $transactionId, ?int $amount): array
    {
        // An empty body refunds the whole transaction, which also moves it to the "refund" status.
        $body = null === $amount ? [] : ['amount' => (float) Json::toMajorUnits($amount)];

        [$statusCode, $data] = $this->request($credentials, 'POST', '/transactions/' . rawurlencode($transactionId) . '/refunds', $body);

        if (200 !== $statusCode || self::RESULT_FAILED === Json::string($data, 'result')) {
            throw $this->apiError('Creating Tpay refund failed', $statusCode, $data);
        }

        $refunds = self::refunds($data);

        $this->logger->info('Tpay refund requested.', ['tpay_transaction_id' => $transactionId, 'amount' => $amount, 'refunds' => count($refunds)]);

        return $refunds;
    }

    public function getRefunds(TpayCredentials $credentials, string $transactionId): array
    {
        $refunds = [];
        for ($page = 1; $page <= self::MAX_REFUND_PAGES; ++$page) {
            [$statusCode, $data] = $this->request($credentials, 'GET', sprintf('/transactions/%s/refunds?page=%d', rawurlencode($transactionId), $page));

            if (200 !== $statusCode || self::RESULT_FAILED === Json::string($data, 'result')) {
                throw $this->apiError('Retrieving Tpay refunds failed', $statusCode, $data);
            }

            $pageRefunds = self::refunds($data);
            foreach ($pageRefunds as $refund) {
                $refunds[] = $refund;
            }

            $total = $data['total'] ?? null;
            if ([] === $pageRefunds || !is_int($total) || count($refunds) >= $total) {
                break;
            }
        }

        return $refunds;
    }

    /**
     * Reads all refund lists of a GET/POST /transactions/{id}/refunds response.
     *
     * @param array<array-key, mixed> $data
     *
     * @return list<TpayRefund>
     */
    private static function refunds(array $data): array
    {
        // Refunds waiting for the payer's bank account and card refunds are listed without a status.
        $lists = [
            'refundsList' => null,
            'refundsNoAccountList' => TpayRefund::STATUS_TO_COMPLETE,
            'refundsCardsList' => TpayRefund::STATUS_PENDING,
        ];

        $refunds = [];
        foreach ($lists as $key => $defaultStatus) {
            $items = $data[$key] ?? [];
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $refundId = Json::string($item, 'refundId');
                $status = Json::string($item, 'status') ?? $defaultStatus;
                $amount = Json::amount($item, 'amount');
                $currency = Json::string($item, 'currency');
                if (null === $refundId || null === $status || null === $amount || null === $currency) {
                    continue;
                }

                $refunds[] = new TpayRefund($refundId, $status, $amount, $currency);
            }
        }

        return $refunds;
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array{int, array<array-key, mixed>}
     */
    private function request(TpayCredentials $credentials, string $method, string $path, ?array $json = null, bool $retryOnUnauthorized = true): array
    {
        $token = $this->accessTokenProvider->getToken($credentials);

        $options = [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'max_redirects' => 0,
            'timeout' => $this->timeout,
        ];
        if (null !== $json) {
            $options['headers']['Content-Type'] = 'application/json';
            // An empty body must be "{}", and amounts keep their decimal point ("15.0").
            $options['body'] = json_encode([] === $json ? new stdClass() : $json, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        try {
            $response = $this->httpClient->request($method, $credentials->environment->apiUrl() . $path, $options);
            $statusCode = $response->getStatusCode();

            if (401 === $statusCode && $retryOnUnauthorized) {
                $this->accessTokenProvider->invalidate($credentials);

                return $this->request($credentials, $method, $path, $json, false);
            }

            try {
                $data = $response->toArray(false);
            } catch (DecodingExceptionInterface) {
                $data = [];
            }
        } catch (TransportExceptionInterface) {
            $this->logger->warning('Tpay API is unreachable.', ['method' => $method, 'path' => $path]);

            throw new TpayTransportException('Could not reach the Tpay API.');
        } catch (HttpClientExceptionInterface) {
            throw new TpayTransportException('Tpay API returned an unreadable response.');
        }

        return [$statusCode, $data];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function apiError(string $message, int $statusCode, array $data): TpayApiException
    {
        $errors = $data['errors'] ?? null;
        $error = is_array($errors) && is_array($errors[0] ?? null) ? $errors[0] : [];
        $errorCode = Json::string($error, 'errorCode');
        $errorMessage = Json::string($error, 'errorMessage');

        $this->logger->error($message . '.', [
            'http_status' => $statusCode,
            'tpay_error_code' => $errorCode,
            'tpay_request_id' => Json::string($data, 'requestId'),
        ]);

        return new TpayApiException(
            sprintf(
                '%s (HTTP %d%s%s).',
                $message,
                $statusCode,
                null !== $errorCode ? ', ' . $errorCode : '',
                null !== $errorMessage ? ': ' . mb_substr($errorMessage, 0, 200) : '',
            ),
            $statusCode,
            $errorCode,
        );
    }
}
