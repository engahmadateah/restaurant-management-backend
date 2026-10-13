<?php

namespace App\Enums;

enum PayrollStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Paid = 'paid';

  
    public static function lockedValues(): array
    {
        return [self::Approved->value, self::Paid->value];
    }
}
