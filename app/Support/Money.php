<?php

namespace App\Support;

/**
 * مقارنات المبالغ بالسنت (أعداد صحيحة) حتى ما يصير فيه أخطاء تقريب
 * ناتجة عن مقارنة float مباشرة (مثل 0.1 + 0.2 != 0.3).
 */
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
