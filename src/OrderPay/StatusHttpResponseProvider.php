<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\OrderPay;

use Refberg\SyliusTpayPlugin\Api\Model\TpayTransaction;
use Refberg\SyliusTpayPlugin\Order\TransactionRequestFactory;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\FinalUrlProviderInterface;
use Sylius\Bundle\PaymentBundle\Provider\HttpResponseProviderInterface;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfiguration;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * After returning from Tpay through the "success" URL while the transaction is still pending
 * (e.g. a bank transfer not booked yet): show the thank-you page instead of offering to pay again.
 * The payment itself stays unpaid until Tpay confirms it; the query parameter only picks the page.
 *
 * A customer coming back through the "error" URL, and completed or failed payments, get the default Sylius flow.
 */
final readonly class StatusHttpResponseProvider implements HttpResponseProviderInterface
{
    public function __construct(private FinalUrlProviderInterface $finalUrlProvider)
    {
    }

    public function supports(Request|RequestConfiguration $request, PaymentRequestInterface $paymentRequest): bool
    {
        $httpRequest = $request instanceof RequestConfiguration ? $request->getRequest() : $request;

        return PaymentRequestInterface::STATE_COMPLETED === $paymentRequest->getState() &&
            TpayTransaction::STATUS_PENDING === ($paymentRequest->getResponseData()['tpay_status'] ?? null) &&
            TransactionRequestFactory::RETURN_SUCCESS === $httpRequest->query->get(TransactionRequestFactory::RETURN_PARAMETER);
    }

    public function getResponse(Request|RequestConfiguration $request, PaymentRequestInterface $paymentRequest): RedirectResponse
    {
        return new RedirectResponse($this->finalUrlProvider->getUrl(null));
    }
}
