<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\CommandHandler;

use LogicException;
use Psr\Log\LoggerInterface;
use Refberg\SyliusTpayPlugin\Api\Model\TpayTransaction;
use Refberg\SyliusTpayPlugin\Payment\PaymentDetails;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use function in_array;
use function is_string;

/**
 * @internal
 *
 * @property StateMachineInterface $stateMachine
 * @property LoggerInterface $logger
 */
trait PaymentRequestHandlerTrait
{
    /**
     * @param array<string, mixed> $responseData
     */
    private function completeRequest(PaymentRequestInterface $paymentRequest, array $responseData): void
    {
        $paymentRequest->setResponseData($responseData);
        $this->transitionRequest($paymentRequest, PaymentRequestTransitions::TRANSITION_COMPLETE);
    }

    private function failRequest(PaymentRequestInterface $paymentRequest, string $reason): void
    {
        $paymentRequest->setResponseData(['error' => $reason]);
        $this->transitionRequest($paymentRequest, PaymentRequestTransitions::TRANSITION_FAIL);
    }

    private function transitionRequest(PaymentRequestInterface $paymentRequest, string $transition): void
    {
        if ($this->stateMachine->can($paymentRequest, PaymentRequestTransitions::GRAPH, $transition)) {
            $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, $transition);
        }
    }

    /**
     * Sylius reuses a capture request that is not final, so a finished Tpay transaction would send the customer back to a dead payment page.
     *
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface>|null $paymentRequestRepository
     * @param string|null $transactionId null closes the capture requests of every Tpay transaction with a final status
     */
    private function closeCaptureRequests(?PaymentRequestRepositoryInterface $paymentRequestRepository, PaymentInterface $payment, ?string $transactionId = null): void
    {
        if (!$paymentRequestRepository instanceof PaymentRequestRepositoryInterface) {
            return;
        }

        $details = PaymentDetails::of($payment);
        foreach ($paymentRequestRepository->findByPaymentIdAndStates($payment->getId(), [PaymentRequestInterface::STATE_PROCESSING]) as $captureRequest) {
            $captureTransactionId = $captureRequest->getResponseData()['transaction_id'] ?? null;
            if (
                !is_string($captureTransactionId) ||
                (null !== $transactionId && $transactionId !== $captureTransactionId) ||
                !in_array($captureRequest->getAction(), [PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::ACTION_AUTHORIZE], true)
            ) {
                continue;
            }

            $status = $details->getTransactionStatus($captureTransactionId);
            $transition = match (true) {
                TpayTransaction::isPaidStatus($status), TpayTransaction::STATUS_REFUND === $status => PaymentRequestTransitions::TRANSITION_COMPLETE,
                TpayTransaction::STATUS_CANCELED === $status => PaymentRequestTransitions::TRANSITION_FAIL,
                default => null,
            };
            if (null !== $transition) {
                $this->transitionRequest($captureRequest, $transition);
            }
        }
    }

    /**
     * The customer switched to another payment method after being redirected to Tpay, then paid the old
     * Tpay transaction anyway. Refunds from Sylius use the payment's method, so it must be the Tpay method that was paid.
     */
    private function restorePaidMethod(PaymentInterface $payment, PaymentMethodInterface $method, string $transactionId): void
    {
        if ($payment->getMethod() === $method) {
            return;
        }

        $previousMethod = $payment->getMethod();
        $payment->setMethod($method);

        // The applier saved its own details; a fresh read keeps completed_transaction_id.
        $details = PaymentDetails::of($payment);
        $details->addWarning(PaymentDetails::WARNING_PAYMENT_METHOD_CHANGED, $transactionId);
        $details->save();
        $this->logger->warning('Tpay paid the payment after the customer switched to another payment method; the Tpay method was restored.', [
            'payment_id' => $payment->getId(),
            'tpay_transaction_id' => $transactionId,
            'switched_to_method' => $previousMethod?->getCode(),
        ]);
    }

    private static function corePayment(PaymentRequestInterface $paymentRequest): PaymentInterface
    {
        $payment = $paymentRequest->getPayment();
        if (!$payment instanceof PaymentInterface) {
            throw new LogicException('Tpay payment requests require a Sylius Core payment.');
        }

        return $payment;
    }
}
