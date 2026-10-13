<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Exception\InsufficientFundsException;
use App\Exception\SameWalletTransferException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Repository\DatabaseTransactionManagerInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Util\Money;
use DateTimeImmutable;

readonly class TransferService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private ExchangeRateService $exchangeRateService,
        private SpreadService $spreadService,
        private DatabaseTransactionManagerInterface $databaseTransactionManager,
    ) {
    }

    public function transfer(
        int $userId,
        int $fromWalletId,
        int $toWalletId,
        string $fromAmount,
    ): Transaction {
        if ($fromWalletId === $toWalletId) {
            throw new SameWalletTransferException();
        }

        return $this->databaseTransactionManager->transactional(function () use ($userId, $fromWalletId, $toWalletId, $fromAmount): Transaction {
            // Only the source wallet changes here, so it is the only row that needs a lock.
            $fromWallet = $this->walletRepository->findByIdForUpdate($fromWalletId);
            if (null === $fromWallet || $fromWallet->getUserId() !== $userId) {
                throw new WalletNotFoundException($fromWalletId);
            }

            $toWallet = $this->walletRepository->findById($toWalletId);
            if (null === $toWallet || $toWallet->getUserId() !== $userId) {
                throw new WalletNotFoundException($toWalletId);
            }

            if ($fromWallet->isBlocked()) {
                throw new WalletBlockedException($fromWalletId);
            }

            if ($toWallet->isBlocked()) {
                throw new WalletBlockedException($toWalletId);
            }

            $fromAmount = Money::round($fromAmount);

            if (bccomp($fromWallet->getBalance(), $fromAmount, Money::SCALE) < 0) {
                throw new InsufficientFundsException($fromWalletId);
            }

            $fromCurrency = $fromWallet->getCurrency();
            $toCurrency = $toWallet->getCurrency();

            $exchangeRate = $this->exchangeRateService->getExchangeRateBetween($fromCurrency, $toCurrency);
            $rawToAmount = bcmul($fromAmount, $exchangeRate, Money::CALCULATION_SCALE);
            $spread = $this->spreadService->calculateSpread($rawToAmount, $fromCurrency, $toCurrency);
            $toAmount = Money::round(bcsub($rawToAmount, $spread, Money::CALCULATION_SCALE));

            // Funds are held on the source wallet now; the target wallet is credited when the transaction is completed.
            $fromWallet->setBalance(bcsub($fromWallet->getBalance(), $fromAmount, Money::SCALE));
            $fromWallet->setLastActivityAt(new DateTimeImmutable());
            $this->walletRepository->save($fromWallet);

            $transaction = Transaction::create(
                fromWalletId: $fromWalletId,
                toWalletId: $toWalletId,
                fromAmount: $fromAmount,
                toAmount: $toAmount,
                fromCurrency: $fromCurrency,
                toCurrency: $toCurrency,
                spread: $spread,
                exchangeRate: bcround($exchangeRate, 6),
                requiresAntiFraudCheck: bccomp($toAmount, '15000', Money::SCALE) > 0,
            );

            $this->transactionRepository->save($transaction);

            return $transaction;
        });
    }
}
