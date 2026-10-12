<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Expense;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Payroll;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

/**
 * تعريف الإيراد والربح (شوف App\Services\SalesReport):
 * الإيراد الصافي = المبيعات - الاسترجاعات - الضريبة، والربح = الإيراد الصافي - المصاريف - الرواتب.
 */
class ReportDefinitionsTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    private function range(): string
    {
        return 'from=' . now()->subDay()->toDateString() . '&to=' . now()->addDay()->toDateString();
    }

    /** طلب بسعر 100، ضريبة افتراضية 16% => total = 116 */
    private function orderOf100(): int
    {
        $item = $this->makeMenuItem(['price' => 100]);

        return $this->placeOrder([['menu_item_id' => $item->id, 'quantity' => 1]])->assertCreated()->json('id');
    }

    public function test_net_revenue_excludes_tax(): void
    {
        $this->orderOf100();

        $res = $this->getJson('/api/reports/sales?' . $this->range())->assertOk();

        $this->assertSame(1, $res->json('orders_count'));
        $this->assertEquals(116.0, $res->json('gross_sales'));
        $this->assertEquals(16.0, $res->json('tax'));
        $this->assertEquals(100.0, $res->json('net_revenue'));
        $this->assertEquals(100.0, $res->json('revenue')); // الاسم القديم = net_revenue
        $this->assertEquals(100.0, $res->json('by_day.0.net_revenue'));
        $this->assertEquals(100.0, $res->json('by_order_type.0.net_revenue'));
    }

    public function test_unpaid_orders_show_as_outstanding(): void
    {
        $this->orderOf100();

        $res = $this->getJson('/api/reports/sales?' . $this->range())->assertOk();

        $this->assertEquals(0.0, $res->json('collected'));
        $this->assertEquals(116.0, $res->json('outstanding'));
    }

    public function test_approved_refunds_reduce_revenue_but_pending_and_rejected_do_not(): void
    {
        $orderId = $this->orderOf100();
        $this->postJson('/api/payments', ['order_id' => $orderId, 'method' => 'cash', 'amount' => 116])->assertCreated();

        $approved = $this->postJson('/api/refunds', ['order_id' => $orderId, 'amount' => 20, 'reason' => 'x'])->assertCreated()->json('id');
        $this->postJson("/api/refunds/{$approved}/approve")->assertOk();

        $rejected = $this->postJson('/api/refunds', ['order_id' => $orderId, 'amount' => 10, 'reason' => 'y'])->assertCreated()->json('id');
        $this->postJson("/api/refunds/{$rejected}/reject")->assertOk();

        $this->postJson('/api/refunds', ['order_id' => $orderId, 'amount' => 5, 'reason' => 'pending'])->assertCreated();

        $res = $this->getJson('/api/reports/sales?' . $this->range())->assertOk();

        $this->assertEquals(20.0, $res->json('refunds'));
        $this->assertEquals(80.0, $res->json('net_revenue'));   // 116 - 20 - 16
        $this->assertEquals(96.0, $res->json('collected'));     // 116 - 20
        $this->assertEquals(0.0, $res->json('outstanding'));
    }

    public function test_cancelled_orders_are_not_revenue(): void
    {
        $this->orderOf100();
        $cancelled = $this->orderOf100();
        $this->putJson("/api/orders/{$cancelled}", ['status' => 'cancelled'])->assertOk();

        $this->assertSame(1, $this->getJson('/api/reports/sales?' . $this->range())->json('orders_count'));
    }

    public function test_category_revenue_includes_modifiers_and_the_proportional_discount(): void
    {
        $category = Category::firstOrCreate(['slug' => 'grill'], ['name' => 'Grill']);
        $item = $this->makeMenuItem(['category_id' => $category->id, 'price' => 100]);
        $group = ModifierGroup::create(['menu_item_id' => $item->id, 'name' => 'Extras', 'is_required' => false, 'max_selections' => 1]);
        $extra = Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Cheese', 'extra_price' => 20]);
        Coupon::create(['code' => 'TEN', 'type' => 'percentage', 'value' => 10, 'is_active' => true]);

        // (100 + 20) = 120، خصم 10% => 108 بدون ضريبة
        $this->placeOrder(
            [['menu_item_id' => $item->id, 'quantity' => 1, 'modifiers' => [$extra->id]]],
            ['coupon_code' => 'TEN']
        )->assertCreated();

        $res = $this->getJson('/api/reports/revenue-by-category?' . $this->range())->assertOk();
        $this->assertEquals(108.0, $res->json('categories.0.revenue'));

        $top = $this->getJson('/api/reports/top-items?' . $this->range())->assertOk();
        $this->assertEquals(108.0, $top->json('items.0.revenue'));

        // مجموع التصنيفات = الإيراد الصافي (بدون استرجاعات): 108 * 1.16 = total، net = total - tax
        $sales = $this->getJson('/api/reports/sales?' . $this->range())->assertOk();
        $this->assertEquals(108.0, $sales->json('net_revenue'));
    }

    public function test_profit_subtracts_expenses_and_approved_payroll_from_net_revenue(): void
    {
        $this->orderOf100(); // net 100

        Expense::create(['title' => 'Rent', 'category' => 'rent', 'amount' => 30, 'expense_date' => now()->toDateString()]);

        $employee = User::create(['name' => 'E', 'email' => 'e@t.local', 'password' => 'secret123', 'role' => 'cashier', 'is_active' => true]);
        $payroll = fn (string $status, float $gross, string $end) => Payroll::create([
            'user_id' => $employee->id,
            'period_start' => now()->subDays(10 + (int) $gross)->toDateString(),
            'period_end' => $end,
            'salary_type' => 'monthly',
            'gross_pay' => $gross, 'net_pay' => $gross, 'status' => $status,
        ]);
        $payroll('approved', 20, now()->toDateString());
        $payroll('paid', 10, now()->toDateString());
        $payroll('draft', 500, now()->toDateString());                       // مسودة: ما بتتحسب
        $payroll('approved', 700, now()->subYear()->toDateString());         // خارج الفترة

        $res = $this->getJson('/api/reports/profit?' . $this->range())->assertOk();

        $this->assertEquals(100.0, $res->json('net_revenue'));
        $this->assertEquals(30.0, $res->json('expenses'));
        $this->assertEquals(30.0, $res->json('payroll'));
        $this->assertEquals(60.0, $res->json('total_costs'));
        $this->assertEquals(40.0, $res->json('net_profit'));
    }

    public function test_dashboard_revenue_uses_the_same_definition(): void
    {
        $this->orderOf100();

        $stats = $this->getJson('/api/dashboard/stats')->assertOk();

        $this->assertEquals(100.0, $stats->json('revenue_today'));
        $this->assertEquals(100.0, $stats->json('revenue_total'));
        $this->assertEquals(100.0, $stats->json('revenue_by_day.6.revenue'));
    }

    public function test_invalid_date_range_is_a_validation_error_not_a_500(): void
    {
        $this->getJson('/api/reports/sales?from=not-a-date')->assertStatus(422)->assertJsonValidationErrors('from');
        $this->getJson('/api/reports/sales?from=2026-10-10&to=2026-10-01')->assertStatus(422)->assertJsonValidationErrors('to');
    }

    public function test_exports_still_work_with_the_new_columns(): void
    {
        $this->orderOf100();
        Expense::create(['title' => 'Rent', 'category' => 'rent', 'amount' => 30, 'expense_date' => now()->toDateString()]);

        foreach (['sales', 'top-items', 'revenue-by-category', 'profit', 'staff-performance'] as $type) {
            $this->get("/api/reports/export?type={$type}&format=csv&" . $this->range())->assertOk();
            $this->get("/api/reports/export?type={$type}&format=pdf&" . $this->range())->assertOk();
        }
    }

    public function test_staff_performance_reports_net_revenue_per_user(): void
    {
        $this->orderOf100();

        $res = $this->getJson('/api/reports/staff-performance?' . $this->range())->assertOk();

        $this->assertSame(1, $res->json('staff.0.orders_count'));
        $this->assertEquals(100.0, $res->json('staff.0.revenue'));
        $this->assertNotNull($res->json('staff.0.name'));
    }
}
