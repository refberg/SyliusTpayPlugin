<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Integration;

use PHPUnit\Framework\Attributes\TestWith;
use Refberg\SyliusTpayPlugin\Payment\PaymentDetails;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\Response;
use function array_values;
use function json_encode;

final class RefundFlowTest extends IntegrationTestCase
{
    public function test_admin_refund_requests_a_single_full_refund_at_tpay(): void
    {
        $payment = $this->paidPayment();
        $transactionId = $this->tpay->lastTransactionId();

        $this->refundInAdmin($payment);

        $refundCalls = $this->tpay->requests('POST', '#/refunds$#');
        self::assertCount(1, $refundCalls);
        self::assertSame('/transactions/' . $transactionId . '/refunds', $refundCalls[0]['path']);
        // An empty body refunds the whole transaction.
        self::assertSame('{}', $refundCalls[0]['body']);
        self::assertSame('refund', $this->tpay->transaction($transactionId)['status']);

        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $requests[0]->getState());

        $payment = $this->reloadPayment($payment);
        self::assertSame('refunded', $payment->getState());
        $refunds = PaymentDetails::of($payment)->refunds();
        self::assertCount(1, $refunds);
        $refund = $refunds[(string) $requests[0]->getId()] ?? null;
        self::assertIsArray($refund);
        self::assertSame($this->tpay->refunds($transactionId)[0]['refundId'], $refund['refund_id']);
        self::assertSame(['new', 1500, false], [$refund['status'], $refund['amount'], $refund['external']]);
    }

    public function test_chargeback_notification_records_the_final_refund_status(): void
    {
        $payment = $this->paidPayment();
        $transactionId = $this->tpay->lastTransactionId();
        $this->refundInAdmin($payment);
        $this->tpay->setRefundStatus(self::string($this->tpay->refunds($transactionId)[0]['refundId']), 'done');

        // Duplicate delivery included.
        self::assertSame(Response::HTTP_OK, $this->notifyTransaction($transactionId, 'CHARGEBACK')->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->notifyTransaction($transactionId, 'CHARGEBACK')->getStatusCode());

        $details = $this->details($payment);
        self::assertCount(1, $details->refunds());
        self::assertSame('done', array_values($details->refunds())[0]['status']);
        self::assertSame(1500, $details->finalizedRefundAmount());
        self::assertSame('refunded', $this->reloadPayment($payment)->getState());
        self::assertCount(1, $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND));
    }

    public function test_refund_canceled_by_tpay_is_flagged_for_manual_handling(): void
    {
        $payment = $this->paidPayment();
        $transactionId = $this->tpay->lastTransactionId();
        $this->refundInAdmin($payment);
        $this->tpay->setRefundStatus(self::string($this->tpay->refunds($transactionId)[0]['refundId']), 'cancel');

        self::assertSame(Response::HTTP_OK, $this->notifyTransaction($transactionId, 'CHARGEBACK')->getStatusCode());

        $payment = $this->reloadPayment($payment);
        // Sylius has no way back from "refunded": the merchant refunds manually.
        self::assertSame('refunded', $payment->getState());
        $details = PaymentDetails::of($payment);
        self::assertSame('cancel', array_values($details->refunds())[0]['status']);
        self::assertFalse($details->hasActiveRefund());
        self::assertSame([['type' => PaymentDetails::WARNING_REFUND_CANCELED, 'transaction_id' => $transactionId]], $details->warnings());
    }

    #[TestWith([400, 'refund_not_allowed'])]
    #[TestWith([503, 'internal_error'])]
    public function test_failed_refund_call_is_recorded_on_the_payment_request(int $httpStatus, string $errorCode): void
    {
        $payment = $this->paidPayment();
        // The refund list is read first; the refund call itself fails.
        $this->tpay->failNextRequest($httpStatus, $errorCode, 'POST');

        $this->refundInAdmin($payment);

        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $requests[0]->getState());
        self::assertStringContainsString($errorCode, (string) json_encode($requests[0]->getResponseData()));
        self::assertSame([], $this->details($payment)->refunds());
        self::assertSame('refunded', $this->reloadPayment($payment)->getState());
        self::assertCount(1, $this->tpay->requests('POST', '#/refunds$#'));
        self::assertSame([], $this->tpay->refunds());
    }

    public function test_refund_request_for_a_payment_that_was_not_refunded_in_sylius_never_reaches_tpay(): void
    {
        $payment = $this->paidPayment();

        $this->dispatchPaymentRequest($payment, PaymentRequestInterface::ACTION_REFUND);

        self::assertSame([], $this->tpay->requests(null, '#/refunds#'));
        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $requests[0]->getState());
        self::assertSame(['error' => 'Payment in state "completed" cannot be refunded.'], $requests[0]->getResponseData());
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    public function test_admin_refund_after_a_partial_panel_refund_requests_only_the_remaining_amount(): void
    {
        $payment = $this->paidPayment();
        $transactionId = $this->tpay->lastTransactionId();
        // Partial refunds made in the Tpay panel send no notification.
        $this->tpay->createPanelRefund($transactionId, 500);

        $this->refundInAdmin($payment);

        $refundCalls = $this->tpay->requests('POST', '#/refunds$#');
        self::assertCount(1, $refundCalls);
        self::assertSame('{"amount":10.0}', $refundCalls[0]['body']);

        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $requests[0]->getState());
        $details = $this->details($payment);
        self::assertCount(2, $details->refunds());
        self::assertSame(1500, $details->activeRefundAmount());
        self::assertTrue($details->hasActiveRefund());
    }

    public function test_admin_refund_after_panel_refunds_covering_the_payment_does_not_call_tpay(): void
    {
        $payment = $this->paidPayment();
        $transactionId = $this->tpay->lastTransactionId();
        $this->tpay->createPanelRefund($transactionId, 1000, 'pending');
        $this->tpay->createPanelRefund($transactionId, 500, 'new');

        $this->refundInAdmin($payment);

        self::assertSame([], $this->tpay->requests('POST', '#/refunds$#'));
        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $requests[0]->getState());
        self::assertTrue(self::at($requests[0]->getResponseData(), 'covered_by_tpay_panel_refunds'));
    }

    public function test_admin_refund_ignores_canceled_panel_refunds(): void
    {
        $payment = $this->paidPayment();
        $transactionId = $this->tpay->lastTransactionId();
        $this->tpay->createPanelRefund($transactionId, 1500, 'cancel');

        $this->refundInAdmin($payment);

        $refundCalls = $this->tpay->requests('POST', '#/refunds$#');
        self::assertCount(1, $refundCalls);
        self::assertSame('{}', $refundCalls[0]['body']);
    }

    private function paidPayment(): PaymentInterface
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();
        $this->tpay->setTransactionStatus($transactionId, 'correct');
        $this->notifyTransaction($transactionId);

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());

        return $payment;
    }

    /**
     * Same transition the admin "Refund" button applies.
     */
    private function refundInAdmin(PaymentInterface $payment): void
    {
        $payment = $this->reloadPayment($payment);
        $stateMachine = self::service(StateMachineInterface::class, 'sylius_abstraction.state_machine');

        $stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        $this->em()->flush();
    }
}
