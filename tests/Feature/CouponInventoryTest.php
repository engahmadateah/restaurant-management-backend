<?php

namespace Tests\Feature;

use App\Models\Coupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class CouponInventoryTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    // ---------------- الكوبون ----------------

    public function test_percentage_coupon_cannot_exceed_100_percent(): void
    {
        $this->postJson('/api/coupons', ['code' => 'FREE', 'type' => 'percentage', 'value' => 150])
            ->assertStatus(422)->assertJsonValidationErrors('value');

        $this->postJson('/api/coupons', ['code' => 'FULL', 'type' => 'percentage', 'value' => 100])->assertCreated();
        // الكوبون الثابت ممكن يكون أي قيمة
        $this->postJson('/api/coupons', ['code' => 'BIG', 'type' => 'fixed', 'value' => 500])->assertCreated();
    }

    public function test_update_cannot_turn_a_big_fixed_coupon_into_a_percentage(): void
    {
        $coupon = Coupon::create(['code' => 'FIX', 'type' => 'fixed', 'value' => 500, 'is_active' => true]);

        // تغيير النوع بس (القيمة الحالية 500 لحالها غلط كنسبة)
        $this->putJson("/api/coupons/{$coupon->id}", ['type' => 'percentage'])
            ->assertStatus(422)->assertJsonValidationErrors('value');

        // تغيير القيمة بس على كوبون نسبي
        $pct = Coupon::create(['code' => 'PCT', 'type' => 'percentage', 'value' => 10, 'is_active' => true]);
        $this->putJson("/api/coupons/{$pct->id}", ['value' => 101])->assertStatus(422)->assertJsonValidationErrors('value');
        $this->putJson("/api/coupons/{$pct->id}", ['value' => 20])->assertOk();
    }

    public function test_legacy_percentage_coupon_above_100_never_discounts_more_than_the_subtotal(): void
    {
        // بيانات قديمة دخلت قبل الإصلاح
        Coupon::create(['code' => 'OLD150', 'type' => 'percentage', 'value' => 150, 'is_active' => true]);
        $item = $this->makeMenuItem(['price' => 20]);

        $res = $this->placeOrder([['menu_item_id' => $item->id, 'quantity' => 1]], ['coupon_code' => 'OLD150'])->assertCreated();

        $this->assertEquals(20.0, (float) $res->json('discount')); // مش أكتر من المجموع
    }

    // ---------------- المخزون ----------------

    public function test_inventory_settings_with_null_quantity_keeps_the_current_values(): void
    {
        $item = $this->makeMenuItem(['track_stock' => true, 'stock_quantity' => 7, 'low_stock_threshold' => 2]);

        $this->putJson("/api/inventory/{$item->id}/settings", [
            'track_stock' => true,
            'stock_quantity' => null,
            'low_stock_threshold' => 3,
        ])->assertOk();

        $item->refresh();
        $this->assertSame(7, $item->stock_quantity);
        $this->assertSame(3, $item->low_stock_threshold);
    }

    public function test_inventory_settings_can_still_set_quantity(): void
    {
        $item = $this->makeMenuItem(['track_stock' => false, 'stock_quantity' => 0]);

        $this->putJson("/api/inventory/{$item->id}/settings", ['track_stock' => true, 'stock_quantity' => 12])->assertOk();

        $this->assertSame(12, $item->fresh()->stock_quantity);
        $this->assertTrue($item->fresh()->track_stock);
    }
}
