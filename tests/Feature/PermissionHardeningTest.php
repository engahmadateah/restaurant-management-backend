<?php

namespace Tests\Feature;

use App\Models\Review;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

/**
 * مسارات كانت مفتوحة لأي موظف مسجل دخول (شيف/سائق...) أو محمية بـ role:admin
 * رغم إن config/permissions.php بيعطي الصلاحية للمدير.
 */
class PermissionHardeningTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    // ---------------- التقييمات ----------------

    private function review(): Review
    {
        $item = $this->makeMenuItem();

        return Review::create(['menu_item_id' => $item->id, 'rating' => 3, 'comment' => 'ok', 'is_approved' => false]);
    }

    public function test_only_reviews_approvers_can_moderate_reviews(): void
    {
        $review = $this->review();

        foreach (['driver', 'chef', 'cashier', 'waiter'] as $role) {
            $this->actingAsRole($role);
            $this->putJson("/api/reviews/{$review->id}", ['is_approved' => true])->assertForbidden();
            $this->deleteJson("/api/reviews/{$review->id}")->assertForbidden();
        }
        $this->assertFalse((bool) $review->fresh()->is_approved);

        $this->actingAsRole('manager');
        $this->putJson("/api/reviews/{$review->id}", ['is_approved' => true])->assertOk();
        $this->assertTrue((bool) $review->fresh()->is_approved);
        $this->deleteJson("/api/reviews/{$review->id}")->assertOk();
    }

    public function test_reviews_are_hidden_from_chef_and_driver_but_cashier_can_add_unapproved_ones(): void
    {
        $item = $this->makeMenuItem();

        foreach (['chef', 'driver'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/reviews')->assertForbidden();
            $this->postJson('/api/reviews', ['menu_item_id' => $item->id, 'rating' => 5])->assertForbidden();
        }

        $this->actingAsRole('cashier');
        $this->getJson('/api/reviews')->assertOk();
        $this->postJson('/api/reviews', ['menu_item_id' => $item->id, 'rating' => 5])
            ->assertCreated()->assertJsonPath('is_approved', false);

        // اللي عنده صلاحية الموافقة بينشر تقييمه مباشرة
        $this->actingAsRole('manager');
        $this->postJson('/api/reviews', ['menu_item_id' => $item->id, 'rating' => 4])
            ->assertCreated()->assertJsonPath('is_approved', true);
    }

    // ---------------- الداشبورد ----------------

    public function test_dashboard_hides_revenue_from_users_without_reports_view(): void
    {
        foreach (['chef', 'driver', 'cashier'] as $role) {
            $this->actingAsRole($role);
            $res = $this->getJson('/api/dashboard/stats')->assertOk();
            $this->assertNull($res->json('revenue_today'));
            $this->assertNull($res->json('revenue_total'));
            $this->assertNull($res->json('revenue_by_day'));
            $this->assertNotNull($res->json('orders_total'));
        }

        $this->actingAsRole('manager');
        $this->getJson('/api/dashboard/stats')->assertOk()
            ->assertJsonStructure(['revenue_today', 'revenue_total', 'revenue_by_day']);
    }

    // ---------------- الفواتير ----------------

    public function test_invoice_requires_invoices_print(): void
    {
        $order = $this->makeOrder(20);

        foreach (['waiter', 'chef', 'driver'] as $role) {
            $this->actingAsRole($role);
            $this->get("/api/orders/{$order->id}/invoice")->assertForbidden();
        }

        $this->actingAsRole('cashier');
        $this->assertNotSame(403, $this->get("/api/orders/{$order->id}/invoice")->status());
    }

    // ---------------- الورديات ----------------

    public function test_employee_cannot_open_another_employees_shift(): void
    {
        $owner = $this->actingAsRole('waiter');
        $shift = $owner->shifts()->create(['clock_in' => now()]);

        $this->getJson("/api/shifts/{$shift->id}")->assertOk();

        $this->actingAsRole('chef');
        $this->getJson("/api/shifts/{$shift->id}")->assertForbidden();

        $this->actingAsRole('manager');
        $this->getJson("/api/shifts/{$shift->id}")->assertOk();
    }

    public function test_shift_list_follows_shifts_manage_all_permission(): void
    {
        $waiter = $this->actingAsRole('waiter');
        $waiter->shifts()->create(['clock_in' => now()]);

        $chef = $this->actingAsRole('chef');
        $chef->shifts()->create(['clock_in' => now()]);

        // الشيف بيشوف ورديته بس
        $this->assertCount(1, $this->getJson('/api/shifts')->assertOk()->json());

        // صلاحية shifts.manage_all بتفتح الكل حتى لو الدور مش مدير
        $chef->extra_permissions = ['shifts.manage_all'];
        $chef->save();
        $this->assertCount(2, $this->getJson('/api/shifts')->assertOk()->json());
    }

    // ---------------- بيانات الزبائن والمال ----------------

    public function test_chef_and_driver_cannot_read_customers_coupons_payments_refunds_loyalty_or_favorites(): void
    {
        $customer = $this->makeCustomer();

        foreach (['chef', 'driver'] as $role) {
            $this->actingAsRole($role);

            foreach (['/api/customers', '/api/coupons', '/api/payments', '/api/refunds',
                "/api/customers/{$customer->id}", "/api/customers/{$customer->id}/loyalty",
                "/api/customers/{$customer->id}/favorites"] as $url) {
                $this->getJson($url)->assertForbidden();
            }

            // الطلبات لسا مفتوحة إلهم (شاشة المطبخ والتوصيل)
            $this->getJson('/api/orders')->assertOk();
        }

        $this->actingAsRole('cashier');
        foreach (['/api/customers', '/api/coupons', '/api/payments', '/api/refunds',
            "/api/customers/{$customer->id}/loyalty", "/api/customers/{$customer->id}/favorites"] as $url) {
            $this->getJson($url)->assertOk();
        }
    }

    // ---------------- الحجوزات والمخزون ----------------

    public function test_reservations_need_reservations_manage(): void
    {
        foreach (['chef', 'driver'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/reservations')->assertForbidden();
            $this->postJson('/api/reservations', [])->assertForbidden();
        }

        foreach (['waiter', 'cashier', 'manager'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/reservations')->assertOk();
        }
    }

    public function test_inventory_read_needs_menu_manage(): void
    {
        foreach (['waiter', 'cashier', 'chef'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/inventory')->assertForbidden();
            $this->getJson('/api/inventory/low-stock')->assertForbidden();
        }

        $this->actingAsRole('manager');
        $this->getJson('/api/inventory')->assertOk();
        $this->getJson('/api/inventory/low-stock')->assertOk();
    }

    // ---------------- المصاريف / الإعدادات / الموظفين ----------------

    public function test_manager_can_manage_expenses_but_cashier_cannot(): void
    {
        $payload = ['title' => 'Rent', 'category' => 'rent', 'amount' => 100, 'expense_date' => now()->toDateString()];

        $this->actingAsRole('cashier');
        $this->getJson('/api/expenses')->assertForbidden();
        $this->postJson('/api/expenses', $payload)->assertForbidden();

        $this->actingAsRole('manager');
        $id = $this->postJson('/api/expenses', $payload)->assertCreated()->json('id');
        $this->getJson('/api/expenses')->assertOk();
        $this->putJson("/api/expenses/{$id}", ['amount' => 120])->assertOk();
        $this->deleteJson("/api/expenses/{$id}")->assertOk();
    }

    public function test_view_only_expenses_permission_cannot_write(): void
    {
        $user = $this->actingAsRole('cashier');
        $user->extra_permissions = ['expenses.view'];
        $user->save();

        $this->getJson('/api/expenses')->assertOk();
        $this->postJson('/api/expenses', ['title' => 'x', 'category' => 'other', 'amount' => 1, 'expense_date' => now()->toDateString()])
            ->assertForbidden();
    }

    public function test_settings_and_currencies_follow_settings_manage(): void
    {
        $manager = $this->actingAsRole('manager');

        $this->getJson('/api/settings')->assertOk(); // القراءة لكل المسجلين
        $this->putJson('/api/settings', ['settings' => ['tax_rate' => '0.10']])->assertForbidden();
        $this->postJson('/api/currencies', ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'exchange_rate' => 1])->assertForbidden();

        $manager->extra_permissions = ['settings.manage'];
        $manager->save();

        $this->putJson('/api/settings', ['settings' => ['tax_rate' => '0.10']])->assertOk();
        $this->assertSame('0.10', Setting::where('key', 'tax_rate')->value('value'));
    }

    public function test_staff_management_stays_admin_only_and_users_manage_cannot_be_granted(): void
    {
        $this->actingAsRole('manager');
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/permissions')->assertForbidden();

        $admin = $this->actingAsRole('admin');
        $target = $this->actingAsRole('cashier');
        $this->actingAsRole('admin');

        $this->assertNotContains('users.manage', $this->getJson('/api/permissions')->assertOk()->json('all'));
        $this->putJson("/api/users/{$target->id}/permissions", ['extra_permissions' => ['users.manage']])
            ->assertStatus(422);
    }

    public function test_new_reservations_permission_is_listed_and_grantable(): void
    {
        $this->actingAsRole('admin');
        $this->assertContains('reservations.manage', $this->getJson('/api/permissions')->assertOk()->json('all'));

        $chef = $this->actingAsRole('chef');
        $this->getJson('/api/reservations')->assertForbidden();
        $chef->extra_permissions = ['reservations.manage'];
        $chef->save();
        $this->getJson('/api/reservations')->assertOk();
    }

    // ---------------- دليل الموظفين المختصر ----------------

    public function test_staff_directory_lets_non_admins_pick_staff_without_exposing_sensitive_fields(): void
    {
        $this->actingAsRole('driver');
        $this->getJson('/api/staff-directory')->assertForbidden();

        $this->actingAsRole('chef');
        $this->getJson('/api/staff-directory')->assertForbidden();

        // الكاشير بيعيّن سائق: لازم يشوف السواقين
        $driver = $this->actingAsRole('driver');
        $this->actingAsRole('cashier');
        $res = $this->getJson('/api/staff-directory?role=driver&is_active=1')->assertOk();
        $this->assertContains($driver->id, array_column($res->json(), 'id'));
        $this->assertSame(['driver'], array_values(array_unique(array_column($res->json(), 'role'))));
        $this->assertSame(['id', 'is_active', 'name', 'phone', 'role'], collect($res->json(0))->keys()->sort()->values()->all());

        // المدير (payroll/schedules) كمان
        $this->actingAsRole('manager');
        $this->getJson('/api/staff-directory')->assertOk();

        // وما فيه إيميل ولا راتب بالرد
        $this->assertArrayNotHasKey('email', $this->getJson('/api/staff-directory')->json(0));
        $this->assertArrayNotHasKey('monthly_salary', $this->getJson('/api/staff-directory')->json(0));
    }
}
