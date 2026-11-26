<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Unit\CommandHandler;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusTpayPlugin\Command\NotifyPaymentRequest;
use Refberg\SyliusTpayPlugin\CommandHandler\NotifyPaymentRequestHandler;
use Refberg\SyliusTpayPlugin\Config\CredentialsResolver;
use Refberg\SyliusTpayPlugin\Notification\Notification;
use Refberg\SyliusTpayPlugin\Notification\NotificationParserInterface;
use Refberg\SyliusTpayPlugin\Payment\PaymentDetails;
use Refberg\SyliusTpayPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusTpayPlugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusTpayPlugin\Payment\PaymentSynchronizerInterface;
use Refberg\SyliusTpayPlugin\TpayGateway;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Tests\Refberg\SyliusTpayPlugin\Support\FakeTpay;
use Tests\Refberg\SyliusTpayPlugin\Support\Payments;
use Tests\Refberg\SyliusTpayPlugin\Support\PaymentStateMachine;

final class NotifyPaymentRequestHandlerTest extends TestCase
{
    public function test_invalid_configuration_fails_the_request(): void
    {
        $parser = $this->createMock(NotificationParserInterface::class);
        $parser->expects(self::never())->method('parseStoredPayload');
        [$handler, $paymentRequest] = $this->handler(self::createStub(PaymentSynchronizerInterface::class), $parser, ['environment' => 'sandbox']);

        $handler(new NotifyPaymentRequest('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(['error' => 'Payment method "TPAY" is missing the Tpay "merchantId" setting.'], $paymentRequest->getResponseData());
    }

    public function test_rejected_notification_fails_the_request_without_locking_or_api_calls(): void
    {
        $synchronizer = $this->createMock(PaymentSynchronizerInterface::class);
        $synchronizer->expects(self::never())->method('synchronize');
        $synchronizer->expects(self::never())->method('synchronizeRefunds');
        $locker = $this->createMock(PaymentLockerInterface::class);
        $locker->expects(self::never())->method('lock');
        [$handler, $paymentRequest] = $this->handler($synchronizer, self::parserReturning(null), locker: $locker);

        $handler(new NotifyPaymentRequest('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(['error' => 'Invalid notification signature or body.'], $paymentRequest->getResponseData());
    }

    public function test_title_of_another_payment_is_rejected(): void
    {
        $synchronizer = $this->createMock(PaymentSynchronizerInterface::class);
        $synchronizer->expects(self::never())->method('synchronize');
        $synchronizer->expects(self::never())->method('synchronizeRefunds');
        [$handler, $paymentRequest] = $this->handler($synchronizer, self::parserReturning(self::notification(Notification::TYPE_PAYMENT, 'TR-OTHER')));

        $handler(new NotifyPaymentRequest('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(['error' => 'Tpay transaction is not linked to this payment.'], $paymentRequest->getResponseData());
    }

    public function test_refund_notification_synchronizes_refunds_before_the_payment(): void
    {
        $calls = [];
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('synchronizeRefunds')->willReturnCallback(static function (mixed $payment, string $transactionId) use (&$calls): void {
            $calls[] = 'synchronizeRefunds ' . $transactionId;
        });
        $synchronizer->method('synchronize')->willReturnCallback(static function (mixed $payment, string $transactionId) use (&$calls): string {
            $calls[] = 'synchronize ' . $transactionId;

            return PaymentStateApplierInterface::RESULT_UNCHANGED;
        });
        [$handler, $paymentRequest] = $this->handler($synchronizer, self::parserReturning(self::notification(Notification::TYPE_REFUND, 'TR-T1')));

        $handler(new NotifyPaymentRequest('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));

        self::assertSame(['synchronizeRefunds T1', 'synchronize T1'], $calls);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $paymentRequest->getState());
    }

    public function test_payment_notification_does_not_synchronize_refunds(): void
    {
        $synchronizer = $this->createMock(PaymentSynchronizerInterface::class);
        $synchronizer->expects(self::never())->method('synchronizeRefunds');
        $synchronizer->expects(self::once())->method('synchronize')->willReturn(PaymentStateApplierInterface::RESULT_UNCHANGED);
        [$handler, $paymentRequest] = $this->handler($synchronizer, self::parserReturning(self::notification(Notification::TYPE_PAYMENT, 'TR-T1')));

        $handler(new NotifyPaymentRequest('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));

        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $paymentRequest->getState());
    }

    #[TestWith([PaymentStateApplierInterface::RESULT_COMPLETED, true])]
    #[TestWith([PaymentStateApplierInterface::RESULT_UNCHANGED, false])]
    public function test_only_a_completed_payment_restores_the_paid_method(string $result, bool $restored): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('synchronize')->willReturn($result);
        $locker = $this->createMock(PaymentLockerInterface::class);
        $locker->expects(self::once())->method('lock');
        [$handler, $paymentRequest] = $this->handler($synchronizer, self::parserReturning(self::notification(Notification::TYPE_PAYMENT, 'TR-T1')), locker: $locker);
        $payment = $paymentRequest->getPayment();
        $otherMethod = new PaymentMethod();
        $otherMethod->setCode('BANK_TRANSFER');
        $payment->setMethod($otherMethod);

        $handler(new NotifyPaymentRequest('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));

        self::assertSame($restored ? $paymentRequest->getMethod() : $otherMethod, $payment->getMethod());
        self::assertSame([
            'type' => Notification::TYPE_PAYMENT,
            'transaction_id' => 'T1',
            'title' => 'TR-T1',
            'result' => $result,
            'payment_state' => PaymentInterface::STATE_NEW,
        ], $paymentRequest->getResponseData());
    }

    public function test_only_capture_requests_of_the_notified_transaction_are_closed(): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('synchronize')->willReturnCallback(static function (PaymentInterface $payment, string $transactionId): string {
            $details = PaymentDetails::of($payment);
            $details->recordTransactionStatus($transactionId, 'correct');
            $details->save();

            return PaymentStateApplierInterface::RESULT_UNCHANGED;
        });
        $requests = [];
        $repository = self::createStub(PaymentRequestRepositoryInterface::class);
        $repository->method('findByPaymentIdAndStates')->willReturnCallback(static function () use (&$requests): array {
            return $requests;
        });
        [$handler, $paymentRequest] = $this->handler($synchronizer, self::parserReturning(self::notification(Notification::TYPE_PAYMENT, 'TR-T1')), paymentRequestRepository: $repository);
        $payment = $paymentRequest->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $details = PaymentDetails::of($payment);
        $details->addTransaction('T2', 'TR-T2', 'ext-T2', FakeTpay::MERCHANT_ID, 1500, 'PLN');
        $details->recordTransactionStatus('T2', 'canceled');
        $details->save();
        $capture = self::processingRequest($paymentRequest, PaymentRequestInterface::ACTION_CAPTURE, 'T1');
        $otherTransactionCapture = self::processingRequest($paymentRequest, PaymentRequestInterface::ACTION_CAPTURE, 'T2');
        $captureWithoutTransaction = self::processingRequest($paymentRequest, PaymentRequestInterface::ACTION_CAPTURE, null);
        $refund = self::processingRequest($paymentRequest, PaymentRequestInterface::ACTION_REFUND, 'T1');
        $requests = [$otherTransactionCapture, $captureWithoutTransaction, $refund, $capture];

        $handler(new NotifyPaymentRequest('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));

        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $capture->getState());
        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $otherTransactionCapture->getState());
        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $captureWithoutTransaction->getState());
        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $refund->getState());
    }

    private static function processingRequest(PaymentRequestInterface $notifyRequest, string $action, ?string $transactionId): PaymentRequestInterface
    {
        $request = new PaymentRequest($notifyRequest->getPayment(), $notifyRequest->getMethod());
        $request->setAction($action);
        $request->setState(PaymentRequestInterface::STATE_PROCESSING);
        if (null !== $transactionId) {
            $request->setResponseData(['transaction_id' => $transactionId]);
        }

        return $request;
    }

    private static function parserReturning(?Notification $notification): NotificationParserInterface
    {
        $parser = self::createStub(NotificationParserInterface::class);
        $parser->method('parseStoredPayload')->willReturn($notification);

        return $parser;
    }

    private static function notification(string $type, string $title): Notification
    {
        return new Notification($type, FakeTpay::MERCHANT_ID, $title, 'ext-T1', Notification::TYPE_REFUND === $type ? Notification::STATUS_CHARGEBACK : Notification::STATUS_TRUE, 1500, 'PLN');
    }

    /**
     * @param array<string, string>|null $config
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface>|null $paymentRequestRepository
     *
     * @return array{NotifyPaymentRequestHandler, PaymentRequestInterface}
     */
    private function handler(
        PaymentSynchronizerInterface $synchronizer,
        NotificationParserInterface $parser,
        ?array $config = null,
        ?PaymentLockerInterface $locker = null,
        ?PaymentRequestRepositoryInterface $paymentRequestRepository = null,
    ): array {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName(TpayGateway::FACTORY_NAME);
        $gatewayConfig->setConfig($config ?? [
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
        $details->save();

        $paymentRequest = new PaymentRequest($payment, $method);
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_NOTIFY);
        $paymentRequest->setPayload(['http_request' => ['content' => 'signed body', 'headers' => []]]);

        $paymentRequestProvider = self::createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $handler = new NotifyPaymentRequestHandler(
            $paymentRequestProvider,
            new CredentialsResolver(),
            $parser,
            $synchronizer,
            $locker ?? self::createStub(PaymentLockerInterface::class),
            new PaymentStateMachine(),
            paymentRequestRepository: $paymentRequestRepository,
        );

        return [$handler, $paymentRequest];
    }
}
