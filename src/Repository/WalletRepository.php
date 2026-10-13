<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Wallet;
use App\Enum\Currency;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use ReflectionClass;

readonly class WalletRepository implements WalletRepositoryInterface
{
    private const string TABLE_NAME = 'wallets';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @throws Exception
     */
    public function findById(int $id): ?Wallet
    {
        return $this->fetchById($id, forUpdate: false);
    }

    /**
     * Locks the row until the surrounding database transaction ends.
     *
     * @throws Exception
     */
    public function findByIdForUpdate(int $id): ?Wallet
    {
        return $this->fetchById($id, forUpdate: true);
    }

    /**
     * @throws Exception
     */
    private function fetchById(int $id, bool $forUpdate): ?Wallet
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where('id = :id')
            ->andWhere('deleted_at IS NULL');

        if ($forUpdate) {
            $qb->forUpdate();
        }

        $row = $this->connection->fetchAssociative($qb->getSQL(), ['id' => $id]);

        if (!$row) {
            return null;
        }

        return $this->buildEntity($row);
    }

    /**
     * @return Wallet[]
     *
     * @throws Exception
     */
    public function findByUserId(int $userId): array
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where('user_id = :user_id')
            ->andWhere('deleted_at IS NULL');

        $rows = $this->connection->fetchAllAssociative($qb->getSQL(), ['user_id' => $userId]);

        return array_map($this->buildEntity(...), $rows);
    }

    /**
     * @throws Exception
     */
    public function findByUserIdAndCurrency(int $userId, Currency $currency): ?Wallet
    {
        return $this->fetchByUserIdAndCurrency($userId, $currency, deleted: false);
    }

    /**
     * @throws Exception
     */
    public function findDeletedByUserIdAndCurrency(int $userId, Currency $currency): ?Wallet
    {
        return $this->fetchByUserIdAndCurrency($userId, $currency, deleted: true);
    }

    /**
     * @throws Exception
     */
    private function fetchByUserIdAndCurrency(int $userId, Currency $currency, bool $deleted): ?Wallet
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where('user_id = :user_id')
            ->andWhere('currency = :currency')
            ->andWhere($deleted ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL');

        $row = $this->connection->fetchAssociative($qb->getSQL(), [
            'user_id' => $userId,
            'currency' => $currency->value,
        ]);

        if (!$row) {
            return null;
        }

        return $this->buildEntity($row);
    }

    /**
     * @throws Exception
     */
    public function save(Wallet $wallet): void
    {
        if (null === $wallet->getId()) {
            $this->insert($wallet);
        } else {
            $this->update($wallet);
        }
    }

    private function buildEntity(array $row): Wallet
    {
        return new Wallet(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            currency: Currency::from($row['currency']),
            balance: (string) $row['balance'],
            isBlocked: (bool) $row['is_blocked'],
            lastActivityAt: null !== $row['last_activity_at'] ? new DateTimeImmutable($row['last_activity_at']) : null,
            createdAt: new DateTimeImmutable($row['created_at']),
            deletedAt: null !== $row['deleted_at'] ? new DateTimeImmutable($row['deleted_at']) : null,
        );
    }

    /**
     * @throws Exception
     */
    private function insert(Wallet $wallet): void
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->insert(self::TABLE_NAME)
            ->values([
                'user_id' => ':user_id',
                'currency' => ':currency',
                'balance' => ':balance',
                'is_blocked' => ':is_blocked',
                'last_activity_at' => ':last_activity_at',
                'created_at' => ':created_at',
            ]);

        $this->connection->executeQuery(
            $qb->getSQL(),
            [
                'user_id' => $wallet->getUserId(),
                'currency' => $wallet->getCurrency()->value,
                'balance' => $wallet->getBalance(),
                'is_blocked' => (int) $wallet->isBlocked(),
                'last_activity_at' => $wallet->getLastActivityAt()?->setTimezone(timezone: new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'created_at' => $wallet->getCreatedAt()
                    ->setTimezone(timezone: new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s'),
            ]
        );

        $id = (int) $this->connection->lastInsertId();

        $reflectionClass = new ReflectionClass($wallet);
        $reflectionProperty = $reflectionClass->getProperty('id');
        $reflectionProperty->setValue(objectOrValue: $wallet, value: $id);
    }

    /**
     * @throws Exception
     */
    private function update(Wallet $wallet): void
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->update(self::TABLE_NAME)
            ->set('balance', ':balance')
            ->set('is_blocked', ':is_blocked')
            ->set('last_activity_at', ':last_activity_at')
            ->set('deleted_at', ':deleted_at')
            ->where('id = :id');

        $this->connection->executeQuery(
            $qb->getSQL(),
            [
                'balance' => $wallet->getBalance(),
                'is_blocked' => (int) $wallet->isBlocked(),
                'last_activity_at' => $wallet->getLastActivityAt()?->setTimezone(timezone: new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'deleted_at' => $wallet->getDeletedAt()?->setTimezone(timezone: new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'id' => $wallet->getId(),
            ]
        );
    }
}
