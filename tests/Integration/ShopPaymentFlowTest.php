<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Refberg\SyliusTpayPlugin\Payment\PaymentDetails;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\Response;
use Tests\Refberg\SyliusTpayPlugin\Support\FakeTpay;
use function http_build_query;
use function parse_str;
use function str_replace;

final class ShopPaymentFlowTest extends IntegrationTestCase
{
    public function test_customer_is_redirected_to_tpay_with_server_side_amount(): void
    {
        $payment = $this->createPlacedOrder();

        $redirect = $this->payInShop();

        $transactionId = $this->tpay->lastTransactionId();
        $transaction = $this->tpay->transaction($transactionId);
        self::assertSame('https://secure.sandbox.tpay.com/?title=' . $transaction['title'] . '&uid=fake', $redirect);

        $request = $transaction['request'];
        self::assertIsArray($request);
        self::assertSame(15.0, $request['amount']);
        self::assertSame('PLN', $request['currency']);
        self::assertSame('Order 000000001', $request['description']);
        self::assertSame(['email' => 'buyer@example.com', 'name' => 'buyer@example.com', 'ip' => '10.0.0.1'], $request['payer']);
        self::assertSame('en', $request['lang']);
        self::assertSame('http://localhost/payment-methods/TPAY', self::at($request, 'callbacks', 'notification', 'url'));
        self::assertMatchesRegularExpression('#^http://localhost/en_US/order/after-pay/[0-9a-f-]{36}\?tpay_return=success$#', self::at($request, 'callbacks', 'payerUrls', 'success'));
        self::assertMatchesRegularExpression('#^http://localhost/en_US/order/after-pay/[0-9a-f-]{36}\?tpay_return=error$#', self::at($request, 'callbacks', 'payerUrls', 'error'));
        $paymentId = $payment->getId();
        self::assertIsInt($paymentId);
        self::assertMatchesRegularExpression('#^000000001-' . $paymentId . '-[0-9a-f]{12}$#', $request['hiddenDescription']);

        self::assertSame('new', $this->reloadPayment($payment)->getState());
        self::assertSame($transactionId, $this->details($payment)->getCurrentTransactionId());
    }

    public function test_opening_the_pay_page_again_reuses_the_tpay_transaction(): void
    {
        $this->createPlacedOrder();

        $first = $this->payInShop();
        $second = $this->payInShop();

        self::assertSame($first, $second);
        self::assertCount(1, $this->tpay->transactionIds());
    }

    public function test_returning_from_tpay_does_not_pay_the_order_by_itself(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();

        // The return URL can be opened by anyone: it is not proof of payment.
        $this->client->request('GET', $this->returnUrl($transactionId));

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame('new', $this->reloadPayment($payment)->getState());
        self::assertCount(1, $this->tpay->requests('GET', '#^/transactions/[^/]+$#'));
    }

