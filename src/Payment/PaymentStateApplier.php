<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Payment;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusTpayPlugin\Api\Model\TpayTransaction;
use Refberg\SyliusTpayPlugin\Config\CredentialsResolverInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use function in_array;

/**
 * The only place where the plugin changes the state of a Sylius payment.
 *
 * Input must be a Tpay transaction that was fetched from the Tpay API and validated against the payment.
 * Rules are monotonic, so duplicated or out-of-order notifications cannot change the outcome:
 *  - "paid" / "correct" completes the payment once; a second Tpay transaction paying the same payment
 *    is recorded as a warning and never pays the order twice, a completed payment never goes back,
 *  - "refund" (full refund) refunds a payment completed by that transaction,
 *  - "canceled" fails the payment only when no other transaction of this payment may still be paid
 *    and the payment still uses a Tpay method, and never for a transaction whose capture request was cancelled
 *    (the customer switched the payment method),
 *  - "pending" never changes the payment state.
 */
final readonly class PaymentStateApplier implements PaymentStateApplierInterface
{
    /**
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface>|null $paymentRequestRepository
     */
    public function __construct(
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
        private ?CredentialsResolverInterface $credentialsResolver = null,
        private ?PaymentRequestRepositoryInterface $paymentRequestRepository = null,
    ) {
    }

    public function apply(PaymentInterface $payment, TpayTransaction $transaction): string
    {
        $details = PaymentDetails::of($payment);
        $details->recordTransactionStatus($transaction->transactionId, $transaction->status);

        $result = match (true) {
            TpayTransaction::isPaidStatus($transaction->status) => $this->onPaid($payment, $details, $transaction),
            TpayTransaction::STATUS_REFUND === $transaction->status => $this->onRefunded($payment, $details, $transaction),
            TpayTransaction::STATUS_CANCELED === $transaction->status => $this->onCanceled($payment, $details, $transaction),
            default => self::RESULT_UNCHANGED,
        };

        // Saved before a refund transition runs its listeners, which read the details too.
        $details->save();
        if (self::RESULT_REFUNDED === $result) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        }

        return $result;
    }

    private function onPaid(PaymentInterface $payment, PaymentDetails $details, TpayTransaction $transaction): string
    {
        $completedTransactionId = $details->getCompletedTransactionId();
        if ($completedTransactionId === $transaction->transactionId) {
            return self::RESULT_UNCHANGED;
        }

        if (null === $completedTransactionId && $this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
            $details->setCompletedTransactionId($transaction->transactionId);

            $this->logger->info('Tpay payment completed.', ['payment_id' => $payment->getId(), 'tpay_transaction_id' => $transaction->transactionId]);

            return self::RESULT_COMPLETED;
        }

        $warning = null !== $completedTransactionId ? PaymentDetails::WARNING_DUPLICATE_PAYMENT : PaymentDetails::WARNING_LATE_PAYMENT;
        $details->addWarning($warning, $transaction->transactionId);

        $this->logger->critical('Tpay transaction was paid, but the Sylius payment cannot be completed. Manual refund may be needed.', [
            'payment_id' => $payment->getId(),
            'payment_state' => $payment->getState(),
            'tpay_transaction_id' => $transaction->transactionId,
            'reason' => $warning,
        ]);

        return self::RESULT_WARNING;
    }

    private function onRefunded(PaymentInterface $payment, PaymentDetails $details, TpayTransaction $transaction): string
    {
        $completedTransactionId = $details->getCompletedTransactionId();
        if (null === $completedTransactionId) {
            // Paid and fully refunded before Sylius saw the payment: nothing was sold, nothing to complete.
            $details->addWarning(PaymentDetails::WARNING_REFUNDED_BEFORE_COMPLETION, $transaction->transactionId);
            $this->logger->warning('Tpay transaction was refunded before the Sylius payment was completed.', [
                'payment_id' => $payment->getId(),
                'tpay_transaction_id' => $transaction->transactionId,
            ]);

            return self::RESULT_WARNING;
        }

        if (
            $completedTransactionId !== $transaction->transactionId ||
            !$this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND)
        ) {
            return self::RESULT_UNCHANGED;
        }

        $this->logger->info('Tpay transaction was fully refunded; refunding the Sylius payment.', [
            'payment_id' => $payment->getId(),
            'tpay_transaction_id' => $transaction->transactionId,
        ]);

        return self::RESULT_REFUNDED;
    }

    private function onCanceled(PaymentInterface $payment, PaymentDetails $details, TpayTransaction $transaction): string
    {
        if (null !== $details->getCompletedTransactionId() || $details->hasOpenTransactions()) {
            return self::RESULT_UNCHANGED;
        }

        if (!$this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_FAIL)) {
            return self::RESULT_UNCHANGED;
        }

        $method = $payment->getMethod();
        if (
            ($this->credentialsResolver instanceof CredentialsResolverInterface && $method instanceof PaymentMethodInterface && !$this->credentialsResolver->supports($method)) ||
            $this->isCaptureCancelled($payment, $transaction->transactionId)
        ) {
            $this->logger->info('Tpay transaction was canceled after the customer switched to another payment method; payment state not changed.', ['payment_id' => $payment->getId(), 'payment_method' => $method?->getCode(), 'tpay_transaction_id' => $transaction->transactionId]);

            return self::RESULT_UNCHANGED;
        }

        $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_FAIL);

        $this->logger->info('Tpay payment failed.', ['payment_id' => $payment->getId()]);

        return self::RESULT_FAILED;
    }

    // Sylius cancels the capture request when the customer switches the payment method, and the plugin then cancels its transaction.
    private function isCaptureCancelled(PaymentInterface $payment, string $transactionId): bool
    {
        if (!$this->paymentRequestRepository instanceof PaymentRequestRepositoryInterface) {
            return false;
        }

        foreach ($this->paymentRequestRepository->findByPaymentIdAndStates($payment->getId(), [PaymentRequestInterface::STATE_CANCELLED]) as $paymentRequest) {
            if (
                in_array($paymentRequest->getAction(), [PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::ACTION_AUTHORIZE], true) &&
                $transactionId === ($paymentRequest->getResponseData()['transaction_id'] ?? null)
            ) {
                return true;
            }
        }

        return false;
    }
}
