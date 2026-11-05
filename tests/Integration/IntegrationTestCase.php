<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Integration;

use DateTime;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Refberg\SyliusTpayPlugin\Payment\PaymentDetails;
use Refberg\SyliusTpayPlugin\TpayGateway;
use Sylius\Bundle\PaymentBundle\Announcer\PaymentRequestAnnouncerInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Cache\CacheInterface;
use Tests\Refberg\SyliusTpayPlugin\Support\FakeTpay;
use function is_array;
use function json_decode;
use function json_encode;
use function str_replace;
use function strtolower;
use const JSON_THROW_ON_ERROR;

/**
 * Runs the real Sylius test application with the Tpay HTTP client replaced by FakeTpay.
 */
abstract class IntegrationTestCase extends WebTestCase
{
    protected const METHOD_CODE = 'TPAY';

    protected KernelBrowser $client;

    protected FakeTpay $tpay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        // Keep one kernel (and one FakeTpay state) for all requests of a test.
        $this->client->disableReboot();

        $this->tpay = self::service(FakeTpay::class, FakeTpay::class);
        self::service(CacheInterface::class, 'refberg_tpay.cache')->clear();

        $connection = $this->em()->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        (new ORMPurger($this->em()))->purge();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function em(): EntityManagerInterface
    {
        return self::service(EntityManagerInterface::class, 'doctrine.orm.entity_manager');
    }

    /**
     * Creates a placed order (checkout completed) with one Tpay payment of 15.00 PLN awaiting payment.
     */
    protected function createPlacedOrder(int $amount = 1500): PaymentInterface
    {
        $locale = $this->factory('locale')->createNew();
        self::assertInstanceOf(LocaleInterface::class, $locale);
        $locale->setCode('en_US');

        $currency = $this->factory('currency')->createNew();
        self::assertInstanceOf(CurrencyInterface::class, $currency);
        $currency->setCode('PLN');

        $channel = $this->factory('channel')->createNew();
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $channel->setCode('WEB');
        $channel->setName('Web');
        $channel->setEnabled(true);
        $channel->setBaseCurrency($currency);
        $channel->addCurrency($currency);
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);
        $channel->setTaxCalculationStrategy('order_items_based');

        $method = $this->createTpayMethod(self::METHOD_CODE);
        $method->addChannel($channel);

        $customer = $this->factory('customer')->createNew();
        self::assertInstanceOf(CustomerInterface::class, $customer);
        $customer->setEmail('buyer@example.com');

        $order = $this->factory('order')->createNew();
        self::assertInstanceOf(OrderInterface::class, $order);
        $order->setChannel($channel);
        $order->setCurrencyCode('PLN');
        $order->setLocaleCode('en_US');
        $order->setCustomer($customer);
        $order->setNumber('000000001');
        $order->setTokenValue('order-token');
        $order->setCheckoutState('completed');
        $order->setState('new');
        $order->setPaymentState('awaiting_payment');
        $order->setCustomerIp('10.0.0.1');
        $order->setCheckoutCompletedAt(new DateTime());

        $payment = $this->factory('payment')->createNew();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $payment->setMethod($method);
        $payment->setAmount($amount);
        $payment->setCurrencyCode('PLN');
        $payment->setState('new');
        $order->addPayment($payment);

        $em = $this->em();
        foreach ([$locale, $currency, $channel, $customer, $order, $payment] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return $payment;
    }

    /**
     * Creates a Tpay payment method of the FakeTpay merchant, persisted but not flushed.
     *
     * @param array<string, string> $config overrides of the gateway configuration
     */
    protected function createTpayMethod(string $code, array $config = []): PaymentMethodInterface
    {
        $gatewayConfig = $this->factory('gateway_config')->createNew();
        self::assertInstanceOf(GatewayConfigInterface::class, $gatewayConfig);
        $gatewayConfig->setFactoryName(TpayGateway::FACTORY_NAME);
        $gatewayConfig->setGatewayName(strtolower($code));
        $gatewayConfig->setConfig($config + $this->tpayCredentials());
        if ($gatewayConfig instanceof GatewayConfig) {
            $gatewayConfig->setUsePayum(false);
        }

        $method = $this->factory('payment_method')->createNew();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $method->setCode($code);
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName('Tpay');
        $method->setEnabled(true);
        $method->setGatewayConfig($gatewayConfig);

        $this->em()->persist($gatewayConfig);
        $this->em()->persist($method);

        return $method;
    }

