<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Unit\Payment;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusTpayPlugin\Api\Model\TpayRefund;
use Refberg\SyliusTpayPlugin\Payment\PaymentDetails;
use Tests\Refberg\SyliusTpayPlugin\Support\Payments;

final class PaymentDetailsTest extends TestCase
{
    public function test_transactions_are_stored_under_the_plugin_key_and_found_by_title(): void
    {
        $payment = Payments::payment();
        $payment->setDetails(['other' => 'kept']);
        $details = PaymentDetails::of($payment);
        $details->addTransaction('T1', 'TR-1', 'ext-1', '1010', 1500, 'PLN');
        $details->addTransaction('T2', 'TR-2', 'ext-2', '1010', 1500, 'PLN');
        $details->save();

        $details = PaymentDetails::of($payment);
        self::assertSame('kept', $payment->getDetails()['other']);
        self::assertTrue($details->hasTransaction('T1'));
        self::assertFalse($details->hasTransaction('TR-1'));
        self::assertSame('T2', $details->findTransactionIdByTitle('TR-2'));
        self::assertNull($details->findTransactionIdByTitle('TR-3'));
        self::assertSame(['TR-1', 'ext-1', '1010', 'pending'], [$details->getTitle('T1'), $details->getExtOrderId('T1'), $details->getMerchantId('T1'), $details->getTransactionStatus('T1')]);
        self::assertSame('T2', $details->getCurrentTransactionId());
        self::assertSame(['T2', 'T1'], $details->getOpenTransactionIds());
    }

    public function test_transaction_status_never_goes_back(): void
    {
        $details = PaymentDetails::of(Payments::payment());
        $details->addTransaction('T1', 'TR-1', 'ext-1', '1010', 1500, 'PLN');

        $details->recordTransactionStatus('T1', 'paid');
        $details->recordTransactionStatus('T1', 'pending');
        self::assertSame('paid', $details->getTransactionStatus('T1'));

        $details->recordTransactionStatus('T1', 'correct');
        $details->recordTransactionStatus('T1', 'canceled');
        $details->recordTransactionStatus('T1', 'paid');
        self::assertSame('correct', $details->getTransactionStatus('T1'));

        $details->recordTransactionStatus('T1', 'refund');
        $details->recordTransactionStatus('T1', 'unknown');
        self::assertSame('refund', $details->getTransactionStatus('T1'));
        self::assertSame([], $details->getOpenTransactionIds());

        $details->recordTransactionStatus('missing', 'paid');
        self::assertFalse($details->hasTransaction('missing'));
    }

    public function test_warnings_are_not_duplicated(): void
    {
        $details = PaymentDetails::of(Payments::payment());
        $details->addWarning(PaymentDetails::WARNING_DUPLICATE_PAYMENT, 'T1');
        $details->addWarning(PaymentDetails::WARNING_DUPLICATE_PAYMENT, 'T1');
        $details->addWarning(PaymentDetails::WARNING_DUPLICATE_PAYMENT, 'T2');

        self::assertCount(2, $details->warnings());
    }

    public function test_sylius_refund_gets_its_tpay_id_and_other_refunds_are_external(): void
    {
        $details = PaymentDetails::of(Payments::payment());
        $details->recordRefund('hash', 1000, 'PLN');
        self::assertTrue($details->hasActiveRefund());

        $canceled = $details->synchronizeRefunds([
            new TpayRefund('rf_panel', 'done', 500, 'PLN'),
            new TpayRefund('rf_sylius', 'new', 1000, 'PLN'),
        ]);

        self::assertSame([], $canceled);
        $refunds = $details->refunds();
        // The panel refund has another amount, so it does not take the Sylius refund's place.
        self::assertSame('rf_sylius', $refunds['hash']['refund_id']);
        self::assertFalse($refunds['hash']['external']);
        self::assertTrue($refunds['tpay-rf_panel']['external']);
        self::assertSame(1500, $details->activeRefundAmount());
        self::assertSame(500, $details->finalizedRefundAmount());
    }

    public function test_refund_statuses_are_updated_until_final_and_cancellations_reported_once(): void
    {
        $details = PaymentDetails::of(Payments::payment());
        $details->recordRefund('hash', 1500, 'PLN');
        $details->synchronizeRefunds([new TpayRefund('rf_1', 'pending', 1500, 'PLN')]);

        self::assertSame(['hash'], $details->synchronizeRefunds([new TpayRefund('rf_1', 'cancel', 1500, 'PLN')]));
        self::assertSame([], $details->synchronizeRefunds([new TpayRefund('rf_1', 'cancel', 1500, 'PLN')]));
        self::assertSame([], $details->synchronizeRefunds([new TpayRefund('rf_1', 'done', 1500, 'PLN')]));

        self::assertSame('cancel', $details->refunds()['hash']['status']);
        self::assertFalse($details->hasActiveRefund());
        self::assertSame(0, $details->activeRefundAmount());
    }

    public function test_external_refunds_never_count_as_a_sylius_refund(): void
    {
        $details = PaymentDetails::of(Payments::payment());
        $details->synchronizeRefunds([new TpayRefund('rf_panel', 'new', 1500, 'PLN')]);
        $details->synchronizeRefunds([new TpayRefund('rf_panel', 'done', 1500, 'PLN')]);

        self::assertFalse($details->hasActiveRefund());
        self::assertCount(1, $details->refunds());
        self::assertSame(1500, $details->finalizedRefundAmount());
    }

    public function test_malformed_stored_data_is_ignored(): void
    {
        $payment = Payments::payment();
        $payment->setDetails(['refberg_tpay' => ['transactions' => 'x', 'refunds' => [1, ['amount' => 'x', 'status' => 'done']], 'warnings' => ['x']]]);
        $details = PaymentDetails::of($payment);

        self::assertSame([], $details->getOpenTransactionIds());
        self::assertSame(0, $details->finalizedRefundAmount());
        self::assertSame([], $details->warnings());

        $payment->setDetails(['refberg_tpay' => 'x']);
        self::assertNull(PaymentDetails::of($payment)->getCurrentTransactionId());
    }
}
