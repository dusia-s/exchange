<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Transaction;
use App\Enum\TransactionStatus;
use App\Repository\TransactionRepositoryInterface;
use App\Service\TransactionProcessorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:process-transactions', description: 'Processes pending and fraud-review transactions')]
final class ProcessTransactionsCommand extends Command
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly TransactionProcessorService $transactionProcessorService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pending = $this->transactionRepository->findByStatus(TransactionStatus::PENDING);
        $fraudReview = $this->transactionRepository->findByStatus(TransactionStatus::FRAUD_REVIEW);

        if ([] === $pending && [] === $fraudReview) {
            $io->info('No transactions to process.');

            return Command::SUCCESS;
        }

        foreach ($pending as $transaction) {
            if (!$this->transactionProcessorService->complete($transaction)) {
                $this->warnAlreadyProcessed($io, $transaction);
            } elseif (TransactionStatus::COMPLETED === $transaction->getStatus()) {
                $io->success(sprintf('Transaction #%d completed.', $transaction->getId()));
            } else {
                $io->warning(sprintf('Transaction #%d rejected (wallet missing or blocked).', $transaction->getId()));
            }
        }

        foreach ($fraudReview as $transaction) {
            $io->section(sprintf('Fraud review — Transaction #%d', $transaction->getId()));
            $io->definitionList(
                ['From wallet' => $transaction->getFromWalletId()],
                ['To wallet' => $transaction->getToWalletId()],
                ['Amount' => sprintf('%s %s → %s %s', $transaction->getFromAmount(), $transaction->getFromCurrency()->value, $transaction->getToAmount(), $transaction->getToCurrency()->value)],
                ['Exchange rate' => $transaction->getExchangeRate()],
                ['Spread' => $transaction->getSpread()],
                ['Created at' => $transaction->getCreatedAt()->format('Y-m-d H:i:s')],
            );

            $approved = $io->confirm('Approve this transaction?');

            if ($approved) {
                if (!$this->transactionProcessorService->complete($transaction)) {
                    $this->warnAlreadyProcessed($io, $transaction);
                } elseif (TransactionStatus::COMPLETED === $transaction->getStatus()) {
                    $io->success(sprintf('Transaction #%d approved and completed.', $transaction->getId()));
                } else {
                    $io->warning(sprintf('Transaction #%d rejected (wallet missing or blocked).', $transaction->getId()));
                }
            } elseif (!$this->transactionProcessorService->reject($transaction)) {
                $this->warnAlreadyProcessed($io, $transaction);
            } else {
                $io->warning(sprintf('Transaction #%d rejected.', $transaction->getId()));
            }
        }

        return Command::SUCCESS;
    }

    private function warnAlreadyProcessed(SymfonyStyle $io, Transaction $transaction): void
    {
        $io->note(sprintf(
            'Transaction #%d was already processed elsewhere (status: %s); skipped.',
            $transaction->getId(),
            $transaction->getStatus()->value,
        ));
    }
}
