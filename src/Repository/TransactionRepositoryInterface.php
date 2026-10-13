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

    /** @return Transaction[] */
    public function findByStatus(TransactionStatus $status): array;

    public function save(Transaction $transaction): void;
}
