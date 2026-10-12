<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Payroll;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

/**
 * حذف موظف أو صنف كان يمسح بالـ cascade طلباته/أسطر طلباته/رواتبه.
 * هلأ بيصير soft delete والسجل التاريخي بيضل سليم.
 */
class HistoricalRecordsTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    private function staff(string $email = 'staff@t.local'): User
    {
        return User::create([
            'name' => 'Old Cashier', 'email' => $email, 'password' => 'secret123',
            'role' => 'cashier', 'is_active' => true,
        ]);
    }

    // ---------------- الموظفين ----------------

    public function test_deleting_a_user_keeps_his_orders_shifts_and_payroll(): void
    {
        $staff = $this->staff();
        $order = $this->makeOrder(40, ['user_id' => $staff->id]);
        $staff->shifts()->create(['clock_in' => now()->subHours(8), 'clock_out' => now()]);
        Payroll::create([
            'user_id' => $staff->id,
            'period_start' => now()->subDays(30)->toDateString(),
            'period_end' => now()->toDateString(),
            'salary_type' => 'monthly',
            'gross_pay' => 500, 'net_pay' => 500, 'status' => 'paid',
        ]);

        $this->deleteJson("/api/users/{$staff->id}")->assertOk();

        $this->assertSoftDeleted('users', ['id' => $staff->id]);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('shifts', ['user_id' => $staff->id]);
        $this->assertDatabaseHas('payrolls', ['user_id' => $staff->id]);

        // اسم الموظف المحذوف لسا ظاهر على طلباته القديمة
        $this->getJson("/api/orders/{$order->id}")->assertOk()->assertJsonPath('user.name', 'Old Cashier');
    }

    public function test_deleted_user_disappears_from_lists_and_cannot_log_in_but_email_is_free_again(): void
    {
        $staff = $this->staff('reuse@t.local');

        $this->deleteJson("/api/users/{$staff->id}")->assertOk();

        $ids = array_column($this->getJson('/api/users?per_page=all')->assertOk()->json(), 'id');
        $this->assertNotContains($staff->id, $ids);

        $this->assertNotContains($staff->id, array_column($this->getJson('/api/staff-directory')->json(), 'id'));

        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/login', ['email' => 'reuse@t.local', 'password' => 'secret123']);
        $this->assertContains($login->status(), [401, 422]);

        // نفس الإيميل ينفع لموظف جديد
        $this->actingAsRole('admin');
        $this->postJson('/api/users', [
            'name' => 'New Hire', 'email' => 'reuse@t.local', 'password' => 'secret123', 'role' => 'cashier',
        ])->assertCreated();
    }

    public function test_deleted_employee_name_still_shows_in_staff_report(): void
    {
        $staff = $this->staff();
        $item = $this->makeMenuItem(['price' => 100]);
        $this->placeOrder([['menu_item_id' => $item->id, 'quantity' => 1]])->assertCreated();
        \App\Models\Order::query()->update(['user_id' => $staff->id]);

        $this->deleteJson("/api/users/{$staff->id}")->assertOk();

        $range = 'from=' . now()->subDay()->toDateString() . '&to=' . now()->addDay()->toDateString();
        $this->getJson('/api/reports/staff-performance?' . $range)->assertOk()
            ->assertJsonPath('staff.0.name', 'Old Cashier');
    }

    // ---------------- الأصناف ----------------

    public function test_deleting_a_menu_item_keeps_it_on_past_orders_and_reports(): void
    {
        $item = $this->makeMenuItem(['name' => 'Shawarma', 'price' => 100]);
        $orderId = $this->placeOrder([['menu_item_id' => $item->id, 'quantity' => 2]])->assertCreated()->json('id');

        $this->deleteJson("/api/menu-items/{$item->id}")->assertOk();

        $this->assertSoftDeleted('menu_items', ['id' => $item->id]);
        $this->assertDatabaseHas('order_items', ['order_id' => $orderId, 'menu_item_id' => $item->id]);

        // الطلب القديم بيضل يعرض اسم الصنف
        $this->getJson("/api/orders/{$orderId}")->assertOk()->assertJsonPath('items.0.menu_item.name', 'Shawarma');

        // التقارير لسا بتحسبه
        $range = 'from=' . now()->subDay()->toDateString() . '&to=' . now()->addDay()->toDateString();
        $top = $this->getJson('/api/reports/top-items?' . $range)->assertOk();
        $this->assertSame('Shawarma', $top->json('items.0.name'));
        $this->assertSame(2, $top->json('items.0.quantity_sold'));
    }

    public function test_deleted_menu_item_is_hidden_and_cannot_be_ordered(): void
    {
        $item = $this->makeMenuItem(['price' => 10]);
        $this->deleteJson("/api/menu-items/{$item->id}")->assertOk();

        $ids = array_column($this->getJson('/api/menu-items')->assertOk()->json(), 'id');
        $this->assertNotContains($item->id, $ids);

        $this->placeOrder([['menu_item_id' => $item->id, 'quantity' => 1]])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.menu_item_id');
    }

    public function test_cancelling_an_order_with_an_archived_item_still_works(): void
    {
        $item = $this->makeMenuItem(['price' => 10, 'track_stock' => true, 'stock_quantity' => 5]);
        $orderId = $this->placeOrder([['menu_item_id' => $item->id, 'quantity' => 2]])->assertCreated()->json('id');
        $this->assertSame(3, $item->fresh()->stock_quantity);

        $this->deleteJson("/api/menu-items/{$item->id}")->assertOk();
        $this->putJson("/api/orders/{$orderId}", ['status' => 'cancelled'])->assertOk();

        $this->assertSame(5, \App\Models\MenuItem::withTrashed()->find($item->id)->stock_quantity);
    }

    // ---------------- التصنيفات ----------------

    public function test_category_with_items_cannot_be_deleted(): void
    {
        $category = Category::create(['name' => 'Starters', 'slug' => 'starters']);
        $item = $this->makeMenuItem(['category_id' => $category->id]);

        $this->deleteJson("/api/categories/{$category->id}")->assertStatus(422)->assertJsonValidationErrors('category');
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('menu_items', ['id' => $item->id]);
    }

    public function test_category_with_archived_items_that_were_sold_cannot_be_deleted(): void
    {
        $category = Category::create(['name' => 'Grill', 'slug' => 'grill-x']);
        $item = $this->makeMenuItem(['category_id' => $category->id, 'price' => 10]);
        $this->placeOrder([['menu_item_id' => $item->id, 'quantity' => 1]])->assertCreated();
        $this->deleteJson("/api/menu-items/{$item->id}")->assertOk();

        $this->deleteJson("/api/categories/{$category->id}")->assertStatus(422)->assertJsonValidationErrors('category');
        $this->assertDatabaseHas('order_items', ['menu_item_id' => $item->id]);
    }

    public function test_empty_category_can_be_deleted(): void
    {
        $category = Category::create(['name' => 'Empty', 'slug' => 'empty']);

        $this->deleteJson("/api/categories/{$category->id}")->assertOk();
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    // ---------------- الفهارس ----------------

    public function test_performance_indexes_exist(): void
    {
        $orderIndexes = array_column(Schema::getIndexes('orders'), 'name');

        foreach (['orders_created_at_index', 'orders_status_created_at_index', 'orders_payment_status_index', 'orders_type_delivery_status_index'] as $name) {
            $this->assertContains($name, $orderIndexes);
        }
        $this->assertContains('expenses_expense_date_index', array_column(Schema::getIndexes('expenses'), 'name'));
        $this->assertContains('shifts_user_clock_in_index', array_column(Schema::getIndexes('shifts'), 'name'));
    }
}
