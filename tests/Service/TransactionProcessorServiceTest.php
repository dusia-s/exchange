<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Repository\CompanyWalletRepositoryInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\TransactionProcessorService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TransactionProcessorServiceTest extends TestCase
{
    private WalletRepositoryInterface $walletRepository;
    private TransactionRepositoryInterface $transactionRepository;
    private CompanyWalletRepositoryInterface $companyWalletRepository;
    private TransactionProcessorService $transactionProcessorService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->companyWalletRepository = $this->createMock(CompanyWalletRepositoryInterface::class);

        $this->transactionProcessorService = new TransactionProcessorService(
            $this->walletRepository,
            $this->transactionRepository,
            $this->companyWalletRepository,
        );
    }

    public function testCompleteCreditsTargetWalletAndSetsCompletedStatus(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance('400.0000');

        $toWallet = Wallet::create(1, Currency::EUR);
        $toWallet->setBalance('100.0000');

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with($toWallet);

        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with($transaction);

        $this->transactionProcessorService->complete($transaction);

        // The source wallet was debited when the transfer was created, so it is not touched again.
        self::assertSame('400.0000', $fromWallet->getBalance());
        self::assertSame('125.0000', $toWallet->getBalance());
        self::assertNotNull($toWallet->getLastActivityAt());
        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
        self::assertNull($transaction->getAntiFraudCheckedAt());
    }

    public function testCompleteAddsSpreadToCompanyWalletInTargetCurrency(): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, Wallet::create(1, Currency::PLN)],
                [2, Wallet::create(1, Currency::EUR)],
            ]);

        $this->companyWalletRepository
            ->expects(self::once())
            ->method('addToBalance')
            ->with(Currency::EUR, '0.5000');

        $this->transactionProcessorService->complete($transaction);
    }

    public function testCompleteSkipsCompanyWalletWhenSpreadIsZero(): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false, spread: '0.00');

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, Wallet::create(1, Currency::PLN)],
                [2, Wallet::create(1, Currency::EUR)],
            ]);

        $this->companyWalletRepository->expects(self::never())->method('addToBalance');

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
    }

    public function testCompleteSetsAntiFraudCheckedAtWhenRequired(): void
    {
        $toWallet = Wallet::create(1, Currency::EUR);

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: true);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, Wallet::create(1, Currency::PLN)],
                [2, $toWallet],
            ]);

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
        self::assertNotNull($transaction->getAntiFraudCheckedAt());
    }

    public function testCompleteRejectsWhenFromWalletNotFound(): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, null],
                [2, Wallet::create(1, Currency::EUR)],
            ]);

        $this->walletRepository->expects(self::never())->method('save');
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
    }

    public function testCompleteRejectsAndRefundsWhenToWalletNotFound(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance('400.0000');

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, null],
            ]);

        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with($fromWallet);
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertSame('500.0000', $fromWallet->getBalance());
    }

    public function testRejectRefundsSourceWalletAndSetsRejectedStatus(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance('400.0000');

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with($transaction);
        $this->walletRepository
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($fromWallet);
        $this->walletRepository
            ->expects($this->once())
            ->method('save')
            ->with($fromWallet);
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');

        $this->transactionProcessorService->reject($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertSame('500.0000', $fromWallet->getBalance());
        self::assertNull($transaction->getAntiFraudCheckedAt());
    }

    public function testRejectSetsAntiFraudCheckedAtWhenRequired(): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: true);

        $this->transactionProcessorService->reject($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertNotNull($transaction->getAntiFraudCheckedAt());
    }

    #[DataProvider('finalStatusProvider')]
    public function testAlreadyProcessedTransactionIsNotProcessedAgain(TransactionStatus $status): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);
        $transaction->setStatus($status);

        $this->walletRepository->expects(self::never())->method('findById');
        $this->walletRepository->expects(self::never())->method('save');
        $this->transactionRepository->expects(self::never())->method('save');
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');

        self::assertFalse($this->transactionProcessorService->complete($transaction));
        self::assertFalse($this->transactionProcessorService->reject($transaction));

        self::assertSame($status, $transaction->getStatus());
    }

    /**
     * @return iterable<string, array{TransactionStatus}>
     */
    public static function finalStatusProvider(): iterable
    {
        yield 'completed' => [TransactionStatus::COMPLETED];
        yield 'rejected' => [TransactionStatus::REJECTED];
    }

    private function makeTransaction(bool $requiresAntiFraudCheck, string $spread = '0.5000'): Transaction
    {
        return Transaction::create(
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: '100.0000',
            toAmount: '25.0000',
            fromCurrency: Currency::PLN,
            toCurrency: Currency::EUR,
            spread: $spread,
            exchangeRate: '0.250000',
            requiresAntiFraudCheck: $requiresAntiFraudCheck,
        );
    }
}
