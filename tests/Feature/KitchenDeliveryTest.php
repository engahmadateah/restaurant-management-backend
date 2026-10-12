<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class KitchenDeliveryTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    private function deliveryOrder(array $attrs = [])
    {
        return $this->makeOrder(30, array_merge([
            'order_type' => 'delivery',
            'delivery_status' => 'pending',
            'status' => 'ready',
        ], $attrs));
    }

    private function driver(): User
    {
        return User::create([
            'name' => 'Driver', 'email' => 'd' . uniqid() . '@t.local', 'password' => 'secret123',
            'role' => 'driver', 'is_active' => true,
        ]);
    }

    // ---------------- المطبخ ----------------

    public function test_kitchen_routes_need_kitchen_permission(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(10);

        $this->actingAsRole('driver');
        $this->getJson('/api/kitchen/queue')->assertForbidden();
        $this->postJson("/api/kitchen/orders/{$order->id}/start")->assertForbidden();

        $this->actingAsRole('chef');
        $this->getJson('/api/kitchen/queue')->assertOk();
        $this->postJson("/api/kitchen/orders/{$order->id}/start")->assertOk();
    }

    public function test_admin_can_grant_and_revoke_kitchen_permission_per_user(): void
    {
        $admin = $this->actingAsRole('admin');
        $driver = $this->driver();

        Sanctum::actingAs($driver);
        $this->getJson('/api/kitchen/queue')->assertForbidden();

        Sanctum::actingAs($admin);
        $this->putJson("/api/users/{$driver->id}/permissions", ['extra_permissions' => ['kitchen.manage']])->assertOk();

        Sanctum::actingAs($driver->fresh());
        $this->getJson('/api/kitchen/queue')->assertOk();

        Sanctum::actingAs($admin);
        $chef = $this->actingAsRole('chef');
        Sanctum::actingAs($admin);
        $this->putJson("/api/users/{$chef->id}/permissions", ['revoked_permissions' => ['kitchen.manage']])->assertOk();

        Sanctum::actingAs($chef->fresh());
        $this->getJson('/api/kitchen/queue')->assertForbidden();
    }

    // ---------------- التوصيل ----------------

    public function test_driver_can_only_update_his_own_delivery(): void
    {
        $this->actingAsRole('admin');
        $mine = $this->driver();
        $other = $this->driver();
        $order = $this->deliveryOrder(['driver_id' => $mine->id, 'delivery_status' => 'assigned']);

        Sanctum::actingAs($other);
        $this->postJson("/api/orders/{$order->id}/delivery-status", ['delivery_status' => 'out_for_delivery'])->assertForbidden();

        Sanctum::actingAs($mine);
        $this->postJson("/api/orders/{$order->id}/delivery-status", ['delivery_status' => 'out_for_delivery'])->assertOk();
        $this->postJson("/api/orders/{$order->id}/delivery-status", ['delivery_status' => 'delivered'])->assertOk();
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_driver_list_only_shows_his_orders(): void
    {
        $this->actingAsRole('admin');
        $mine = $this->driver();
        $other = $this->driver();
        $this->deliveryOrder(['driver_id' => $mine->id, 'delivery_status' => 'assigned']);
        $this->deliveryOrder(['driver_id' => $other->id, 'delivery_status' => 'assigned']);

        Sanctum::actingAs($mine);
        $this->getJson('/api/deliveries')->assertOk()->assertJsonCount(1);

        $this->actingAsRole('cashier');
        $this->getJson('/api/deliveries')->assertOk()->assertJsonCount(2);
    }

    public function test_driver_cannot_assign_drivers(): void
    {
        $this->actingAsRole('admin');
        $driver = $this->driver();
        $order = $this->deliveryOrder();

        Sanctum::actingAs($driver);
        $this->postJson("/api/orders/{$order->id}/assign-driver", ['driver_id' => $driver->id])->assertForbidden();
    }

    public function test_cashier_can_assign_active_driver_but_not_inactive_one(): void
    {
        $this->actingAsRole('cashier');
        $driver = $this->driver();
        $inactive = $this->driver();
        $inactive->update(['is_active' => false]);
        $order = $this->deliveryOrder();

        $this->postJson("/api/orders/{$order->id}/assign-driver", ['driver_id' => $inactive->id])->assertStatus(422);
        $this->postJson("/api/orders/{$order->id}/assign-driver", ['driver_id' => $driver->id])->assertOk();
        $this->assertSame('assigned', $order->fresh()->delivery_status);
    }

    public function test_cancelled_order_cannot_be_delivered_or_reassigned(): void
    {
        $this->actingAsRole('admin');
        $driver = $this->driver();
        $order = $this->deliveryOrder(['status' => 'cancelled', 'driver_id' => $driver->id, 'delivery_status' => 'assigned']);

        $this->postJson("/api/orders/{$order->id}/delivery-status", ['delivery_status' => 'out_for_delivery'])->assertStatus(422);
        $this->postJson("/api/orders/{$order->id}/assign-driver", ['driver_id' => $driver->id])->assertStatus(422);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_order_must_be_ready_before_leaving_for_delivery(): void
    {
        $this->actingAsRole('admin');
        $driver = $this->driver();
        $order = $this->deliveryOrder(['status' => 'preparing', 'driver_id' => $driver->id, 'delivery_status' => 'assigned']);

        $this->postJson("/api/orders/{$order->id}/delivery-status", ['delivery_status' => 'out_for_delivery'])->assertStatus(422);
    }
}
