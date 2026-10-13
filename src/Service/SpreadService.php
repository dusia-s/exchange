<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Currency;
use App\Util\Money;

class SpreadService
{
    private const array LIQUIDITY_SCORE = [
        Currency::USD->value => '1.00',
        Currency::EUR->value => '0.95',
        Currency::GBP->value => '0.85',
        Currency::CHF->value => '0.80',
        Currency::JPY->value => '0.75',
        Currency::PLN->value => '0.55',
        Currency::HUF->value => '0.40',
    ];

    private const string BASE_SPREAD_PERCENT = '0.5';

    public function calculateSpread(
        string $price,
        Currency $fromCurrency,
        Currency $toCurrency,
    ): string {
        if ($fromCurrency === $toCurrency) {
            return Money::round('0');
        }

        $fromLiquidity = self::LIQUIDITY_SCORE[$fromCurrency->value];
        $toLiquidity = self::LIQUIDITY_SCORE[$toCurrency->value];

        $pairLiquidity = bcdiv(bcadd($fromLiquidity, $toLiquidity, Money::CALCULATION_SCALE), '2', Money::CALCULATION_SCALE);

        $spreadPercent = bcdiv(self::BASE_SPREAD_PERCENT, $pairLiquidity, Money::CALCULATION_SCALE);

        $spread = bcdiv(bcmul($price, $spreadPercent, Money::CALCULATION_SCALE), '100', Money::CALCULATION_SCALE);

        return Money::round($spread);
    }
}
