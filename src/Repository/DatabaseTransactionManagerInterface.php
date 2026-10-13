<?php

declare(strict_types=1);

namespace App\Repository;

interface DatabaseTransactionManagerInterface
{
    /**
     * Runs the operation inside a database transaction: commits when it returns, rolls back and rethrows when it throws.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function transactional(callable $operation): mixed;
}
