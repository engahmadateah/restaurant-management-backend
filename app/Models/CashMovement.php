<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    use Auditable;

    public const TYPE_EXPENSE = 'expense';
    public const TYPE_WITHDRAWAL = 'withdrawal';
    public const TYPE_DEPOSIT = 'deposit';

    public const TYPES = [self::TYPE_EXPENSE, self::TYPE_WITHDRAWAL, self::TYPE_DEPOSIT];

 
    public const OUTFLOW_TYPES = [self::TYPE_EXPENSE, self::TYPE_WITHDRAWAL];

    protected $fillable = ['cash_drawer_id', 'user_id', 'type', 'amount', 'reason', 'expense_id'];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function drawer(): BelongsTo
    {
        return $this->belongsTo(CashDrawer::class, 'cash_drawer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function isOutflow(): bool
    {
        return in_array($this->type, self::OUTFLOW_TYPES, true);
    }
}
