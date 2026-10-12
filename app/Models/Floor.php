<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Floor extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'name', 'canvas_width', 'canvas_height', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class);
    }
}
