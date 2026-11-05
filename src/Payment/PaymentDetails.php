<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Payment;

use Refberg\SyliusTpayPlugin\Api\Model\TpayRefund;
use Refberg\SyliusTpayPlugin\Api\Model\TpayTransaction;
use Refberg\SyliusTpayPlugin\TpayGateway;
use Sylius\Component\Payment\Model\PaymentInterface;
use function array_reverse;
use function is_array;
use function is_int;
use function is_string;

/**
 * Typed access to the Tpay data stored in Payment::$details[TpayGateway::DETAILS_KEY].
 *
 * Shape:
 *   transactions:             map transactionId => {title, ext_order_id, status, amount, currency}
 *   current_transaction_id:   Tpay transaction created most recently for this payment
 *   completed_transaction_id: Tpay transaction that completed this payment
 *   refunds:                  map key => {refund_id, status, amount, currency, external}
 *                             (refunds requested by Sylius are keyed by the payment request hash,
 *                             refunds made outside Sylius by "tpay-<refundId>")
 *   warnings:                 list of {type, transaction_id}
 *
 * Only scalars and arrays are stored, so the data stays JSON-serializable.
 */
final class PaymentDetails
{
    public const WARNING_DUPLICATE_PAYMENT = 'duplicate_payment';

    public const WARNING_LATE_PAYMENT = 'payment_completed_after_failure';

    public const WARNING_PAYMENT_METHOD_CHANGED = 'paid_with_previous_payment_method';

    public const WARNING_REFUNDED_BEFORE_COMPLETION = 'refunded_before_completion';

    public const WARNING_REFUND_CANCELED = 'refund_canceled';

    /** Later statuses never go back to earlier ones. */
    private const STATUS_RANK = [
        TpayTransaction::STATUS_PENDING => 0,
        TpayTransaction::STATUS_PAID => 1,
        TpayTransaction::STATUS_CORRECT => 2,
        TpayTransaction::STATUS_CANCELED => 2,
        TpayTransaction::STATUS_REFUND => 3,
    ];

    /** @var array<array-key, mixed> */
    private array $data;

    private function __construct(private readonly PaymentInterface $payment)
    {
        $data = $payment->getDetails()[TpayGateway::DETAILS_KEY] ?? [];
        $this->data = is_array($data) ? $data : [];
    }

    public static function of(PaymentInterface $payment): self
    {
        return new self($payment);
    }

    public function save(): void
    {
        $details = $this->payment->getDetails();
        $details[TpayGateway::DETAILS_KEY] = $this->data;
        $this->payment->setDetails($details);
    }

    public function addTransaction(string $transactionId, string $title, string $extOrderId, int $amount, string $currency): void
    {
        $transactions = $this->transactions();
        $transactions[$transactionId] = [
            'title' => $title,
            'ext_order_id' => $extOrderId,
            'status' => TpayTransaction::STATUS_PENDING,
            'amount' => $amount,
            'currency' => $currency,
        ];
        $this->data['transactions'] = $transactions;
        $this->data['current_transaction_id'] = $transactionId;
    }

    public function hasTransaction(string $transactionId): bool
    {
        return isset($this->transactions()[$transactionId]);
    }

    /**
     * Notifications name the transaction by its title (tr_id), the API by its ID.
     */
    public function findTransactionIdByTitle(string $title): ?string
    {
        foreach ($this->transactions() as $transactionId => $transaction) {
            if (($transaction['title'] ?? null) === $title) {
                return (string) $transactionId;
            }
        }

        return null;
    }

    public function getTitle(string $transactionId): ?string
    {
        return self::stringValue($this->transactions()[$transactionId]['title'] ?? null);
    }

    public function getExtOrderId(string $transactionId): ?string
    {
        return self::stringValue($this->transactions()[$transactionId]['ext_order_id'] ?? null);
    }

    public function getTransactionStatus(string $transactionId): ?string
    {
        return self::stringValue($this->transactions()[$transactionId]['status'] ?? null);
    }

    /**
     * Records the latest known status. A status never goes back (e.g. "correct" to "pending").
     */
    public function recordTransactionStatus(string $transactionId, string $status): void
    {
        $transactions = $this->transactions();
        if (!isset($transactions[$transactionId])) {
            return;
        }

        $current = self::stringValue($transactions[$transactionId]['status'] ?? null);
        if (null !== $current && (self::STATUS_RANK[$status] ?? 0) <= (self::STATUS_RANK[$current] ?? 0)) {
            return;
        }

        $transactions[$transactionId]['status'] = $status;
        $this->data['transactions'] = $transactions;
    }

    public function getCurrentTransactionId(): ?string
    {
        return self::stringValue($this->data['current_transaction_id'] ?? null);
    }

    public function getCompletedTransactionId(): ?string
    {
        return self::stringValue($this->data['completed_transaction_id'] ?? null);
    }

    public function setCompletedTransactionId(string $transactionId): void
    {
        $this->data['completed_transaction_id'] = $transactionId;
    }

    /**
     * @return list<string> IDs of transactions that may still be paid, newest first
     */
    public function getOpenTransactionIds(): array
    {
        $open = [];
        foreach ($this->transactions() as $transactionId => $transaction) {
            if (TpayTransaction::isOpenStatus(self::stringValue($transaction['status'] ?? null))) {
                $open[] = (string) $transactionId;
            }
        }

        return array_reverse($open);
    }

