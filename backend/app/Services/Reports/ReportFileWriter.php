<?php

namespace App\Services\Reports;

use App\Enums\ReportFormat;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * T25 — renders a (headers, rows) dataset to CSV or PDF bytes and writes
 * it to the private 'reports' disk (never public — same posture as T21's
 * 'attachments' disk; ReportController issues a signed URL for download).
 */
class ReportFileWriter
{
    /** @return string the disk key the file was written to */
    public function write(string $tenantId, string $reportId, ReportFormat $format, string $title, array $headers, array $rows): string
    {
        $extension = $format === ReportFormat::Csv ? 'csv' : 'pdf';
        $key = "{$tenantId}/{$reportId}.{$extension}";

        $contents = $format === ReportFormat::Csv
            ? $this->renderCsv($headers, $rows)
            : $this->renderPdf($title, $headers, $rows);

        Storage::disk('reports')->put($key, $contents);

        return $key;
    }

    private function renderCsv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'w+');

        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function renderPdf(string $title, array $headers, array $rows): string
    {
        $html = $this->renderHtml($title, $headers, $rows);

        return Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output();
    }

    /**
     * Builds the report's HTML directly (no Blade view) — simple enough
     * that a template file would just add an extra place to keep in sync,
     * and this keeps the whole render path in one class.
     */
    private function renderHtml(string $title, array $headers, array $rows): string
    {
        $escape = fn ($v) => e((string) ($v ?? ''));

        $theadCells = implode('', array_map(fn ($h) => '<th>'.$escape($h).'</th>', $headers));

        $bodyRows = implode('', array_map(
            fn ($row) => '<tr>'.implode('', array_map(fn ($cell) => '<td>'.$escape($cell).'</td>', $row)).'</tr>',
            $rows
        ));

        $generatedAt = $escape(now()->toDateTimeString());
        $rowCount = count($rows);
        $titleEscaped = $escape($title);
        $slug = Str::slug($title);

        return <<<HTML
        <html>
        <head>
        <meta charset="utf-8">
        <style>
            body { font-family: sans-serif; font-size: 11px; color: #1a1a1a; }
            h1 { font-size: 16px; margin-bottom: 2px; }
            .meta { color: #555; margin-bottom: 12px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
            th { background: #f0f0f0; }
        </style>
        </head>
        <body>
        <h1>{$titleEscaped}</h1>
        <div class="meta">Generated {$generatedAt} &middot; {$rowCount} row(s) &middot; ref: {$slug}</div>
        <table>
            <thead><tr>{$theadCells}</tr></thead>
            <tbody>{$bodyRows}</tbody>
        </table>
        </body>
        </html>
        HTML;
    }
}
