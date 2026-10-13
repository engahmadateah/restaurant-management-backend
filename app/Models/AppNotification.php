<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class AppNotification extends Model
{
    use HasFactory;

    protected $table = 'app_notifications';

    protected $fillable = [
        'user_id', 'customer_id', 'order_id', 'reservation_id',
        'type', 'title', 'message', 'is_read', 'read_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }


    public static function notifyUser(?int $userId, string $type, string $title, string $message, array $extra = []): ?self
    {
        if (! $userId) {
            return null;
        }

        return self::create(array_merge([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
        ], $extra));
    }

    public static function notifyRole(string $role, string $type, string $title, string $message, array $extra = []): void
    {
        $userIds = User::where('role', $role)->pluck('id');

        foreach ($userIds as $userId) {
            self::notifyUser($userId, $type, $title, $message, $extra);
        }
    }
}
