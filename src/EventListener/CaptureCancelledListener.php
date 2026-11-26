<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\EventListener;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Refberg\SyliusTpayPlugin\Api\Exception\TpayApiException;
use Refberg\SyliusTpayPlugin\Api\Exception\TpayException;
use Refberg\SyliusTpayPlugin\Api\Model\TpayTransaction;
use Refberg\SyliusTpayPlugin\Api\TpayClientInterface;
use Refberg\SyliusTpayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusTpayPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusTpayPlugin\Payment\PaymentDetails;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use function in_array;
use function is_string;

/**
 * Asks Tpay to cancel the transaction of a capture request that Sylius cancelled (e.g. the customer switched
 * to another payment method), so the customer cannot pay it any more.
 *
 * It runs inside a Doctrine flush, so it writes nothing: the transaction state is changed only by a notification
 * or a status check.
 */
final readonly class CaptureCancelledListener
{
    public function __construct(
        private CredentialsResolverInterface $credentialsResolver,
        private TpayClientInterface $client,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $paymentRequest = $event->getSubject();
        if (
            !$paymentRequest instanceof PaymentRequestInterface ||
            !in_array($paymentRequest->getAction(), [PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::ACTION_AUTHORIZE], true)
        ) {
            return;
        }

        $transactionId = $paymentRequest->getResponseData()['transaction_id'] ?? null;
        $payment = $paymentRequest->getPayment();
        if (
            !is_string($transactionId) ||
            TpayTransaction::STATUS_PENDING !== PaymentDetails::of($payment)->getTransactionStatus($transactionId) ||
            !$this->credentialsResolver->supports($paymentRequest->getMethod())
        ) {
            return;
        }

        try {
            $this->client->cancelTransaction($this->credentialsResolver->resolve($paymentRequest->getMethod()), $transactionId);
        } catch (TpayException|InvalidConfigurationException $exception) {
            // A refusal usually means the transaction was paid, or was already canceled or expired, so it needs a manual check.
            $refused = $exception instanceof TpayApiException && !$exception->isTransient() && !$exception->isAuthorizationError();
            $this->logger->log($refused ? LogLevel::CRITICAL : LogLevel::WARNING, 'Could not cancel the Tpay transaction of a cancelled payment request; it may have been paid, canceled or expired, check it in the Tpay panel.', [
                'payment_id' => $payment->getId(),
                'tpay_transaction_id' => $transactionId,
                'reason' => $exception->getMessage(),
            ]);

            return;
        }

        $this->logger->info('Requested cancellation of the Tpay transaction of a cancelled payment request.', [
            'payment_id' => $payment->getId(),
            'tpay_transaction_id' => $transactionId,
        ]);
    }
}
