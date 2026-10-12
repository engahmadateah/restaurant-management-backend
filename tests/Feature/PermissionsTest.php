<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class PermissionsTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/orders')->assertStatus(401);
    }

    public function test_chef_can_read_menu_but_not_write_it(): void
    {
        $this->actingAsRole('chef');
        $item = $this->makeMenuItem();

        $this->getJson('/api/menu-items')->assertOk();
        $this->postJson('/api/menu-items', ['name' => 'x', 'price' => 1])->assertForbidden();
        $this->deleteJson("/api/menu-items/{$item->id}")->assertForbidden();
        $this->assertDatabaseHas('menu_items', ['id' => $item->id]);
    }

    public function test_chef_and_driver_cannot_touch_money_or_delete_orders(): void
    {
        foreach (['chef', 'driver'] as $role) {
            $this->actingAsRole($role);
            $order = $this->makeOrder();

            $this->postJson('/api/payments', ['order_id' => $order->id, 'method' => 'cash', 'amount' => 10])->assertForbidden();
            $this->postJson('/api/refunds', ['order_id' => $order->id, 'amount' => 1, 'reason' => 'x'])->assertForbidden();
            $this->deleteJson("/api/orders/{$order->id}")->assertForbidden();
        }
    }

    public function test_cashier_can_work_orders_and_payments_but_not_delete_or_manage_coupons(): void
    {
        $this->actingAsRole('cashier');
        $order = $this->makeOrder(50);

        $this->getJson('/api/orders')->assertOk();
        $this->postJson('/api/payments', ['order_id' => $order->id, 'method' => 'cash', 'amount' => 50])->assertCreated();

        $this->deleteJson("/api/orders/{$order->id}")->assertForbidden();
        $this->postJson('/api/coupons', ['code' => 'X', 'type' => 'fixed', 'value' => 5])->assertForbidden();
        $this->getJson('/api/users')->assertForbidden();
    }

    public function test_cashier_cannot_approve_refunds_or_adjust_loyalty(): void
    {
        $this->actingAsRole('cashier');
        $customer = $this->makeCustomer();

        $this->postJson("/api/customers/{$customer->id}/loyalty/adjust", ['points' => 500, 'reason' => 'free'])->assertForbidden();
        $this->deleteJson("/api/customers/{$customer->id}")->assertForbidden();
    }

    public function test_manager_can_manage_coupons_and_delete_cancelled_orders(): void
    {
        $this->actingAsRole('manager');

        $this->postJson('/api/coupons', ['code' => 'WELCOME', 'type' => 'fixed', 'value' => 5])->assertCreated();

        $order = $this->makeOrder(20, ['status' => 'cancelled']);
        $this->deleteJson("/api/orders/{$order->id}")->assertOk();
    }
}
