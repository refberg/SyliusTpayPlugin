<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Integration;

use Symfony\Component\HttpFoundation\Response;

/**
 * Headless checkout through Sylius API Platform endpoints, without any shop UI.
 */
final class ApiPaymentFlowTest extends IntegrationTestCase
{
    public function test_api_client_gets_the_tpay_payment_url_and_checks_status(): void
    {
        $payment = $this->createPlacedOrder();

        $created = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'payload' => ['continue_url' => 'https://app.example/payment/return'],
        ]);

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame('capture', $created['action']);
        self::assertSame('processing', $created['state']);
        $transactionId = $this->tpay->lastTransactionId();
        $transaction = $this->tpay->transaction($transactionId);
        self::assertIsString($transaction['title']);
        self::assertSame('https://secure.sandbox.tpay.com/?title=' . $transaction['title'] . '&uid=fake', self::at($created, 'responseData', 'redirect_url'));
        self::assertSame($transactionId, self::at($created, 'responseData', 'transaction_id'));
        // A continue URL from the API client is used as it is, for both outcomes.
        $request = $transaction['request'];
        self::assertIsArray($request);
        self::assertSame(
            ['success' => 'https://app.example/payment/return', 'error' => 'https://app.example/payment/return'],
            self::at($request, 'callbacks', 'payerUrls'),
        );

        // Payment still unpaid; the client asks for the status after the customer returns.
        $this->tpay->setTransactionStatus($transactionId, 'correct');
        $status = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'action' => 'status',
        ]);

        self::assertSame('completed', $status['state']);
        self::assertSame('completed', self::at($status, 'responseData', 'payment_state'));
        self::assertSame('correct', self::at($status, 'responseData', 'tpay_status'));
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    public function test_invalid_continue_url_fails_the_request_without_calling_tpay(): void
    {
        $payment = $this->createPlacedOrder();

        $created = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'payload' => ['continue_url' => 'javascript:alert(1)'],
        ]);

        self::assertSame('failed', $created['state']);
        self::assertSame([], $this->tpay->transactionIds());
        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_refund_action_is_not_available_to_shop_api_clients(): void
    {
        $payment = $this->createPlacedOrder();

        $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'action' => 'refund',
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->tpay->requests(null, '#/refunds#'));
    }
}
