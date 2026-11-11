<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class Money
{
    public static function parse(?string $amount, ?string $currency, string $field = 'amount'): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }
        $digits = config('spendtrace.currencies')[$currency ?? ''] ?? null;
        if ($digits === null || ! preg_match('/^-?\d{1,9}(?:\.\d{1,3})?$/D', $amount)) {
            throw ValidationException::withMessages([$field => 'Enter an exact amount and a supported currency.']);
        }
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-')), 2, '');
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
