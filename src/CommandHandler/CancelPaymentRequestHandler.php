<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusTpayPlugin\Api\Exception\TpayException;
use Refberg\SyliusTpayPlugin\Api\Model\TpayTransaction;
use Refberg\SyliusTpayPlugin\Api\TpayClientInterface;
use Refberg\SyliusTpayPlugin\Command\CancelPaymentRequest;
use Refberg\SyliusTpayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusTpayPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusTpayPlugin\Payment\PaymentDetails;
use Refberg\SyliusTpayPlugin\Payment\PaymentLockerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use function implode;
use function sprintf;

/**
 * Cancels every Tpay transaction of the payment that may still be paid, after the payment
 * was cancelled in Sylius (e.g. by an administrator). Without it the customer could still
 * pay a cancelled order.
 *
 * @internal
 */
final readonly class CancelPaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsResolverInterface $credentialsResolver,
        private TpayClientInterface $client,
        private PaymentLockerInterface $paymentLocker,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(CancelPaymentRequest $command): void
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
        if (PaymentInterface::STATE_CANCELLED !== $payment->getState()) {
            $this->failRequest($paymentRequest, sprintf('Payment in state "%s" cannot be cancelled.', (string) $payment->getState()));

            return;
        }

        $details = PaymentDetails::of($payment);

        $canceled = [];
        $errors = [];
        foreach ($details->getOpenTransactionIds() as $transactionId) {
            try {
                $this->client->cancelTransaction($credentials, $transactionId);
                $details->recordTransactionStatus($transactionId, TpayTransaction::STATUS_CANCELED);
                $canceled[] = $transactionId;
            } catch (TpayException $exception) {
                $errors[] = $exception->getMessage();
                $this->logger->error('Could not cancel Tpay transaction; check it in the Tpay panel.', [
                    'payment_id' => $payment->getId(),
                    'tpay_transaction_id' => $transactionId,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
        $details->save();

        if ([] !== $errors) {
            $this->failRequest($paymentRequest, implode(' ', $errors));

            return;
        }

        $this->completeRequest($paymentRequest, ['canceled_transaction_ids' => $canceled]);
    }
}
