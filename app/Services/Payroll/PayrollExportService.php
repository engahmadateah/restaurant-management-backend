<?php

namespace App\Services\Payroll;

use App\Models\Payroll;
use App\Services\CsvExporter;
use App\Services\XlsxExporter;
use Illuminate\Validation\ValidationException;

/**
 * تصدير الرواتب للمحاسب (xlsx أو csv) مع صف مجاميع. فلاتر اختيارية: from/to (كشوفات فترتها
 * بتتقاطع مع المجال)، status، user_id.
 */
class PayrollExportService
{
    private const HEADERS = [
        'الموظف', 'الدور', 'من', 'إلى', 'نوع الراتب', 'إجمالي الساعات', 'ساعات عادية', 'ساعات إضافية',
        'الراتب العادي', 'الإضافي', 'الإجمالي', 'الاستقطاعات', 'الصافي', 'الحالة', 'تاريخ الاعتماد', 'تاريخ الدفع',
    ];

    private const MONEY = ['regular_pay', 'overtime_pay', 'gross_pay', 'deductions', 'net_pay'];

    /** @return array{content: string, content_type: string, filename: string} */
    public function export(array $filters): array
    {
        $format = $filters['format'] ?? 'xlsx';

        if ($format === 'xlsx' && ! XlsxExporter::isAvailable()) {
            throw ValidationException::withMessages(['format' => ['امتداد zip غير مفعّل على السيرفر، استخدم csv']]);
        }

        $rows = $this->rows($filters);
        $filename = 'payroll-' . now()->toDateString();

        if ($format === 'csv') {
            return [
                'content' => CsvExporter::build(self::HEADERS, $rows),
                'content_type' => 'text/csv; charset=UTF-8',
                'filename' => $filename . '.csv',
            ];
        }

        return [
            'content' => XlsxExporter::build(self::HEADERS, $rows, 'Payroll', boldLastRow: $rows !== []),
            'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'filename' => $filename . '.xlsx',
        ];
    }

    private function rows(array $filters): array
    {
        $payrolls = Payroll::with('user:id,name,role')
            ->overlapping($filters['from'] ?? null, $filters['to'] ?? null)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->orderBy('period_start')
            ->orderBy('user_id')
            ->get();

        $totals = array_fill_keys(['total_hours', 'regular_hours', 'overtime_hours', ...self::MONEY], 0.0);

        $rows = [];
        foreach ($payrolls as $p) {
            foreach ($totals as $k => $_) {
                $totals[$k] += (float) $p->{$k};
            }

            $rows[] = [
                $p->user?->name ?? ('#' . $p->user_id), (string) $p->user?->role,
                $p->period_start->toDateString(), $p->period_end->toDateString(), $p->salary_type,
                (float) $p->total_hours, (float) $p->regular_hours, (float) $p->overtime_hours,
                (float) $p->regular_pay, (float) $p->overtime_pay, (float) $p->gross_pay, (float) $p->deductions, (float) $p->net_pay,
                $p->status->value, $p->approved_at?->toDateString(), $p->paid_at?->toDateString(),
            ];
        }

        if ($rows !== []) {
            $rows[] = ['الإجمالي', '', '', '', '',
                round($totals['total_hours'], 2), round($totals['regular_hours'], 2), round($totals['overtime_hours'], 2),
                round($totals['regular_pay'], 2), round($totals['overtime_pay'], 2), round($totals['gross_pay'], 2),
                round($totals['deductions'], 2), round($totals['net_pay'], 2), '', '', ''];
        }

        return $rows;
    }
}
