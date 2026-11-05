<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Payment;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusTpayPlugin\Api\TpayClientInterface;
use Refberg\SyliusTpayPlugin\Config\TpayCredentials;
use Refberg\SyliusTpayPlugin\Order\TpayTransactionValidatorInterface;
use Sylius\Component\Payment\Model\PaymentInterface;

final readonly class PaymentSynchronizer implements PaymentSynchronizerInterface
{
    public function __construct(
        private TpayClientInterface $client,
        private TpayTransactionValidatorInterface $validator,
        private PaymentStateApplierInterface $stateApplier,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function synchronize(PaymentInterface $payment, string $transactionId, TpayCredentials $credentials): string
    {
        $transaction = $this->client->getTransaction($credentials, $transactionId);

        $errors = $this->validator->validate($transaction, $payment, $credentials);
        if ([] !== $errors) {
            $this->logger->error('Tpay transaction does not match the Sylius payment; state not changed.', [
                'payment_id' => $payment->getId(),
                'tpay_transaction_id' => $transactionId,
                'errors' => $errors,
            ]);

            return self::RESULT_INVALID;
        }

        return $this->stateApplier->apply($payment, $transaction);
    }

    public function synchronizeRefunds(PaymentInterface $payment, string $transactionId, TpayCredentials $credentials): void
    {
        $tpayRefunds = [];
        foreach ($this->client->getRefunds($credentials, $transactionId) as $refund) {
            // Refunds are processed in the transaction currency; anything else does not belong to this payment.
            if ($refund->currency === $payment->getCurrencyCode()) {
                $tpayRefunds[] = $refund;
            }
        }

        $details = PaymentDetails::of($payment);
        foreach ($details->synchronizeRefunds($tpayRefunds) as $key) {
            $details->addWarning(PaymentDetails::WARNING_REFUND_CANCELED, $transactionId);
            $this->logger->error('Tpay canceled a refund; it has to be handled manually.', [
                'payment_id' => $payment->getId(),
                'tpay_transaction_id' => $transactionId,
                'refund' => $key,
            ]);
        }
        $details->save();
    }
}
