<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    private function item(string $categorySlug, float $price): int
    {
        $category = Category::firstOrCreate(['slug' => $categorySlug], ['name' => ucfirst($categorySlug)]);

        return $this->makeMenuItem(['category_id' => $category->id, 'price' => $price])->id;
    }

    private function range(): string
    {
        return 'from=' . now()->subDay()->toDateString() . '&to=' . now()->addDay()->toDateString();
    }

    public function test_revenue_by_category_sums_per_category_and_skips_cancelled_orders(): void
    {
        $grill = $this->item('grill', 10);
        $drinks = $this->item('drinks', 2);

        $this->placeOrder([['menu_item_id' => $grill, 'quantity' => 3], ['menu_item_id' => $drinks, 'quantity' => 2]])->assertCreated();

        // طلب ملغي ما لازم يدخل بالتقرير
        $cancelled = $this->placeOrder([['menu_item_id' => $grill, 'quantity' => 100]])->assertCreated()->json('id');
        $this->putJson("/api/orders/{$cancelled}", ['status' => 'cancelled'])->assertOk();

        $res = $this->getJson('/api/reports/revenue-by-category?' . $this->range())->assertOk();

        $byName = collect($res->json('categories'))->keyBy('category_name');
        $this->assertEquals(30.0, (float) $byName['Grill']['revenue']);
        $this->assertEquals(3, (int) $byName['Grill']['quantity_sold']);
        $this->assertEquals(4.0, (float) $byName['Drinks']['revenue']);
        $this->assertSame('Grill', $res->json('categories.0.category_name')); // الأعلى إيراداً أولاً
    }

    public function test_top_items_limit_is_clamped(): void
    {
        $a = $this->item('grill', 10);
        $b = $this->item('grill', 5);
        $this->placeOrder([['menu_item_id' => $a, 'quantity' => 2], ['menu_item_id' => $b, 'quantity' => 1]])->assertCreated();

        $this->getJson('/api/reports/top-items?limit=0&' . $this->range())->assertOk()->assertJsonCount(1, 'items');
        $this->getJson('/api/reports/top-items?limit=-3&' . $this->range())->assertOk()->assertJsonCount(1, 'items');
        $this->getJson('/api/reports/top-items?limit=100000&' . $this->range())->assertOk()->assertJsonCount(2, 'items');
    }

    public function test_csv_export_of_revenue_by_category_works(): void
    {
        $grill = $this->item('grill', 10);
        $this->placeOrder([['menu_item_id' => $grill, 'quantity' => 1]])->assertCreated();

        $res = $this->get('/api/reports/export?type=revenue-by-category&format=csv&' . $this->range());

        $res->assertOk();
        $this->assertStringContainsString('Grill', $res->getContent());
    }
}
