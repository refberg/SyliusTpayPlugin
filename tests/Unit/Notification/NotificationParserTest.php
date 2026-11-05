<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Unit\Notification;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusTpayPlugin\Config\Environment;
use Refberg\SyliusTpayPlugin\Config\TpayCredentials;
use Refberg\SyliusTpayPlugin\Notification\Notification;
use Refberg\SyliusTpayPlugin\Notification\NotificationParser;
use Refberg\SyliusTpayPlugin\Signature\CertificateProvider;
use Refberg\SyliusTpayPlugin\Signature\ChecksumVerifier;
use Refberg\SyliusTpayPlugin\Signature\JwsVerifier;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Tests\Refberg\SyliusTpayPlugin\Support\FakeTpay;
use function http_build_query;
use function md5;

final class NotificationParserTest extends TestCase
{
    #[TestWith(['TRUE', Notification::TYPE_PAYMENT])]
    #[TestWith(['true', Notification::TYPE_PAYMENT])]
    #[TestWith(['PAID', Notification::TYPE_PAYMENT])]
    #[TestWith(['CHARGEBACK', Notification::TYPE_REFUND])]
    public function test_signed_notification_is_parsed(string $status, string $type): void
    {
        $body = self::body(['tr_status' => $status]);

        $notification = self::parser()->parse($body, ['x-jws-signature' => [FakeTpay::jws($body)]], self::credentials());

        self::assertInstanceOf(Notification::class, $notification);
        self::assertSame($type, $notification->type);
        self::assertSame(FakeTpay::MERCHANT_ID, $notification->merchantId);
        self::assertSame('TR-ABC-123X', $notification->title);
        self::assertSame('000000001-1-abcdef012345', $notification->crc);
        self::assertSame(1500, $notification->amount);
    }

    public function test_stored_payload_is_parsed_like_the_request(): void
    {
        $body = self::body();
        $payload = ['http_request' => ['content' => $body, 'headers' => ['x-jws-signature' => [FakeTpay::jws($body)]]]];

        self::assertInstanceOf(Notification::class, self::parser()->parseStoredPayload($payload, self::credentials()));
    }

    #[TestWith([null])]
    #[TestWith([[]])]
    #[TestWith([['http_request' => 'x']])]
    #[TestWith([['http_request' => ['content' => ['x']]]])]
    #[TestWith([['http_request' => ['content' => 'x', 'headers' => 'x']]])]
    public function test_malformed_stored_payload_is_rejected(mixed $payload): void
    {
        self::assertNull(self::parser()->parseStoredPayload($payload, self::credentials()));
    }

    public function test_missing_jws_is_rejected(): void
    {
        self::assertNull(self::parser()->parse(self::body(), [], self::credentials()));
    }

    public function test_wrong_checksum_is_rejected(): void
    {
        $body = self::body(['md5sum' => md5('other')]);

        self::assertNull(self::parser()->parse($body, ['x-jws-signature' => [FakeTpay::jws($body)]], self::credentials()));
    }

    public function test_notification_of_another_merchant_is_rejected(): void
    {
        $body = self::body(['id' => '2020']);

        self::assertNull(self::parser()->parse($body, ['x-jws-signature' => [FakeTpay::jws($body)]], self::credentials()));
    }

    /**
     * @param array<string, string> $override
     */
    #[TestWith([['tr_status' => 'FALSE']])]
    #[TestWith([['tr_status' => '']])]
    #[TestWith([['tr_crc' => '']])]
    public function test_unknown_status_or_missing_crc_is_rejected(array $override): void
    {
        $body = self::body($override);

        self::assertNull(self::parser()->parse($body, ['x-jws-signature' => [FakeTpay::jws($body)]], self::credentials()));
    }

    public function test_empty_body_is_rejected(): void
    {
        self::assertNull(self::parser()->parse('', [], self::credentials()));
    }

    /**
     * @param array<string, string> $override
     */
    private static function body(array $override = []): string
    {
        $fields = $override + [
            'id' => FakeTpay::MERCHANT_ID,
            'tr_id' => 'TR-ABC-123X',
            'tr_date' => '2026-01-01 12:00:00',
            'tr_crc' => '000000001-1-abcdef012345',
            'tr_amount' => '15.00',
            'tr_paid' => '15.00',
            'tr_desc' => 'Order 000000001',
            'tr_status' => 'TRUE',
            'tr_error' => 'none',
            'tr_email' => 'buyer@example.com',
        ];
        $fields['md5sum'] ??= md5($fields['id'] . $fields['tr_id'] . $fields['tr_amount'] . $fields['tr_crc'] . FakeTpay::SECURITY_CODE);

        return http_build_query($fields);
    }

    private static function parser(): NotificationParser
    {
        return new NotificationParser(
            new ChecksumVerifier(),
            new JwsVerifier(new CertificateProvider(new MockHttpClient(new FakeTpay()), new ArrayAdapter())),
        );
    }

    private static function credentials(): TpayCredentials
    {
        return new TpayCredentials(FakeTpay::MERCHANT_ID, FakeTpay::CLIENT_ID, FakeTpay::CLIENT_SECRET, FakeTpay::SECURITY_CODE, Environment::Sandbox);
    }
}
