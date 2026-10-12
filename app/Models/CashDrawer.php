<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashDrawer extends Model
{
    use Auditable;

    /** صندوق مفتوح أكتر من هالمدة = على الأغلب منسي، الواجهة بتنبّه */
    public const STALE_AFTER_HOURS = 24;

    protected $fillable = [
        'user_id', 'shift_id', 'opening_float', 'opened_at', 'closed_at', 'closed_by',
        'cash_sales', 'cash_refunds', 'cash_expenses', 'cash_withdrawals', 'cash_deposits',
        'expected_cash', 'counted_cash', 'difference', 'notes',
    ];

    protected $casts = [
        'opening_float' => 'decimal:2',
        'cash_sales' => 'decimal:2',
        'cash_refunds' => 'decimal:2',
        'cash_expenses' => 'decimal:2',
        'cash_withdrawals' => 'decimal:2',
        'cash_deposits' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'counted_cash' => 'decimal:2',
        'difference' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    // اسمها closer (مش closedBy): relation باسم closedBy بتنكتب بالـ JSON تحت المفتاح closed_by
    // وبتطمس عمود closed_by (الـ id)
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by')->withTrashed();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    public function isStale(): bool
    {
        return $this->isOpen() && $this->opened_at->lte(now()->subHours(self::STALE_AFTER_HOURS));
    }

    /** هل الدفعة وقعت ضمن صندوق مسكّر؟ (لحماية الأرقام المقفولة من التعديل) */
    public static function closedCovers(Payment $payment): bool
    {
        if ($payment->received_by === null) {
            return false;
        }

        return static::query()
            ->where('user_id', $payment->received_by)
            ->whereNotNull('closed_at')
            ->where('opened_at', '<=', $payment->created_at)
            ->where('closed_at', '>=', $payment->created_at)
            ->exists();
    }
}
