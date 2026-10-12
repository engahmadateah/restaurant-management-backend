<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\User;
use App\Services\PayrollCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function employee(array $attrs = []): User
    {
        static $n = 0;
        $n++;

        return User::create(array_merge([
            'name' => 'Emp ' . $n,
            'email' => "emp{$n}@test.local",
            'password' => 'secret123',
            'role' => 'cashier',
            'is_active' => true,
            'salary_type' => 'hourly',
            'hourly_rate' => 10,
            'overtime_multiplier' => 1.5,
            'weekly_overtime_threshold_hours' => 40,
        ], $attrs));
    }

    private function shift(User $user, string $start, float $hours, bool $closed = true): void
    {
        $in = Carbon::parse($start);

        Shift::create([
            'user_id' => $user->id,
            'clock_in' => $in,
            'clock_out' => $closed ? $in->copy()->addMinutes((int) round($hours * 60)) : null,
        ]);
    }

    public function test_overtime_is_calculated_per_week_not_over_the_whole_period(): void
    {
        $user = $this->employee();

        // أسبوع 1: 45 ساعة (5 إضافي)، أسبوع 2: 30 ساعة (ولا ساعة إضافية)
        foreach (['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06'] as $day) {
            $this->shift($user, "$day 08:00", 9);
        }
        foreach (['2026-03-09', '2026-03-10', '2026-03-11'] as $day) {
            $this->shift($user, "$day 08:00", 10);
        }

        $r = (new PayrollCalculator)->calculate($user, Carbon::parse('2026-03-02'), Carbon::parse('2026-03-15'));

        $this->assertSame(75.0, $r['total_hours']);
        $this->assertSame(70.0, $r['regular_hours']);   // 40 + 30
        $this->assertSame(5.0, $r['overtime_hours']);
        $this->assertSame(700.0, (float) $r['regular_pay']);
        $this->assertSame(75.0, (float) $r['overtime_pay']); // 5 * 10 * 1.5
        $this->assertSame(775.0, (float) $r['gross_pay']);
    }

    public function test_open_shift_is_ignored(): void
    {
        $user = $this->employee();
        $this->shift($user, '2026-03-02 08:00', 8);
        $this->shift($user, '2026-03-03 08:00', 8, closed: false);

        $r = (new PayrollCalculator)->calculate($user, Carbon::parse('2026-03-01'), Carbon::parse('2026-03-07'));

        $this->assertSame(8.0, $r['total_hours']);
    }

    public function test_shifts_outside_the_period_are_ignored(): void
    {
        $user = $this->employee();
        $this->shift($user, '2026-02-27 08:00', 8);
        $this->shift($user, '2026-03-02 08:00', 4);

        $r = (new PayrollCalculator)->calculate($user, Carbon::parse('2026-03-01'), Carbon::parse('2026-03-07'));

        $this->assertSame(4.0, $r['total_hours']);
        $this->assertSame(40.0, (float) $r['gross_pay']);
    }

    public function test_monthly_salary_is_fixed_regardless_of_hours(): void
    {
        $user = $this->employee(['salary_type' => 'monthly', 'monthly_salary' => 1000, 'hourly_rate' => null]);
        $this->shift($user, '2026-03-02 08:00', 50);

        $r = (new PayrollCalculator)->calculate($user, Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $this->assertSame(1000.0, (float) $r['gross_pay']);
        $this->assertSame(0, $r['overtime_pay']);
        $this->assertSame(10.0, $r['overtime_hours']);
    }
}
