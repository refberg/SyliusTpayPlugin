<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Unit\Signature;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusTpayPlugin\Api\Exception\TpayTransportException;
use Refberg\SyliusTpayPlugin\Config\Environment;
use Refberg\SyliusTpayPlugin\Signature\CertificateProvider;
use Refberg\SyliusTpayPlugin\Signature\CertificateProviderInterface;
use Refberg\SyliusTpayPlugin\Signature\JwsVerifier;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Tests\Refberg\SyliusTpayPlugin\Support\FakeTpay;
use function base64_encode;
use function explode;
use function json_encode;
use function rtrim;
use function strtr;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

final class JwsVerifierTest extends TestCase
{
    private const BODY = 'id=1010&tr_id=TR-ABC-123X&tr_status=TRUE';

    public function test_signature_of_the_body_by_the_tpay_certificate_is_accepted(): void
    {
        self::assertTrue($this->verifier()->verify(self::BODY, FakeTpay::jws(self::BODY), Environment::Sandbox));
    }

    public function test_attached_payload_matching_the_body_is_accepted(): void
    {
        [$header, , $signature] = explode('.', FakeTpay::jws(self::BODY));

        self::assertTrue($this->verifier()->verify(self::BODY, $header . '.' . self::base64Url(self::BODY) . '.' . $signature, Environment::Sandbox));
    }

    public function test_signature_of_another_body_is_rejected(): void
    {
        self::assertFalse($this->verifier()->verify(self::BODY . '&x=1', FakeTpay::jws(self::BODY), Environment::Sandbox));
    }

    public function test_attached_payload_of_another_body_is_rejected(): void
    {
        [$header, , $signature] = explode('.', FakeTpay::jws(self::BODY));

        self::assertFalse($this->verifier()->verify(self::BODY, $header . '.' . self::base64Url('other') . '.' . $signature, Environment::Sandbox));
    }

    public function test_key_not_certified_by_the_tpay_root_is_rejected(): void
    {
        self::assertFalse($this->verifier()->verify(self::BODY, FakeTpay::jws(self::BODY, FakeTpay::foreignKey()), Environment::Sandbox));
    }

    public function test_certificate_of_the_other_environment_is_rejected(): void
    {
        self::assertFalse($this->verifier()->verify(self::BODY, FakeTpay::jws(self::BODY), Environment::Production));
    }

    #[TestWith(['https://attacker.example/x509/notifications-jws.pem'])]
    #[TestWith(['https://secure.sandbox.tpay.com.attacker.example/x509/notifications-jws.pem'])]
    #[TestWith(['https://secure.sandbox.tpay.com@attacker.example/x509/notifications-jws.pem'])]
    #[TestWith(['http://secure.sandbox.tpay.com/x509/notifications-jws.pem'])]
    #[TestWith(['https://secure.sandbox.tpay.com:8443/x509/notifications-jws.pem'])]
    #[TestWith(['https://secure.sandbox.tpay.com/x509/../uploads/key.pem'])]
    #[TestWith(['https://secure.sandbox.tpay.com/other/notifications-jws.pem'])]
    #[TestWith(['https://secure-sandbox.tpay.com/x509/notifications-jws.pem'])]
    public function test_certificate_url_outside_the_tpay_certificate_path_is_never_downloaded(string $url): void
    {
        $certificates = $this->createMock(CertificateProviderInterface::class);
        $certificates->expects(self::never())->method('get');

        self::assertFalse((new JwsVerifier($certificates))->verify(self::BODY, FakeTpay::jws(self::BODY, certificateUrl: $url), Environment::Sandbox));
    }

    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith(['abc'])]
    #[TestWith(['a.b'])]
    #[TestWith(['..c'])]
    #[TestWith(['a..'])]
    #[TestWith(['!!!..!!!'])]
    public function test_malformed_header_is_rejected(?string $jws): void
    {
        self::assertFalse($this->verifier()->verify(self::BODY, $jws, Environment::Sandbox));
    }

    #[TestWith(['HS256'])]
    #[TestWith(['none'])]
    public function test_other_algorithms_are_rejected(string $algorithm): void
    {
        [, , $signature] = explode('.', FakeTpay::jws(self::BODY));
        $header = self::base64Url(json_encode(['alg' => $algorithm, 'x5u' => FakeTpay::CERTIFICATE_URL], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        self::assertFalse($this->verifier()->verify(self::BODY, $header . '..' . $signature, Environment::Sandbox));
    }

    public function test_empty_body_is_rejected(): void
    {
        self::assertFalse($this->verifier()->verify('', FakeTpay::jws(''), Environment::Sandbox));
    }

    public function test_unavailable_certificate_is_a_transport_error(): void
    {
        $tpay = new FakeTpay();
        $tpay->makeCertificatesUnavailable();
        $verifier = new JwsVerifier(new CertificateProvider(new MockHttpClient($tpay), new ArrayAdapter()));

        $this->expectException(TpayTransportException::class);
        $verifier->verify(self::BODY, FakeTpay::jws(self::BODY), Environment::Sandbox);
    }

    public function test_header_is_found_case_insensitively(): void
    {
        self::assertSame('jws', $this->verifier()->extractHeader(['X-Jws-Signature' => ['jws']]));
        self::assertSame('jws', $this->verifier()->extractHeader(['x-jws-signature' => 'jws']));
        self::assertNull($this->verifier()->extractHeader(['x-other' => ['jws']]));
        self::assertNull($this->verifier()->extractHeader(['x-jws-signature' => [['nested']]]));
    }

    private function verifier(): JwsVerifier
    {
        return new JwsVerifier(new CertificateProvider(new MockHttpClient(new FakeTpay()), new ArrayAdapter()));
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
