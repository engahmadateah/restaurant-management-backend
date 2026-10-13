<?php

namespace App\Services;


class CsvExporter
{
    /**
     * @param  string[]  $headers
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    public static function build(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

      
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
