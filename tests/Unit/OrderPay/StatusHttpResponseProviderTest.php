<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Unit\OrderPay;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusTpayPlugin\Api\Model\TpayTransaction;
use Refberg\SyliusTpayPlugin\OrderPay\StatusHttpResponseProvider;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\FinalUrlProviderInterface;
use Sylius\Bundle\ResourceBundle\Controller\Parameters;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfiguration;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Resource\Metadata\MetadataInterface;
use Symfony\Component\HttpFoundation\Request;

final class StatusHttpResponseProviderTest extends TestCase
{
    public function test_pending_transaction_after_the_success_url_shows_the_thank_you_page(): void
    {
        $provider = $this->provider();
        $paymentRequest = $this->paymentRequest(PaymentRequestInterface::STATE_COMPLETED, TpayTransaction::STATUS_PENDING);
        $request = new Request(['tpay_return' => 'success']);

        self::assertTrue($provider->supports($request, $paymentRequest));
        self::assertTrue($provider->supports(new RequestConfiguration(self::createStub(MetadataInterface::class), $request, new Parameters()), $paymentRequest));
        self::assertSame('/en_US/order/thank-you', $provider->getResponse($request, $paymentRequest)->getTargetUrl());
    }

    /**
     * @param array<string, string> $query
     */
    #[TestWith([[]])]
    #[TestWith([['tpay_return' => 'error']])]
    public function test_pending_transaction_without_the_success_url_is_left_to_sylius(array $query): void
    {
        $paymentRequest = $this->paymentRequest(PaymentRequestInterface::STATE_COMPLETED, TpayTransaction::STATUS_PENDING);

        self::assertFalse($this->provider()->supports(new Request($query), $paymentRequest));
    }

    #[TestWith([TpayTransaction::STATUS_CORRECT])]
    #[TestWith([TpayTransaction::STATUS_CANCELED])]
    #[TestWith([null])]
    public function test_finished_or_unknown_status_is_left_to_sylius(?string $status): void
    {
        $paymentRequest = $this->paymentRequest(PaymentRequestInterface::STATE_COMPLETED, $status);

        self::assertFalse($this->provider()->supports(new Request(['tpay_return' => 'success']), $paymentRequest));
    }

    #[TestWith([PaymentRequestInterface::STATE_NEW])]
    #[TestWith([PaymentRequestInterface::STATE_FAILED])]
    public function test_request_that_is_not_completed_is_left_to_sylius(string $state): void
    {
        $paymentRequest = $this->paymentRequest($state, TpayTransaction::STATUS_PENDING);

        self::assertFalse($this->provider()->supports(new Request(['tpay_return' => 'success']), $paymentRequest));
    }

    private function provider(): StatusHttpResponseProvider
    {
        $finalUrlProvider = self::createStub(FinalUrlProviderInterface::class);
        $finalUrlProvider->method('getUrl')->willReturn('/en_US/order/thank-you');

        return new StatusHttpResponseProvider($finalUrlProvider);
    }

    private function paymentRequest(string $state, ?string $status): PaymentRequestInterface
    {
        $paymentRequest = self::createStub(PaymentRequestInterface::class);
        $paymentRequest->method('getState')->willReturn($state);
        $paymentRequest->method('getResponseData')->willReturn(null === $status ? [] : ['tpay_status' => $status]);

        return $paymentRequest;
    }
}
