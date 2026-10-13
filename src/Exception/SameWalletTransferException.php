<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class SameWalletTransferException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Source and target wallet must differ.');
    }
}
