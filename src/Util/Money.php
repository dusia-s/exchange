<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Money amounts are decimal strings handled with bcmath, matching the DECIMAL(15,4) columns.
 */
final class Money
{
    /** Scale of stored amounts and balances. */
    public const int SCALE = 4;

    /** Scale of exchange rates and intermediate results, before rounding to SCALE. */
    public const int CALCULATION_SCALE = 10;

    private function __construct()
    {
    }

    /**
     * Brings a well-formed decimal string to the storage scale, rounding half away from zero.
     */
    public static function round(string $amount): string
    {
        return bcround($amount, self::SCALE);
    }
}
