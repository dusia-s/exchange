<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Wallet;
use App\Enum\Currency;

interface WalletRepositoryInterface
{
    public function findById(int $id): ?Wallet;

    /**
     * Same as findById(), but locks the row until the surrounding database transaction ends.
     */
    public function findByIdForUpdate(int $id): ?Wallet;

    /** @return Wallet[] */
    public function findByUserId(int $userId): array;

    public function findByUserIdAndCurrency(int $userId, Currency $currency): ?Wallet;

    public function save(Wallet $wallet): void;
}
