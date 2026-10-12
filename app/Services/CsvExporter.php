<?php

namespace App\Services;

/**
 * مولّد ملفات CSV بسيط (بيفتح بإكسل/Google Sheets مباشرة).
 *
 * ليش CSV وليس xlsx حقيقي؟ توليد ملف .xlsx فعلي بتنسيقاته (ألوان، خطوط...)
 * بيحتاج مكتبة زي PhpSpreadsheet، وما فينا نجيبها عن طريق composer لأنو
 * بيئة التطوير هون ما عندها وصول لـ packagist.org. الـ CSV يلي منولده هون
 * بيفتح تلقائيًا بإكسل وبيحافظ 100% على الأرقام والنصوص العربية (بفضل
 * BOM UTF-8)، فهو بديل عملي وسريع. لو احتجتوا xlsx فعلي لاحقًا، سهل
 * نضيف PhpSpreadsheet متى ما صار في وصول للإنترنت بالسيرفر.
 */
class CsvExporter
{
    /**
     * @param  string[]  $headers
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    public static function build(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        // BOM حتى إكسل يفتح النص العربي/UTF-8 صح من أول مرة
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }
}