    public function hasOpenTransactions(): bool
    {
        return [] !== $this->getOpenTransactionIds();
    }

    public function addWarning(string $type, string $transactionId): void
    {
        $warnings = $this->warnings();
        foreach ($warnings as $warning) {
            if (($warning['type'] ?? null) === $type && ($warning['transaction_id'] ?? null) === $transactionId) {
                return;
            }
        }

        $warnings[] = ['type' => $type, 'transaction_id' => $transactionId];
        $this->data['warnings'] = $warnings;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function warnings(): array
    {
        $warnings = $this->data['warnings'] ?? [];
        if (!is_array($warnings)) {
            return [];
        }

        $result = [];
        foreach ($warnings as $warning) {
            if (is_array($warning)) {
                $result[] = $warning;
            }
        }

        return $result;
    }

    /**
     * Records a refund requested by Sylius. Its Tpay refund ID is filled in by synchronizeRefunds().
     */
    public function recordRefund(string $key, int $amount, string $currency): void
    {
        $refunds = $this->refunds();
        $refunds[$key] = [
            'refund_id' => null,
            'status' => TpayRefund::STATUS_NEW,
            'amount' => $amount,
            'currency' => $currency,
            'external' => false,
        ];
        $this->data['refunds'] = $refunds;
    }

    /**
     * Applies the refunds listed by Tpay:
     *  - a known refund gets its new status (a final status is never overwritten),
     *  - an unknown refund first fills in a Sylius refund that has no Tpay ID yet and the same amount,
     *  - any other refund was made outside Sylius (e.g. in the Tpay panel) and is recorded as external.
     *
     * @param list<TpayRefund> $tpayRefunds
     *
     * @return list<string> keys of refunds that became canceled
     */
    public function synchronizeRefunds(array $tpayRefunds): array
    {
        $refunds = $this->refunds();
        $canceled = [];

        foreach ($tpayRefunds as $tpayRefund) {
            $key = self::findRefundKey($refunds, $tpayRefund);
            if (null === $key) {
                $refunds['tpay-' . $tpayRefund->refundId] = [
                    'refund_id' => $tpayRefund->refundId,
                    'status' => $tpayRefund->status,
                    'amount' => $tpayRefund->amount,
                    'currency' => $tpayRefund->currency,
                    'external' => true,
                ];

                continue;
            }

            $refunds[$key]['refund_id'] = $tpayRefund->refundId;
            $current = self::stringValue($refunds[$key]['status'] ?? null);
            if (TpayRefund::isFinalStatus($current) || $current === $tpayRefund->status) {
                continue;
            }

            $refunds[$key]['status'] = $tpayRefund->status;
            if (TpayRefund::isCanceledStatus($tpayRefund->status)) {
                $canceled[] = (string) $key;
            }
        }

        $this->data['refunds'] = $refunds;

        return $canceled;
    }

    public function finalizedRefundAmount(): int
    {
        return $this->refundAmount(static fn (?string $status): bool => TpayRefund::isFinalizedStatus($status));
    }

    /**
     * Refunds that are not canceled, including those made outside Sylius.
     */
    public function activeRefundAmount(): int
    {
        return $this->refundAmount(static fn (?string $status): bool => !TpayRefund::isCanceledStatus($status));
    }

    /**
     * Only refunds requested by Sylius count; refunds made outside Sylius are covered by activeRefundAmount().
     */
    public function hasActiveRefund(): bool
    {
        foreach ($this->refunds() as $refund) {
            if (true !== ($refund['external'] ?? false) && !TpayRefund::isCanceledStatus(self::stringValue($refund['status'] ?? null))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<array-key, array<array-key, mixed>>
     */
    public function refunds(): array
    {
        $refunds = $this->data['refunds'] ?? [];

        return is_array($refunds) ? self::mapOfArrays($refunds) : [];
    }

    /**
     * @param array<array-key, array<array-key, mixed>> $refunds
     */
    private static function findRefundKey(array $refunds, TpayRefund $tpayRefund): int|string|null
    {
        foreach ($refunds as $key => $refund) {
            if (($refund['refund_id'] ?? null) === $tpayRefund->refundId) {
                return $key;
            }
        }

        foreach ($refunds as $key => $refund) {
            if (null === ($refund['refund_id'] ?? null) && true !== ($refund['external'] ?? false) && ($refund['amount'] ?? null) === $tpayRefund->amount) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return array<array-key, array<array-key, mixed>>
     */
    private function transactions(): array
    {
        $transactions = $this->data['transactions'] ?? [];

        return is_array($transactions) ? self::mapOfArrays($transactions) : [];
    }

    /**
     * @param array<array-key, mixed> $items
     *
     * @return array<array-key, array<array-key, mixed>>
     */
    private static function mapOfArrays(array $items): array
    {
        $result = [];
        foreach ($items as $key => $item) {
            if (is_array($item)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /**
     * @param callable(?string): bool $statusFilter
     */
    private function refundAmount(callable $statusFilter): int
    {
        $total = 0;
        foreach ($this->refunds() as $refund) {
            $amount = $refund['amount'] ?? 0;
            if (is_int($amount) && $statusFilter(self::stringValue($refund['status'] ?? null))) {
                $total += $amount;
            }
        }

        return $total;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
