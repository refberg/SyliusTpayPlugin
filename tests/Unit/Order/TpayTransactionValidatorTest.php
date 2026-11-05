<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Unit\Order;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusTpayPlugin\Api\Model\TpayTransaction;
use Refberg\SyliusTpayPlugin\Config\Environment;
use Refberg\SyliusTpayPlugin\Config\TpayCredentials;
use Refberg\SyliusTpayPlugin\Order\TpayTransactionValidator;
use Refberg\SyliusTpayPlugin\Payment\PaymentDetails;
use Sylius\Component\Core\Model\PaymentInterface;
use Tests\Refberg\SyliusTpayPlugin\Support\Payments;

final class TpayTransactionValidatorTest extends TestCase
{
    public function test_matching_transaction_has_no_errors(): void
    {
        self::assertSame([], (new TpayTransactionValidator())->validate(self::transaction(), self::payment(), self::credentials()));
    }

    #[TestWith(['transactionId', 'OTHER', 'Tpay transaction is not linked to this payment'])]
    #[TestWith(['title', 'TR-OTHER', 'title does not match'])]
    #[TestWith(['hiddenDescription', 'other', 'hiddenDescription does not match'])]
    #[TestWith(['hiddenDescription', null, 'hiddenDescription does not match'])]
    #[TestWith(['amount', 1499, 'amount does not match'])]
    #[TestWith(['currency', 'EUR', 'currency does not match'])]
    public function test_mismatch_is_reported(string $field, mixed $value, string $error): void
    {
        $fields = ['transactionId' => 'T1', 'title' => 'TR-1', 'status' => 'correct', 'amount' => 1500, 'currency' => 'PLN', 'hiddenDescription' => 'ext-1', $field => $value];
        /** @var array{transactionId: string, title: string, status: string, amount: int, currency: string, hiddenDescription: ?string} $fields */
        $transaction = new TpayTransaction(...$fields);

        self::assertContains($error, (new TpayTransactionValidator())->validate($transaction, self::payment(), self::credentials()));
    }

    public function test_transaction_created_for_another_merchant_is_reported(): void
    {
        $credentials = new TpayCredentials('2020', 'client', 'secret', 'code', Environment::Sandbox);

        self::assertSame(['merchant ID does not match'], (new TpayTransactionValidator())->validate(self::transaction(), self::payment(), $credentials));
    }

    private static function payment(): PaymentInterface
    {
        $payment = Payments::payment();
        $details = PaymentDetails::of($payment);
        $details->addTransaction('T1', 'TR-1', 'ext-1', '1010', 1500, 'PLN');
        $details->save();

        return $payment;
    }

    private static function transaction(): TpayTransaction
    {
        return new TpayTransaction('T1', 'TR-1', 'correct', 1500, 'PLN', 'ext-1');
    }

    private static function credentials(): TpayCredentials
    {
        return new TpayCredentials('1010', 'client', 'secret', 'code', Environment::Sandbox);
    }
}
