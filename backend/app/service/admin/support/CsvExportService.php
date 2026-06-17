<?php
declare(strict_types=1);

namespace app\service\admin\support;

use mall_base\base\BaseService;

/**
 * CSV export helper.
 */
class CsvExportService extends BaseService
{
    /**
     * @param array<string, string> $headers field => title
     * @param array<int, array<string, mixed>> $rows
     */
    public function make(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_values($headers));

        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys($headers) as $field) {
                $value = $row[$field] ?? '';
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $line[] = $value === null ? '' : (string) $value;
            }
            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }
}
