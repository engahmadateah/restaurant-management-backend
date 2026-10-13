<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MenuItem extends Model
{
    use Auditable, HasFactory, HasTranslations, SoftDeletes;

    protected $fillable = [
        'category_id', 'name', 'description', 'translations', 'price', 'tax_rate',
        'image', 'is_available', 'sort_order',
        'track_stock', 'stock_quantity', 'low_stock_threshold',
    ];

   
    protected array $translatable = ['name', 'description'];

    protected $casts = [
        'price' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'is_available' => 'boolean',
        'track_stock' => 'boolean',
        'stock_quantity' => 'integer',
        'low_stock_threshold' => 'integer',
        'translations' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function modifierGroups(): HasMany
    {
        return $this->hasMany(ModifierGroup::class);
    }

   
    public function getIsLowStockAttribute(): bool
    {
        return $this->track_stock && $this->stock_quantity <= $this->low_stock_threshold;
    }

    protected $appends = ['is_low_stock'];


    public function effectiveTaxRate(float $fallback): float
    {
        if ($this->tax_rate !== null) {
            return (float) $this->tax_rate;
        }

        if ($this->category && $this->category->tax_rate !== null) {
            return (float) $this->category->tax_rate;
        }

        return $fallback;
    }
}
