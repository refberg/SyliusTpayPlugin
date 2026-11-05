<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Api\Model;

use function in_array;
use function strtolower;

/**
 * A refund of a transaction, as listed by GET /transactions/{transactionId}/refunds.
 *
 * Statuses are compared lower-case: the API reference uses "done", the refund guide "Done".
 */
final readonly class TpayRefund
{
    public const STATUS_NEW = 'new';

    public const STATUS_PENDING = 'pending';

    public const STATUS_TO_COMPLETE = 'to_complete';

    public const STATUS_HOLD = 'hold';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_DONE = 'done';

    public const STATUS_CANCEL = 'cancel';

    /** Card refunds. */
    public const STATUS_AUTHORIZED = 'authorized';

    /** Card refunds. */
    public const STATUS_DECLINED = 'declined';

    public const STATUS_BLIK_PROCESSED = 'blik_processed';

    private const FINALIZED = [self::STATUS_DONE, self::STATUS_AUTHORIZED, self::STATUS_BLIK_PROCESSED];

    private const CANCELED = [self::STATUS_CANCEL, self::STATUS_DECLINED];

    public string $status;

    public function __construct(
        public string $refundId,
        string $status,
        public int $amount,
        public string $currency,
    ) {
        $this->status = strtolower($status);
    }

    /**
     * The money was sent to the payer.
     */
    public static function isFinalizedStatus(?string $status): bool
    {
        return in_array($status, self::FINALIZED, true);
    }

    /**
     * The refund will not be processed. Any other status counts as a refund in progress.
     */
    public static function isCanceledStatus(?string $status): bool
    {
        return in_array($status, self::CANCELED, true);
    }

    public static function isFinalStatus(?string $status): bool
    {
        return self::isFinalizedStatus($status) || self::isCanceledStatus($status);
    }
}
