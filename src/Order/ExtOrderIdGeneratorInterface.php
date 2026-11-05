<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Order;

use InvalidArgumentException;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * extractPaymentId() must read the payment ID back from any value returned by generate(): notifications are matched to payments by it.
 */
interface ExtOrderIdGeneratorInterface
{
    /**
     * @throws InvalidArgumentException
     */
    public function generate(PaymentInterface $payment, PaymentRequestInterface $paymentRequest): string;

    public function extractPaymentId(string $extOrderId): ?int;
}
