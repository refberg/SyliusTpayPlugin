<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Integration;

use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\Attributes\TestWith;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Tests\Refberg\SyliusTpayPlugin\Support\FakeTpay;
use function sprintf;

final class AdminGatewayConfigurationTest extends IntegrationTestCase
{
    private const FIELD = 'sylius_admin_payment_method[gatewayConfig][config][%s]';

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->factory('admin_user')->createNew();
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        $admin->setUsername('admin');
        $admin->setEmail('admin@example.com');
        $admin->setPlainPassword('admin');
        $admin->setLocaleCode('en_US');
        $admin->setEnabled(true);
        $this->em()->persist($admin);
        $this->em()->flush();

        $this->client->loginUser($admin, 'admin');
    }

    public function test_payment_method_list_offers_the_tpay_gateway(): void
    {
        $crawler = $this->client->request('GET', '/admin/payment-methods/');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filterXPath('//a[contains(@href, "/admin/payment-methods/new/refberg_tpay")]'));
    }

    public function test_new_tpay_method_form_contains_the_gateway_configuration(): void
    {
        $crawler = $this->client->request('GET', '/admin/payment-methods/new/refberg_tpay');

        self::assertResponseIsSuccessful();
        foreach (['environment', 'merchant_id', 'client_id', 'client_secret', 'security_code'] as $field) {
            self::assertCount(1, $crawler->filterXPath(sprintf('//*[@name="%s"]', sprintf(self::FIELD, $field))), $field);
        }
    }

    public function test_edit_form_does_not_render_stored_secrets(): void
    {
        $this->createStoredMethod();

        $crawler = $this->editPage();

        self::assertSame(FakeTpay::MERCHANT_ID, $crawler->filterXPath(sprintf('//input[@name="%s"]', sprintf(self::FIELD, 'merchant_id')))->attr('value'));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(FakeTpay::SECURITY_CODE, $html);
        self::assertStringNotContainsString(FakeTpay::CLIENT_SECRET, $html);
    }

    public function test_saving_with_empty_secret_fields_keeps_the_stored_encrypted_secrets(): void
    {
        $this->createStoredMethod();

        $this->submitEditForm(['merchant_id' => '145227', 'client_secret' => '', 'security_code' => '']);

        self::assertResponseRedirects();
        $config = $this->storedConfig();
        self::assertSame('145227', $config['merchant_id']);
        self::assertSame(FakeTpay::CLIENT_SECRET, $config['client_secret']);
        self::assertSame(FakeTpay::SECURITY_CODE, $config['security_code']);

        $rawConfig = $this->em()->getConnection()->fetchOne('SELECT config FROM sylius_gateway_config');
        self::assertIsString($rawConfig);
        self::assertStringNotContainsString(FakeTpay::SECURITY_CODE, $rawConfig);
        self::assertStringNotContainsString(FakeTpay::CLIENT_SECRET, $rawConfig);
    }

    #[TestWith([''])]
    #[TestWith(['12a4'])]
    public function test_invalid_merchant_id_is_rejected_and_the_configuration_is_not_changed(string $merchantId): void
    {
        $this->createStoredMethod();

        $crawler = $this->submitEditForm(['merchant_id' => $merchantId]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertCount(1, $crawler->filterXPath(sprintf('//*[@name="%s" and contains(@class, "is-invalid")]', sprintf(self::FIELD, 'merchant_id'))));
        self::assertSame(FakeTpay::MERCHANT_ID, $this->storedConfig()['merchant_id']);
    }

    public function test_edit_form_shows_the_notification_url(): void
    {
        $this->createStoredMethod();

        $this->editPage();

        self::assertStringContainsString('http://localhost/payment-methods/TPAY', (string) $this->client->getResponse()->getContent());
    }

    private function createStoredMethod(): void
    {
        $this->createTpayMethod(self::METHOD_CODE);
        $this->em()->flush();
    }

    private function editPage(): Crawler
    {
        $id = $this->storedMethod()->getId();
        self::assertIsInt($id);
        $crawler = $this->client->request('GET', sprintf('/admin/payment-methods/%d/edit', $id));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @param array<string, string> $config
     */
    private function submitEditForm(array $config): Crawler
    {
        $form = $this->editPage()->filterXPath('//form[@name="sylius_admin_payment_method"]')->form();
        foreach ($config as $field => $value) {
            $form[sprintf(self::FIELD, $field)] = $value;
        }

        return $this->client->submit($form);
    }

    /**
     * @return array<string, mixed>
     */
    private function storedConfig(): array
    {
        $gatewayConfig = $this->storedMethod()->getGatewayConfig();
        self::assertInstanceOf(GatewayConfigInterface::class, $gatewayConfig);

        return $gatewayConfig->getConfig();
    }

    private function storedMethod(): PaymentMethodInterface
    {
        $this->em()->clear();
        $method = self::service(ObjectRepository::class, 'sylius.repository.payment_method')->findOneBy(['code' => self::METHOD_CODE]);
        self::assertInstanceOf(PaymentMethodInterface::class, $method);

        return $method;
    }
}
