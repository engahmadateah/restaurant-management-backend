<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CsvExporter;
use App\Services\Pdf\ReportPdfBuilder;
use App\Models\Setting;
use App\Services\Pdf\ZReportPdfBuilder;
use App\Services\SalesReport;
use App\Services\ZReport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * كل أرقام الإيراد والربح محسوبة من App\Services\SalesReport (تعريف واحد للكل،
 * شوف التعليق بأول الكلاس هناك): الإيراد الصافي = المبيعات - الاسترجاعات - الضريبة،
 * والربح = الإيراد الصافي - المصاريف - الرواتب المعتمدة.
 */
class ReportController extends Controller
{
    public function __construct(private SalesReport $report)
    {
    }

    /** @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon} */
    private function dateRange(Request $request): array
    {
        // تاريخ غلط أو from > to كانت تعطي 500 أو نتيجة فاضية بصمت
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $from = $request->filled('from') ? $request->date('from')->startOfDay() : now()->subDays(30)->startOfDay();
        $to = $request->filled('to') ? $request->date('to')->endOfDay() : now()->endOfDay();

        return [$from, $to];
    }

    private function limit(Request $request): int
    {
        return max(1, min((int) $request->get('limit', 10), 100));
    }

    public function sales(Request $request)
    {
        [$from, $to] = $this->dateRange($request);

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ] + $this->report->summary($from, $to) + [
            'by_day' => $this->report->byDay($from, $to)->values(),
            'by_order_type' => $this->report->byOrderType($from, $to)->values(),
        ]);
    }

    /** تقرير نهاية اليوم (JSON). ?date=YYYY-MM-DD، الافتراضي اليوم */
    public function zReport(Request $request, ZReport $z)
    {
        return response()->json($z->forDay($this->zDay($request)));
    }

    /** نفس التقرير كـ PDF للطباعة (reports.export) */
    public function zReportPdf(Request $request, ZReport $z)
    {
        $report = $z->forDay($this->zDay($request));

        $pdf = ZReportPdfBuilder::build($report, Setting::get('restaurant_name') ?: 'Restaurant', Setting::get('currency') ?: 'USD');

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="z-report-' . $report['date'] . '.pdf"',
        ]);
    }

    private function zDay(Request $request): \Illuminate\Support\Carbon
    {
        $request->validate(['date' => 'nullable|date|before_or_equal:today']);

        return $request->filled('date') ? $request->date('date') : now();
    }

    public function topItems(Request $request)
    {
        [$from, $to] = $this->dateRange($request);

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'items' => $this->report->topItems($from, $to, $this->limit($request))->values(),
        ]);
    }

    public function revenueByCategory(Request $request)
    {
        [$from, $to] = $this->dateRange($request);

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'categories' => $this->report->revenueByCategory($from, $to)->values(),
        ]);
    }

    public function profit(Request $request)
    {
        [$from, $to] = $this->dateRange($request);

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ] + $this->report->profit($from, $to));
    }

    public function staffPerformance(Request $request)
    {
        [$from, $to] = $this->dateRange($request);

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'staff' => $this->staffRows($from, $to),
        ]);
    }

    /** @return array<int, array> */
    private function staffRows($from, $to): array
    {
        $rows = $this->report->byStaff($from, $to);
        $users = User::withTrashed()->whereIn('id', $rows->pluck('user_id'))->get(['id', 'name', 'role'])->keyBy('id');

        return $rows->map(fn ($row) => [
            'user_id' => $row['user_id'],
            'name' => $users[$row['user_id']]->name ?? null,
            'role' => $users[$row['user_id']]->role ?? null,
        ] + $row)->values()->all();
    }

    /**
     * تصدير أي تقرير من التقارير فوق كـ CSV (يفتح مباشرة بإكسل) أو PDF.
     * GET /reports/export?type=sales|top-items|revenue-by-category|profit|staff-performance&format=csv|pdf&from=&to=
     *
     * (تصدير xlsx حقيقي بيحتاج مكتبة PhpSpreadsheet غير متوفرة هلق بدون
     * وصول composer/packagist بالسيرفر - شوف App\Services\CsvExporter)
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['sales', 'top-items', 'revenue-by-category', 'profit', 'staff-performance'])],
            'format' => ['required', Rule::in(['csv', 'pdf'])],
        ]);

        [$from, $to] = $this->dateRange($request);
        [$title, $meta, $headers, $rows, $widths] = match ($validated['type']) {
            'sales' => $this->exportSalesData($from, $to),
            'top-items' => $this->exportTopItemsData($request, $from, $to),
            'revenue-by-category' => $this->exportRevenueByCategoryData($from, $to),
            'profit' => $this->exportProfitData($from, $to),
            'staff-performance' => $this->exportStaffPerformanceData($from, $to),
        };

        $filename = $validated['type'] . '-' . $from->toDateString() . '-to-' . $to->toDateString();

        if ($validated['format'] === 'csv') {
            $csv = CsvExporter::build($headers, $rows);

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}.csv\"",
            ]);
        }

        $pdf = ReportPdfBuilder::build($title, $meta, $headers, $rows, $widths);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}.pdf\"",
        ]);
    }

    private function money(float $value): string
    {
        return number_format($value, 2);
    }

    private function exportSalesData($from, $to): array
    {
        $s = $this->report->summary($from, $to);

        $meta = [
            "Period: {$from->toDateString()} to {$to->toDateString()}",
            "Orders: {$s['orders_count']}",
            'Gross Sales: ' . $this->money($s['gross_sales']),
            'Tax: ' . $this->money($s['tax']),
            'Refunds: ' . $this->money($s['refunds']),
            'Net Revenue: ' . $this->money($s['net_revenue']),
        ];

        $rows = $this->report->byDay($from, $to)->map(fn ($r) => [
            $r['date'], $r['orders_count'], $this->money($r['gross_sales']), $this->money($r['net_revenue']),
        ])->all();

        return ['Sales Report', $meta, ['Date', 'Orders', 'Gross Sales', 'Net Revenue'], $rows, [120, 90, 120, 120]];
    }

    private function exportTopItemsData(Request $request, $from, $to): array
    {
        $limit = $this->limit($request);

        $meta = ["Period: {$from->toDateString()} to {$to->toDateString()}", "Top {$limit} items"];
        $rows = $this->report->topItems($from, $to, $limit)->map(fn ($r) => [
            $r['name'] ?? ('#' . $r['menu_item_id']), $r['quantity_sold'], $this->money($r['revenue']),
        ])->all();

        return ['Top Selling Items', $meta, ['Item', 'Qty Sold', 'Revenue'], $rows, [250, 100, 100]];
    }

    private function exportRevenueByCategoryData($from, $to): array
    {
        $meta = ["Period: {$from->toDateString()} to {$to->toDateString()}"];
        $rows = $this->report->revenueByCategory($from, $to)->map(fn ($r) => [
            $r['category_name'], $r['quantity_sold'], $this->money($r['revenue']),
        ])->all();

        return ['Revenue by Category', $meta, ['Category', 'Qty Sold', 'Revenue'], $rows, [250, 100, 100]];
    }

    private function exportProfitData($from, $to): array
    {
        $p = $this->report->profit($from, $to);

        $meta = [
            "Period: {$from->toDateString()} to {$to->toDateString()}",
            'Net Revenue: ' . $this->money($p['net_revenue']),
            'Expenses: ' . $this->money($p['expenses']),
            'Payroll: ' . $this->money($p['payroll']),
            'Net Profit: ' . $this->money($p['net_profit']),
        ];
        $rows = $p['expenses_by_category']->map(fn ($r) => [$r->category, $this->money((float) $r->total)])->all();

        return ['Profit & Loss Report', $meta, ['Expense Category', 'Total'], $rows, [250, 150]];
    }

    private function exportStaffPerformanceData($from, $to): array
    {
        $meta = ["Period: {$from->toDateString()} to {$to->toDateString()}"];
        $rows = array_map(fn ($r) => [
            $r['name'] ?? ('#' . $r['user_id']), $r['role'], $r['orders_count'], $this->money($r['net_revenue']),
        ], $this->staffRows($from, $to));

        return ['Staff Performance', $meta, ['Name', 'Role', 'Orders', 'Net Revenue'], $rows, [180, 100, 90, 100]];
    }
}
