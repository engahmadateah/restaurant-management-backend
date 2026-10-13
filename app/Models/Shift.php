<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shift extends Model
{
    use Auditable, HasFactory;

    protected $fillable = ['user_id', 'clock_in', 'clock_out', 'auto_closed', 'notes', 'adjusted_by'];

    protected $casts = [
        'clock_in' => 'datetime',
        'clock_out' => 'datetime',
        'auto_closed' => 'boolean',
    ];

 
    public static function maxMinutes(): int
    {
        $hours = (float) Setting::get('shift_max_hours', 12);

        return (int) round(max(1, min($hours, 24)) * 60);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }


    public function getDurationMinutesAttribute(): ?int
    {
        if (! $this->clock_out) {
            return null;
        }

        return $this->clock_in->diffInMinutes($this->clock_out);
    }

    protected $appends = ['duration_minutes'];
}
