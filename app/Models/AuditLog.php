<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id', 'action', 'auditable_type', 'auditable_id',
        'old_values', 'new_values', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    // اسم مختصر للموديل بدون الـ namespace الكامل (Order بدل App\Models\Order)
    public function getAuditableTypeShortAttribute(): string
    {
        return class_basename($this->auditable_type);
    }

    protected $appends = ['auditable_type_short'];
}
