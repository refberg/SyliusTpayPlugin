<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Integration;

use Symfony\Component\HttpFoundation\Response;
use Tests\Refberg\SyliusTpayPlugin\HeadlessApplication\HeadlessKernel;

/**
 * Application without the shop, admin and Twig Hooks bundles.
 */
final class HeadlessApiPaymentFlowTest extends IntegrationTestCase
{
    public function test_api_checkout_is_paid_by_a_signed_notification_without_the_shop(): void
    {
        $payment = $this->createPlacedOrder();

        $created = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'payload' => ['continue_url' => 'https://app.example/payment/return'],
        ]);

        self::assertSame('processing', $created['state'], (string) $this->client->getResponse()->getContent());
        $transactionId = $this->tpay->lastTransactionId();
        $request = $this->tpay->transaction($transactionId)['request'];
        self::assertIsArray($request);
        self::assertSame('http://localhost/payment-methods/TPAY', self::at($request, 'callbacks', 'notification', 'url'));

        $this->tpay->setTransactionStatus($transactionId, 'correct');

        $response = $this->notifyTransaction($transactionId);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('TRUE', $response->getContent());
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    public function test_capture_without_continue_url_fails_without_calling_tpay(): void
    {
        $payment = $this->createPlacedOrder();

        $created = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
        ]);

        self::assertSame('failed', $created['state']);
        self::assertIsString(self::at($created, 'responseData', 'error'));
        self::assertStringContainsString('continue_url', self::at($created, 'responseData', 'error'));
        self::assertSame([], $this->tpay->transactionIds());
    }

    protected static function getKernelClass(): string
    {
        return HeadlessKernel::class;
    }
}
