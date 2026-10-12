<?php

namespace App\Models;

use App\Enums\PayrollStatus;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payroll extends Model
{
    use Auditable;

    public const WITH_LIST = ['user:id,name,role'];

    public const WITH_DETAIL = ['user:id,name,role', 'generatedBy:id,name'];

    protected $fillable = [
        'user_id', 'period_start', 'period_end',
        'salary_type', 'hourly_rate', 'monthly_salary', 'overtime_multiplier',
        'total_hours', 'regular_hours', 'overtime_hours',
        'regular_pay', 'overtime_pay', 'deductions', 'gross_pay', 'net_pay',
        'status', 'generated_by', 'approved_at', 'paid_at', 'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'hourly_rate' => 'decimal:2',
        'monthly_salary' => 'decimal:2',
        'overtime_multiplier' => 'decimal:2',
        'total_hours' => 'decimal:2',
        'regular_hours' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'regular_pay' => 'decimal:2',
        'overtime_pay' => 'decimal:2',
        'deductions' => 'decimal:2',
        'gross_pay' => 'decimal:2',
        'net_pay' => 'decimal:2',
        'status' => PayrollStatus::class,
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    /**
     * الموظف العادي بيشوف كشوفات راتبه هو بس، صاحب صلاحية payroll.manage بيشوف الكل
     * (وبيقدر يفلتر بـ user_id). فلتر status متاح للكل.
     */
    public function scopeVisibleTo(Builder $query, User $viewer, array $filters = []): Builder
    {
        if (! $viewer->hasPermission('payroll.manage')) {
            $query->where('user_id', $viewer->id);
        } elseif (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query;
    }

    /** الكشوفات يلي فترتها بتتقاطع مع المجال from..to (الاتنين اختياريين) */
    public function scopeOverlapping(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn ($q, $f) => $q->whereDate('period_end', '>=', $f))
            ->when($to, fn ($q, $t) => $q->whereDate('period_start', '<=', $t));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by')->withTrashed();
    }
}
