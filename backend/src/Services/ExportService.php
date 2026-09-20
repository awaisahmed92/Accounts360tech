<?php
declare(strict_types=1);

namespace App\Services;

class ExportService
{
    public static function streamCsv(array $rows, string $filename): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"$filename\"");
        header('Cache-Control: no-cache, must-revalidate');

        $out = fopen('php://output', 'w');

        // BOM for Excel UTF-8
        fputs($out, "\xEF\xBB\xBF");

        // Headers
        fputcsv($out, [
            'Date',
            'Supplier',
            'Category',
            'Currency',
            'Tax Amount',
            'Total Amount',
            'Status',
            'Approved By',
            'Approved At',
            'Uploaded At',
        ]);

        foreach ($rows as $row) {
            fputcsv($out, [
                $row['document_date']  ?? '',
                $row['supplier_name']  ?? '',
                $row['category']       ?? '',
                $row['currency']       ?? 'USD',
                $row['tax_amount']     ?? '',
                $row['total_amount']   ?? '',
                $row['status']         ?? '',
                $row['approved_by_name'] ?? '',
                $row['approved_at']    ?? '',
                $row['created_at']     ?? '',
            ]);
        }

        fclose($out);
        exit;
    }
}
