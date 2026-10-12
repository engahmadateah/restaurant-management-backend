<?php

namespace Tests\Feature;

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class PaymentsPaginationTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pay(string $method, float $amount, string $status = 'paid', ?string $day = null, ?string $orderNumber = null): Payment
    {
        $day ? Carbon::setTestNow("{$day} 12:00:00") : Carbon::setTestNow();
        $order = $this->makeOrder(1000, $orderNumber ? ['order_number' => $orderNumber] : []);

        return Payment::create(['order_id' => $order->id, 'method' => $method, 'amount' => $amount, 'status' => $status]);
    }

    public function test_paginates_and_summary_covers_all_pages(): void
    {
        $this->actingAsRole('admin');
        for ($i = 0; $i < 12; $i++) {
            $this->pay($i % 2 ? 'card' : 'cash', 10);
        }

        $res = $this->getJson('/api/payments?per_page=5')->assertOk();

        $this->assertCount(5, $res->json('data'));
        $this->assertSame(12, $res->json('total'));
        $this->assertSame(3, $res->json('last_page'));
        // الإجماليات للنتيجة كلها (120) مش بس الصفحة (50)
        $this->assertEquals(120, $res->json('summary.paid_total'));
        $this->assertEquals(60, $res->json('summary.cash_total'));
        $this->assertEquals(60, $res->json('summary.card_total'));
        $this->assertEquals(0, $res->json('summary.online_total'));
        $this->assertNotNull($res->json('data.0.order.order_number'));

        $this->assertCount(2, $this->getJson('/api/payments?per_page=5&page=3')->json('data'));
    }

    public function test_filters_apply_to_rows_and_summary(): void
    {
        $this->actingAsRole('admin');
        $this->pay('cash', 10, 'paid', '2026-10-01', 'ORD-AAA1');
        $this->pay('card', 20, 'paid', '2026-10-02', 'ORD-BBB2');
        $this->pay('cash', 30, 'pending', '2026-10-03', 'ORD-CCC3');
        $this->pay('cash', 40, 'refunded', '2026-10-03', 'ORD-DDD4');

        $this->assertSame(3, $this->getJson('/api/payments?method=cash')->json('total'));

        $paid = $this->getJson('/api/payments?status=paid')->assertOk();
        $this->assertSame(2, $paid->json('total'));
        $this->assertEquals(30, $paid->json('summary.paid_total'));

        // المعلّقة والمسترجعة ما بتدخل بإجمالي المقبوض حتى لو الفلتر عليها
        $pending = $this->getJson('/api/payments?status=pending')->assertOk();
        $this->assertSame(1, $pending->json('total'));
        $this->assertEquals(0, $pending->json('summary.paid_total'));

        $day = $this->getJson('/api/payments?from=2026-10-03&to=2026-10-03');
        $this->assertSame(2, $day->json('total'));
        $this->assertSame(1, $this->getJson('/api/payments?from=2026-10-02&to=2026-10-02')->json('total'));

        $this->assertSame(1, $this->getJson('/api/payments?search=BBB2')->json('total'));
        $this->assertSame(0, $this->getJson('/api/payments?search=ZZZZ')->json('total'));
    }

    public function test_search_by_numeric_order_id(): void
    {
        $this->actingAsRole('admin');
        $p = $this->pay('cash', 10, 'paid', null, 'ORD-X');
        $this->pay('cash', 10, 'paid', null, 'ORD-Y');

        $res = $this->getJson('/api/payments?search=' . $p->order_id)->assertOk();

        $this->assertContains($p->id, collect($res->json('data'))->pluck('id')->all());
    }

    public function test_order_id_still_returns_a_plain_array(): void
    {
        $this->actingAsRole('admin');
        $p = $this->pay('cash', 10);
        $this->pay('card', 10);

        $res = $this->getJson('/api/payments?order_id=' . $p->order_id)->assertOk();

        $this->assertCount(1, $res->json());
        $this->assertSame($p->id, $res->json('0.id'));
    }

    public function test_per_page_is_capped_and_filters_validated(): void
    {
        $this->actingAsRole('admin');
        $this->pay('cash', 10);

        $this->assertLessThanOrEqual(100, $this->getJson('/api/payments?per_page=100000')->json('per_page'));
        $this->getJson('/api/payments?method=bitcoin')->assertStatus(422);
        $this->getJson('/api/payments?from=2026-10-05&to=2026-10-01')->assertStatus(422);
    }
}
