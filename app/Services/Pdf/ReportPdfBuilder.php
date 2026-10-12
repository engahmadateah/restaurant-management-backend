<?php

namespace App\Services\Pdf;

class ReportPdfBuilder
{
    /**
     * @param  string[]  $metaLines  أسطر معلومات عامة (مجال التاريخ، الإجمالي...)
     * @param  string[]  $headers  عناوين أعمدة الجدول
     * @param  array<int, array<int, string|int|float|null>>  $rows
     * @param  float[]  $widths
     */
    public static function build(string $title, array $metaLines, array $headers, array $rows, array $widths): string
    {
        $pdf = new SimplePdfWriter;

        $pdf->text($title, 16, true, 'center');
        $pdf->spacer(6);

        foreach ($metaLines as $line) {
            $pdf->text($line, 10);
        }

        $pdf->spacer(6);
        $pdf->hr();

        if (empty($rows)) {
            $pdf->text('No data for the selected period.', 10);
        } else {
            $pdf->table($headers, $rows, $widths);
        }

        return $pdf->output();
    }
}
