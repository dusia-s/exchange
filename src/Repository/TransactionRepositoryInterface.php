<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Transaction;
use App\Enum\TransactionStatus;

interface TransactionRepositoryInterface
{
    public function findById(int $id): ?Transaction;

    /**
     * Same as findById(), but locks the row until the surrounding database transaction ends.
     */
    public function findByIdForUpdate(int $id): ?Transaction;

    /** @return Transaction[] */
    public function findByWalletId(int $walletId): array;

    /**
     * Whether the wallet sends or receives a transaction that is still pending or in fraud review.
     */
    public function hasOpenTransactions(int $walletId): bool;

    /** @return Transaction[] */
    public function findByStatus(TransactionStatus $status): array;

    public function save(Transaction $transaction): void;
}
