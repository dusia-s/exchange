<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\Exception\WalletAlreadyExistsException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletHasPendingTransactionsException;
use App\Exception\WalletNotEmptyException;
use App\Exception\WalletNotFoundException;
use App\Repository\DatabaseTransactionManagerInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Util\Money;

readonly class WalletService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private DatabaseTransactionManagerInterface $databaseTransactionManager,
    ) {
    }

    public function createWallet(int $userId, Currency $currency): Wallet
    {
        $existing = $this->walletRepository->findByUserIdAndCurrency($userId, $currency);

        if (null !== $existing) {
            throw new WalletAlreadyExistsException($userId, $currency);
        }

        // The unique (user_id, currency) key still covers deleted wallets, so a deleted one is brought back instead.
        $wallet = $this->walletRepository->findDeletedByUserIdAndCurrency($userId, $currency);
        if (null !== $wallet) {
            $wallet->restore();
        } else {
            $wallet = Wallet::create($userId, $currency);
        }

        $this->walletRepository->save($wallet);

        return $wallet;
    }

    /**
     * Soft-deletes the wallet; its transactions keep referencing it.
     */
    public function deleteWallet(int $userId, int $walletId): void
    {
        $this->databaseTransactionManager->transactional(function () use ($userId, $walletId): void {
            // The lock makes a concurrent deposit or outgoing transfer wait, so the checks below see its result.
            $wallet = $this->walletRepository->findByIdForUpdate($walletId);
            if (null === $wallet || $wallet->getUserId() !== $userId) {
                throw new WalletNotFoundException($walletId);
            }

            if ($wallet->isBlocked()) {
                throw new WalletBlockedException($walletId);
            }

            if (0 !== bccomp($wallet->getBalance(), '0', Money::SCALE)) {
                throw new WalletNotEmptyException($walletId);
            }

            if ($this->transactionRepository->hasOpenTransactions($walletId)) {
                throw new WalletHasPendingTransactionsException($walletId);
            }

            $wallet->markDeleted();
            $this->walletRepository->save($wallet);
        });
    }
}
