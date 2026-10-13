<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\TransactionStatus;
use App\Repository\CompanyWalletRepositoryInterface;
use App\Repository\DatabaseTransactionManagerInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Util\Money;
use DateTimeImmutable;

final readonly class TransactionProcessorService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private CompanyWalletRepositoryInterface $companyWalletRepository,
        private DatabaseTransactionManagerInterface $databaseTransactionManager,
    ) {
    }

    /**
     * Completes the transaction, or rejects it when a wallet is missing.
     *
     * @return bool false when the transaction was already processed elsewhere; $transaction then reflects its current state
     */
    public function complete(Transaction $transaction): bool
    {
        return $this->databaseTransactionManager->transactional(function () use ($transaction): bool {
            $locked = $this->lockIfProcessable($transaction);
            if (null === $locked) {
                return false;
            }

            [$fromWallet, $toWallet] = $this->lockWallets($locked->getFromWalletId(), $locked->getToWalletId());

            if (null === $fromWallet || null === $toWallet) {
                $this->refundAndReject($locked, $fromWallet);
            } else {
                // The source wallet was already debited when the transfer was created.
                $toWallet->setBalance(bcadd($toWallet->getBalance(), $locked->getToAmount(), Money::SCALE));
                $toWallet->setLastActivityAt(new DateTimeImmutable());
                $this->walletRepository->save($toWallet);

                if (bccomp($locked->getSpread(), '0', Money::SCALE) > 0) {
                    $this->companyWalletRepository->addToBalance($locked->getToCurrency(), $locked->getSpread());
                }

                $this->finish($locked, TransactionStatus::COMPLETED);
            }

            $this->syncState($locked, $transaction);

            return true;
        });
    }

    /**
     * @return bool false when the transaction was already processed elsewhere; $transaction then reflects its current state
     */
    public function reject(Transaction $transaction): bool
    {
        return $this->databaseTransactionManager->transactional(function () use ($transaction): bool {
            $locked = $this->lockIfProcessable($transaction);
            if (null === $locked) {
                return false;
            }

            $this->refundAndReject($locked, $this->walletRepository->findByIdForUpdate($locked->getFromWalletId()));
            $this->syncState($locked, $transaction);

            return true;
        });
    }

    /**
     * Re-reads the transaction under a row lock, so two concurrent runs cannot both process it.
     */
    private function lockIfProcessable(Transaction $transaction): ?Transaction
    {
        $id = $transaction->getId();
        $locked = null !== $id ? $this->transactionRepository->findByIdForUpdate($id) : null;

        if (null === $locked) {
            return null;
        }

        if (!in_array($locked->getStatus(), [TransactionStatus::PENDING, TransactionStatus::FRAUD_REVIEW], true)) {
            $this->syncState($locked, $transaction);

            return null;
        }

        return $locked;
    }

    /**
     * Locks both wallets in ascending id order, so concurrent runs always take locks in the same order and cannot deadlock.
     *
     * @return array{?Wallet, ?Wallet}
     */
    private function lockWallets(int $fromWalletId, int $toWalletId): array
    {
        $ids = [$fromWalletId, $toWalletId];
        sort($ids);

        $wallets = [];
        foreach ($ids as $id) {
            $wallets[$id] = $this->walletRepository->findByIdForUpdate($id);
        }

        return [$wallets[$fromWalletId], $wallets[$toWalletId]];
    }

    private function refundAndReject(Transaction $transaction, ?Wallet $fromWallet): void
    {
        // Return the funds held on the source wallet when the transfer was created.
        if (null !== $fromWallet) {
            $fromWallet->setBalance(bcadd($fromWallet->getBalance(), $transaction->getFromAmount(), Money::SCALE));
            $fromWallet->setLastActivityAt(new DateTimeImmutable());
            $this->walletRepository->save($fromWallet);
        }

        $this->finish($transaction, TransactionStatus::REJECTED);
    }

    private function finish(Transaction $transaction, TransactionStatus $status): void
    {
        $transaction->setStatus($status);

        if ($transaction->requiresAntiFraudCheck()) {
            $transaction->setAntiFraudCheckedAt(new DateTimeImmutable());
        }

        $this->transactionRepository->save($transaction);
    }

    private function syncState(Transaction $source, Transaction $target): void
    {
        if ($source === $target) {
            return;
        }

        $target->setStatus($source->getStatus());
        $target->setAntiFraudCheckedAt($source->getAntiFraudCheckedAt());
    }
}
