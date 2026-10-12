<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModifierGroup extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'menu_item_id', 'name', 'is_required', 'max_selections', 'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'max_selections' => 'integer',
        'sort_order' => 'integer',
    ];

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class);
    }
}
