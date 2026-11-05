<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Integration;

use Refberg\SyliusTpayPlugin\Payment\PaymentDetails;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\Response;
use function array_keys;

final class CancelAndExternalRefundTest extends IntegrationTestCase
{
    public function test_cancelling_the_payment_in_sylius_cancels_the_open_tpay_transaction(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);

        self::assertCount(1, $this->tpay->requests('POST', '#^/transactions/' . $transactionId . '/cancel$#'));
        self::assertSame('canceled', $this->tpay->transaction($transactionId)['status']);
        self::assertSame('cancelled', $this->reloadPayment($payment)->getState());
        self::assertSame('canceled', $this->details($payment)->getTransactionStatus($transactionId));

        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_CANCEL);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $requests[0]->getState());
    }

    public function test_cancel_request_for_a_payment_that_was_not_cancelled_in_sylius_keeps_the_transaction_open(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();

        $this->dispatchPaymentRequest($payment, PaymentRequestInterface::ACTION_CANCEL);

        self::assertSame([], $this->tpay->requests('POST', '#/cancel$#'));
        self::assertSame('pending', $this->tpay->transaction($transactionId)['status']);
        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_CANCEL);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $requests[0]->getState());
        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_payment_completed_before_the_cancel_reached_tpay_is_flagged(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();
        // Paid at Tpay, notification not delivered yet.
        $this->tpay->setTransactionStatus($transactionId, 'correct');

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);

        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_CANCEL);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $requests[0]->getState());

        $this->notifyTransaction($transactionId);
        $payment = $this->reloadPayment($payment);
        self::assertSame('cancelled', $payment->getState());
        self::assertSame(PaymentDetails::WARNING_LATE_PAYMENT, PaymentDetails::of($payment)->warnings()[0]['type'] ?? null);
    }

    public function test_full_refund_made_in_tpay_panel_marks_the_payment_refunded(): void
    {
        $payment = $this->paidPayment();
        $transactionId = $this->tpay->lastTransactionId();
        $refundId = $this->tpay->createPanelRefund($transactionId, status: 'new');

        self::assertSame(Response::HTTP_OK, $this->notifyTransaction($transactionId, 'CHARGEBACK')->getStatusCode());
        // Duplicate delivery.
        self::assertSame(Response::HTTP_OK, $this->notifyTransaction($transactionId, 'CHARGEBACK')->getStatusCode());

        $payment = $this->reloadPayment($payment);
        self::assertSame('refunded', $payment->getState());
        $refunds = PaymentDetails::of($payment)->refunds();
        self::assertSame(['tpay-' . $refundId], array_keys($refunds));
        self::assertTrue($refunds['tpay-' . $refundId]['external']);

        // The refund request the "refund" transition creates sees the panel refund and does not refund again.
        self::assertSame([], $this->tpay->requests('POST', '#/refunds$#'));
        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $requests[0]->getState());
        self::assertTrue(self::at($requests[0]->getResponseData(), 'covered_by_tpay_panel_refunds'));
    }

    public function test_partial_refunds_made_in_tpay_panel_do_not_change_the_payment(): void
    {
        $payment = $this->paidPayment();
        $transactionId = $this->tpay->lastTransactionId();
        $this->tpay->createPanelRefund($transactionId, 700);
        $this->tpay->createPanelRefund($transactionId, 800);

        // Even if a notification arrives, the transaction is not in the "refund" status.
        self::assertSame(Response::HTTP_OK, $this->notifyTransaction($transactionId, 'CHARGEBACK')->getStatusCode());

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame(1500, PaymentDetails::of($payment)->finalizedRefundAmount());
        self::assertSame([], $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND));
    }

    public function test_chargeback_for_a_transaction_never_seen_paid_is_only_flagged(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();
        $this->tpay->setTransactionStatus($transactionId, 'correct');
        $this->tpay->createPanelRefund($transactionId);

        self::assertSame(Response::HTTP_OK, $this->notifyTransaction($transactionId, 'CHARGEBACK')->getStatusCode());

        $payment = $this->reloadPayment($payment);
        self::assertSame('new', $payment->getState());
        self::assertSame(
            [['type' => PaymentDetails::WARNING_REFUNDED_BEFORE_COMPLETION, 'transaction_id' => $transactionId]],
            PaymentDetails::of($payment)->warnings(),
        );
    }

    private function paidPayment(): PaymentInterface
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();
        $this->tpay->setTransactionStatus($transactionId, 'correct');
        $this->notifyTransaction($transactionId);

        return $this->reloadPayment($payment);
    }

    private function applyTransition(PaymentInterface $payment, string $transition): void
    {
        $payment = $this->reloadPayment($payment);
        $stateMachine = self::service(StateMachineInterface::class, 'sylius_abstraction.state_machine');
        $stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);
        $this->em()->flush();
    }
}
