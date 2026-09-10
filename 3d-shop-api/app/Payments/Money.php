<?php

namespace App\Payments;

/**
 * Cents in, provider strings out. PayPal wants "149.00" and gives it back the
 * same way, and going through a float to get there loses money on some values.
 */
class Money
{
    public static function toDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }

    public static function toCents(string $decimal): int
    {
        $decimal = trim($decimal);
        $negative = str_starts_with($decimal, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($decimal, '+-'), 2), 2, '0');

        $cents = ((int) $whole) * 100 + (int) substr(str_pad($fraction, 2, '0'), 0, 2);

        return $negative ? -$cents : $cents;
    }
}
