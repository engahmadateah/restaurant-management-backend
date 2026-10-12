<?php

namespace Tests\Concerns;

use App\Models\Category;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

trait CreatesRestaurantData
{
    protected function actingAsRole(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role . uniqid() . '@test.local',
            'password' => 'secret123',
            'role' => $role,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        return $user;
    }

    protected function makeMenuItem(array $attrs = []): MenuItem
    {
        static $n = 0;
        $n++;

        $category = Category::firstOrCreate(['slug' => 'main'], ['name' => 'Main']);

        return MenuItem::create(array_merge([
            'category_id' => $category->id,
            'name' => 'Item ' . $n,
            'price' => 10.00,
            'is_available' => true,
            'track_stock' => false,
            'stock_quantity' => 0,
            'low_stock_threshold' => 0,
        ], $attrs));
    }

    protected function makeCustomer(array $attrs = []): Customer
    {
        static $n = 0;
        $n++;

        return Customer::create(array_merge([
            'name' => 'Customer ' . $n,
            'phone' => '0790000' . str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            'loyalty_points' => 0,
        ], $attrs));
    }

    /** طلب جاهز بقيمة total محددة (بدون المرور بالـ API) لاختبار الدفع والاسترجاع */
    protected function makeOrder(float $total = 100.00, array $attrs = []): Order
    {
        $user = User::first() ?? $this->actingAsRole('admin');

        return Order::create(array_merge([
            'order_number' => 'ORD-' . strtoupper(uniqid()),
            'user_id' => $user->id,
            'order_type' => 'takeaway',
            'status' => 'pending',
            'subtotal' => $total,
            'discount' => 0,
            'tax' => 0,
            'total' => $total,
        ], $attrs));
    }

    protected function placeOrder(array $items, array $extra = [])
    {
        return $this->postJson('/api/orders', array_merge([
            'order_type' => 'takeaway',
            'items' => $items,
        ], $extra));
    }
}
