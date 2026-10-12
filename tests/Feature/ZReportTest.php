<?php

namespace Tests\Feature;

use App\Models\CashDrawer;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class ZReportTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedDay(): User
    {
        $cashier = $this->actingAsRole('cashier');

        // أمس: ما بيدخل بتقرير اليوم
        Carbon::setTestNow('2026-10-06 12:00:00');
        $old = $this->makeOrder(999);
        Payment::create(['order_id' => $old->id, 'method' => 'cash', 'amount' => 999, 'status' => 'paid', 'received_by' => $cashier->id]);

        Carbon::setTestNow('2026-10-07 12:00:00');
        $o1 = $this->makeOrder(100, ['tax' => 10, 'discount' => 5, 'delivery_fee' => 3]);
        $cash = Payment::create(['order_id' => $o1->id, 'method' => 'cash', 'amount' => 100, 'tendered_amount' => 120, 'change_amount' => 20, 'status' => 'paid', 'received_by' => $cashier->id]);
        $o2 = $this->makeOrder(50);
        Payment::create(['order_id' => $o2->id, 'method' => 'card', 'amount' => 50, 'status' => 'paid', 'received_by' => $cashier->id]);
        $this->makeOrder(70, ['status' => 'cancelled']);

        Refund::create([
            'order_id' => $o1->id, 'payment_id' => $cash->id, 'amount' => 20, 'reason' => 'x', 'status' => 'approved',
            'requested_by' => $cashier->id, 'processed_by' => $cashier->id, 'processed_at' => now(),
        ]);
        // معلّق/مرفوض ما بينحسب
        Refund::create([
            'order_id' => $o2->id, 'amount' => 7, 'reason' => 'x', 'status' => 'pending', 'requested_by' => $cashier->id,
        ]);

        foreach ([[-5, 'a'], [2, 'b']] as [$diff, $n]) {
            CashDrawer::create([
                'user_id' => $cashier->id, 'opening_float' => 100, 'opened_at' => now()->subHours(3), 'closed_at' => now(),
                'cash_sales' => 0, 'cash_refunds' => 0, 'expected_cash' => 100, 'counted_cash' => 100 + $diff, 'difference' => $diff,
            ]);
        }

        return $cashier;
    }

    public function test_report_numbers(): void
    {
        $this->seedDay();
        $this->actingAsRole('manager');

        $r = $this->getJson('/api/reports/z-report')->assertOk();

        $this->assertSame('2026-10-07', $r->json('date'));
        $this->assertSame(2, $r->json('sales.orders_count'));
        $this->assertEquals(150, $r->json('sales.gross_sales'));
        $this->assertEquals(5, $r->json('sales.discounts'));
        $this->assertEquals(3, $r->json('sales.delivery_fees'));
        $this->assertEquals(10, $r->json('sales.tax'));
        $this->assertSame(1, $r->json('sales.cancelled_orders'));

        $methods = collect($r->json('payments.by_method'))->keyBy('method');
        $this->assertEquals(100, $methods['cash']['total']);
        $this->assertEquals(20, $methods['cash']['change_given']);
        $this->assertEquals(50, $methods['card']['total']);
        $this->assertEquals(0, $methods['online']['total']);
        $this->assertEquals(150, $r->json('payments.collected'));

        $this->assertSame(1, $r->json('refunds.count'));
        $this->assertEquals(20, $r->json('refunds.total'));
        $this->assertEquals(20, $r->json('refunds.cash_total'));
        $this->assertEquals(130, $r->json('net_collected'));

        $this->assertSame(2, $r->json('drawers.count'));
        $this->assertEquals(-5, $r->json('drawers.shortage'));
        $this->assertEquals(2, $r->json('drawers.overage'));
        $this->assertEquals(-3, $r->json('drawers.net_difference'));
    }

    public function test_other_day_and_empty_day(): void
    {
        $this->seedDay();
        $this->actingAsRole('manager');

        $yesterday = $this->getJson('/api/reports/z-report?date=2026-10-06')->assertOk();
        $this->assertEquals(999, $yesterday->json('payments.collected'));

        $empty = $this->getJson('/api/reports/z-report?date=2026-01-01')->assertOk();
        $this->assertSame(0, $empty->json('sales.orders_count'));
        $this->assertEquals(0, $empty->json('net_collected'));
        $this->assertSame([], $empty->json('drawers.items'));
    }

    public function test_future_or_invalid_date_is_rejected(): void
    {
        $this->actingAsRole('manager');

        $this->getJson('/api/reports/z-report?date=2026-10-09')->assertStatus(422);
        $this->getJson('/api/reports/z-report?date=nonsense')->assertStatus(422);
    }

    public function test_pdf_is_a_real_pdf(): void
    {
        $this->seedDay();
        $this->actingAsRole('manager');

        $res = $this->get('/api/reports/z-report/pdf', ['Accept' => 'application/pdf'])->assertOk();

        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertStringContainsString('application/pdf', $res->headers->get('Content-Type'));
    }

    public function test_cashier_cannot_see_the_report(): void
    {
        $this->actingAsRole('cashier');

        $this->getJson('/api/reports/z-report')->assertForbidden();
        $this->getJson('/api/reports/z-report/pdf')->assertForbidden();
    }
}
