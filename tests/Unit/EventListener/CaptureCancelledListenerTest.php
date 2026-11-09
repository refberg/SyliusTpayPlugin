<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Unit\EventListener;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusTpayPlugin\Api\Exception\TpayApiException;
use Refberg\SyliusTpayPlugin\Api\Exception\TpayTransportException;
use Refberg\SyliusTpayPlugin\Api\TpayClientInterface;
use Refberg\SyliusTpayPlugin\Config\CredentialsResolver;
use Refberg\SyliusTpayPlugin\EventListener\CaptureCancelledListener;
use Refberg\SyliusTpayPlugin\Payment\PaymentDetails;
use Refberg\SyliusTpayPlugin\TpayGateway;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;
use Tests\Refberg\SyliusTpayPlugin\Support\FakeTpay;
use Tests\Refberg\SyliusTpayPlugin\Support\MemoryLogger;
use Tests\Refberg\SyliusTpayPlugin\Support\Payments;

final class CaptureCancelledListenerTest extends TestCase
{
    public function test_pending_tpay_transaction_of_a_cancelled_capture_is_cancelled_in_tpay(): void
    {
        $paymentRequest = self::cancelledRequest(PaymentRequestInterface::ACTION_CAPTURE, 'pending', TpayGateway::FACTORY_NAME);
        $client = $this->createMock(TpayClientInterface::class);
        $client->expects(self::once())->method('cancelTransaction')->with(self::anything(), 'T1');
        $logger = new MemoryLogger();

        (new CaptureCancelledListener(new CredentialsResolver(), $client, $logger))(new CompletedEvent($paymentRequest, new Marking()));

        self::assertSame('info', $logger->records[0]['level'] ?? null);
        self::assertSame('pending', PaymentDetails::of($paymentRequest->getPayment())->getTransactionStatus('T1'));
    }

    #[TestWith([409, 'critical'])]
    #[TestWith([400, 'critical'])]
    #[TestWith([503, 'warning'])]
    #[TestWith([429, 'warning'])]
    #[TestWith([401, 'warning'])]
    public function test_tpay_error_is_only_logged_and_a_refusal_is_critical(int $httpStatus, string $level): void
    {
        $paymentRequest = self::cancelledRequest(PaymentRequestInterface::ACTION_CAPTURE, 'pending', TpayGateway::FACTORY_NAME);
        $details = $paymentRequest->getPayment()->getDetails();
        $responseData = $paymentRequest->getResponseData();
        $client = self::createStub(TpayClientInterface::class);
        $client->method('cancelTransaction')->willThrowException(new TpayApiException('Canceling Tpay transaction failed.', $httpStatus));
        $logger = new MemoryLogger();

        (new CaptureCancelledListener(new CredentialsResolver(), $client, $logger))(new CompletedEvent($paymentRequest, new Marking()));

        self::assertSame($level, $logger->records[0]['level'] ?? null);
        self::assertSame(PaymentRequestInterface::STATE_CANCELLED, $paymentRequest->getState());
        self::assertSame($responseData, $paymentRequest->getResponseData());
        self::assertSame($details, $paymentRequest->getPayment()->getDetails());
    }

    public function test_transport_error_is_logged_as_a_warning(): void
    {
        $client = self::createStub(TpayClientInterface::class);
        $client->method('cancelTransaction')->willThrowException(new TpayTransportException('Could not reach the Tpay API.'));
        $logger = new MemoryLogger();

        (new CaptureCancelledListener(new CredentialsResolver(), $client, $logger))(new CompletedEvent(self::cancelledRequest(PaymentRequestInterface::ACTION_CAPTURE, 'pending', TpayGateway::FACTORY_NAME), new Marking()));

        self::assertSame('warning', $logger->records[0]['level'] ?? null);
    }

    #[TestWith([PaymentRequestInterface::ACTION_CAPTURE, 'paid', TpayGateway::FACTORY_NAME])]
    #[TestWith([PaymentRequestInterface::ACTION_CAPTURE, 'correct', TpayGateway::FACTORY_NAME])]
    #[TestWith([PaymentRequestInterface::ACTION_CAPTURE, 'canceled', TpayGateway::FACTORY_NAME])]
    #[TestWith([PaymentRequestInterface::ACTION_STATUS, 'pending', TpayGateway::FACTORY_NAME])]
    #[TestWith([PaymentRequestInterface::ACTION_CAPTURE, 'pending', 'offline'])]
    public function test_other_requests_do_not_reach_tpay(string $action, string $tpayStatus, string $factoryName): void
    {
        $client = $this->createMock(TpayClientInterface::class);
        $client->expects(self::never())->method('cancelTransaction');

        (new CaptureCancelledListener(new CredentialsResolver(), $client))(new CompletedEvent(self::cancelledRequest($action, $tpayStatus, $factoryName), new Marking()));
    }

    private static function cancelledRequest(string $action, string $tpayStatus, string $factoryName): PaymentRequestInterface
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName($factoryName);
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
        $details = PaymentDetails::of($payment);
        $details->addTransaction('T1', 'TR-T1', 'ext-T1', FakeTpay::MERCHANT_ID, 1500, 'PLN');
        $details->recordTransactionStatus('T1', $tpayStatus);
        $details->save();

        $paymentRequest = new PaymentRequest($payment, $method);
        $paymentRequest->setAction($action);
        $paymentRequest->setState(PaymentRequestInterface::STATE_CANCELLED);
        $paymentRequest->setResponseData(['redirect_url' => 'https://secure.sandbox.tpay.com/?title=TR-T1&uid=fake', 'transaction_id' => 'T1', 'title' => 'TR-T1']);

        return $paymentRequest;
    }
}
