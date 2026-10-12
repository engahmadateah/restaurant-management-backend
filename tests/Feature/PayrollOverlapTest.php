<?php

namespace Tests\Feature;

use App\Models\Payroll;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class PayrollOverlapTest extends TestCase
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

    private function employee(string $name = 'Emp'): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => $name . $n, 'email' => "emp{$n}@payroll.test", 'password' => 'secret123',
            'role' => 'cashier', 'is_active' => true, 'salary_type' => 'hourly', 'hourly_rate' => 10,
        ]);
    }

    private function generate(array $body)
    {
        return $this->postJson('/api/payroll/generate', $body);
    }

    public function test_generates_a_payroll_for_a_finished_period(): void
    {
        $this->actingAsRole('admin');
        $emp = $this->employee();

        $this->generate(['period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'user_id' => $emp->id])
            ->assertCreated()
            ->assertJsonCount(1, 'generated')
            ->assertJsonPath('conflicts', []);
    }

    public function test_overlapping_period_for_the_same_employee_is_refused(): void
    {
        $this->actingAsRole('admin');
        $emp = $this->employee();
        $this->generate(['period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'user_id' => $emp->id])->assertCreated();

        // يتداخل من الأخير، من الأول، ويحتويها، ويكون جواها
        foreach ([['2026-09-30', '2026-10-05'], ['2026-08-25', '2026-09-01'], ['2026-08-01', '2026-10-05'], ['2026-09-10', '2026-09-12']] as [$from, $to]) {
            $this->generate(['period_start' => $from, 'period_end' => $to, 'user_id' => $emp->id])
                ->assertStatus(422)
                ->assertJsonPath('conflicts.0.period_start', '2026-09-01')
                ->assertJsonPath('conflicts.0.period_end', '2026-09-30');
        }

        $this->assertSame(1, Payroll::where('user_id', $emp->id)->count());
    }

    public function test_overlap_is_checked_against_every_status(): void
    {
        $this->actingAsRole('admin');
        $emp = $this->employee();
        Payroll::create([
            'user_id' => $emp->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-15',
            'salary_type' => 'hourly', 'status' => 'paid',
        ]);

        $this->generate(['period_start' => '2026-09-10', 'period_end' => '2026-09-20', 'user_id' => $emp->id])
            ->assertStatus(422)
            ->assertJsonPath('conflicts.0.status', 'paid');
    }

    public function test_adjacent_periods_are_fine(): void
    {
        $this->actingAsRole('admin');
        $emp = $this->employee();

        $this->generate(['period_start' => '2026-09-01', 'period_end' => '2026-09-15', 'user_id' => $emp->id])->assertCreated();
        $this->generate(['period_start' => '2026-09-16', 'period_end' => '2026-09-30', 'user_id' => $emp->id])
            ->assertCreated()->assertJsonCount(1, 'generated');
    }

    public function test_exact_duplicate_keeps_the_old_skipped_behaviour(): void
    {
        $this->actingAsRole('admin');
        $emp = $this->employee();
        $body = ['period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'user_id' => $emp->id];

        $this->generate($body)->assertCreated();
        $this->generate($body)->assertCreated()->assertJsonCount(0, 'generated')->assertJsonPath('skipped.0', $emp->name);

        $this->assertSame(1, Payroll::where('user_id', $emp->id)->count());
    }

    public function test_bulk_generation_skips_only_the_conflicting_employee(): void
    {
        $this->actingAsRole('admin');
        $a = $this->employee('A');
        $b = $this->employee('B');
        Payroll::create([
            'user_id' => $a->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-15',
            'salary_type' => 'hourly', 'status' => 'draft',
        ]);

        $res = $this->generate(['period_start' => '2026-09-10', 'period_end' => '2026-09-30'])->assertCreated();

        $generatedUsers = collect($res->json('generated'))->pluck('user_id');
        $this->assertTrue($generatedUsers->contains($b->id));
        $this->assertFalse($generatedUsers->contains($a->id));
        $this->assertSame($a->id, $res->json('conflicts.0.user_id'));
    }

    public function test_deleting_the_draft_frees_the_period(): void
    {
        $this->actingAsRole('admin');
        $emp = $this->employee();
        $id = $this->generate(['period_start' => '2026-09-01', 'period_end' => '2026-09-15', 'user_id' => $emp->id])
            ->json('generated.0.id');

        $this->deleteJson("/api/payroll/{$id}")->assertOk();

        $this->generate(['period_start' => '2026-09-10', 'period_end' => '2026-09-20', 'user_id' => $emp->id])->assertCreated();
    }

    public function test_a_period_that_has_not_ended_is_refused(): void
    {
        $this->actingAsRole('admin');
        $emp = $this->employee();

        $this->generate(['period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'user_id' => $emp->id])
            ->assertStatus(422)->assertJsonValidationErrors('period_end');

        // اليوم نفسه مسموح
        $this->generate(['period_start' => '2026-10-01', 'period_end' => '2026-10-07', 'user_id' => $emp->id])->assertCreated();
    }

    public function test_warns_about_open_and_auto_closed_shifts_in_the_period(): void
    {
        $this->actingAsRole('admin');
        $emp = $this->employee();
        Shift::create(['user_id' => $emp->id, 'clock_in' => '2026-09-05 08:00:00', 'clock_out' => '2026-09-05 20:00:00', 'auto_closed' => true]);
        Shift::create(['user_id' => $emp->id, 'clock_in' => '2026-09-06 08:00:00']);
        $clean = $this->employee();
        Shift::create(['user_id' => $clean->id, 'clock_in' => '2026-09-05 08:00:00', 'clock_out' => '2026-09-05 16:00:00']);

        $res = $this->generate(['period_start' => '2026-09-01', 'period_end' => '2026-09-30'])->assertCreated();

        $this->assertCount(1, $res->json('warnings'));
        $this->assertSame($emp->id, $res->json('warnings.0.user_id'));
        $this->assertSame(1, $res->json('warnings.0.open_shifts'));
        $this->assertSame(1, $res->json('warnings.0.auto_closed_shifts'));
    }

    public function test_deductions_cannot_exceed_gross_pay(): void
    {
        $this->actingAsRole('admin');
        $emp = $this->employee();
        Shift::create(['user_id' => $emp->id, 'clock_in' => '2026-09-05 08:00:00', 'clock_out' => '2026-09-05 18:00:00']); // 10 ساعات = 100
        $id = $this->generate(['period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'user_id' => $emp->id])
            ->json('generated.0.id');

        $this->putJson("/api/payroll/{$id}", ['deductions' => 100.01])->assertStatus(422)->assertJsonValidationErrors('deductions');
        $this->putJson("/api/payroll/{$id}", ['deductions' => 100])->assertOk();
        $this->assertEquals(0, Payroll::find($id)->net_pay);
    }
}
