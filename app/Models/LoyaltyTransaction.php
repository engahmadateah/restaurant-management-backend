<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سجل حركات نقاط الولاء لكل زبون (كسب من طلب، استبدال بخصم، أو تعديل يدوي من الأدمن).
 */
class LoyaltyTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'order_id', 'points', 'type', 'description',
    ];

    protected $casts = [
        'points' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
