<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class Money
{
    /** Keep the printed decimal independent of currency; never use floats. */
    public static function decimal(?string $amount, string $field = 'amount'): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }
        if (! preg_match('/^-?\d{1,9}(?:\.\d{1,6})?$/D', $amount)) {
            throw ValidationException::withMessages([$field => 'Enter an exact amount using a decimal point, without grouping separators (up to 6 decimal places).']);
        }

        return $amount;
    }

    public static function parse(?string $amount, ?string $currency, string $field = 'amount'): ?int
    {
        $amount = self::decimal($amount, $field);
        if ($amount === null || $currency === null) {
            return null;
        }
        $digits = config('spendtrace.currencies')[$currency] ?? null;
        if ($digits === null) {
            throw ValidationException::withMessages([$field => 'Select a supported currency to calculate minor units.']);
        }

        return self::scaled($amount, $digits, $field);
    }

    /** Exact common-scale arithmetic for printed amounts, including unknown currencies. */
    public static function scaled(string $amount, int $digits = 6, string $field = 'amount'): int
    {
        self::decimal($amount, $field);
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-')), 2, '');
        $fraction = rtrim($fraction, '0');
        if (strlen($fraction) > $digits) {
            throw ValidationException::withMessages([$field => "This currency allows {$digits} decimal places."]);
        }

        return ((int) $whole * (10 ** $digits) + (int) str_pad($fraction, $digits, '0')) * ($negative ? -1 : 1);
    }

    public static function format(?int $minor, ?string $currency): ?string
    {
        if ($minor === null || ! array_key_exists($currency ?? '', config('spendtrace.currencies'))) {
            return null;
        }
        $digits = config('spendtrace.currencies')[$currency];
        $factor = 10 ** $digits;

        return ($minor < 0 ? '-' : '').intdiv(abs($minor), $factor).($digits ? '.'.str_pad((string) (abs($minor) % $factor), $digits, '0', STR_PAD_LEFT) : '');
    }
}
