<?php

namespace App\Support;


class Money
{
    public static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function fromCents(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
