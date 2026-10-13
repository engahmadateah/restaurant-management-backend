<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Support\Money;

class Order extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'order_number', 'table_id', 'customer_id', 'user_id', 'coupon_id',
        'order_type', 'status', 'subtotal', 'discount', 'tax', 'total',
        'payment_status', 'notes',
        'preparing_at', 'ready_at', 'completed_at',
        'loyalty_points_earned', 'loyalty_points_redeemed',
        'driver_id', 'delivery_address', 'delivery_phone', 'delivery_fee', 'delivery_status',
        'source', 'public_token', 'public_request_id',
    ];

   
    protected $hidden = ['public_token', 'public_request_id'];

    protected array $auditExclude = ['public_token', 'public_request_id'];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'preparing_at' => 'datetime',
        'ready_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

   
    public const STATUS_TRANSITIONS = [
        'pending' => ['preparing', 'ready', 'completed', 'cancelled'],
        'preparing' => ['ready', 'completed', 'cancelled'],
        'ready' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::STATUS_TRANSITIONS[$this->status] ?? [], true);
    }

    
    public function paymentTotals(): array
    {
        $gross = Money::cents($this->payments()->whereIn('status', ['paid', 'refunded'])->sum('amount'));
        $approved = Money::cents($this->refunds()->where('status', 'approved')->sum('amount'));
        $pending = Money::cents($this->refunds()->where('status', 'pending')->sum('amount'));

        return [
            'gross' => $gross,
            'approved' => $approved,
            'pending' => $pending,
            'net' => $gross - $approved,
        ];
    }

    public function syncPaymentStatus(): void
    {
        $t = $this->paymentTotals();
        $total = Money::cents($this->total);

        if ($t['gross'] > 0 && $t['approved'] >= $t['gross']) {
            $status = 'refunded';
        } elseif ($total > 0 && $t['net'] >= $total) {
            $status = 'paid';
        } elseif ($t['net'] > 0) {
            $status = 'partial';
        } else {
            $status = 'unpaid';
        }

        if ($this->payment_status !== $status) {
            $this->update(['payment_status' => $status]);
        }
    }

   
    public function cancel(): void
    {
        DB::transaction(function () {
            $order = self::whereKey($this->id)->lockForUpdate()->firstOrFail();

            if ($order->status === 'cancelled') {
                return;
            }

            if (! $order->canTransitionTo('cancelled')) {
                throw ValidationException::withMessages([
                    'status' => ['ما بينقدر ينلغي طلب حالته "' . $order->status . '"'],
                ]);
            }

            if ($order->paymentTotals()['net'] > 0) {
                throw ValidationException::withMessages([
                    'status' => ['الطلب عليه مبلغ مدفوع، لازم تعمل استرجاع قبل الإلغاء'],
                ]);
            }

           
            $order->load('items');
            foreach ($order->items->sortBy('menu_item_id') as $item) {
                $menuItem = MenuItem::withTrashed()->whereKey($item->menu_item_id)->lockForUpdate()->first();
                if ($menuItem && $menuItem->track_stock) {
                    $menuItem->increment('stock_quantity', $item->quantity);
                }
            }

       
            if ($order->loyalty_points_redeemed > 0 && $order->customer_id) {
                $customer = Customer::whereKey($order->customer_id)->lockForUpdate()->first();
                $customer?->addLoyaltyPoints(
                    (int) $order->loyalty_points_redeemed,
                    'adjust',
                    'إرجاع نقاط بسبب إلغاء الطلب ' . $order->order_number,
                    $order->id
                );
            }

            if ($order->table_id) {
                $order->table()->update(['status' => 'available']);
            }

            $update = ['status' => 'cancelled'];

           
            if ($order->order_type === 'delivery') {
                $update['delivery_status'] = 'cancelled';
            }

            $order->update($update);

            
            if ($order->order_type === 'delivery' && $order->driver_id) {
                AppNotification::notifyUser(
                    $order->driver_id,
                    'order_status',
                    'تم إلغاء طلب توصيل',
                    'الطلب رقم ' . $order->order_number . ' انلغى',
                    ['order_id' => $order->id]
                );
            }

            $this->setRawAttributes($order->getAttributes(), true);
        });
    }

  
    public function markCompleted(): void
    {
        if ($this->status === 'completed') {
            return;
        }

        $this->status = 'completed';
        $this->completed_at = now();
        $this->save();

        if ($this->table_id) {
            $this->table()->update(['status' => 'available']);
        }

        if ($this->customer_id && $this->loyalty_points_earned == 0) {
            $rate = (float) (Setting::get('loyalty_points_per_currency', 1));
            $points = (int) floor(((float) $this->total) * $rate);

            if ($points > 0) {
                $this->customer->addLoyaltyPoints(
                    $points,
                    'earn',
                    'نقاط من الطلب ' . $this->order_number,
                    $this->id
                );

                $this->loyalty_points_earned = $points;
                $this->save();
            }
        }
    }
}
