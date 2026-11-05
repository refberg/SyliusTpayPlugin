<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Payment;

use Refberg\SyliusTpayPlugin\Api\Exception\TpayException;
use Refberg\SyliusTpayPlugin\Config\TpayCredentials;
use Sylius\Component\Payment\Model\PaymentInterface;

interface PaymentSynchronizerInterface
{
    public const RESULT_INVALID = 'invalid';

    /**
     * Fetches one Tpay transaction, checks it against the payment and applies its state.
     *
     * @throws TpayException
     *
     * @return string one of PaymentStateApplierInterface::RESULT_* or self::RESULT_INVALID
     */
    public function synchronize(PaymentInterface $payment, string $transactionId, TpayCredentials $credentials): string;

    /**
     * Fetches the refunds of a Tpay transaction and records them in the payment details.
     *
     * @throws TpayException
     */
    public function synchronizeRefunds(PaymentInterface $payment, string $transactionId, TpayCredentials $credentials): void;
}
