<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusTpayPlugin\Api\Exception\TpayException;
use Refberg\SyliusTpayPlugin\Command\StatusPaymentRequest;
use Refberg\SyliusTpayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusTpayPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusTpayPlugin\Payment\PaymentDetails;
use Refberg\SyliusTpayPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusTpayPlugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusTpayPlugin\Payment\PaymentSynchronizerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use function array_slice;

/**
 * Runs when the customer comes back from Tpay (after-pay) or when an API client asks for the status.
 *
 * The state is taken from GET /transactions/{transactionId}; query parameters of the return URL are ignored.
 *
 * @internal
 */
final readonly class StatusPaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    private const MAX_CHECKED_TRANSACTIONS = 3;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsResolverInterface $credentialsResolver,
        private PaymentSynchronizerInterface $synchronizer,
        private PaymentLockerInterface $paymentLocker,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(StatusPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        $payment = self::corePayment($paymentRequest);

        try {
            $method = $paymentRequest->getMethod();
            $credentials = $this->credentialsResolver->resolve($method);
            $this->paymentLocker->lock($payment);

            $details = PaymentDetails::of($payment);
            foreach (array_slice($details->getOpenTransactionIds(), 0, self::MAX_CHECKED_TRANSACTIONS) as $transactionId) {
                if (PaymentStateApplierInterface::RESULT_COMPLETED === $this->synchronizer->synchronize($payment, $transactionId, $credentials)) {
                    $this->restorePaidMethod($payment, $method, $transactionId);
                }
            }
        } catch (TpayException|InvalidConfigurationException $exception) {
            $this->logger->warning('Could not check Tpay transaction status.', [
                'payment_id' => $payment->getId(),
                'reason' => $exception->getMessage(),
            ]);
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $details = PaymentDetails::of($payment);
        $currentTransactionId = $details->getCurrentTransactionId();

        $this->completeRequest($paymentRequest, [
            'payment_state' => $payment->getState(),
            'transaction_id' => $currentTransactionId,
            'tpay_status' => null !== $currentTransactionId ? $details->getTransactionStatus($currentTransactionId) : null,
        ]);
    }
}
