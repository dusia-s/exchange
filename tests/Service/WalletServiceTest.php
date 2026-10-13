<?php

declare(strict_types=1);

namespace App\Tests\Service;

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
use App\Service\WalletService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class WalletServiceTest extends TestCase
{
    private WalletRepositoryInterface $walletRepository;
    private TransactionRepositoryInterface $transactionRepository;
    private DatabaseTransactionManagerInterface $databaseTransactionManager;
    private WalletService $walletService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->databaseTransactionManager = $this->createMock(DatabaseTransactionManagerInterface::class);
        $this->databaseTransactionManager
            ->method('transactional')
            ->willReturnCallback(static fn (callable $operation): mixed => $operation());

        $this->walletService = new WalletService(
            $this->walletRepository,
            $this->transactionRepository,
            $this->databaseTransactionManager,
        );
    }

    public function testCreateWalletSuccessfully(): void
    {
        $userId = 1;
        $currency = Currency::EUR;

        $this->walletRepository
            ->expects(self::once())
            ->method('findByUserIdAndCurrency')
            ->with($userId, $currency)
            ->willReturn(null);

        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with($this->isInstanceOf(Wallet::class));

        $wallet = $this->walletService->createWallet($userId, $currency);

        self::assertSame($userId, $wallet->getUserId());
        self::assertSame($currency, $wallet->getCurrency());
        self::assertSame('0.0000', $wallet->getBalance());
        self::assertFalse($wallet->isBlocked());
    }

    public function testCreateWalletThrowsWhenWalletAlreadyExists(): void
    {
        $userId = 1;
        $currency = Currency::PLN;

        $existingWallet = Wallet::create($userId, $currency);

        $this->walletRepository
            ->expects(self::once())
            ->method('findByUserIdAndCurrency')
            ->with($userId, $currency)
            ->willReturn($existingWallet);

        $this->walletRepository
            ->expects(self::never())
            ->method('save');

        $this->expectException(WalletAlreadyExistsException::class);
        $this->expectExceptionMessage('Wallet for user 1 in currency PLN already exists.');

        $this->walletService->createWallet($userId, $currency);
    }

    public function testCreateWalletRestoresDeletedWalletInSameCurrency(): void
    {
        $deletedWallet = $this->makeWallet(id: 7);
        $deletedWallet->markDeleted();

        $this->walletRepository->method('findByUserIdAndCurrency')->willReturn(null);
        $this->walletRepository
            ->expects(self::once())
            ->method('findDeletedByUserIdAndCurrency')
            ->with(1, Currency::PLN)
            ->willReturn($deletedWallet);
        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with($deletedWallet);

        $wallet = $this->walletService->createWallet(1, Currency::PLN);

        self::assertSame($deletedWallet, $wallet);
        self::assertSame(7, $wallet->getId());
        self::assertFalse($wallet->isDeleted());
    }

    public function testDeleteWalletMarksEmptyWalletDeleted(): void
    {
        $wallet = $this->makeWallet(id: 5);

        $this->walletRepository
            ->expects(self::once())
            ->method('findByIdForUpdate')
            ->with(5)
            ->willReturn($wallet);
        $this->transactionRepository
            ->expects(self::once())
            ->method('hasOpenTransactions')
            ->with(5)
            ->willReturn(false);
        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with($wallet);
        $this->databaseTransactionManager->expects(self::once())->method('transactional');

        $this->walletService->deleteWallet(1, 5);

        self::assertTrue($wallet->isDeleted());
    }

    public function testDeleteWalletThrowsWhenWalletNotFound(): void
    {
        $this->walletRepository->method('findByIdForUpdate')->willReturn(null);
        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 99 not found.');

        $this->walletService->deleteWallet(1, 99);
    }

    public function testDeleteWalletThrowsWhenWalletBelongsToOtherUser(): void
    {
        $this->walletRepository->method('findByIdForUpdate')->willReturn($this->makeWallet(id: 5, userId: 2));
        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 5 not found.');

        $this->walletService->deleteWallet(1, 5);
    }

    public function testDeleteWalletThrowsWhenWalletBlocked(): void
    {
        $wallet = $this->makeWallet(id: 5);
        $wallet->setIsBlocked(true);

        $this->walletRepository->method('findByIdForUpdate')->willReturn($wallet);
        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletBlockedException::class);

        $this->walletService->deleteWallet(1, 5);
    }

    public function testDeleteWalletThrowsWhenBalanceIsNotZero(): void
    {
        $wallet = $this->makeWallet(id: 5);
        $wallet->setBalance('0.0001');

        $this->walletRepository->method('findByIdForUpdate')->willReturn($wallet);
        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotEmptyException::class);
        $this->expectExceptionMessage('Wallet 5 must have a zero balance before it can be deleted.');

        $this->walletService->deleteWallet(1, 5);
    }

    public function testDeleteWalletThrowsWhenTransactionsArePending(): void
    {
        $wallet = $this->makeWallet(id: 5);

        $this->walletRepository->method('findByIdForUpdate')->willReturn($wallet);
        $this->transactionRepository->expects(self::once())->method('hasOpenTransactions')->with(5)->willReturn(true);
        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletHasPendingTransactionsException::class);

        $this->walletService->deleteWallet(1, 5);
    }

    private function makeWallet(int $id, int $userId = 1): Wallet
    {
        return new Wallet(
            id: $id,
            userId: $userId,
            currency: Currency::PLN,
            balance: '0.0000',
            isBlocked: false,
            lastActivityAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }
}
