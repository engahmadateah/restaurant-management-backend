<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestaurantTable extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'table_number', 'capacity', 'status',
        'floor_id', 'pos_x', 'pos_y', 'width', 'height', 'rotation', 'shape',
    ];

    // رمز الـ QR سرّي: ما بيطلع بأي رد عادي (بس من GET /tables/{id}/qr لصاحب tables.manage)
    protected $hidden = ['qr_token'];

    protected array $auditExclude = ['qr_token'];

    protected $casts = [
        'pos_x' => 'float',
        'pos_y' => 'float',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'table_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'table_id');
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }
}