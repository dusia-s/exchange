<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;
use Throwable;

readonly class DatabaseTransactionManager implements DatabaseTransactionManagerInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @throws Throwable
     */
    public function transactional(callable $operation): mixed
    {
        return $this->connection->transactional(static fn (): mixed => $operation());
    }
}
