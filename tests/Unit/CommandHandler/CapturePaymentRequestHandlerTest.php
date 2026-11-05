<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Unit\CommandHandler;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusTpayPlugin\Api\AccessTokenProvider;
use Refberg\SyliusTpayPlugin\Api\TpayClient;
use Refberg\SyliusTpayPlugin\Command\CapturePaymentRequest;
use Refberg\SyliusTpayPlugin\CommandHandler\CapturePaymentRequestHandler;
use Refberg\SyliusTpayPlugin\Config\CredentialsResolver;
use Refberg\SyliusTpayPlugin\Order\ExtOrderIdGenerator;
use Refberg\SyliusTpayPlugin\Order\TransactionRequestFactoryInterface;
use Refberg\SyliusTpayPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusTpayPlugin\TpayGateway;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Tests\Refberg\SyliusTpayPlugin\Support\FakeTpay;
use Tests\Refberg\SyliusTpayPlugin\Support\Payments;
use Tests\Refberg\SyliusTpayPlugin\Support\PaymentStateMachine;

final class CapturePaymentRequestHandlerTest extends TestCase
{
    public function test_payment_completed_while_waiting_for_the_lock_gets_no_new_tpay_transaction(): void
    {
        $tpay = new FakeTpay();
        $httpClient = new MockHttpClient($tpay);

        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName(TpayGateway::FACTORY_NAME);
        $gatewayConfig->setGatewayName('tpay');
        $gatewayConfig->setConfig([
            'environment' => 'sandbox',
            'merchant_id' => FakeTpay::MERCHANT_ID,
            'client_id' => FakeTpay::CLIENT_ID,
            'client_secret' => FakeTpay::CLIENT_SECRET,
            'security_code' => FakeTpay::SECURITY_CODE,
        ]);
        $method = new PaymentMethod();
        $method->setCode('TPAY');
        $method->setGatewayConfig($gatewayConfig);

        $payment = Payments::payment();
        $payment->setMethod($method);

        $paymentRequest = new class($payment, $method) extends PaymentRequest {
            public function getId(): string
            {
                return '3f2504e0-4f89-11d3-9a0c-0305e82c3301';
            }
        };

        $paymentRequestProvider = self::createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $transactionRequestFactory = self::createStub(TransactionRequestFactoryInterface::class);
        $transactionRequestFactory->method('create')->willReturn(['amount' => 15.0, 'description' => 'Order', 'payer' => ['email' => 'a@example.com', 'name' => 'A']]);

        // A notification completes the payment while the capture waits for the lock.
        $paymentLocker = self::createStub(PaymentLockerInterface::class);
        $paymentLocker->method('lock')->willReturnCallback(static function (PaymentInterface $locked): void {
            $locked->setState(PaymentInterface::STATE_COMPLETED);
        });

        $handler = new CapturePaymentRequestHandler(
            $paymentRequestProvider,
            new CredentialsResolver(),
            new TpayClient($httpClient, new AccessTokenProvider($httpClient, new ArrayAdapter())),
            $transactionRequestFactory,
            new ExtOrderIdGenerator(),
            $paymentLocker,
            new PaymentStateMachine(),
        );

        $handler(new CapturePaymentRequest('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));

        self::assertSame([], $tpay->requests('POST', '#^/transactions$#'));
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(['error' => 'Payment in state "completed" cannot be paid.'], $paymentRequest->getResponseData());
    }
}
