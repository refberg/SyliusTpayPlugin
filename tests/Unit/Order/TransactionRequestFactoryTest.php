<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Unit\Order;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusTpayPlugin\Config\Environment;
use Refberg\SyliusTpayPlugin\Config\TpayCredentials;
use Refberg\SyliusTpayPlugin\Order\TransactionRequestFactory;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentRequest;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tests\Refberg\SyliusTpayPlugin\Support\Payments;
use function mb_strlen;
use function str_repeat;

final class TransactionRequestFactoryTest extends TestCase
{
    public function test_body_uses_the_server_side_amount_and_the_billing_address(): void
    {
        $payment = self::payment(1999);
        $address = new Address();
        $address->setFirstName('Jan');
        $address->setLastName('Kowalski');
        $address->setPhoneNumber('+48 600 000 000');
        $order = $payment->getOrder();
        self::assertInstanceOf(Order::class, $order);
        $order->setBillingAddress($address);
        $order->setLocaleCode('pl_PL');

        $body = $this->factory()->create(self::request($payment), $payment, self::credentials(), 'ext-1');

        self::assertSame([
            'amount' => 19.99,
            'currency' => 'PLN',
            'description' => 'Order 000000001',
            'hiddenDescription' => 'ext-1',
            'payer' => ['email' => 'buyer@example.com', 'name' => 'Jan Kowalski', 'phone' => '+48 600 000 000', 'ip' => '10.0.0.1'],
            'callbacks' => [
                'payerUrls' => [
                    'success' => 'https://shop.example/after-pay/abc?tpay_return=success',
                    'error' => 'https://shop.example/after-pay/abc?tpay_return=error',
                ],
                'notification' => ['url' => 'https://shop.example/payment-methods/TPAY'],
            ],
            'lang' => 'pl',
        ], $body);
    }

    public function test_unsupported_language_and_invalid_ip_are_left_out(): void
    {
        $payment = self::payment();
        $order = $payment->getOrder();
        self::assertInstanceOf(Order::class, $order);
        $order->setLocaleCode('cs_CZ');
        $order->setCustomerIp('not-an-ip');

        $body = $this->factory()->create(self::request($payment), $payment, self::credentials(), 'ext-1');

        self::assertArrayNotHasKey('lang', $body);
        self::assertSame(['email' => 'buyer@example.com', 'name' => 'buyer@example.com'], $body['payer']);
    }

    public function test_long_order_number_is_cut_to_the_tpay_description_limit(): void
    {
        $payment = self::payment();
        $payment->getOrder()?->setNumber(str_repeat('9', 200));

        $body = $this->factory()->create(self::request($payment), $payment, self::credentials(), 'ext-1');

        self::assertIsString($body['description']);
        self::assertSame(128, mb_strlen($body['description']));
    }

    public function test_api_client_continue_url_is_used_as_it_is(): void
    {
        $payment = self::payment();
        $request = self::request($payment, ['continue_url' => 'https://app.example/return?x=1']);

        $body = $this->factory(false)->create($request, $payment, self::credentials(), 'ext-1');

        self::assertIsArray($body['callbacks']);
        self::assertSame(['success' => 'https://app.example/return?x=1', 'error' => 'https://app.example/return?x=1'], $body['callbacks']['payerUrls'] ?? null);
    }

    #[TestWith(['javascript:alert(1)'])]
    #[TestWith(['/relative'])]
    #[TestWith(['ftp://example.com'])]
    public function test_invalid_continue_url_is_rejected(string $url): void
    {
        $payment = self::payment();

        $this->expectException(InvalidArgumentException::class);
        $this->factory()->create(self::request($payment, ['continue_url' => $url]), $payment, self::credentials(), 'ext-1');
    }

    public function test_continue_url_is_required_without_the_shop(): void
    {
        $payment = self::payment();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('continue_url');
        $this->factory(false)->create(self::request($payment), $payment, self::credentials(), 'ext-1');
    }

    #[TestWith([0])]
    #[TestWith([-100])]
    public function test_non_positive_amount_is_rejected(int $amount): void
    {
        $payment = self::payment($amount);

        $this->expectException(InvalidArgumentException::class);
        $this->factory()->create(self::request($payment), $payment, self::credentials(), 'ext-1');
    }

    public function test_customer_e_mail_is_required(): void
    {
        $payment = self::payment();
        $payment->getOrder()?->getCustomer()?->setEmail(null);

        $this->expectException(InvalidArgumentException::class);
        $this->factory()->create(self::request($payment), $payment, self::credentials(), 'ext-1');
    }

    private function factory(bool $withShop = true): TransactionRequestFactory
    {
        $urlGenerator = self::createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://shop.example/payment-methods/TPAY');

        $afterPay = null;
        if ($withShop) {
            $afterPay = self::createStub(UrlProviderInterface::class);
            $afterPay->method('getUrl')->willReturn('https://shop.example/after-pay/abc');
        }

        return new TransactionRequestFactory($urlGenerator, $afterPay);
    }

    private static function payment(int $amount = 1500): PaymentInterface
    {
        $customer = new Customer();
        $customer->setEmail('buyer@example.com');
        $order = new Order();
        $order->setNumber('000000001');
        $order->setCustomer($customer);
        $order->setCustomerIp('10.0.0.1');
        $payment = Payments::payment($amount);
        $order->addPayment($payment);

        return $payment;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private static function request(PaymentInterface $payment, ?array $payload = null): PaymentRequest
    {
        $method = new PaymentMethod();
        $method->setCode('TPAY');
        $request = new PaymentRequest($payment, $method);
        $request->setPayload($payload);

        return $request;
    }

    private static function credentials(): TpayCredentials
    {
        return new TpayCredentials('1010', 'client', 'secret', 'code', Environment::Sandbox);
    }
}
