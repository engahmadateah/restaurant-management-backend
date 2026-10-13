<?php

namespace App\Services\Pdf;

use App\Models\Payroll;
use App\Models\Setting;
use App\Models\Shift;


class PayslipPdfBuilder
{
    public static function build(Payroll $payroll): string
    {
        $payroll->loadMissing('user:id,name,role');
        $currency = Setting::get('currency') ?: 'USD';
        $m = fn ($v) => number_format((float) $v, 2) . ' ' . $currency;
        $user = $payroll->user;

        $pdf = new SimplePdfWriter;
        $pdf->text(Setting::get('restaurant_name') ?: 'Restaurant', 16, true, 'center');
        $pdf->text('Payslip', 13, true, 'center');
        $pdf->spacer(4);
        $pdf->hr();

        $pdf->text('Employee: ' . ($user?->name ?? '#' . $payroll->user_id), 11, true);
        $pdf->text('Role: ' . ucfirst((string) $user?->role), 10);
        $pdf->text('Period: ' . $payroll->period_start->toDateString() . ' to ' . $payroll->period_end->toDateString(), 10);
        $pdf->text('Status: ' . ucfirst($payroll->status->value)
            . ($payroll->paid_at ? ' (paid ' . $payroll->paid_at->toDateString() . ')' : ''), 10);
        $pdf->spacer(4);
        $pdf->hr();

        $rate = $payroll->salary_type === 'monthly'
            ? 'Monthly salary: ' . $m($payroll->monthly_salary)
            : 'Hourly rate: ' . $m($payroll->hourly_rate);

        $pdf->table(['Item', 'Value'], [
            ['Salary type', ucfirst((string) $payroll->salary_type)],
            ['Rate', $rate],
            ['Total hours', number_format((float) $payroll->total_hours, 2)],
            ['Regular hours', number_format((float) $payroll->regular_hours, 2)],
            ['Overtime hours (x' . number_format((float) $payroll->overtime_multiplier, 2) . ')', number_format((float) $payroll->overtime_hours, 2)],
            ['Regular pay', $m($payroll->regular_pay)],
            ['Overtime pay', $m($payroll->overtime_pay)],
            ['Gross pay', $m($payroll->gross_pay)],
            ['Deductions', $m($payroll->deductions)],
            ['NET PAY', $m($payroll->net_pay)],
        ], [230, 230]);

        if ($payroll->notes) {
            $pdf->spacer(4);
            $pdf->text('Notes: ' . $payroll->notes, 9);
        }

        $shifts = Shift::where('user_id', $payroll->user_id)
            ->whereNotNull('clock_out')
            ->whereBetween('clock_in', [$payroll->period_start->copy()->startOfDay(), $payroll->period_end->copy()->endOfDay()])
            ->orderBy('clock_in')
            ->get();

        $pdf->spacer(6);
        $pdf->hr();
        $pdf->text('Shifts in this period', 11, true);
        if ($shifts->isEmpty()) {
            $pdf->text('No completed shifts.', 10);
        } else {
            $pdf->table(['Date', 'Clock in', 'Clock out', 'Hours', 'Note'], $shifts->map(fn (Shift $s) => [
                $s->clock_in->toDateString(),
                $s->clock_in->format('H:i'),
                $s->clock_out->format('H:i'),
                number_format($s->clock_in->diffInMinutes($s->clock_out) / 60, 2),
                $s->auto_closed ? 'auto-closed' : '',
            ])->all(), [90, 80, 80, 70, 120]);
        }

        return $pdf->output();
    }
}
