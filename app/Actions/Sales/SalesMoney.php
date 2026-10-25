<?php

namespace App\Actions\Sales;

use Illuminate\Validation\ValidationException;

class SalesMoney
{
    public static function minor(string $amount): int
    {
        if (! preg_match('/^([0-9]{1,12})(?:\.([0-9]{1,2}))?$/D', $amount, $matches)) {
            throw ValidationException::withMessages(['amount' => 'Enter a non-negative amount with at most two decimal places.']);
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    public static function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
