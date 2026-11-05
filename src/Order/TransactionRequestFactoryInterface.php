<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Order;

use InvalidArgumentException;
use Refberg\SyliusTpayPlugin\Config\TpayCredentials;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

interface TransactionRequestFactoryInterface
{
    /**
     * @throws InvalidArgumentException
     *
     * @return array<string, mixed> body of POST /transactions
     */
    public function create(
        PaymentRequestInterface $paymentRequest,
        PaymentInterface $payment,
        TpayCredentials $credentials,
        string $extOrderId,
    ): array;
}
