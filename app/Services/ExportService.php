<?php

declare(strict_types=1);

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV / XLSX / PDF exports (docs/02 FR-012, AT-008).
 *
 * All three formats are produced from the same ReportService rows, so a user
 * who exports the same filter twice in different formats gets the same numbers
 * -- AT-008 requires the exported data to match the filtered records, and that
 * has to hold per format.
 */
class ExportService
{
    public function __construct(private readonly ReportService $reports) {}

    /**
     * Streamed CSV.
     *
     * Written straight to the output buffer rather than assembled in memory,
     * so the response size is bounded by the row width, not the row count.
     */
    public function csv(AnalyticsFilter $filter, string $filename): StreamedResponse
    {
        $rows = $this->reports->chargingRows($filter);
        $columns = ReportService::CHARGING_COLUMNS;

        return response()->streamDownload(function () use ($rows, $columns): void {
            $handle = fopen('php://output', 'wb');

            // UTF-8 BOM: without it Excel on Windows renders Thai station
            // names as mojibake.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, array_values($columns));

            foreach ($rows as $row) {
                fputcsv($handle, $this->orderRow($row, $columns));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // Financial data: never cached by a proxy.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * Streamed XLSX via OpenSpout, which writes row by row rather than
     * building a spreadsheet object graph.
     */
    public function xlsx(AnalyticsFilter $filter, string $filename): StreamedResponse
    {
        $rows = $this->reports->chargingRows($filter);
        $columns = ReportService::CHARGING_COLUMNS;

        return response()->streamDownload(function () use ($rows, $columns): void {
            $writer = new XlsxWriter;
            $writer->openToFile('php://output');

            $writer->addRow(Row::fromValues(array_values($columns)));

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($this->orderRow($row, $columns)));
            }

            $writer->close();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * PDF summary.
     *
     * Not streamed: dompdf renders a whole document, so this is capped to a
     * reasonable page count rather than being offered for unbounded ranges.
     * A user wanting everything should take the CSV.
     *
     * @param  array<string, mixed>  $summary
     */
    public function pdf(AnalyticsFilter $filter, array $summary, string $filename): Response
    {
        $rows = $this->reports->chargingRows($filter)->take(self::PDF_ROW_LIMIT)->all();

        $pdf = Pdf::loadView('exports.charging-report', [
            'rows' => $rows,
            'columns' => ReportService::CHARGING_COLUMNS,
            'summary' => $summary,
            'filter' => $filter->describe(),
            'truncated' => count($rows) >= self::PDF_ROW_LIMIT,
            'generatedAt' => now()->timezone((string) config('app.display_timezone')),
        ]);

        return $pdf->download($filename);
    }

    /**
     * Cap on PDF rows. Beyond this the document stops being readable and
     * dompdf's memory use grows sharply; CSV and XLSX remain unbounded.
     */
    private const PDF_ROW_LIMIT = 1000;

    /**
     * Project a row onto the column order, so every format emits the same
     * columns in the same sequence even if a row omits a key.
     *
     * @param  array<string, string|null>  $row
     * @param  array<string, string>  $columns
     * @return list<string>
     */
    private function orderRow(array $row, array $columns): array
    {
        $ordered = [];

        foreach (array_keys($columns) as $key) {
            // A metric that could not be computed becomes an empty cell, never
            // a zero (docs/06).
            $ordered[] = $row[$key] ?? '';
        }

        return $ordered;
    }
}
