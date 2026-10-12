<?php

namespace Tests\Feature;

use App\Models\Payroll;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;
use ZipArchive;

class PayslipExportTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    private function payroll(User $u, string $status = 'approved', string $from = '2026-09-01', string $to = '2026-09-30', float $net = 800): Payroll
    {
        return Payroll::create([
            'user_id' => $u->id, 'period_start' => $from, 'period_end' => $to, 'salary_type' => 'hourly', 'hourly_rate' => 10,
            'total_hours' => 90, 'regular_hours' => 80, 'overtime_hours' => 10, 'regular_pay' => 800, 'overtime_pay' => 150,
            'gross_pay' => 950, 'deductions' => 950 - $net, 'net_pay' => $net, 'status' => $status,
        ]);
    }

    public function test_employee_gets_own_payslip_pdf_only(): void
    {
        $emp = $this->actingAsRole('cashier');
        Shift::create(['user_id' => $emp->id, 'clock_in' => '2026-09-05 08:00:00', 'clock_out' => '2026-09-05 16:00:00']);
        $mine = $this->payroll($emp);
        $other = $this->payroll(User::create(['name' => 'Z', 'email' => 'z@t.local', 'password' => 'secret123', 'role' => 'waiter', 'is_active' => true]));

        $res = $this->get("/api/payroll/{$mine->id}/payslip", ['Accept' => 'application/pdf'])->assertOk();
        $this->assertStringStartsWith('%PDF', $res->getContent());

        $this->getJson("/api/payroll/{$other->id}/payslip")->assertForbidden();
    }

    public function test_draft_has_no_payslip_and_manager_can_print_anyone(): void
    {
        $emp = $this->actingAsRole('cashier');
        $draft = $this->payroll($emp, 'draft');
        $paid = $this->payroll($emp, 'paid', '2026-08-01', '2026-08-31');

        $this->getJson("/api/payroll/{$draft->id}/payslip")->assertStatus(422);

        $this->actingAsRole('manager');
        $this->get("/api/payroll/{$paid->id}/payslip", ['Accept' => 'application/pdf'])->assertOk();
        $this->getJson("/api/payroll/{$draft->id}/payslip")->assertStatus(422);
    }

    public function test_xlsx_export_is_a_valid_workbook_with_totals(): void
    {
        $a = User::create(['name' => 'Ahmad', 'email' => 'a@t.local', 'password' => 'secret123', 'role' => 'cashier', 'is_active' => true]);
        $b = User::create(['name' => 'علي <&>', 'email' => 'b@t.local', 'password' => 'secret123', 'role' => 'waiter', 'is_active' => true]);
        $this->payroll($a, 'paid', net: 800);
        $this->payroll($b, 'approved', net: 700);
        $this->payroll($b, 'draft', '2026-08-01', '2026-08-31', net: 100);
        $this->actingAsRole('manager');

        $res = $this->get('/api/payroll/export?status=approved', ['Accept' => 'application/json']);
        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('PK', $res->getContent());

        $tmp = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($tmp, $res->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp));
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $part) {
            $xml = $zip->getFromName($part);
            $this->assertNotFalse($xml, "missing {$part}");
            $this->assertNotFalse(simplexml_load_string($xml), "{$part} is not well-formed XML");
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($tmp);

        // فلتر الحالة: بس approved (علي) + صف مجاميع
        $this->assertStringContainsString('علي &lt;&amp;&gt;', $sheet);
        $this->assertStringNotContainsString('Ahmad', $sheet);
        $this->assertStringContainsString('الإجمالي', $sheet);
        $this->assertStringContainsString('<v>700.000000</v>', $sheet);
    }

    public function test_xlsx_without_rows_still_valid_and_csv_has_bom(): void
    {
        $this->actingAsRole('manager');

        $empty = $this->get('/api/payroll/export')->assertOk();
        $this->assertStringStartsWith('PK', $empty->getContent());

        $emp = User::create(['name' => 'Ahmad', 'email' => 'a@t.local', 'password' => 'secret123', 'role' => 'cashier', 'is_active' => true]);
        $this->payroll($emp);
        $csv = $this->get('/api/payroll/export?format=csv')->assertOk();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv->getContent());
        $this->assertStringContainsString('Ahmad', $csv->getContent());
        $this->assertStringContainsString('الإجمالي', $csv->getContent());
    }

    public function test_period_filter_uses_overlap(): void
    {
        $emp = User::create(['name' => 'Ahmad', 'email' => 'a@t.local', 'password' => 'secret123', 'role' => 'cashier', 'is_active' => true]);
        $this->payroll($emp, 'paid', '2026-08-01', '2026-08-31');
        $this->actingAsRole('manager');

        $inRange = $this->get('/api/payroll/export?format=csv&from=2026-08-15&to=2026-09-10')->getContent();
        $outRange = $this->get('/api/payroll/export?format=csv&from=2026-09-01&to=2026-09-30')->getContent();

        $this->assertStringContainsString('Ahmad', $inRange);
        $this->assertStringNotContainsString('Ahmad', $outRange);
    }

    public function test_export_is_manager_only_and_validates_format(): void
    {
        $this->actingAsRole('cashier');
        $this->getJson('/api/payroll/export')->assertForbidden();

        $this->actingAsRole('manager');
        $this->getJson('/api/payroll/export?format=pdf')->assertStatus(422);
    }
}
