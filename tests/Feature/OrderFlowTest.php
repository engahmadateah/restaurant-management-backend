<?php

namespace Tests\Feature;

use App\Models\Coupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    // ---------------- المخزون ----------------

    public function test_order_decrements_tracked_stock(): void
    {
        $item = $this->makeMenuItem(['track_stock' => true, 'stock_quantity' => 5]);

        $this->placeOrder([['menu_item_id' => $item->id, 'quantity' => 2]])->assertCreated();

        $this->assertSame(3, $item->fresh()->stock_quantity);
    }

    public function test_cannot_order_more_than_stock(): void
    {
        $item = $this->makeMenuItem(['track_stock' => true, 'stock_quantity' => 2]);

        $this->placeOrder([['menu_item_id' => $item->id, 'quantity' => 3]])->assertStatus(422);

        $this->assertSame(2, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_same_item_on_two_lines_is_checked_as_one_quantity(): void
    {
        // قبل الإصلاح: كل سطر كان يتفحص لحاله (3 <= 5) فيعدّي 6 قطع من مخزون 5
        $item = $this->makeMenuItem(['track_stock' => true, 'stock_quantity' => 5]);

        $this->placeOrder([
            ['menu_item_id' => $item->id, 'quantity' => 3],
            ['menu_item_id' => $item->id, 'quantity' => 3],
        ])->assertStatus(422);

        $this->assertSame(5, $item->fresh()->stock_quantity);
    }

    public function test_unavailable_item_cannot_be_ordered(): void
    {
        $item = $this->makeMenuItem(['is_available' => false]);

        $this->placeOrder([['menu_item_id' => $item->id, 'quantity' => 1]])->assertStatus(422);
    }

    // ---------------- الكوبونات ----------------

    public function test_fixed_coupon_larger_than_subtotal_never_makes_total_negative(): void
    {
        $item = $this->makeMenuItem(['price' => 10]);
        Coupon::create(['code' => 'BIG', 'type' => 'fixed', 'value' => 500, 'is_active' => true]);

        $res = $this->placeOrder([['menu_item_id' => $item->id, 'quantity' => 1]], ['coupon_code' => 'BIG'])->assertCreated();

        $this->assertEquals(10.00, (float) $res->json('discount'));
        $this->assertEquals(0.00, (float) $res->json('total'));
    }

    public function test_invalid_or_expired_coupon_is_rejected_not_ignored(): void
    {
        $item = $this->makeMenuItem();
        Coupon::create(['code' => 'OLD', 'type' => 'fixed', 'value' => 1, 'is_active' => true, 'expires_at' => now()->subDay()]);
        $lines = [['menu_item_id' => $item->id, 'quantity' => 1]];

        $this->placeOrder($lines, ['coupon_code' => 'NOPE'])->assertStatus(422)->assertJsonValidationErrors('coupon_code');
        $this->placeOrder($lines, ['coupon_code' => 'OLD'])->assertStatus(422)->assertJsonValidationErrors('coupon_code');
    }

    public function test_coupon_is_valid_through_its_expiry_day(): void
    {
        $item = $this->makeMenuItem();
        Coupon::create(['code' => 'TODAY', 'type' => 'percentage', 'value' => 10, 'is_active' => true, 'expires_at' => now()->toDateString()]);

        $this->placeOrder([['menu_item_id' => $item->id, 'quantity' => 1]], ['coupon_code' => 'TODAY'])->assertCreated();
        $this->postJson('/api/coupons/validate', ['code' => 'TODAY'])->assertOk();
    }

    // ---------------- نقاط الولاء ----------------

    public function test_redeem_points_only_spends_points_actually_used(): void
    {
        // سعر النقطة الافتراضي 0.05 => طلب بـ 10 بيستهلك 200 نقطة كحد أقصى
        $item = $this->makeMenuItem(['price' => 10]);
        $customer = $this->makeCustomer(['loyalty_points' => 1000]);

        $res = $this->placeOrder(
            [['menu_item_id' => $item->id, 'quantity' => 1]],
            ['customer_id' => $customer->id, 'redeem_points' => 1000]
        )->assertCreated();

        $this->assertEquals(10.00, (float) $res->json('discount'));
        $this->assertSame(800, $customer->fresh()->loyalty_points);
    }

    // ---------------- حالات الطلب ----------------

    public function test_cancel_restores_stock_loyalty_points_and_table(): void
    {
        $item = $this->makeMenuItem(['price' => 10, 'track_stock' => true, 'stock_quantity' => 5]);
        $customer = $this->makeCustomer(['loyalty_points' => 100]);

        $id = $this->placeOrder(
            [['menu_item_id' => $item->id, 'quantity' => 2]],
            ['customer_id' => $customer->id, 'redeem_points' => 40]
        )->assertCreated()->json('id');

        $this->assertSame(3, $item->fresh()->stock_quantity);
        $this->assertSame(60, $customer->fresh()->loyalty_points);

        $this->putJson("/api/orders/{$id}", ['status' => 'cancelled'])->assertOk()->assertJsonPath('status', 'cancelled');

        $this->assertSame(5, $item->fresh()->stock_quantity);
        $this->assertSame(100, $customer->fresh()->loyalty_points);
    }

    public function test_cancel_twice_does_not_restore_stock_twice(): void
    {
        $item = $this->makeMenuItem(['track_stock' => true, 'stock_quantity' => 5]);
        $id = $this->placeOrder([['menu_item_id' => $item->id, 'quantity' => 2]])->json('id');

        $this->putJson("/api/orders/{$id}", ['status' => 'cancelled'])->assertOk();
        $this->putJson("/api/orders/{$id}", ['status' => 'cancelled'])->assertOk();

        $this->assertSame(5, $item->fresh()->stock_quantity);
    }

    public function test_final_states_cannot_be_reopened(): void
    {
        $item = $this->makeMenuItem();
        $id = $this->placeOrder([['menu_item_id' => $item->id, 'quantity' => 1]])->json('id');

        $this->putJson("/api/orders/{$id}", ['status' => 'completed'])->assertOk();
        $this->putJson("/api/orders/{$id}", ['status' => 'pending'])->assertStatus(422);
        $this->putJson("/api/orders/{$id}", ['status' => 'cancelled'])->assertStatus(422);
    }

    public function test_paid_order_cannot_be_cancelled_without_refund(): void
    {
        $item = $this->makeMenuItem(['price' => 10]);
        $res = $this->placeOrder([['menu_item_id' => $item->id, 'quantity' => 1]])->assertCreated();
        $id = $res->json('id');

        $this->postJson('/api/payments', ['order_id' => $id, 'method' => 'cash', 'amount' => 5])->assertCreated();

        $this->putJson("/api/orders/{$id}", ['status' => 'cancelled'])->assertStatus(422);
    }

    public function test_order_with_payments_cannot_be_deleted(): void
    {
        $order = $this->makeOrder(30);
        $this->postJson('/api/payments', ['order_id' => $order->id, 'method' => 'cash', 'amount' => 30])->assertCreated();

        $this->deleteJson("/api/orders/{$order->id}")->assertStatus(422);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_per_page_is_capped(): void
    {
        $this->getJson('/api/orders?per_page=100000')->assertOk()->assertJsonPath('per_page', 100);
    }
}
