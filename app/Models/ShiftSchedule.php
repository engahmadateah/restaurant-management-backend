<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// وردية مجدولة مسبقًا (متل "وليد شغال بكرا من 9-5") - مختلفة عن Shift
// يلي هو تسجيل حضور/انصراف فعلي بالـ clock-in/out
class ShiftSchedule extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'user_id', 'scheduled_date', 'start_time', 'end_time',
        'status', 'notes', 'created_by', 'shift_id',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
