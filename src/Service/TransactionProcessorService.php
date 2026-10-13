<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\TransactionStatus;
use App\Repository\CompanyWalletRepositoryInterface;
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
    ) {
    }

    /**
     * Completes the transaction, or rejects it when a wallet is missing.
     *
     * @return bool false when the transaction was already processed
     */
    public function complete(Transaction $transaction): bool
    {
        if (!$this->isProcessable($transaction)) {
            return false;
        }

        $fromWallet = $this->walletRepository->findById($transaction->getFromWalletId());
        $toWallet = $this->walletRepository->findById($transaction->getToWalletId());

        if (null === $fromWallet || null === $toWallet) {
            $this->refundAndReject($transaction, $fromWallet);
        } else {
            // The source wallet was already debited when the transfer was created.
            $toWallet->setBalance(bcadd($toWallet->getBalance(), $transaction->getToAmount(), Money::SCALE));
            $toWallet->setLastActivityAt(new DateTimeImmutable());
            $this->walletRepository->save($toWallet);

            if (bccomp($transaction->getSpread(), '0', Money::SCALE) > 0) {
                $this->companyWalletRepository->addToBalance($transaction->getToCurrency(), $transaction->getSpread());
            }

            $this->finish($transaction, TransactionStatus::COMPLETED);
        }

        return true;
    }

    /**
     * @return bool false when the transaction was already processed
     */
    public function reject(Transaction $transaction): bool
    {
        if (!$this->isProcessable($transaction)) {
            return false;
        }

        $this->refundAndReject($transaction, $this->walletRepository->findById($transaction->getFromWalletId()));

        return true;
    }

    private function isProcessable(Transaction $transaction): bool
    {
        return in_array($transaction->getStatus(), [TransactionStatus::PENDING, TransactionStatus::FRAUD_REVIEW], true);
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
}
