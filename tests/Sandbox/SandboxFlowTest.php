<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Sandbox;

use Refberg\SyliusTpayPlugin\Api\TpayClientInterface;
use Refberg\SyliusTpayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusTpayPlugin\Config\TpayCredentials;
use Refberg\SyliusTpayPlugin\Payment\PaymentDetails;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Tests\Refberg\SyliusTpayPlugin\Integration\IntegrationTestCase;
use Tests\Refberg\SyliusTpayPlugin\Support\SandboxEnvironment;
use function fwrite;
use function json_encode;
use function sleep;
use function sprintf;
use function time;
use const STDERR;

/**
 * End-to-end checks against the real Tpay sandbox (openapi.sandbox.tpay.com). Never part of CI.
 *
 * Run with:
 *   TPAY_SANDBOX=1 TPAY_SANDBOX_MERCHANT_ID=... TPAY_SANDBOX_CLIENT_ID=... \
 *   TPAY_SANDBOX_CLIENT_SECRET=... TPAY_SANDBOX_SECURITY_CODE=... vendor/bin/phpunit --testsuite=sandbox
 *
 * Add TPAY_SANDBOX_MANUAL=1 to also run the tests that wait for you to pay or reject the printed
 * sandbox payment page in a browser. Tpay notifications cannot reach a local machine, so the
 * payment state is taken from the Tpay API, like after the customer returns to the shop.
 */
final class SandboxFlowTest extends IntegrationTestCase
{
    private const POLL_SECONDS = 300;

    protected function setUp(): void
    {
        if (!SandboxEnvironment::isEnabled() || null === SandboxEnvironment::credentials()) {
            self::markTestSkipped('Set TPAY_SANDBOX=1 and TPAY_SANDBOX_* credentials to run tests against the Tpay sandbox.');
        }

        parent::setUp();

        // The notification URL must look public to Tpay; notifications are not expected to arrive.
        $this->client->setServerParameters(['HTTP_HOST' => 'shop.example.com', 'HTTPS' => 'on']);
    }

    public function test_transaction_is_created_with_the_payment_data_and_read_back(): void
    {
        $payment = $this->createPlacedOrder();
        $redirect = $this->payInShop();
        self::assertStringStartsWith('https://secure.sandbox.tpay.com/', $redirect);

        $details = $this->details($payment);
        $transactionId = $details->getCurrentTransactionId();
        self::assertNotNull($transactionId);

        $transaction = $this->tpay()->getTransaction($this->credentials($payment), $transactionId);
        self::assertSame('pending', $transaction->status);
        self::assertSame(1500, $transaction->amount);
        self::assertSame('PLN', $transaction->currency);
        self::assertSame($details->getExtOrderId($transactionId), $transaction->hiddenDescription);
        self::assertSame($details->getTitle($transactionId), $transaction->title);
    }

    public function test_cancelling_the_payment_cancels_the_tpay_transaction(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $transactionId = $this->details($payment)->getCurrentTransactionId();
        self::assertNotNull($transactionId);

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);

        $cancelRequests = $this->paymentRequests(PaymentRequestInterface::ACTION_CANCEL);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $cancelRequests[0]->getState(), (string) json_encode($cancelRequests[0]->getResponseData()));
        self::assertSame('canceled', $this->tpay()->getTransaction($this->credentials($payment), $transactionId)->status);
    }

    public function test_paid_order_is_completed_and_can_be_refunded(): void
    {
        $this->requireManualPayment();

        $payment = $this->createPlacedOrder();
        fwrite(STDERR, sprintf("\nPay this sandbox transaction successfully within %d s: %s\n", self::POLL_SECONDS, $this->payInShop()));
        $payment = $this->waitForPaymentState($payment, 'completed');

        self::assertSame('paid', $payment->getOrder()?->getPaymentState());
        self::assertNotNull(PaymentDetails::of($payment)->getCompletedTransactionId());

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_REFUND);

        $refundRequests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $refundRequests);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $refundRequests[0]->getState(), (string) json_encode($refundRequests[0]->getResponseData()));
        $refunds = $this->details($payment)->refunds();
        self::assertCount(1, $refunds);
    }

    public function test_rejected_payment_is_not_paid(): void
    {
        $this->requireManualPayment();

        $payment = $this->createPlacedOrder();
        fwrite(STDERR, sprintf("\nReject (fail) this sandbox transaction within %d s: %s\n", self::POLL_SECONDS, $this->payInShop()));

        $deadline = time() + self::POLL_SECONDS;
        do {
            sleep(10);
            $this->returnToShop();
            $payment = $this->reloadPayment($payment);
        } while ('new' === $payment->getState() && time() < $deadline);

        self::assertNotSame('completed', $payment->getState());
        self::assertNotSame('paid', $payment->getOrder()?->getPaymentState());
    }

    /**
     * @return array<string, string>
     */
    protected function tpayCredentials(): array
    {
        return ['environment' => 'sandbox'] + (SandboxEnvironment::credentials() ?? []);
    }

    private function requireManualPayment(): void
    {
        if (!SandboxEnvironment::isManual()) {
            self::markTestSkipped('Set TPAY_SANDBOX_MANUAL=1 to pay the sandbox transaction yourself.');
        }
    }

    /**
     * Opens the after-pay page (the customer coming back), which makes the plugin ask the Tpay API for the state.
     */
    private function returnToShop(): void
    {
        $captureRequests = $this->paymentRequests(PaymentRequestInterface::ACTION_CAPTURE);
        self::assertNotEmpty($captureRequests);
        $this->client->request('GET', '/en_US/order/after-pay/' . $captureRequests[0]->getId());
    }

    private function waitForPaymentState(PaymentInterface $payment, string $state): PaymentInterface
    {
        $deadline = time() + self::POLL_SECONDS;
        do {
            $this->returnToShop();
            $payment = $this->reloadPayment($payment);
            if ($state === $payment->getState()) {
                return $payment;
            }

            sleep(5);
        } while (time() < $deadline);

        $details = PaymentDetails::of($payment);
        self::fail(sprintf(
            'Payment did not reach "%s" within %d s; it is "%s", Tpay status "%s".',
            $state,
            self::POLL_SECONDS,
            (string) $payment->getState(),
            (string) $details->getTransactionStatus((string) $details->getCurrentTransactionId()),
        ));
    }

    private function credentials(PaymentInterface $payment): TpayCredentials
    {
        $method = $this->reloadPayment($payment)->getMethod();
        self::assertNotNull($method);

        return self::service(CredentialsResolverInterface::class, CredentialsResolverInterface::class)->resolve($method);
    }

    private function tpay(): TpayClientInterface
    {
        return self::service(TpayClientInterface::class, TpayClientInterface::class);
    }

    private function applyTransition(PaymentInterface $payment, string $transition): void
    {
        $payment = $this->reloadPayment($payment);
        self::service(StateMachineInterface::class, 'sylius_abstraction.state_machine')
            ->apply($payment, PaymentTransitions::GRAPH, $transition);
        $this->em()->flush();
    }
}
