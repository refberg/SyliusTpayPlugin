<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Api;

use Refberg\SyliusTpayPlugin\Api\Exception\TpayException;
use Refberg\SyliusTpayPlugin\Api\Model\TpayRefund;
use Refberg\SyliusTpayPlugin\Api\Model\TpayTransaction;
use Refberg\SyliusTpayPlugin\Api\Model\TransactionCreated;
use Refberg\SyliusTpayPlugin\Config\TpayCredentials;

interface TpayClientInterface
{
    /**
     * @param array<string, mixed> $transaction body of POST /transactions
     *
     * @throws TpayException
     */
    public function createTransaction(TpayCredentials $credentials, array $transaction): TransactionCreated;

    /**
     * @throws TpayException
     */
    public function getTransaction(TpayCredentials $credentials, string $transactionId): TpayTransaction;

    /**
     * Cancels a pending transaction (POST /transactions/{transactionId}/cancel), so it can no longer be paid.
     *
     * @throws TpayException
     */
    public function cancelTransaction(TpayCredentials $credentials, string $transactionId): void;

    /**
     * Requests a refund: the whole transaction when $amount is null, otherwise $amount in minor units.
     *
     * @throws TpayException
     *
     * @return list<TpayRefund> all refunds of the transaction after the request
     */
    public function createRefund(TpayCredentials $credentials, string $transactionId, ?int $amount): array;

    /**
     * @throws TpayException
     *
     * @return list<TpayRefund>
     */
    public function getRefunds(TpayCredentials $credentials, string $transactionId): array;
}
