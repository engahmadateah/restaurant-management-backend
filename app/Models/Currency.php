<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use Auditable;

    protected $fillable = ['code', 'name', 'symbol', 'exchange_rate', 'is_default', 'is_active'];

    protected $casts = [
        'exchange_rate' => 'decimal:6',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    // بيحوّل مبلغ (مخزّن دايمًا بالعملة الأساسية) لهاد العملة
    public function convert(float $amountInBaseCurrency): float
    {
        return round($amountInBaseCurrency * (float) $this->exchange_rate, 2);
    }
}
