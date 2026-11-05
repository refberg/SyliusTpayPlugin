<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Support;

use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Builds unsaved Sylius payments for unit tests.
 */
final class Payments
{
    public static function payment(int $amount = 1500, string $currency = 'PLN', string $state = PaymentInterface::STATE_NEW, int $id = 42): PaymentInterface
    {
        $payment = new class($id) extends Payment {
            public function __construct(private readonly int $fixedId)
            {
                parent::__construct();
            }

            public function getId(): int
            {
                return $this->fixedId;
            }
        };
        $payment->setAmount($amount);
        $payment->setCurrencyCode($currency);
        $payment->setState($state);

        return $payment;
    }
}