    public function test_returning_through_the_success_url_while_pending_shows_thank_you_page_and_keeps_payment_unpaid(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();

        $this->client->request('GET', $this->returnUrl($transactionId));

        self::assertStringContainsString('/order/thank-you', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_returning_through_the_error_url_while_pending_lets_the_customer_pay_again(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();

        $this->client->request('GET', $this->returnUrl($transactionId, 'error'));

        self::assertStringNotContainsString('/order/thank-you', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    #[TestWith(['paid'])]
    #[TestWith(['correct'])]
    public function test_returning_after_tpay_received_the_payment_marks_it_paid(string $status): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();
        $this->tpay->setTransactionStatus($transactionId, $status);

        $this->client->request('GET', $this->returnUrl($transactionId, 'error'));

        self::assertStringContainsString('/order/thank-you', (string) $this->client->getResponse()->headers->get('Location'));
        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame('paid', $payment->getOrder()?->getPaymentState());
    }

    public function test_signed_payment_notification_pays_the_order_and_is_answered_with_true(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();
        $this->tpay->setTransactionStatus($transactionId, 'correct');

        $response = $this->notifyTransaction($transactionId);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('TRUE', $response->getContent());
        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame('paid', $payment->getOrder()?->getPaymentState());
        self::assertSame($transactionId, PaymentDetails::of($payment)->getCompletedTransactionId());
    }

    public function test_duplicated_notifications_are_accepted_and_change_nothing(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();
        $this->tpay->setTransactionStatus($transactionId, 'correct');

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(Response::HTTP_OK, $this->notifyTransaction($transactionId)->getStatusCode());
        }

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame([], PaymentDetails::of($payment)->warnings());
        self::assertCount(3, $this->paymentRequests(PaymentRequestInterface::ACTION_NOTIFY));
    }

    public function test_notification_with_a_wrong_checksum_or_signature_is_rejected_and_not_stored(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();
        $this->tpay->setTransactionStatus($transactionId, 'correct');
        $body = $this->tpay->notificationBody($transactionId);

        // md5sum computed with another security code, but a valid JWS of that body.
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($this->tpay->notificationBody($transactionId, securityCode: 'attacker-code'))->getStatusCode());
        // No JWS, a JWS of another key, a JWS naming a foreign certificate.
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($body, null)->getStatusCode());
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($body, FakeTpay::jws($body, FakeTpay::foreignKey()))->getStatusCode());
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($body, FakeTpay::jws($body, certificateUrl: 'https://attacker.example/x509/notifications-jws.pem'))->getStatusCode());
        // A JWS of the original body on a modified one.
        $tampered = str_replace('tr_status=TRUE', 'tr_status=CHARGEBACK', $body);
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($tampered, FakeTpay::jws($body))->getStatusCode());
        // Signed correctly, but for another merchant.
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($this->tpay->notificationBody($transactionId, override: ['id' => '9999']))->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
        self::assertCount(0, $this->paymentRequests(PaymentRequestInterface::ACTION_NOTIFY));
        self::assertCount(0, $this->tpay->requests('GET', '#^/transactions/#'));
    }

    public function test_notification_for_an_unknown_transaction_gets_404_so_tpay_stops_resending(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();

        self::assertSame(Response::HTTP_NOT_FOUND, $this->notify($this->tpay->notificationBody($transactionId, override: ['tr_id' => 'TR-FOREIGN']))->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->notify($this->tpay->notificationBody($transactionId, override: ['tr_crc' => 'other-999-abcdef012345']))->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->notify($this->tpay->notificationBody($transactionId, override: ['tr_crc' => 'not-ours']))->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_notification_for_a_method_without_credentials_returns_503_so_tpay_retries(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();
        $this->tpay->setTransactionStatus($transactionId, 'correct');
        $this->createTpayMethod('TPAY2')->getGatewayConfig()?->setConfig(['environment' => 'sandbox']);
        $this->em()->flush();

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $this->notify($this->tpay->notificationBody($transactionId), methodCode: 'TPAY2')->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
        self::assertCount(0, $this->paymentRequests(PaymentRequestInterface::ACTION_NOTIFY));
    }

    public function test_unavailable_signing_certificate_returns_503_so_tpay_retries(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();
        $this->tpay->setTransactionStatus($transactionId, 'correct');
        $this->tpay->makeCertificatesUnavailable();

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $this->notifyTransaction($transactionId)->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function mismatches(): iterable
    {
        yield 'amount' => ['amount', 0.01];
        yield 'currency' => ['currency', 'EUR'];
        yield 'hidden description' => ['hiddenDescription', 'tampered-1-abcdef012345'];
        yield 'title' => ['title', 'TR-OTHER'];
    }

    #[DataProvider('mismatches')]
    public function test_transaction_that_does_not_match_the_payment_never_pays_it(string $field, mixed $value): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();
        $body = $this->tpay->notificationBody($transactionId);

        $this->tpay->setTransactionStatus($transactionId, 'correct');
        $this->tpay->overrideTransaction($transactionId, $field, $value);

        self::assertSame(Response::HTTP_OK, $this->notify($body)->getStatusCode());
        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_notification_is_not_trusted_without_the_api(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();

        // Correctly signed "TRUE", but the Tpay API still reports "pending".
        self::assertSame(Response::HTTP_OK, $this->notifyTransaction($transactionId)->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_canceled_transaction_fails_the_payment(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();
        $this->tpay->setTransactionStatus($transactionId, 'canceled');

        $this->client->request('GET', $this->returnUrl($transactionId, 'error'));

        self::assertSame('failed', $this->reloadPayment($payment)->getState());
    }

    public function test_late_pending_or_canceled_state_never_undoes_a_completed_payment(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();
        $this->tpay->setTransactionStatus($transactionId, 'correct');
        $this->notifyTransaction($transactionId);

        foreach (['pending', 'canceled'] as $status) {
            $this->tpay->setTransactionStatus($transactionId, $status);
            self::assertSame(Response::HTTP_OK, $this->notifyTransaction($transactionId)->getStatusCode());
        }

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame('correct', PaymentDetails::of($payment)->getTransactionStatus($transactionId));
    }

    public function test_two_paid_transactions_never_pay_the_sylius_order_twice(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $firstTransactionId = $this->tpay->lastTransactionId();

        // The first attempt is abandoned; the customer starts a new one.
        $this->finalizeCaptureRequests(PaymentRequestTransitions::TRANSITION_FAIL);
        $this->payInShop();
        $secondTransactionId = $this->tpay->lastTransactionId();
        self::assertNotSame($firstTransactionId, $secondTransactionId);

        $this->tpay->setTransactionStatus($secondTransactionId, 'correct');
        $this->notifyTransaction($secondTransactionId);
        $this->tpay->setTransactionStatus($firstTransactionId, 'correct');
        self::assertSame(Response::HTTP_OK, $this->notifyTransaction($firstTransactionId)->getStatusCode());

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        $details = PaymentDetails::of($payment);
        self::assertSame($secondTransactionId, $details->getCompletedTransactionId());
        self::assertSame([['type' => PaymentDetails::WARNING_DUPLICATE_PAYMENT, 'transaction_id' => $firstTransactionId]], $details->warnings());
    }

    public function test_transient_tpay_error_returns_5xx_so_tpay_retries(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();
        $this->tpay->setTransactionStatus($transactionId, 'correct');
        $this->tpay->failNextRequest(503);

        $this->client->catchExceptions(true);
        self::assertGreaterThanOrEqual(500, $this->notifyTransaction($transactionId)->getStatusCode());
        self::assertSame('new', $this->reloadPayment($payment)->getState());

        self::assertSame(Response::HTTP_OK, $this->notifyTransaction($transactionId)->getStatusCode());
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    #[TestWith([401])]
    #[TestWith([403])]
    public function test_tpay_api_rejecting_the_plugin_credentials_returns_5xx_so_tpay_retries(int $httpStatus): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();
        $this->tpay->setTransactionStatus($transactionId, 'correct');
        $this->tpay->failNextRequest($httpStatus, 'unauthorized');
        if (401 === $httpStatus) {
            // The client retries a 401 once with a fresh token.
            $this->tpay->failNextRequest($httpStatus, 'unauthorized');
        }

        $this->client->catchExceptions(true);
        self::assertGreaterThanOrEqual(500, $this->notifyTransaction($transactionId)->getStatusCode());
        self::assertSame('new', $this->reloadPayment($payment)->getState());

        self::assertSame(Response::HTTP_OK, $this->notifyTransaction($transactionId)->getStatusCode());
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    public function test_permanent_tpay_error_is_answered_with_true_so_tpay_stops_resending(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();
        $this->tpay->failNextRequest(400, 'invalid_request');

        $response = $this->notifyTransaction($transactionId);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('TRUE', $response->getContent());
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $this->paymentRequests(PaymentRequestInterface::ACTION_NOTIFY)[0]->getState());
        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    #[TestWith(['notification'])]
    #[TestWith(['return'])]
    public function test_payment_made_in_tpay_after_the_customer_switched_payment_method_completes_it_with_a_warning(string $confirmedBy): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();
        $this->switchToBankTransfer($payment);

        $this->tpay->setTransactionStatus($transactionId, 'correct');
        if ('notification' === $confirmedBy) {
            self::assertSame(Response::HTTP_OK, $this->notifyTransaction($transactionId)->getStatusCode());
            $this->client->request('GET', $this->returnUrl($transactionId));
            self::assertTrue($this->client->getResponse()->isRedirect());
        } else {
            $this->client->request('GET', $this->returnUrl($transactionId));
            self::assertTrue($this->client->getResponse()->isRedirect());
            self::assertSame(Response::HTTP_OK, $this->notifyTransaction($transactionId)->getStatusCode());
        }

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame(self::METHOD_CODE, $payment->getMethod()?->getCode());
        $details = PaymentDetails::of($payment);
        self::assertSame($transactionId, $details->getCompletedTransactionId());
        self::assertSame([['type' => PaymentDetails::WARNING_PAYMENT_METHOD_CHANGED, 'transaction_id' => $transactionId]], $details->warnings());

        // The restored Tpay method lets an admin refund reach Tpay.
        $stateMachine = self::service(StateMachineInterface::class, 'sylius_abstraction.state_machine');
        $stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        $this->em()->flush();

        self::assertCount(1, $this->tpay->requests('POST', '#^/transactions/' . $transactionId . '/refunds$#'));
    }

    #[TestWith(['notification'])]
    #[TestWith(['return'])]
    public function test_pending_transaction_after_the_customer_switched_payment_method_keeps_the_new_method(string $checkedBy): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();
        $this->switchToBankTransfer($payment);

        if ('notification' === $checkedBy) {
            self::assertSame(Response::HTTP_OK, $this->notifyTransaction($transactionId)->getStatusCode());
        } else {
            $this->client->request('GET', $this->returnUrl($transactionId));
            self::assertTrue($this->client->getResponse()->isRedirect());
        }

        $payment = $this->reloadPayment($payment);
        self::assertSame('new', $payment->getState());
        self::assertSame('BANK_TRANSFER', $payment->getMethod()?->getCode());
        self::assertSame([], PaymentDetails::of($payment)->warnings());
    }

    public function test_notification_for_another_tpay_method_never_pays_the_payment(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();
        $this->createTpayMethod('TPAY2', ['merchant_id' => '2020', 'security_code' => 'other-security-code']);
        $this->em()->flush();
        $this->tpay->setTransactionStatus($transactionId, 'correct');

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($this->tpay->notificationBody($transactionId), methodCode: 'TPAY2')->getStatusCode());
        // Correctly signed for the other merchant, but the transaction was created for the first one.
        $body = $this->tpay->notificationBody($transactionId, override: ['id' => '2020'], securityCode: 'other-security-code');
        self::assertSame(Response::HTTP_OK, $this->notify($body, methodCode: 'TPAY2')->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_notification_amount_field_order_does_not_matter_for_the_checksum(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->tpay->lastTransactionId();
        $this->tpay->setTransactionStatus($transactionId, 'correct');

        parse_str($this->tpay->notificationBody($transactionId), $fields);
        $reordered = http_build_query(['md5sum' => $fields['md5sum']] + $fields);

        self::assertSame(Response::HTTP_OK, $this->notify($reordered)->getStatusCode());
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    private function switchToBankTransfer(PaymentInterface $payment): void
    {
        $bankTransfer = $this->factory('payment_method')->createNew();
        self::assertInstanceOf(PaymentMethodInterface::class, $bankTransfer);
        $bankTransfer->setCode('BANK_TRANSFER');
        $bankTransfer->setCurrentLocale('en_US');
        $bankTransfer->setFallbackLocale('en_US');
        $bankTransfer->setName('Bank transfer');
        $payment = $this->reloadPayment($payment);
        $payment->setMethod($bankTransfer);
        $this->em()->persist($bankTransfer);
        $this->em()->flush();
    }

    private function finalizeCaptureRequests(string $transition): void
    {
        $stateMachine = self::service(StateMachineInterface::class, 'sylius_abstraction.state_machine');

        foreach ($this->paymentRequests(PaymentRequestInterface::ACTION_CAPTURE) as $request) {
            if ($stateMachine->can($request, PaymentRequestTransitions::GRAPH, $transition)) {
                $stateMachine->apply($request, PaymentRequestTransitions::GRAPH, $transition);
            }
        }
        $this->em()->flush();
    }
}
