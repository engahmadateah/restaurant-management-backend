<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Reservation extends Model
{
    use Auditable, HasFactory;

   
    public const WITH = ['table', 'customer', 'user'];

  
    public const HOLD_BEFORE_MINUTES = 60;

    
    public const HOLD_AFTER_MINUTES = 120;

    protected $fillable = [
        'table_id', 'customer_id', 'user_id',
        'customer_name', 'customer_phone',
        'party_size', 'reservation_date', 'reservation_time',
        'status', 'notes',
    ];

    protected $casts = [
        'reservation_date' => 'date',
        'party_size' => 'integer',
        'status' => ReservationStatus::class,
    ];

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['date'] ?? null, fn ($q, $date) => $q->whereDate('reservation_date', $date))
            ->when($filters['table_id'] ?? null, fn ($q, $tableId) => $q->where('table_id', $tableId));
    }

    public function startsAt(): Carbon
    {
        return Carbon::parse($this->reservation_date->toDateString() . ' ' . substr((string) $this->reservation_time, 0, 5));
    }

    
    public function shouldHoldTable(?Carbon $now = null): bool
    {
        $now ??= now();
        $start = $this->startsAt();

        return $now->between(
            $start->copy()->subMinutes(self::HOLD_BEFORE_MINUTES),
            $start->copy()->addMinutes(self::HOLD_AFTER_MINUTES)
        );
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
