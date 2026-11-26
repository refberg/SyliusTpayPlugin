<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Unit\CommandHandler;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusTpayPlugin\Api\Exception\TpayApiException;
use Refberg\SyliusTpayPlugin\Command\StatusPaymentRequest;
use Refberg\SyliusTpayPlugin\CommandHandler\StatusPaymentRequestHandler;
use Refberg\SyliusTpayPlugin\Config\CredentialsResolver;
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
use Tests\Refberg\SyliusTpayPlugin\Support\MemoryLogger;
use Tests\Refberg\SyliusTpayPlugin\Support\Payments;
use Tests\Refberg\SyliusTpayPlugin\Support\PaymentStateMachine;
use function array_filter;
use function array_values;
use function in_array;

final class StatusPaymentRequestHandlerTest extends TestCase
{
    #[TestWith(['paid', PaymentRequestInterface::STATE_COMPLETED])]
    #[TestWith(['correct', PaymentRequestInterface::STATE_COMPLETED])]
    #[TestWith(['refund', PaymentRequestInterface::STATE_COMPLETED])]
    #[TestWith(['canceled', PaymentRequestInterface::STATE_FAILED])]
    #[TestWith(['pending', PaymentRequestInterface::STATE_PROCESSING])]
    public function test_capture_request_of_the_checked_tpay_transaction_is_closed_by_its_final_status(string $tpayStatus, string $expectedState): void
    {
        $method = self::tpayMethod();
        $payment = self::payment($method, ['T1']);

        $capture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_PROCESSING, 'T1');
        $otherTransactionCapture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_PROCESSING, 'T2');
        $cancelledCapture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_CANCELLED, 'T1');
        $statusRequest = self::request($payment, $method, PaymentRequestInterface::ACTION_STATUS, PaymentRequestInterface::STATE_NEW, null);
        $requests = [$capture, $otherTransactionCapture, $cancelledCapture, $statusRequest];

        $this->handle($statusRequest, $requests, $tpayStatus);

        self::assertSame($expectedState, $capture->getState());
        self::assertSame(['redirect_url' => 'https://secure.sandbox.tpay.com/?title=TR-T1&uid=fake', 'transaction_id' => 'T1', 'title' => 'TR-T1'], $capture->getResponseData());
        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $otherTransactionCapture->getState());
        self::assertSame(PaymentRequestInterface::STATE_CANCELLED, $cancelledCapture->getState());
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $statusRequest->getState());
    }

    #[TestWith(['correct', PaymentRequestInterface::STATE_COMPLETED])]
    #[TestWith(['refund', PaymentRequestInterface::STATE_COMPLETED])]
    #[TestWith(['canceled', PaymentRequestInterface::STATE_FAILED])]
    public function test_capture_request_left_processing_for_an_already_finished_tpay_transaction_is_closed(string $tpayStatus, string $expectedState): void
    {
        $method = self::tpayMethod();
        $payment = self::payment($method, ['T1']);
        $details = PaymentDetails::of($payment);
        $details->recordTransactionStatus('T1', $tpayStatus);
        $details->save();

        $capture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_PROCESSING, 'T1');
        $statusRequest = self::request($payment, $method, PaymentRequestInterface::ACTION_STATUS, PaymentRequestInterface::STATE_NEW, null);

        $this->handle($statusRequest, [$capture, $statusRequest], 'pending');

        self::assertSame($expectedState, $capture->getState());
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $statusRequest->getState());
    }

    public function test_api_error_fails_the_request_and_keeps_the_payment(): void
    {
        $method = self::tpayMethod();
        $payment = self::payment($method, ['T1']);
        $capture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_PROCESSING, 'T1');
        $statusRequest = self::request($payment, $method, PaymentRequestInterface::ACTION_STATUS, PaymentRequestInterface::STATE_NEW, null);
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('synchronize')->willThrowException(new TpayApiException('Retrieving Tpay transaction failed (HTTP 503).', 503));
        $logger = new MemoryLogger();

        $this->invoke($statusRequest, [$capture, $statusRequest], $synchronizer, $logger);

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $statusRequest->getState());
        self::assertSame(['error' => 'Retrieving Tpay transaction failed (HTTP 503).'], $statusRequest->getResponseData());
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $capture->getState());
        self::assertSame('warning', $logger->records[0]['level'] ?? null);
        self::assertSame(['payment_id' => 42, 'reason' => 'Retrieving Tpay transaction failed (HTTP 503).'], $logger->records[0]['context']);
    }

    public function test_invalid_configuration_fails_the_request(): void
    {
        $method = self::tpayMethod();
        $method->getGatewayConfig()?->setConfig(['environment' => 'sandbox']);
        $payment = self::payment($method, ['T1']);
        $statusRequest = self::request($payment, $method, PaymentRequestInterface::ACTION_STATUS, PaymentRequestInterface::STATE_NEW, null);
        $synchronizer = $this->createMock(PaymentSynchronizerInterface::class);
        $synchronizer->expects(self::never())->method('synchronize');

        $this->invoke($statusRequest, [$statusRequest], $synchronizer);

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $statusRequest->getState());
        self::assertSame(['error' => 'Payment method "TPAY" is missing the Tpay "merchantId" setting.'], $statusRequest->getResponseData());
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
    }

    public function test_three_newest_open_transactions_are_checked_and_only_a_completed_one_restores_the_paid_method(): void
    {
        $method = self::tpayMethod();
        $payment = self::payment($method, ['T1', 'T2', 'T3', 'T4']);
        $otherMethod = new PaymentMethod();
        $otherMethod->setCode('BANK_TRANSFER');
        $payment->setMethod($otherMethod);
        $statusRequest = self::request($payment, $method, PaymentRequestInterface::ACTION_STATUS, PaymentRequestInterface::STATE_NEW, null);
        $checked = [];
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('synchronize')->willReturnCallback(static function (PaymentInterface $synchronized, string $transactionId) use (&$checked): string {
            $checked[] = $transactionId;

            return 'T3' === $transactionId ? PaymentStateApplierInterface::RESULT_COMPLETED : PaymentStateApplierInterface::RESULT_UNCHANGED;
        });
        $locker = $this->createMock(PaymentLockerInterface::class);
        $locker->expects(self::once())->method('lock')->with($payment);

        $this->invoke($statusRequest, [$statusRequest], $synchronizer, locker: $locker);

        self::assertSame(['T4', 'T3', 'T2'], $checked);
        self::assertSame($method, $payment->getMethod());
        self::assertSame([['type' => PaymentDetails::WARNING_PAYMENT_METHOD_CHANGED, 'transaction_id' => 'T3']], PaymentDetails::of($payment)->warnings());
        self::assertSame(['payment_state' => PaymentInterface::STATE_NEW, 'transaction_id' => 'T4', 'tpay_status' => 'pending'], $statusRequest->getResponseData());
    }

    /**
     * @param list<PaymentRequestInterface> $requests
     */
    private function handle(PaymentRequestInterface $statusRequest, array $requests, string $tpayStatus): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('synchronize')->willReturnCallback(static function (PaymentInterface $synchronized, string $transactionId) use ($tpayStatus): string {
            $details = PaymentDetails::of($synchronized);
            $details->recordTransactionStatus($transactionId, $tpayStatus);
            $details->save();

            return PaymentStateApplierInterface::RESULT_UNCHANGED;
        });

        $this->invoke($statusRequest, $requests, $synchronizer);
    }

    /**
     * @param list<PaymentRequestInterface> $requests
     */
    private function invoke(PaymentRequestInterface $statusRequest, array $requests, PaymentSynchronizerInterface $synchronizer, MemoryLogger $logger = new MemoryLogger(), ?PaymentLockerInterface $locker = null): void
    {
        $paymentRequestProvider = self::createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($statusRequest);

        $paymentRequestRepository = self::createStub(PaymentRequestRepositoryInterface::class);
        $paymentRequestRepository->method('findByPaymentIdAndStates')->willReturnCallback(
            static fn (mixed $paymentId, array $states): array => array_values(array_filter($requests, static fn (PaymentRequestInterface $request): bool => in_array($request->getState(), $states, true))),
        );

        $handler = new StatusPaymentRequestHandler(
            $paymentRequestProvider,
            new CredentialsResolver(),
            $synchronizer,
            $locker ?? self::createStub(PaymentLockerInterface::class),
            new PaymentStateMachine(),
            $logger,
            $paymentRequestRepository,
        );

        $handler(new StatusPaymentRequest('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));
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

    /**
     * @param list<string> $transactionIds
     */
    private static function payment(PaymentMethod $method, array $transactionIds): PaymentInterface
    {
        $payment = Payments::payment();
        $payment->setMethod($method);
        $details = PaymentDetails::of($payment);
        foreach ($transactionIds as $transactionId) {
            $details->addTransaction($transactionId, 'TR-' . $transactionId, 'ext-' . $transactionId, FakeTpay::MERCHANT_ID, 1500, 'PLN');
        }
        $details->save();

        return $payment;
    }

    private static function request(PaymentInterface $payment, PaymentMethod $method, string $action, string $state, ?string $transactionId): PaymentRequestInterface
    {
        $request = new PaymentRequest($payment, $method);
        $request->setAction($action);
        $request->setState($state);
        if (null !== $transactionId) {
            $request->setResponseData(['redirect_url' => 'https://secure.sandbox.tpay.com/?title=TR-' . $transactionId . '&uid=fake', 'transaction_id' => $transactionId, 'title' => 'TR-' . $transactionId]);
        }

        return $request;
    }
}
