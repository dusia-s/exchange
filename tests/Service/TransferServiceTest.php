<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\InsufficientFundsException;
use App\Exception\SameWalletTransferException;
use App\Exception\WalletNotFoundException;
use App\Repository\DatabaseTransactionManagerInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\ExchangeRateService;
use App\Service\SpreadService;
use App\Service\TransferService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TransferServiceTest extends TestCase
{
    private WalletRepositoryInterface $walletRepository;
    private TransactionRepositoryInterface $transactionRepository;
    private ExchangeRateService $exchangeRateService;
    private SpreadService $spreadService;
    private TransferService $transferService;
    private DatabaseTransactionManagerInterface $databaseTransactionManager;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->exchangeRateService = $this->createMock(ExchangeRateService::class);
        $this->spreadService = $this->createMock(SpreadService::class);
        $this->databaseTransactionManager = $this->createMock(DatabaseTransactionManagerInterface::class);
        $this->databaseTransactionManager
            ->method('transactional')
            ->willReturnCallback(static fn (callable $operation): mixed => $operation());

        $this->transferService = new TransferService(
            $this->walletRepository,
            $this->transactionRepository,
            $this->exchangeRateService,
            $this->spreadService,
            $this->databaseTransactionManager,
        );
    }

    public function testTransferSuccessfully(): void
    {
        $userId = 1;
        $fromWallet = Wallet::create($userId, Currency::PLN);
        $fromWallet->setBalance('5000.0000');
        $toWallet = Wallet::create($userId, Currency::EUR);
        $toWallet->setBalance('100.0000');

        $this->walletRepository
            ->expects(self::once())
            ->method('findByIdForUpdate')
            ->with(1)
            ->willReturn($fromWallet);
        $this->walletRepository
            ->expects(self::once())
            ->method('findById')
            ->with(2)
            ->willReturn($toWallet);

        $this->exchangeRateService
            ->expects(self::once())
            ->method('getExchangeRateBetween')
            ->with(Currency::PLN, Currency::EUR)
            ->willReturn('0.25');

        $this->spreadService
            ->expects(self::once())
            ->method('calculateSpread')
            ->with('250.0000000000', Currency::PLN, Currency::EUR)
            ->willReturn('1.00');

        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with($fromWallet);

        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with($this->isInstanceOf(Transaction::class));

        $this->databaseTransactionManager->expects(self::once())->method('transactional');

        $transaction = $this->transferService->transfer($userId, 1, 2, '1000.00');

        // Funds are held on the source wallet; the target wallet is only credited on completion.
        self::assertSame('4000.0000', $fromWallet->getBalance());
        self::assertNotNull($fromWallet->getLastActivityAt());
        self::assertSame('100.0000', $toWallet->getBalance());
        self::assertSame(TransactionStatus::PENDING, $transaction->getStatus());
        self::assertFalse($transaction->requiresAntiFraudCheck());
        self::assertSame('1000.0000', $transaction->getFromAmount());
        self::assertSame('249.0000', $transaction->getToAmount());
        self::assertSame('0.250000', $transaction->getExchangeRate());
        self::assertSame('1.00', $transaction->getSpread());
        self::assertSame(Currency::PLN, $transaction->getFromCurrency());
        self::assertSame(Currency::EUR, $transaction->getToCurrency());
    }

    public function testTransferThrowsWhenSourceAndTargetWalletAreTheSame(): void
    {
        $this->walletRepository->expects(self::never())->method('findByIdForUpdate');
        $this->databaseTransactionManager->expects(self::never())->method('transactional');
        $this->walletRepository->expects(self::never())->method('save');
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(SameWalletTransferException::class);

        $this->transferService->transfer(1, 1, 1, '100.00');
    }

    public function testTransferThrowsWhenInsufficientFunds(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance('99.9900');

        $this->stubWallets([
            [1, $fromWallet],
            [2, Wallet::create(1, Currency::EUR)],
        ]);

        $this->walletRepository->expects(self::never())->method('save');
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(InsufficientFundsException::class);
        $this->expectExceptionMessage('Insufficient funds in wallet 1.');

        $this->transferService->transfer(1, 1, 2, '100.00');
    }

    public function testTransferAllowsSpendingEntireBalance(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance('100.0000');

        $this->stubWallets([
            [1, $fromWallet],
            [2, Wallet::create(1, Currency::EUR)],
        ]);
        $this->exchangeRateService->method('getExchangeRateBetween')->willReturn('0.25');
        $this->spreadService->method('calculateSpread')->willReturn('0.13');

        $this->transferService->transfer(1, 1, 2, '100.00');

        self::assertSame('0.0000', $fromWallet->getBalance());
    }

    public function testTransferThrowsWhenFromWalletNotFound(): void
    {
        $this->walletRepository
            ->expects($this->once())
            ->method('findByIdForUpdate')
            ->with(99)
            ->willReturn(null);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 99 not found.');

        $this->transferService->transfer(1, 99, 2, '100.00');
    }

    public function testTransferThrowsWhenToWalletNotFound(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);

        $this->stubWallets([
            [1, $fromWallet],
            [99, null],
        ]);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 99 not found.');

        $this->transferService->transfer(1, 1, 99, '100.00');
    }

    public function testTransferThrowsWhenFromWalletBelongsToOtherUser(): void
    {
        $fromWallet = Wallet::create(2, Currency::PLN);

        $this->walletRepository
            ->expects($this->once())
            ->method('findByIdForUpdate')
            ->with(1)
            ->willReturn($fromWallet);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 1 not found.');

        $this->transferService->transfer(1, 1, 2, '100.00');
    }

    public function testTransferThrowsWhenToWalletBelongsToOtherUser(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $toWallet = Wallet::create(2, Currency::EUR);

        $this->stubWallets([
            [1, $fromWallet],
            [2, $toWallet],
        ]);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 2 not found.');

        $this->transferService->transfer(1, 1, 2, '100.00');
    }

    /**
     * @param list<array{int, ?Wallet}> $map
     */
    private function stubWallets(array $map): void
    {
        $this->walletRepository->method('findById')->willReturnMap($map);
        $this->walletRepository->method('findByIdForUpdate')->willReturnMap($map);
    }
}
