<?php

namespace App\Enums;

enum PayrollStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Paid = 'paid';

    /** الكشوفات يلي بتدخل بحسابات الربح وبتقفل تعديل الورديات */
    public static function lockedValues(): array
    {
        return [self::Approved->value, self::Paid->value];
    }
}
