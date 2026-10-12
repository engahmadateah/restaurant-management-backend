<?php

namespace App\Services\Pdf;

/** PDF لتقرير نهاية اليوم. نصوص لاتينية (الخط القياسي ما بيدعم العربي، شوف SimplePdfWriter). */
class ZReportPdfBuilder
{
    public static function build(array $r, string $restaurantName, string $currency): string
    {
        $m = fn ($v) => number_format((float) $v, 2) . ' ' . $currency;
        $pdf = new SimplePdfWriter;

        $pdf->text($restaurantName, 16, true, 'center');
        $pdf->text('Daily Closing Report (Z-Report)', 12, true, 'center');
        $pdf->text('Date: ' . $r['date'], 10, false, 'center');
        $pdf->spacer(4);
        $pdf->hr();

        $pdf->text('SALES', 11, true);
        $s = $r['sales'];
        $pdf->table(['Metric', 'Value'], [
            ['Orders', (string) $s['orders_count']],
            ['Gross sales', $m($s['gross_sales'])],
            ['Discounts given', $m($s['discounts'])],
            ['Delivery fees', $m($s['delivery_fees'])],
            ['Tax collected', $m($s['tax'])],
            ['Unpaid (outstanding)', $m($s['outstanding'])],
            ['Cancelled orders', (string) $s['cancelled_orders']],
        ], [220, 200]);
        $pdf->spacer(4);
        $pdf->hr();

        $pdf->text('PAYMENTS COLLECTED', 11, true);
        $rows = array_map(fn ($p) => [ucfirst($p['method']), $p['count'], $m($p['total']), $m($p['change_given'])], $r['payments']['by_method']);
        $pdf->table(['Method', 'Count', 'Total', 'Change given'], $rows, [110, 70, 150, 150]);
        $pdf->text('Total collected: ' . $m($r['payments']['collected']), 10, true);
        $pdf->spacer(4);
        $pdf->hr();

        $pdf->text('REFUNDS (approved today)', 11, true);
        $pdf->table(['Metric', 'Value'], [
            ['Count', (string) $r['refunds']['count']],
            ['Total refunded', $m($r['refunds']['total'])],
            ['Of which cash', $m($r['refunds']['cash_total'])],
        ], [220, 200]);
        $pdf->spacer(4);
        $pdf->text('NET COLLECTED: ' . $m($r['net_collected']), 12, true);
        $pdf->spacer(4);
        $pdf->hr();

        $pdf->text('CASH DRAWERS (closed today)', 11, true);
        $d = $r['drawers'];
        if ($d['items'] === []) {
            $pdf->text('No drawers closed today.', 10);
        } else {
            $rows = array_map(fn ($x) => [
                (string) $x['user'], number_format($x['opening_float'], 2), number_format($x['expected_cash'], 2),
                number_format($x['counted_cash'], 2), number_format($x['difference'], 2),
            ], $d['items']);
            $pdf->table(['Cashier', 'Opening', 'Expected', 'Counted', 'Difference'], $rows, [140, 90, 90, 90, 90]);
            $pdf->text('Shortage: ' . $m($d['shortage']) . '   Overage: ' . $m($d['overage']) . '   Net: ' . $m($d['net_difference']), 10, true);
        }
        if ($d['open_now'] > 0) {
            $pdf->text('Warning: ' . $d['open_now'] . ' drawer(s) still open.', 10, true);
        }

        $c = $r['cash_movements'];
        if ($c['count'] > 0) {
            $pdf->spacer(4);
            $pdf->hr();
            $pdf->text('CASH MOVEMENTS (recorded today)', 11, true);
            $pdf->table(['Type', 'Total'], [
                ['Cash expenses', $m($c['expenses'])],
                ['Withdrawals', $m($c['withdrawals'])],
                ['Deposits', $m($c['deposits'])],
            ], [220, 200]);
        }

        return $pdf->output();
    }
}
