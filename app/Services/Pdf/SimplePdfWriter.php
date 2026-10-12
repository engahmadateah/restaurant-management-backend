<?php

namespace App\Services\Pdf;

/**
 * مولّد PDF بسيط بدون أي مكتبات خارجية (لا dompdf ولا PhpSpreadsheet).
 *
 * ليش هيك؟ بيئة التطوير هون ما عندها وصول لـ packagist.org لتنزيل
 * حزم composer جديدة، فكتبنا مولّد PDF مبسّط يشتغل بـ PHP الصافي
 * (يبني بايتات ملف PDF يدويًا حسب الـ spec) بدون أي dependency.
 *
 * ملاحظة مهمة: الخط المستخدم Helvetica القياسي (WinAnsi)، وهو ما بيدعم
 * الحروف العربية (الـ shaping/bidi لازمه مكتبة متخصصة). فـ أي نص عربي
 * منمرره بيتحول تلقائيًا لأقرب حروف لاتينية أو "?" حتى ما ينكسر الملف.
 * الأرقام والتواريخ والأسعار (يلي هي أهم شي بالفاتورة/التقرير) بتظهر
 * صح 100%. لدعم عربي كامل بالـ PDF مستقبلاً، لازم نضيف dompdf/mpdf
 * عن طريق composer لما يصير في وصول للإنترنت.
 */
class SimplePdfWriter
{
    protected array $pages = [];

    protected string $buffer = '';

    protected float $y;

    protected float $pageWidth = 595.28; // A4

    protected float $pageHeight = 841.89;

    protected float $marginX = 40;

    protected float $marginTop = 40;

    protected float $marginBottom = 40;

    public function __construct()
    {
        $this->y = $this->pageHeight - $this->marginTop;
    }

    protected function newPage(): void
    {
        $this->pages[] = $this->buffer;
        $this->buffer = '';
        $this->y = $this->pageHeight - $this->marginTop;
    }

    protected function ensureSpace(float $needed): void
    {
        if ($this->y - $needed < $this->marginBottom) {
            $this->newPage();
        }
    }

    // بينظف النص لحروف لاتينية آمنة جوا ملف PDF (شوف الملاحظة فوق بخصوص العربي)
    protected function esc(string $text): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
        if ($converted === false || $converted === '') {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
    }

    protected function textWidth(string $text, float $size): float
    {
        return strlen($text) * $size * 0.5;
    }

    public function text(string $text, float $size = 11, bool $bold = false, string $align = 'left'): void
    {
        $this->ensureSpace($size + 8);
        $font = $bold ? '/F2' : '/F1';
        $escaped = $this->esc($text);

        $x = $this->marginX;
        if ($align === 'right') {
            $x = $this->pageWidth - $this->marginX - $this->textWidth($text, $size);
        } elseif ($align === 'center') {
            $x = ($this->pageWidth - $this->textWidth($text, $size)) / 2;
        }

        $this->buffer .= sprintf("BT %s %.1f Tf %.2f %.2f Td (%s) Tj ET\n", $font, $size, $x, $this->y, $escaped);
        $this->y -= ($size + 8);
    }

    public function spacer(float $height = 8): void
    {
        $this->ensureSpace($height);
        $this->y -= $height;
    }

    public function hr(): void
    {
        $this->ensureSpace(12);
        $this->buffer .= sprintf("0.6 G 0.6 w %.2f %.2f m %.2f %.2f l S\n", $this->marginX, $this->y, $this->pageWidth - $this->marginX, $this->y);
        $this->y -= 12;
    }

    /**
     * @param  string[]  $headers
     * @param  array<int, array<int, string|int|float|null>>  $rows
     * @param  float[]  $widths  عرض كل عمود بالنقاط (points)
     */
    public function table(array $headers, array $rows, array $widths): void
    {
        $rowHeight = 16;

        $this->ensureSpace($rowHeight + 6);
        $x = $this->marginX;
        foreach ($headers as $i => $header) {
            $this->buffer .= sprintf("BT /F2 9 Tf %.2f %.2f Td (%s) Tj ET\n", $x, $this->y, $this->esc((string) $header));
            $x += $widths[$i] ?? 70;
        }
        $this->y -= 4;
        $this->buffer .= sprintf("0.6 G 0.6 w %.2f %.2f m %.2f %.2f l S\n", $this->marginX, $this->y, $this->pageWidth - $this->marginX, $this->y);
        $this->y -= ($rowHeight - 4);

        foreach ($rows as $row) {
            $this->ensureSpace($rowHeight);
            $x = $this->marginX;
            foreach ($row as $i => $cell) {
                $this->buffer .= sprintf("BT /F1 9 Tf %.2f %.2f Td (%s) Tj ET\n", $x, $this->y, $this->esc((string) $cell));
                $x += $widths[$i] ?? 70;
            }
            $this->y -= $rowHeight;
        }
    }

    public function output(): string
    {
        $this->pages[] = $this->buffer;
        $this->buffer = '';
        if (empty($this->pages)) {
            $this->pages[] = '';
        }

        $numPages = count($this->pages);
        $pageObjStart = 5; // 1=Catalog 2=Pages 3=FontRegular 4=FontBold
        $kidsIds = [];
        for ($i = 0; $i < $numPages; $i++) {
            $kidsIds[] = $pageObjStart + $i * 2;
        }
        $kids = implode(' ', array_map(fn ($id) => "{$id} 0 R", $kidsIds));

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = "<< /Type /Pages /Kids [{$kids}] /Count {$numPages} >>";
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        for ($i = 0; $i < $numPages; $i++) {
            $pageId = $pageObjStart + $i * 2;
            $contentId = $pageId + 1;
            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                $this->pageWidth,
                $this->pageHeight,
                $contentId
            );
            $stream = $this->pages[$i];
            $objects[$contentId] = sprintf("<< /Length %d >>\nstream\n%sendstream", strlen($stream), $stream);
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $maxId = max(array_keys($objects));
        $xrefStart = strlen($pdf);
        $pdf .= 'xref' . "\n" . '0 ' . ($maxId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= isset($offsets[$id])
                ? sprintf("%010d 00000 n \n", $offsets[$id])
                : "0000000000 00000 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF";

        return $pdf;
    }
}
