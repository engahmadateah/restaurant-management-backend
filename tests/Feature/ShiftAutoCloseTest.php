<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Payroll;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class ShiftAutoCloseTest extends TestCase
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

    private function openShift(User $user, string $clockIn): Shift
    {
        return Shift::create(['user_id' => $user->id, 'clock_in' => Carbon::parse($clockIn)]);
    }

    public function test_command_closes_forgotten_shifts_at_the_max_not_at_now(): void
    {
        $user = $this->actingAsRole('cashier');
        $manager = User::create([
            'name' => 'Mgr', 'email' => 'mgr@test.local', 'password' => 'secret123', 'role' => 'manager', 'is_active' => true,
        ]);
        $stale = $this->openShift($user, '2026-10-06 18:00:00'); // 18 ساعة مضت
        $fresh = $this->openShift($this->actingAsRole('waiter'), '2026-10-07 09:00:00'); // 3 ساعات

        $this->artisan('shifts:auto-close')->assertSuccessful();

        $stale->refresh();
        $this->assertTrue($stale->auto_closed);
        $this->assertSame('2026-10-07 06:00:00', $stale->clock_out->format('Y-m-d H:i:s')); // +12 ساعة بالضبط
        $this->assertSame(720, $stale->duration_minutes);

        $this->assertNull($fresh->fresh()->clock_out);

        $this->assertDatabaseHas('app_notifications', ['user_id' => $user->id, 'type' => 'shift']);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $manager->id, 'type' => 'shift']);
        // إشعار واحد ملخّص للمدير مش لكل وردية، وما في إشعار للموظف يلي ورديته سليمة
        $this->assertSame(1, AppNotification::where('user_id', $manager->id)->count());
        $this->assertSame(0, AppNotification::where('user_id', $fresh->user_id)->count());
    }

    public function test_max_hours_comes_from_settings(): void
    {
        $user = $this->actingAsRole('cashier');
        Setting::create(['key' => 'shift_max_hours', 'value' => '8']);
        $shift = $this->openShift($user, '2026-10-07 02:00:00'); // 10 ساعات مضت

        $this->artisan('shifts:auto-close')->assertSuccessful();

        $this->assertSame('2026-10-07 10:00:00', $shift->fresh()->clock_out->format('Y-m-d H:i:s'));
    }

    public function test_clock_in_closes_a_forgotten_shift_instead_of_blocking(): void
    {
        $user = $this->actingAsRole('cashier');
        $old = $this->openShift($user, '2026-10-06 08:00:00');

        $this->postJson('/api/shifts/clock-in')->assertCreated();

        $this->assertTrue($old->fresh()->auto_closed);
        $this->assertSame(1, $user->shifts()->whereNull('clock_out')->count());
    }

    public function test_clock_in_is_still_refused_when_the_open_shift_is_recent(): void
    {
        $user = $this->actingAsRole('cashier');
        $this->openShift($user, '2026-10-07 09:00:00');

        $this->postJson('/api/shifts/clock-in')->assertStatus(422);
        $this->assertSame(1, $user->shifts()->count());
    }

    public function test_late_clock_out_is_capped_instead_of_counting_the_forgotten_hours(): void
    {
        $user = $this->actingAsRole('cashier');
        $shift = $this->openShift($user, '2026-10-05 08:00:00'); // يومين مضوا

        $this->postJson('/api/shifts/clock-out')->assertOk()->assertJsonPath('auto_closed', true);

        $this->assertSame(720, $shift->fresh()->duration_minutes);
    }

    public function test_normal_clock_out_is_not_flagged(): void
    {
        $user = $this->actingAsRole('cashier');
        $shift = $this->openShift($user, '2026-10-07 08:00:00');

        $this->postJson('/api/shifts/clock-out')->assertOk()->assertJsonPath('auto_closed', false);

        $this->assertSame(240, $shift->fresh()->duration_minutes);
    }

    public function test_manager_can_correct_an_auto_closed_shift(): void
    {
        $employee = $this->actingAsRole('cashier');
        $shift = $this->openShift($employee, '2026-10-06 08:00:00');
        $this->artisan('shifts:auto-close');

        $manager = $this->actingAsRole('manager');

        $this->putJson("/api/shifts/{$shift->id}", [
            'clock_in' => '2026-10-06 08:00:00',
            'clock_out' => '2026-10-06 16:30:00',
        ])->assertOk();

        $shift->refresh();
        $this->assertFalse($shift->auto_closed);
        $this->assertSame($manager->id, $shift->adjusted_by);
        $this->assertSame(510, $shift->duration_minutes);
    }

    public function test_cashier_cannot_edit_shifts(): void
    {
        $employee = $this->actingAsRole('cashier');
        $shift = Shift::create([
            'user_id' => $employee->id, 'clock_in' => '2026-10-06 08:00:00', 'clock_out' => '2026-10-06 16:00:00',
        ]);

        $this->putJson("/api/shifts/{$shift->id}", [
            'clock_in' => '2026-10-06 08:00:00', 'clock_out' => '2026-10-06 20:00:00',
        ])->assertForbidden();
    }

    public function test_shift_edit_is_refused_inside_an_approved_payroll_and_on_overlap(): void
    {
        $employee = $this->actingAsRole('cashier');
        $shift = Shift::create([
            'user_id' => $employee->id, 'clock_in' => '2026-10-02 08:00:00', 'clock_out' => '2026-10-02 16:00:00',
        ]);
        Shift::create([
            'user_id' => $employee->id, 'clock_in' => '2026-10-03 08:00:00', 'clock_out' => '2026-10-03 16:00:00',
        ]);
        $this->actingAsRole('manager');

        // تداخل مع وردية تانية لنفس الموظف
        $this->putJson("/api/shifts/{$shift->id}", [
            'clock_in' => '2026-10-02 08:00:00', 'clock_out' => '2026-10-03 09:00:00',
        ])->assertStatus(422);

        Payroll::create([
            'user_id' => $employee->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-04',
            'salary_type' => 'hourly', 'status' => 'approved',
        ]);

        $this->putJson("/api/shifts/{$shift->id}", [
            'clock_in' => '2026-10-02 08:00:00', 'clock_out' => '2026-10-02 18:00:00',
        ])->assertStatus(422)->assertJsonValidationErrors('clock_in');
    }

    public function test_shift_edit_rejects_future_end_and_over_24_hours(): void
    {
        $employee = $this->actingAsRole('cashier');
        $shift = Shift::create([
            'user_id' => $employee->id, 'clock_in' => '2026-10-06 08:00:00', 'clock_out' => '2026-10-06 16:00:00',
        ]);
        $this->actingAsRole('manager');

        $this->putJson("/api/shifts/{$shift->id}", [
            'clock_in' => '2026-10-06 08:00:00', 'clock_out' => '2026-10-08 08:00:00',
        ])->assertStatus(422)->assertJsonValidationErrors('clock_out');

        $this->putJson("/api/shifts/{$shift->id}", [
            'clock_in' => '2026-10-05 08:00:00', 'clock_out' => '2026-10-06 09:00:00',
        ])->assertStatus(422)->assertJsonValidationErrors('clock_out');
    }

    public function test_index_can_filter_shifts_needing_review(): void
    {
        $employee = $this->actingAsRole('cashier');
        $this->openShift($employee, '2026-10-06 08:00:00');
        Shift::create([
            'user_id' => $employee->id, 'clock_in' => '2026-10-01 08:00:00', 'clock_out' => '2026-10-01 16:00:00',
        ]);
        $this->artisan('shifts:auto-close');
        $this->actingAsRole('manager');

        $this->getJson('/api/shifts?auto_closed=1')->assertOk()->assertJsonCount(1);
        $this->getJson('/api/shifts')->assertOk()->assertJsonCount(2);
    }
}
