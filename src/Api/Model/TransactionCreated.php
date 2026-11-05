<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Api\Model;

final readonly class TransactionCreated
{
    public function __construct(
        public string $transactionId,
        public string $title,
        public string $paymentUrl,
        public string $status,
    ) {
    }
}
