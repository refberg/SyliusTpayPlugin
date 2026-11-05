<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Provider;

use Refberg\SyliusTpayPlugin\Config\CredentialsResolverInterface;
use Sylius\Bundle\PaymentBundle\Provider\NotifyResponseProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tpay expects HTTP 200 with the body "TRUE" for an accepted notification and resends it otherwise.
 * Other gateways keep the core response.
 */
final readonly class NotifyResponseProvider implements NotifyResponseProviderInterface
{
    public const ACCEPTED_BODY = 'TRUE';

    public function __construct(
        private NotifyResponseProviderInterface $decorated,
        private CredentialsResolverInterface $credentialsResolver,
    ) {
    }

    public function provide(PaymentRequestInterface $paymentRequest): Response
    {
        if (!$this->credentialsResolver->supports($paymentRequest->getMethod())) {
            return $this->decorated->provide($paymentRequest);
        }

        return new Response(self::ACCEPTED_BODY, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
