<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusTpayPlugin\Api\Exception\TpayApiException;
use Refberg\SyliusTpayPlugin\Command\NotifyPaymentRequest;
use Refberg\SyliusTpayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusTpayPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusTpayPlugin\Notification\Notification;
use Refberg\SyliusTpayPlugin\Notification\NotificationParserInterface;
use Refberg\SyliusTpayPlugin\Payment\PaymentDetails;
use Refberg\SyliusTpayPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusTpayPlugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusTpayPlugin\Payment\PaymentSynchronizerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;

/**
 * Handles a Tpay notification stored by Sylius' notify endpoint.
 *
 * The signatures are verified again here (the notify payment provider already rejected bad ones),
 * then the transaction state is read from the Tpay API, so a stale or replayed notification
 * cannot move the payment backwards.
 *
 * Transient failures (Tpay API, certificate download) and rejected plugin credentials are rethrown:
 * the endpoint answers 5xx and Tpay retries. Permanent problems fail the payment request; the endpoint
 * then still answers "TRUE", so Tpay stops resending a notification that can never be processed.
 *
 * @internal
 */
final readonly class NotifyPaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsResolverInterface $credentialsResolver,
        private NotificationParserInterface $notificationParser,
        private PaymentSynchronizerInterface $synchronizer,
        private PaymentLockerInterface $paymentLocker,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(NotifyPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        $payment = self::corePayment($paymentRequest);

        try {
            $method = $paymentRequest->getMethod();
            $credentials = $this->credentialsResolver->resolve($method);
        } catch (InvalidConfigurationException $exception) {
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $notification = $this->notificationParser->parseStoredPayload($paymentRequest->getPayload(), $credentials);
        if (!$notification instanceof Notification) {
            $this->logger->warning('Rejected Tpay notification with an invalid signature or body.', ['payment_id' => $payment->getId()]);
            $this->failRequest($paymentRequest, 'Invalid notification signature or body.');

            return;
        }

        $this->paymentLocker->lock($payment);
        $details = PaymentDetails::of($payment);

        $transactionId = $details->findTransactionIdByTitle($notification->title);
        if (null === $transactionId) {
            $this->failRequest($paymentRequest, 'Tpay transaction is not linked to this payment.');

            return;
        }

        try {
            if (Notification::TYPE_REFUND === $notification->type) {
                // A full refund (from Sylius or the Tpay panel): record the refunds before the payment may be refunded.
                $this->synchronizer->synchronizeRefunds($payment, $transactionId, $credentials);
            }

            $result = $this->synchronizer->synchronize($payment, $transactionId, $credentials);
        } catch (TpayApiException $exception) {
            // Rejected credentials are fixed by the merchant, so Tpay should keep retrying until then.
            if ($exception->isTransient() || $exception->isAuthorizationError()) {
                throw $exception;
            }

            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        if (PaymentStateApplierInterface::RESULT_COMPLETED === $result) {
            $this->restorePaidMethod($payment, $method, $transactionId);
        }

        $this->completeRequest($paymentRequest, [
            'type' => $notification->type,
            'transaction_id' => $transactionId,
            'title' => $notification->title,
            'result' => $result,
            'payment_state' => $payment->getState(),
        ]);
    }
}
