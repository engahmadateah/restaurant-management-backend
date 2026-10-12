<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

/** التعديلات الحساسة (بيانات الزبون، نسبة ضريبة التصنيف، ساعات الدوام) لازم تظهر بسجل التدقيق */
class AuditCoverageTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    public function test_customer_edit_and_delete_are_audited_with_old_and_new_values(): void
    {
        $manager = $this->actingAsRole('manager');
        $customer = $this->makeCustomer(['name' => 'Old Name', 'phone' => '0933000111']);

        $this->putJson("/api/customers/{$customer->id}", ['name' => 'New Name', 'phone' => '0933000111'])->assertOk();

        $log = AuditLog::where('auditable_type', Customer::class)->where('auditable_id', $customer->id)->where('action', 'updated')->firstOrFail();
        $this->assertSame($manager->id, $log->user_id);
        $this->assertSame('Old Name', $log->old_values['name']);
        $this->assertSame('New Name', $log->new_values['name']);

        $this->deleteJson("/api/customers/{$customer->id}")->assertSuccessful();
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => Customer::class, 'auditable_id' => $customer->id, 'action' => 'deleted']);
    }

    public function test_category_tax_rate_change_is_audited(): void
    {
        $this->actingAsRole('manager');
        $category = Category::create(['name' => 'Drinks', 'slug' => 'drinks', 'is_active' => true, 'sort_order' => 1, 'tax_rate' => 5]);

        $category->update(['tax_rate' => 15]);

        $log = AuditLog::where('auditable_type', Category::class)->where('auditable_id', $category->id)->where('action', 'updated')->firstOrFail();
        $this->assertEquals(5, $log->old_values['tax_rate']);
        $this->assertEquals(15, $log->new_values['tax_rate']);
    }

    public function test_manual_shift_adjustment_is_audited(): void
    {
        $manager = $this->actingAsRole('manager');
        $shift = Shift::create(['user_id' => $manager->id, 'clock_in' => now()->subHours(9), 'clock_out' => now()->subHour()]);

        $shift->update(['clock_out' => now()->subMinutes(30)]);

        $log = AuditLog::where('auditable_type', Shift::class)->where('auditable_id', $shift->id)->where('action', 'updated')->firstOrFail();
        $this->assertArrayHasKey('clock_out', $log->old_values);
        $this->assertArrayHasKey('clock_out', $log->new_values);
    }
}
