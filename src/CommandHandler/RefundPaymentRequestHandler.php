<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusTpayPlugin\Api\Exception\TpayException;
use Refberg\SyliusTpayPlugin\Api\TpayClientInterface;
use Refberg\SyliusTpayPlugin\Command\RefundPaymentRequest;
use Refberg\SyliusTpayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusTpayPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusTpayPlugin\Payment\PaymentDetails;
use Refberg\SyliusTpayPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusTpayPlugin\Payment\PaymentSynchronizerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use function sprintf;

/**
 * Refunds the Tpay transaction that paid the payment, less the refunds made outside Sylius.
 *
 * Refunds made in the Tpay panel are read from the API first (partial refunds send no notification).
 * Without earlier refunds the whole transaction is refunded, which Tpay confirms with a "CHARGEBACK"
 * notification; otherwise only the remaining amount is refunded.
 *
 * Tpay has no idempotency key for refunds. The refund is recorded under the payment request hash,
 * and a request that is not "new" any more (redelivered) is skipped.
 *
 * @internal
 */
final readonly class RefundPaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsResolverInterface $credentialsResolver,
        private TpayClientInterface $client,
        private PaymentSynchronizerInterface $synchronizer,
        private PaymentLockerInterface $paymentLocker,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(RefundPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        if (PaymentRequestInterface::STATE_NEW !== $paymentRequest->getState()) {
            return;
        }

        $payment = self::corePayment($paymentRequest);

        try {
            $credentials = $this->credentialsResolver->resolve($paymentRequest->getMethod());
        } catch (InvalidConfigurationException $exception) {
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $this->paymentLocker->lock($payment);
        if (PaymentInterface::STATE_REFUNDED !== $payment->getState()) {
            $this->failRequest($paymentRequest, sprintf('Payment in state "%s" cannot be refunded.', (string) $payment->getState()));

            return;
        }

        $transactionId = PaymentDetails::of($payment)->getCompletedTransactionId();
        if (null === $transactionId) {
            $this->logger->error('Tpay refund not requested: the payment was not completed through Tpay.', ['payment_id' => $payment->getId()]);
            $this->failRequest($paymentRequest, 'No completed Tpay transaction for this payment.');

            return;
        }

        try {
            $this->synchronizer->synchronizeRefunds($payment, $transactionId, $credentials);
            $details = PaymentDetails::of($payment);

            if ($details->hasActiveRefund()) {
                $this->completeRequest($paymentRequest, ['transaction_id' => $transactionId, 'already_requested' => true]);

                return;
            }

            $paymentAmount = (int) $payment->getAmount();
            $amount = $paymentAmount - $details->activeRefundAmount();
            if ($amount <= 0) {
                $this->logger->info('Tpay refund not requested: refunds made outside Sylius already cover the payment.', [
                    'payment_id' => $payment->getId(),
                    'tpay_transaction_id' => $transactionId,
                ]);
                $this->completeRequest($paymentRequest, ['transaction_id' => $transactionId, 'covered_by_tpay_panel_refunds' => true]);

                return;
            }

            $refunds = $this->client->createRefund($credentials, $transactionId, $amount === $paymentAmount ? null : $amount);
        } catch (TpayException $exception) {
            $this->logger->error('Tpay refund request failed; refund has to be handled manually.', [
                'payment_id' => $payment->getId(),
                'tpay_transaction_id' => $transactionId,
                'reason' => $exception->getMessage(),
            ]);
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $key = (string) $paymentRequest->getId();
        $details->recordRefund($key, $amount, (string) $payment->getCurrencyCode());
        $details->synchronizeRefunds($refunds);
        $details->save();

        $refund = $details->refunds()[$key] ?? [];

        $this->completeRequest($paymentRequest, [
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'refund_id' => $refund['refund_id'] ?? null,
            'refund_status' => $refund['status'] ?? null,
        ]);
    }
}
