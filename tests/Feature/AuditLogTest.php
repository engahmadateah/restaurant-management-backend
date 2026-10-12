<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    /** سجل مباشر بالـ DB (بدون المرور بـ Auditable) بتاريخ اختياري */
    private function log(array $attrs = [], ?string $at = null): AuditLog
    {
        $log = AuditLog::create(array_merge([
            'user_id' => null,
            'action' => 'created',
            'auditable_type' => 'App\\Models\\Coupon',
            'auditable_id' => 1,
            'old_values' => null,
            'new_values' => ['code' => 'X'],
        ], $attrs));

        if ($at) {
            $log->forceFill(['created_at' => $at])->saveQuietly();
        }

        return $log;
    }

    public function test_requires_audit_view_permission(): void
    {
        $log = $this->log();

        $this->actingAsRole('cashier');
        $this->getJson('/api/audit-logs')->assertForbidden();
        $this->getJson('/api/audit-logs/filters')->assertForbidden();
        $this->getJson("/api/audit-logs/{$log->id}")->assertForbidden();

        $this->actingAsRole('manager');
        $this->getJson('/api/audit-logs')->assertOk();
        $this->getJson('/api/audit-logs/filters')->assertOk();
        $this->getJson("/api/audit-logs/{$log->id}")->assertOk()->assertJsonPath('id', $log->id);
    }

    public function test_filters_by_action_model_and_record(): void
    {
        $this->actingAsRole('manager');
        $this->log(['action' => 'created', 'auditable_id' => 1]);
        $this->log(['action' => 'updated', 'auditable_id' => 1]);
        $this->log(['action' => 'updated', 'auditable_id' => 2]);
        $this->log(['action' => 'updated', 'auditable_type' => 'App\\Models\\Order', 'auditable_id' => 1]);

        $this->assertCount(3, $this->getJson('/api/audit-logs?model=Coupon')->assertOk()->json('data'));
        // الاسم الكامل للموديل بيشتغل كمان
        $this->assertCount(3, $this->getJson('/api/audit-logs?model=' . urlencode('App\\Models\\Coupon'))->json('data'));
        $this->assertCount(2, $this->getJson('/api/audit-logs?model=Coupon&action=updated')->json('data'));
        $this->assertCount(2, $this->getJson('/api/audit-logs?model=Coupon&auditable_id=1')->json('data'));
        // auditable_id بدون model بيتجاهل
        $this->assertGreaterThanOrEqual(4, count($this->getJson('/api/audit-logs?auditable_id=2')->json('data')));
    }

    public function test_filters_by_user_and_date_range(): void
    {
        $manager = $this->actingAsRole('manager');
        $other = User::create(['name' => 'Other', 'email' => 'o@t.local', 'password' => 'secret123', 'role' => 'cashier', 'is_active' => true]);

        $this->log(['user_id' => $manager->id], '2026-10-01 10:00:00');
        $this->log(['user_id' => $other->id], '2026-10-05 10:00:00');
        $this->log(['user_id' => $other->id], '2026-10-09 23:30:00');

        $this->assertCount(1, $this->getJson("/api/audit-logs?model=Coupon&user_id={$manager->id}")->json('data'));
        $this->assertCount(2, $this->getJson("/api/audit-logs?model=Coupon&user_id={$other->id}")->json('data'));

        // from/to شاملين (اليوم كامل)
        $this->assertCount(2, $this->getJson('/api/audit-logs?model=Coupon&from=2026-10-05&to=2026-10-09')->json('data'));
        $this->assertCount(1, $this->getJson('/api/audit-logs?model=Coupon&from=2026-10-09')->json('data'));
        $this->assertCount(1, $this->getJson('/api/audit-logs?model=Coupon&to=2026-10-01')->json('data'));
    }

    public function test_newest_first_with_stable_order_for_same_second(): void
    {
        $this->actingAsRole('manager');
        $a = $this->log(['auditable_id' => 1], '2026-10-05 10:00:00');
        $b = $this->log(['auditable_id' => 2], '2026-10-05 10:00:00');
        $c = $this->log(['auditable_id' => 3], '2026-10-06 10:00:00');

        $ids = collect($this->getJson('/api/audit-logs?model=Coupon')->json('data'))->pluck('id')->all();

        $this->assertSame([$c->id, $b->id, $a->id], $ids);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actingAsRole('manager');

        $this->getJson('/api/audit-logs?action=nonsense')->assertStatus(422)->assertJsonValidationErrors('action');
        $this->getJson('/api/audit-logs?from=2026-10-09&to=2026-10-01')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->getJson('/api/audit-logs?from=notadate')->assertStatus(422)->assertJsonValidationErrors('from');
        $this->getJson('/api/audit-logs?model=' . urlencode('Order; drop'))->assertStatus(422)->assertJsonValidationErrors('model');
    }

    public function test_filters_endpoint_lists_models_and_users_that_have_entries(): void
    {
        $manager = $this->actingAsRole('manager');
        $idle = User::create(['name' => 'Idle', 'email' => 'i@t.local', 'password' => 'secret123', 'role' => 'cashier', 'is_active' => true]);
        $this->log(['user_id' => $manager->id]);
        $this->log(['auditable_type' => 'App\\Models\\Order']);

        $res = $this->getJson('/api/audit-logs/filters')->assertOk();

        $this->assertSame(['created', 'updated', 'deleted'], $res->json('actions'));
        $this->assertContains('Coupon', $res->json('models'));
        $this->assertContains('Order', $res->json('models'));
        $this->assertNotContains('App\\Models\\Coupon', $res->json('models')); // أسماء مختصرة

        $userIds = collect($res->json('users'))->pluck('id');
        $this->assertTrue($userIds->contains($manager->id));
        $this->assertFalse($userIds->contains($idle->id));
    }
}
