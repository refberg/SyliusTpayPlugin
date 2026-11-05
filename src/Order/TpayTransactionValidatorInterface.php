<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Order;

use Refberg\SyliusTpayPlugin\Api\Model\TpayTransaction;
use Sylius\Component\Payment\Model\PaymentInterface;

interface TpayTransactionValidatorInterface
{
    /**
     * @return list<string> problems found, empty when the transaction matches the payment
     */
    public function validate(TpayTransaction $transaction, PaymentInterface $payment): array;
}
