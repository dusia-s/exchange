<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class WalletNotEmptyException extends RuntimeException
{
    public function __construct(int $walletId)
    {
        parent::__construct(sprintf('Wallet %d must have a zero balance before it can be deleted.', $walletId));
    }
}
