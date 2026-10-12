<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use Auditable, HasFactory;

    protected $fillable = ['name', 'phone', 'email', 'address', 'loyalty_points'];

    protected $casts = [
        'loyalty_points' => 'integer',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    // الأصناف المفضّلة عند الزبون (Wishlist)
    public function favorites()
    {
        return $this->belongsToMany(MenuItem::class, 'customer_favorites')->withTimestamps();
    }

    /**
     * إضافة نقاط ولاء (كسب أو تعديل يدوي بالزيادة) + تسجيلها بالسجل.
     */
    public function addLoyaltyPoints(int $points, string $type = 'earn', ?string $description = null, ?int $orderId = null): void
    {
        if ($points <= 0) {
            return;
        }

        $this->increment('loyalty_points', $points);

        $this->loyaltyTransactions()->create([
            'order_id' => $orderId,
            'points' => $points,
            'type' => $type,
            'description' => $description,
        ]);
    }

    /**
     * استبدال (خصم) نقاط ولاء + تسجيلها بالسجل كقيمة سالبة.
     */
    public function redeemLoyaltyPoints(int $points, ?string $description = null, ?int $orderId = null, string $type = 'redeem'): void
    {
        if ($points <= 0) {
            return;
        }

        $this->decrement('loyalty_points', $points);

        $this->loyaltyTransactions()->create([
            'order_id' => $orderId,
            'points' => -$points,
            'type' => $type,
            'description' => $description,
        ]);
    }
}
