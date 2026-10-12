<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use Auditable, HasFactory;

    protected $fillable = ['code', 'type', 'value', 'expires_at', 'is_active'];

    protected $casts = [
        'value' => 'decimal:2',
        'expires_at' => 'date',
        'is_active' => 'boolean',
    ];
}