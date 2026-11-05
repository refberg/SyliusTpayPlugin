<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Order;

use Refberg\SyliusTpayPlugin\Api\Model\TpayTransaction;
use Refberg\SyliusTpayPlugin\Config\TpayCredentials;
use Refberg\SyliusTpayPlugin\Payment\PaymentDetails;
use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * Checks that a transaction reported by the Tpay API really belongs to the given Sylius payment.
 *
 * The transaction must have been created for the merchant whose credentials are checking it,
 * so another Tpay payment method (another merchant) can never pay this payment.
 */
final readonly class TpayTransactionValidator implements TpayTransactionValidatorInterface
{
    public function validate(TpayTransaction $transaction, PaymentInterface $payment, TpayCredentials $credentials): array
    {
        $details = PaymentDetails::of($payment);
        $errors = [];

        if (!$details->hasTransaction($transaction->transactionId)) {
            $errors[] = 'Tpay transaction is not linked to this payment';
        }

        if ($details->getMerchantId($transaction->transactionId) !== $credentials->merchantId) {
            $errors[] = 'merchant ID does not match';
        }

        if ($details->getTitle($transaction->transactionId) !== $transaction->title) {
            $errors[] = 'title does not match';
        }

        $expectedExtOrderId = $details->getExtOrderId($transaction->transactionId);
        if (null === $expectedExtOrderId || $transaction->hiddenDescription !== $expectedExtOrderId) {
            $errors[] = 'hiddenDescription does not match';
        }

        if ($transaction->amount !== $payment->getAmount()) {
            $errors[] = 'amount does not match';
        }

        if ($transaction->currency !== $payment->getCurrencyCode()) {
            $errors[] = 'currency does not match';
        }

        return $errors;
    }
}
