<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;


class XlsxExporter
{
    public static function isAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * @param  string[]  $headers
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    public static function build(array $headers, array $rows, string $sheetName = 'Sheet1', bool $boldLastRow = false, bool $rtl = true): string
    {
        if (! self::isAvailable()) {
            throw new RuntimeException('امتداد PHP zip غير مفعّل');
        }

        $sheet = self::sheetXml($headers, $rows, $boldLastRow, $rtl);
        $sheetName = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $sheetName) ?: 'Sheet1', 0, 31);

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new RuntimeException('تعذّر إنشاء ملف Excel');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::esc($sheetName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
       
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEDE7E7"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="5">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="2" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="2" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        $content = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $content;
    }

    private static function sheetXml(array $headers, array $rows, bool $boldLastRow, bool $rtl): string
    {
        $colCount = count($headers);

    
        $widths = array_map(fn ($h) => mb_strlen((string) $h) + 4, $headers);
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $cell) {
                if (is_string($cell)) {
                    $widths[$i] = max($widths[$i] ?? 10, mb_strlen($cell) + 2);
                }
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"' . ($rtl ? ' rightToLeft="1"' : '') . '>'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>';
        for ($i = 0; $i < $colCount; $i++) {
            $w = max(10, min($widths[$i] ?? 12, 50));
            $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $xml .= '</cols><sheetData>';

        $xml .= '<row r="1">';
        foreach (array_values($headers) as $i => $h) {
            $xml .= self::cell($i, 1, (string) $h, 1);
        }
        $xml .= '</row>';

        $lastIndex = count($rows) - 1;
        foreach (array_values($rows) as $r => $row) {
            $excelRow = $r + 2;
            $bold = $boldLastRow && $r === $lastIndex;
            $xml .= '<row r="' . $excelRow . '">';
            foreach (array_values($row) as $i => $cell) {
                if ($cell === null || $cell === '') {
                    continue;
                }
                $style = is_float($cell) ? ($bold ? 4 : 2) : ($bold ? 3 : 0);
                $xml .= self::cell($i, $excelRow, $cell, $style);
            }
            $xml .= '</row>';
        }

        return $xml . '</sheetData></worksheet>';
    }

    private static function cell(int $col, int $row, string|int|float $value, int $style): string
    {
        $ref = self::column($col) . $row;

        if (is_int($value) || is_float($value)) {
            return '<c r="' . $ref . '" s="' . $style . '"><v>' . (is_float($value) ? sprintf('%.6F', $value) : $value) . '</v></c>';
        }

        return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . self::esc($value) . '</t></is></c>';
    }

    private static function column(int $index): string
    {
        $name = '';
        for ($i = $index + 1; $i > 0; $i = intdiv($i - 1, 26)) {
            $name = chr(65 + ($i - 1) % 26) . $name;
        }

        return $name;
    }

    private static function esc(string $text): string
    {
      
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? '';

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
