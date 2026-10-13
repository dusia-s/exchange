<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Enum\TransactionStatus;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Util\Money;
use DateTimeImmutable;

final readonly class TransactionProcessorService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
    ) {
    }

    public function complete(Transaction $transaction): void
    {
        $fromWallet = $this->walletRepository->findById($transaction->getFromWalletId());
        $toWallet = $this->walletRepository->findById($transaction->getToWalletId());

        if (null === $fromWallet || null === $toWallet) {
            $this->reject($transaction);

            return;
        }

        $fromWallet->setBalance(bcsub($fromWallet->getBalance(), $transaction->getFromAmount(), Money::SCALE));
        $fromWallet->setLastActivityAt(new DateTimeImmutable());

        $toWallet->setBalance(bcadd($toWallet->getBalance(), $transaction->getToAmount(), Money::SCALE));
        $toWallet->setLastActivityAt(new DateTimeImmutable());

        $this->walletRepository->save($fromWallet);
        $this->walletRepository->save($toWallet);

        $transaction->setStatus(TransactionStatus::COMPLETED);

        if ($transaction->requiresAntiFraudCheck()) {
            $transaction->setAntiFraudCheckedAt(new DateTimeImmutable());
        }

        $this->transactionRepository->save($transaction);
    }

    public function reject(Transaction $transaction): void
    {
        $transaction->setStatus(TransactionStatus::REJECTED);

        if ($transaction->requiresAntiFraudCheck()) {
            $transaction->setAntiFraudCheckedAt(new DateTimeImmutable());
        }

        $this->transactionRepository->save($transaction);
    }
}
