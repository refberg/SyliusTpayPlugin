<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Unit\Payment;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusTpayPlugin\Api\Model\TpayTransaction;
use Refberg\SyliusTpayPlugin\Config\CredentialsResolver;
use Refberg\SyliusTpayPlugin\Payment\PaymentDetails;
use Refberg\SyliusTpayPlugin\Payment\PaymentStateApplier;
use Refberg\SyliusTpayPlugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusTpayPlugin\TpayGateway;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Tests\Refberg\SyliusTpayPlugin\Support\MemoryLogger;
use Tests\Refberg\SyliusTpayPlugin\Support\Payments;
use Tests\Refberg\SyliusTpayPlugin\Support\PaymentStateMachine;
use function array_filter;
use function array_values;
use function in_array;

final class PaymentStateApplierTest extends TestCase
{
    private PaymentStateMachine $stateMachine;

    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->stateMachine = new PaymentStateMachine();
        $this->logger = new MemoryLogger();
    }

    #[TestWith(['paid'])]
    #[TestWith(['correct'])]
    public function test_paid_transaction_completes_the_payment_once(string $status): void
    {
        $payment = self::payment(['T1']);

        self::assertSame(PaymentStateApplierInterface::RESULT_COMPLETED, $this->applier()->apply($payment, self::transaction('T1', $status)));
        self::assertSame(PaymentStateApplierInterface::RESULT_UNCHANGED, $this->applier()->apply($payment, self::transaction('T1', 'correct')));

        self::assertSame('completed', $payment->getState());
        self::assertSame('T1', PaymentDetails::of($payment)->getCompletedTransactionId());
        self::assertSame([PaymentTransitions::TRANSITION_COMPLETE], $this->stateMachine->appliedTransitions(PaymentTransitions::GRAPH));
    }

    public function test_second_paid_transaction_is_a_warning_and_never_pays_twice(): void
    {
        $payment = self::payment(['T1', 'T2']);
        $this->applier()->apply($payment, self::transaction('T1', 'correct'));

        self::assertSame(PaymentStateApplierInterface::RESULT_WARNING, $this->applier()->apply($payment, self::transaction('T2', 'correct')));

        self::assertSame([['type' => PaymentDetails::WARNING_DUPLICATE_PAYMENT, 'transaction_id' => 'T2']], PaymentDetails::of($payment)->warnings());
        self::assertSame([PaymentTransitions::TRANSITION_COMPLETE], $this->stateMachine->appliedTransitions(PaymentTransitions::GRAPH));
        self::assertSame('critical', $this->logger->records[1]['level'] ?? null);
    }

    public function test_paid_transaction_of_a_failed_or_cancelled_payment_is_a_late_payment_warning(): void
    {
        $payment = self::payment(['T1'], PaymentInterface::STATE_CANCELLED);

        self::assertSame(PaymentStateApplierInterface::RESULT_WARNING, $this->applier()->apply($payment, self::transaction('T1', 'paid')));

        self::assertSame('cancelled', $payment->getState());
        self::assertSame(PaymentDetails::WARNING_LATE_PAYMENT, PaymentDetails::of($payment)->warnings()[0]['type'] ?? null);
    }

    public function test_pending_transaction_changes_nothing(): void
    {
        $payment = self::payment(['T1']);

        self::assertSame(PaymentStateApplierInterface::RESULT_UNCHANGED, $this->applier()->apply($payment, self::transaction('T1', 'pending')));
        self::assertSame('new', $payment->getState());
    }

    public function test_canceled_transaction_fails_the_payment_only_without_other_open_transactions(): void
    {
        $payment = self::payment(['T1', 'T2']);

        self::assertSame(PaymentStateApplierInterface::RESULT_UNCHANGED, $this->applier()->apply($payment, self::transaction('T1', 'canceled')));
        self::assertSame('new', $payment->getState());

        self::assertSame(PaymentStateApplierInterface::RESULT_FAILED, $this->applier()->apply($payment, self::transaction('T2', 'canceled')));
        self::assertSame('failed', $payment->getState());
    }

    public function test_canceled_transaction_does_not_fail_a_payment_switched_to_another_payment_method(): void
    {
        $applier = new PaymentStateApplier($this->stateMachine, credentialsResolver: new CredentialsResolver());
        $payment = self::payment(['T1']);
        $payment->setMethod(self::method('BANK_TRANSFER', 'offline'));

        self::assertSame(PaymentStateApplierInterface::RESULT_UNCHANGED, $applier->apply($payment, self::transaction('T1', 'canceled')));
        self::assertSame('new', $payment->getState());
        self::assertSame('canceled', PaymentDetails::of($payment)->getTransactionStatus('T1'));
    }

    public function test_canceled_transaction_of_a_cancelled_capture_request_does_not_fail_the_payment(): void
    {
        $payment = self::payment(['T1']);
        $method = self::method('TPAY', TpayGateway::FACTORY_NAME);
        $payment->setMethod($method);
        $applier = new PaymentStateApplier($this->stateMachine, paymentRequestRepository: self::paymentRequests([
            self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_CANCELLED, 'T1'),
        ]));

        self::assertSame(PaymentStateApplierInterface::RESULT_UNCHANGED, $applier->apply($payment, self::transaction('T1', 'canceled')));
        self::assertSame('new', $payment->getState());
    }

    public function test_canceled_transaction_fails_the_payment_when_no_capture_request_of_it_was_cancelled(): void
    {
        $payment = self::payment(['T1']);
        $method = self::method('TPAY', TpayGateway::FACTORY_NAME);
        $payment->setMethod($method);
        $applier = new PaymentStateApplier($this->stateMachine, paymentRequestRepository: self::paymentRequests([
            self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_CANCELLED, 'T2'),
            self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_FAILED, 'T1'),
            self::request($payment, $method, PaymentRequestInterface::ACTION_STATUS, PaymentRequestInterface::STATE_CANCELLED, 'T1'),
        ]));

        self::assertSame(PaymentStateApplierInterface::RESULT_FAILED, $applier->apply($payment, self::transaction('T1', 'canceled')));
        self::assertSame('failed', $payment->getState());
    }

    public function test_canceled_transaction_fails_a_switched_payment_when_no_credentials_resolver_is_given(): void
    {
        $payment = self::payment(['T1']);
        $payment->setMethod(self::method('BANK_TRANSFER', 'offline'));

        self::assertSame(PaymentStateApplierInterface::RESULT_FAILED, $this->applier()->apply($payment, self::transaction('T1', 'canceled')));
        self::assertSame('failed', $payment->getState());
    }

    public function test_canceled_transaction_fails_a_payment_that_still_uses_a_tpay_method(): void
    {
        $applier = new PaymentStateApplier($this->stateMachine, credentialsResolver: new CredentialsResolver());
        $payment = self::payment(['T1']);
        $payment->setMethod(self::method('TPAY', TpayGateway::FACTORY_NAME));

        self::assertSame(PaymentStateApplierInterface::RESULT_FAILED, $applier->apply($payment, self::transaction('T1', 'canceled')));
        self::assertSame('failed', $payment->getState());
    }

    public function test_completed_payment_never_goes_back(): void
    {
        $payment = self::payment(['T1']);
        $this->applier()->apply($payment, self::transaction('T1', 'correct'));

        foreach (['pending', 'canceled', 'paid'] as $status) {
            self::assertSame(PaymentStateApplierInterface::RESULT_UNCHANGED, $this->applier()->apply($payment, self::transaction('T1', $status)));
        }

        self::assertSame('completed', $payment->getState());
    }

    public function test_full_refund_of_the_completing_transaction_refunds_the_payment(): void
    {
        $payment = self::payment(['T1', 'T2']);
        $this->applier()->apply($payment, self::transaction('T1', 'correct'));

        self::assertSame(PaymentStateApplierInterface::RESULT_UNCHANGED, $this->applier()->apply($payment, self::transaction('T2', 'refund')));
        self::assertSame(PaymentStateApplierInterface::RESULT_REFUNDED, $this->applier()->apply($payment, self::transaction('T1', 'refund')));
        self::assertSame(PaymentStateApplierInterface::RESULT_UNCHANGED, $this->applier()->apply($payment, self::transaction('T1', 'refund')));

        self::assertSame('refunded', $payment->getState());
        self::assertSame('refund', PaymentDetails::of($payment)->getTransactionStatus('T1'));
    }

    public function test_refund_before_completion_is_only_a_warning(): void
    {
        $payment = self::payment(['T1']);

        self::assertSame(PaymentStateApplierInterface::RESULT_WARNING, $this->applier()->apply($payment, self::transaction('T1', 'refund')));

        self::assertSame('new', $payment->getState());
        self::assertSame(PaymentDetails::WARNING_REFUNDED_BEFORE_COMPLETION, PaymentDetails::of($payment)->warnings()[0]['type'] ?? null);
    }

    private function applier(): PaymentStateApplier
    {
        return new PaymentStateApplier($this->stateMachine, $this->logger);
    }

    /**
     * @param list<string> $transactionIds
     */
    private static function payment(array $transactionIds, string $state = PaymentInterface::STATE_NEW): PaymentInterface
    {
        $payment = Payments::payment(state: $state);
        $details = PaymentDetails::of($payment);
        foreach ($transactionIds as $transactionId) {
            $details->addTransaction($transactionId, 'TR-' . $transactionId, 'ext-' . $transactionId, '1010', 1500, 'PLN');
        }
        $details->save();

        return $payment;
    }

    /**
     * @param list<PaymentRequestInterface> $requests
     *
     * @return PaymentRequestRepositoryInterface<PaymentRequestInterface>
     */
    private static function paymentRequests(array $requests): PaymentRequestRepositoryInterface
    {
        $repository = self::createStub(PaymentRequestRepositoryInterface::class);
        $repository->method('findByPaymentIdAndStates')->willReturnCallback(
            static fn (mixed $paymentId, array $states): array => array_values(array_filter($requests, static fn (PaymentRequestInterface $request): bool => in_array($request->getState(), $states, true))),
        );

        return $repository;
    }

    private static function request(PaymentInterface $payment, PaymentMethod $method, string $action, string $state, string $transactionId): PaymentRequestInterface
    {
        $request = new PaymentRequest($payment, $method);
        $request->setAction($action);
        $request->setState($state);
        $request->setResponseData(['transaction_id' => $transactionId]);

        return $request;
    }

    private static function method(string $code, string $factoryName): PaymentMethod
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName($factoryName);

        $method = new PaymentMethod();
        $method->setCode($code);
        $method->setGatewayConfig($gatewayConfig);

        return $method;
    }

    private static function transaction(string $transactionId, string $status): TpayTransaction
    {
        return new TpayTransaction($transactionId, 'TR-' . $transactionId, $status, 1500, 'PLN', 'ext-' . $transactionId);
    }
}
