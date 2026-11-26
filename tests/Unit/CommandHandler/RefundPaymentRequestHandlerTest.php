<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Unit\CommandHandler;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusTpayPlugin\Api\TpayClientInterface;
use Refberg\SyliusTpayPlugin\Command\RefundPaymentRequest;
use Refberg\SyliusTpayPlugin\CommandHandler\RefundPaymentRequestHandler;
use Refberg\SyliusTpayPlugin\Config\CredentialsResolver;
use Refberg\SyliusTpayPlugin\Payment\PaymentDetails;
use Refberg\SyliusTpayPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusTpayPlugin\Payment\PaymentSynchronizerInterface;
use Refberg\SyliusTpayPlugin\TpayGateway;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Tests\Refberg\SyliusTpayPlugin\Support\FakeTpay;
use Tests\Refberg\SyliusTpayPlugin\Support\MemoryLogger;
use Tests\Refberg\SyliusTpayPlugin\Support\Payments;
use Tests\Refberg\SyliusTpayPlugin\Support\PaymentStateMachine;

final class RefundPaymentRequestHandlerTest extends TestCase
{
    public function test_request_that_is_not_new_is_ignored(): void
    {
        $client = $this->createMock(TpayClientInterface::class);
        $client->expects(self::never())->method('createRefund');
        $locker = $this->createMock(PaymentLockerInterface::class);
        $locker->expects(self::never())->method('lock');

        [$payment, $request] = $this->handle($client, completedTransactionId: 'T1', requestState: PaymentRequestInterface::STATE_PROCESSING, locker: $locker);

        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $request->getState());
        self::assertSame([], $request->getResponseData());
        self::assertSame([], PaymentDetails::of($payment)->refunds());
    }

    public function test_payment_without_a_completed_tpay_transaction_fails_the_request_without_calling_tpay(): void
    {
        $client = $this->createMock(TpayClientInterface::class);
        $client->expects(self::never())->method('createRefund');
        $logger = new MemoryLogger();
        $locker = $this->createMock(PaymentLockerInterface::class);
        $locker->expects(self::once())->method('lock');

        [, $request] = $this->handle($client, completedTransactionId: null, logger: $logger, locker: $locker);

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $request->getState());
        self::assertSame(['error' => 'No completed Tpay transaction for this payment.'], $request->getResponseData());
        self::assertSame('error', $logger->records[0]['level'] ?? null);
        self::assertSame(['payment_id' => 42], $logger->records[0]['context']);
    }

    public function test_active_refund_completes_the_request_without_a_second_refund(): void
    {
        $client = $this->createMock(TpayClientInterface::class);
        $client->expects(self::never())->method('createRefund');
        $synchronizer = $this->createMock(PaymentSynchronizerInterface::class);
        $synchronizer->expects(self::once())->method('synchronizeRefunds')->with(self::anything(), 'T1');

        [$payment, $request] = $this->handle($client, completedTransactionId: 'T1', activeRefund: true, synchronizer: $synchronizer);

        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $request->getState());
        self::assertSame(['transaction_id' => 'T1', 'already_requested' => true], $request->getResponseData());
        self::assertCount(1, PaymentDetails::of($payment)->refunds());
    }

    /**
     * @return array{PaymentInterface, PaymentRequestInterface}
     */
    private function handle(
        TpayClientInterface $client,
        ?string $completedTransactionId,
        string $requestState = PaymentRequestInterface::STATE_NEW,
        bool $activeRefund = false,
        MemoryLogger $logger = new MemoryLogger(),
        ?PaymentLockerInterface $locker = null,
        ?PaymentSynchronizerInterface $synchronizer = null,
    ): array {
        $method = self::tpayMethod();
        $payment = Payments::payment(state: PaymentInterface::STATE_REFUNDED);
        $payment->setMethod($method);
        $details = PaymentDetails::of($payment);
        $details->addTransaction('T1', 'TR-T1', '000000001-42-aaaaaaaaaaaa', FakeTpay::MERCHANT_ID, 1500, 'PLN');
        if (null !== $completedTransactionId) {
            $details->setCompletedTransactionId($completedTransactionId);
        }
        if ($activeRefund) {
            $details->recordRefund('3f2504e0-4f89-11d3-9a0c-0305e82c3300', 1500, 'PLN');
        }
        $details->save();

        $request = new PaymentRequest($payment, $method);
        $request->setAction(PaymentRequestInterface::ACTION_REFUND);
        $request->setState($requestState);

        $paymentRequestProvider = self::createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($request);

        $handler = new RefundPaymentRequestHandler(
            $paymentRequestProvider,
            new CredentialsResolver(),
            $client,
            $synchronizer ?? self::createStub(PaymentSynchronizerInterface::class),
            $locker ?? self::createStub(PaymentLockerInterface::class),
            new PaymentStateMachine(),
            $logger,
        );

        $handler(new RefundPaymentRequest('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));

        return [$payment, $request];
    }

    private static function tpayMethod(): PaymentMethod
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName(TpayGateway::FACTORY_NAME);
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

        return $method;
    }
}
