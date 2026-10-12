<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'menu_item_id', 'quantity', 'price', 'tax_rate', 'tax_amount', 'notes'];

    protected $casts = [
        'price' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'tax_amount' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class)->withTrashed();
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class);
    }

    /**
     * إجمالي سطر هاد الصنف بالطلب (السعر + كل الإضافات المختارة) × الكمية.
     */
    public function getLineTotalAttribute(): float
    {
        $modifiersTotal = $this->modifiers->sum('extra_price');

        return ((float) $this->price + (float) $modifiersTotal) * $this->quantity;
    }

    protected $appends = ['line_total'];
}