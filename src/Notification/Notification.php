<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Notification;

/**
 * A Tpay transaction notification whose md5sum and JWS signature have been verified.
 *
 * Only the identifiers are used: the transaction state itself is always read again from the Tpay API.
 */
final readonly class Notification
{
    /** tr_status "TRUE" (or "PAID"): the transaction was paid. */
    public const TYPE_PAYMENT = 'payment';

    /** tr_status "CHARGEBACK": the transaction was fully refunded (not a bank chargeback). */
    public const TYPE_REFUND = 'refund';

    public const STATUS_TRUE = 'TRUE';

    public const STATUS_PAID = 'PAID';

    public const STATUS_CHARGEBACK = 'CHARGEBACK';

    public function __construct(
        public string $type,
        public string $merchantId,
        public string $title,
        public string $crc,
        public string $status,
        public ?int $amount,
        public ?string $currency,
    ) {
    }
}
