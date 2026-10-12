<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\RestaurantTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class OrderListFiltersTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    /** طلب بتاريخ وحالة محددين. forceFill لأن payment_status/created_at مش دايماً fillable */
    private function order(string $day, array $attrs = [], float $total = 100): Order
    {
        $order = $this->makeOrder($total, ['order_number' => $attrs['order_number'] ?? 'ORD-' . strtoupper(uniqid())]);
        unset($attrs['order_number']);
        $order->forceFill(array_merge(['created_at' => "{$day} 12:00:00"], $attrs))->saveQuietly();

        return $order;
    }

    public function test_search_and_date_filters_apply_across_pages_not_just_the_first(): void
    {
        $this->actingAsRole('admin');
        // 25 طلب قديم + طلب هدف قديم كمان: البحث لازم يلاقيه حتى لو مش بالصفحة الأولى
        $target = $this->order('2026-09-01', ['order_number' => 'ORD-TARGET1']);
        for ($i = 0; $i < 25; $i++) {
            $this->order('2026-10-09');
        }

        $res = $this->getJson('/api/orders?per_page=20&search=TARGET1')->assertOk();
        $this->assertSame(1, $res->json('total'));
        $this->assertSame($target->id, $res->json('data.0.id'));

        $this->assertSame(25, $this->getJson('/api/orders?from=2026-10-09&to=2026-10-09&per_page=5')->json('total'));
        $this->assertSame(1, $this->getJson('/api/orders?to=2026-09-30')->json('total'));
    }

    public function test_search_matches_customer_name_phone_and_table_number(): void
    {
        $this->actingAsRole('admin');
        $customer = $this->makeCustomer(['name' => 'Samer Haddad', 'phone' => '0933111222']);
        $table = RestaurantTable::create(['table_number' => 'T-77', 'capacity' => 4, 'status' => 'available']);
        $byCustomer = $this->order('2026-10-09', ['customer_id' => $customer->id]);
        $byTable = $this->order('2026-10-09', ['table_id' => $table->id]);
        $this->order('2026-10-09');

        $ids = fn (string $q) => collect($this->getJson('/api/orders?search=' . urlencode($q))->json('data'))->pluck('id')->all();

        $this->assertSame([$byCustomer->id], $ids('Samer'));
        $this->assertSame([$byCustomer->id], $ids('0933111'));
        $this->assertSame([$byTable->id], $ids('T-77'));
        // % و _ حرفيين مو wildcard
        $this->assertSame([], $ids('%'));
    }

    public function test_payment_status_filter_including_outstanding(): void
    {
        $this->actingAsRole('admin');
        $this->order('2026-10-09', ['payment_status' => 'unpaid']);
        $this->order('2026-10-09', ['payment_status' => 'partial']);
        $this->order('2026-10-09', ['payment_status' => 'paid']);
        $this->order('2026-10-09', ['payment_status' => 'refunded']);

        $this->assertSame(1, $this->getJson('/api/orders?payment_status=paid')->json('total'));
        $this->assertSame(1, $this->getJson('/api/orders?payment_status=refunded')->json('total'));
        // outstanding = unpaid + partial
        $this->assertSame(2, $this->getJson('/api/orders?payment_status=outstanding')->json('total'));
    }

    public function test_summary_covers_the_whole_filtered_set(): void
    {
        $this->actingAsRole('admin');
        for ($i = 0; $i < 6; $i++) {
            $this->order('2026-10-09', ['status' => 'completed', 'payment_status' => 'paid'], 10);
        }
        $this->order('2026-10-09', ['status' => 'pending', 'payment_status' => 'unpaid'], 50);
        $this->order('2026-10-09', ['status' => 'pending', 'payment_status' => 'partial'], 40);
        $this->order('2026-10-09', ['status' => 'cancelled', 'payment_status' => 'unpaid'], 999);
        $this->order('2026-09-01', ['status' => 'completed', 'payment_status' => 'paid'], 1000); // خارج التاريخ

        $res = $this->getJson('/api/orders?from=2026-10-09&to=2026-10-09&per_page=2')->assertOk();

        $this->assertCount(2, $res->json('data'));
        $this->assertSame(9, $res->json('total'));
        $this->assertSame(6, $res->json('summary.counts.completed'));
        $this->assertSame(2, $res->json('summary.counts.pending'));
        $this->assertSame(1, $res->json('summary.counts.cancelled'));
        // الملغي مو محسوب: 6*10 + 50 + 40
        $this->assertEquals(150, $res->json('summary.revenue'));
        $this->assertSame(2, $res->json('summary.unpaid'));

        // فلتر الحالة بيصغّر القائمة بس عدّادات الحالات بتضل كاملة للتنقل بينها
        $res = $this->getJson('/api/orders?from=2026-10-09&to=2026-10-09&status=pending')->assertOk();
        $this->assertSame(2, $res->json('total'));
        $this->assertSame(6, $res->json('summary.counts.completed'));
        $this->assertEquals(90, $res->json('summary.revenue'));
    }

    public function test_validation_and_all_mode(): void
    {
        $this->actingAsRole('admin');
        $this->order('2026-10-09');

        $this->getJson('/api/orders?status=nonsense')->assertStatus(422)->assertJsonValidationErrors('status');
        $this->getJson('/api/orders?payment_status=weird')->assertStatus(422)->assertJsonValidationErrors('payment_status');
        $this->getJson('/api/orders?from=2026-10-09&to=2026-10-01')->assertStatus(422)->assertJsonValidationErrors('to');

        // per_page=all بيرجّع مصفوفة (استخدام قديم) بدون summary
        $all = $this->getJson('/api/orders?per_page=all')->assertOk();
        $this->assertTrue(array_is_list($all->json()));
        $this->assertCount(1, $all->json());
    }
}
