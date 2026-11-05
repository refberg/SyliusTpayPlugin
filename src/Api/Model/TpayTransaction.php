<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Api\Model;

use function in_array;

/**
 * Transaction state as returned by GET /transactions/{transactionId}.
 */
final readonly class TpayTransaction
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    /** Paid and accepted by the merchant. */
    public const STATUS_CORRECT = 'correct';

    /** Fully refunded. Partial refunds do not change the transaction status. */
    public const STATUS_REFUND = 'refund';

    public const STATUS_CANCELED = 'canceled';

    public function __construct(
        public string $transactionId,
        public string $title,
        public string $status,
        public int $amount,
        public string $currency,
        public ?string $hiddenDescription,
    ) {
    }

    public static function isPaidStatus(?string $status): bool
    {
        return in_array($status, [self::STATUS_PAID, self::STATUS_CORRECT], true);
    }

    /**
     * A transaction that can still be paid by the customer.
     */
    public static function isOpenStatus(?string $status): bool
    {
        return null === $status || self::STATUS_PENDING === $status;
    }
}