    /**
     * Creates and dispatches a payment request the same way the plugin's payment transition listener does.
     */
    protected function dispatchPaymentRequest(PaymentInterface $payment, string $action): void
    {
        $payment = $this->reloadPayment($payment);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);

        $paymentRequest = self::service(PaymentRequestFactoryInterface::class, 'sylius.factory.payment_request')->create($payment, $method);
        $paymentRequest->setAction($action);
        self::service(PaymentRequestRepositoryInterface::class, 'sylius.repository.payment_request')->add($paymentRequest);

        self::service(PaymentRequestAnnouncerInterface::class, 'sylius.announcer.payment_request')->dispatchPaymentRequestCommand($paymentRequest);
    }

    /**
     * @return array<string, string>
     */
    protected function tpayCredentials(): array
    {
        return [
            'environment' => 'sandbox',
            'merchant_id' => FakeTpay::MERCHANT_ID,
            'client_id' => FakeTpay::CLIENT_ID,
            'client_secret' => FakeTpay::CLIENT_SECRET,
            'security_code' => FakeTpay::SECURITY_CODE,
        ];
    }

    /**
     * Goes through the shop "pay" flow and returns the URL of the Tpay payment page.
     */
    protected function payInShop(): string
    {
        $this->client->request('GET', '/en_US/order/order-token/pay');
        $response = $this->client->getResponse();
        self::assertTrue($response->isRedirect(), (string) $response->getContent());

        $this->client->request('GET', (string) $response->headers->get('Location'));
        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode(), (string) $response->getContent());

        return (string) $response->headers->get('Location');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<array-key, mixed>
     */
    protected function postPaymentRequest(array $body): array
    {
        $this->client->request(
            'POST',
            '/api/v2/shop/orders/order-token/payment-requests',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Posts a notification as Tpay does: form-encoded, with the JWS of the exact body unless another one is given.
     */
    protected function notify(string $body, ?string $jws = '', string $methodCode = self::METHOD_CODE): Response
    {
        $server = ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'];
        $jws = '' === $jws ? FakeTpay::jws($body) : $jws;
        if (null !== $jws) {
            $server['HTTP_X_JWS_SIGNATURE'] = $jws;
        }

        $this->client->request('POST', '/payment-methods/' . $methodCode, server: $server, content: $body);

        return $this->client->getResponse();
    }

    protected function notifyTransaction(string $transactionId, string $status = 'TRUE'): Response
    {
        return $this->notify($this->tpay->notificationBody($transactionId, $status));
    }

    /**
     * Path of the shop return URL the customer gets back to from Tpay ("success" or "error").
     */
    protected function returnUrl(string $transactionId, string $kind = 'success'): string
    {
        $request = $this->tpay->transaction($transactionId)['request'];
        self::assertIsArray($request);
        $url = self::at($request, 'callbacks', 'payerUrls', $kind);
        self::assertIsString($url);

        return str_replace('http://localhost', '', $url);
    }

    protected function reloadPayment(PaymentInterface $payment): PaymentInterface
    {
        $this->em()->clear();
        $reloaded = $this->em()->find($payment::class, $payment->getId());
        self::assertInstanceOf(PaymentInterface::class, $reloaded);

        return $reloaded;
    }

    protected function details(PaymentInterface $payment): PaymentDetails
    {
        return PaymentDetails::of($this->reloadPayment($payment));
    }

    /**
     * @return list<PaymentRequestInterface>
     */
    protected function paymentRequests(string $action): array
    {
        $this->em()->clear();
        $repository = self::service(ObjectRepository::class, 'sylius.repository.payment_request');

        /** @var list<PaymentRequestInterface> $requests */
        $requests = $repository->findBy(['action' => $action]);

        return $requests;
    }

    /**
     * Reads a nested value from decoded JSON, failing the test when the path does not exist.
     *
     * @param array<array-key, mixed> $data
     */
    protected static function at(array $data, string ...$path): mixed
    {
        $value = $data;
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return FactoryInterface<object>
     */
    protected function factory(string $resource): FactoryInterface
    {
        return self::service(FactoryInterface::class, 'sylius.factory.' . $resource);
    }

    /**
     * Fetches a service from the test container and checks its type.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected static function service(string $class, string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
