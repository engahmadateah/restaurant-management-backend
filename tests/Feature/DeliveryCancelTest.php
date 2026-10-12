<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class DeliveryCancelTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    private function driver(): User
    {
        return User::create([
            'name' => 'Driver', 'email' => 'd' . uniqid() . '@t.local', 'password' => 'secret123',
            'role' => 'driver', 'is_active' => true,
        ]);
    }

    public function test_cancelling_a_delivery_order_closes_its_delivery_and_notifies_the_driver(): void
    {
        $this->actingAsRole('admin');
        $driver = $this->driver();
        $order = $this->makeOrder(30, [
            'order_type' => 'delivery', 'status' => 'pending',
            'delivery_status' => 'assigned', 'driver_id' => $driver->id,
        ]);

        $this->putJson("/api/orders/{$order->id}", ['status' => 'cancelled'])->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('cancelled', $order->fresh()->delivery_status);
        $this->assertSame(1, AppNotification::where('user_id', $driver->id)->count());
    }

    public function test_cancelled_deliveries_are_not_counted_as_active_on_the_dashboard(): void
    {
        $this->actingAsRole('admin');

        $this->makeOrder(30, ['order_type' => 'delivery', 'status' => 'pending', 'delivery_status' => 'pending']);
        $cancelled = $this->makeOrder(30, ['order_type' => 'delivery', 'status' => 'pending', 'delivery_status' => 'pending']);
        $this->putJson("/api/orders/{$cancelled->id}", ['status' => 'cancelled'])->assertOk();

        $this->getJson('/api/dashboard/stats')->assertOk()->assertJsonPath('deliveries_active', 1);
    }

    public function test_takeaway_cancel_leaves_delivery_status_untouched(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(10, ['order_type' => 'takeaway', 'status' => 'pending']);

        $this->putJson("/api/orders/{$order->id}", ['status' => 'cancelled'])->assertOk();

        $this->assertSame('not_applicable', $order->fresh()->delivery_status);
    }

    public function test_delivery_order_cannot_be_completed_through_the_generic_update(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(30, ['order_type' => 'delivery', 'status' => 'ready', 'delivery_status' => 'pending']);

        $this->putJson("/api/orders/{$order->id}", ['status' => 'completed'])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame('ready', $order->fresh()->status);
    }
}
