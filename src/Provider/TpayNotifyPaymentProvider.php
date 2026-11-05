<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Provider;

use Refberg\SyliusTpayPlugin\Api\Exception\TpayTransportException;
use Refberg\SyliusTpayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusTpayPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusTpayPlugin\Notification\Notification;
use Refberg\SyliusTpayPlugin\Notification\NotificationParserInterface;
use Refberg\SyliusTpayPlugin\Order\ExtOrderIdGeneratorInterface;
use Refberg\SyliusTpayPlugin\Payment\PaymentDetails;
use Sylius\Bundle\PaymentBundle\Provider\NotifyPaymentProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Finds the payment a Tpay notification belongs to.
 *
 * Runs before Sylius stores anything, so notifications with a missing or wrong signature
 * are rejected with HTTP 400 and never create a payment request.
 * A notification of an unknown transaction gets HTTP 404, which stops Tpay from resending it.
 */
final readonly class TpayNotifyPaymentProvider implements NotifyPaymentProviderInterface
{
    /**
     * @param RepositoryInterface<PaymentInterface> $paymentRepository
     */
    public function __construct(
        private CredentialsResolverInterface $credentialsResolver,
        private NotificationParserInterface $notificationParser,
        private ExtOrderIdGeneratorInterface $extOrderIdGenerator,
        private RepositoryInterface $paymentRepository,
    ) {
    }

    public function supports(Request $request, PaymentMethodInterface $paymentMethod): bool
    {
        return $this->credentialsResolver->supports($paymentMethod);
    }

    public function getPayment(Request $request, PaymentMethodInterface $paymentMethod): PaymentInterface
    {
        try {
            $credentials = $this->credentialsResolver->resolve($paymentMethod);
            $notification = $this->notificationParser->parse($request->getContent(), $request->headers->all(), $credentials);
        } catch (InvalidConfigurationException) {
            throw new ServiceUnavailableHttpException(null, 'Tpay payment method is not configured.');
        } catch (TpayTransportException) {
            throw new ServiceUnavailableHttpException(null, 'Tpay notification certificate is unavailable.');
        }

        if (!$notification instanceof Notification) {
            throw new BadRequestHttpException('Invalid Tpay notification signature or body.');
        }

        $paymentId = $this->extOrderIdGenerator->extractPaymentId($notification->crc);
        $payment = null !== $paymentId ? $this->paymentRepository->find($paymentId) : null;
        if (!$payment instanceof PaymentInterface || null === PaymentDetails::of($payment)->findTransactionIdByTitle($notification->title)) {
            throw new NotFoundHttpException('No payment matches this Tpay notification.');
        }

        return $payment;
    }
}
