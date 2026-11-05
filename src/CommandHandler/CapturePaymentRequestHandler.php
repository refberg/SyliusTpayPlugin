<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\CommandHandler;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusTpayPlugin\Api\Exception\TpayException;
use Refberg\SyliusTpayPlugin\Api\TpayClientInterface;
use Refberg\SyliusTpayPlugin\Command\CapturePaymentRequest;
use Refberg\SyliusTpayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusTpayPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusTpayPlugin\Order\ExtOrderIdGeneratorInterface;
use Refberg\SyliusTpayPlugin\Order\TransactionRequestFactoryInterface;
use Refberg\SyliusTpayPlugin\Payment\PaymentDetails;
use Refberg\SyliusTpayPlugin\Payment\PaymentLockerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use function sprintf;

/**
 * Creates a Tpay transaction and stores the payment page URL in the payment request response data.
 *
 * It never completes the payment: only a verified notification or a direct API query can.
 *
 * @internal
 */
final readonly class CapturePaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsResolverInterface $credentialsResolver,
        private TpayClientInterface $client,
        private TransactionRequestFactoryInterface $transactionRequestFactory,
        private ExtOrderIdGeneratorInterface $extOrderIdGenerator,
        private PaymentLockerInterface $paymentLocker,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(CapturePaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);

        // A processing request already has a Tpay transaction: re-sending the customer there is enough.
        if (PaymentRequestInterface::STATE_NEW !== $paymentRequest->getState()) {
            return;
        }

        $payment = self::corePayment($paymentRequest);

        try {
            $credentials = $this->credentialsResolver->resolve($paymentRequest->getMethod());

            // Checked under the lock: a concurrent notification may have just completed the payment.
            $this->paymentLocker->lock($payment);
            if (PaymentInterface::STATE_NEW !== $payment->getState()) {
                $this->failRequest($paymentRequest, sprintf('Payment in state "%s" cannot be paid.', (string) $payment->getState()));

                return;
            }

            $extOrderId = $this->extOrderIdGenerator->generate($payment, $paymentRequest);
            $transactionRequest = $this->transactionRequestFactory->create($paymentRequest, $payment, $credentials, $extOrderId);
            $created = $this->client->createTransaction($credentials, $transactionRequest);
        } catch (TpayException|InvalidConfigurationException|InvalidArgumentException $exception) {
            $this->logger->warning('Could not create Tpay transaction.', [
                'payment_id' => $payment->getId(),
                'reason' => $exception->getMessage(),
            ]);
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $details = PaymentDetails::of($payment);
        $details->addTransaction($created->transactionId, $created->title, $extOrderId, $credentials->merchantId, (int) $payment->getAmount(), (string) $payment->getCurrencyCode());
        $details->save();

        $paymentRequest->setResponseData([
            'redirect_url' => $created->paymentUrl,
            'transaction_id' => $created->transactionId,
            'title' => $created->title,
            'ext_order_id' => $extOrderId,
        ]);
        $this->transitionRequest($paymentRequest, PaymentRequestTransitions::TRANSITION_PROCESS);
    }
}
