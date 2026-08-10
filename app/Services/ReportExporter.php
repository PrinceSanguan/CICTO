<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * §19 "exportable to PDF and Excel".
 *
 * Both exports run SYNCHRONOUSLY. QUEUE_CONNECTION=database has no worker, so a
 * queued export would produce no file and no error -- the user would click
 * Export and simply never receive anything. Synchronous with a hard row cap is
 * the honest trade.
 *
 * openspout rather than maatwebsite/excel: it streams, and it needs neither
 * php-zip's full API surface nor php-gd. On a 256MB shared plan that is the
 * difference between an export and a 500.
 */
class ReportExporter
{
    /**
     * Streaming XLSX.
     *
     * @param  array<int, string>  $headings
     * @param  iterable<int, array<int, scalar|null>>  $rows  values are re-indexed to a list for openspout
     */
    public function xlsx(string $filename, array $headings, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $rows): void {
            $writer = new XlsxWriter;
            $writer->openToFile('php://output');

            $bold = (new Style)->setFontBold();
            $writer->addRow(Row::fromValues(array_values($headings), $bold));

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues(array_values($row)));
            }

            $writer->close();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * CSV, for when "Excel" really meant "a file that opens in Excel".
     *
     * Costs nothing and cannot run out of memory. Kept as a hidden ?format=csv
     * because most clients who ask for Excel are describing this.
     *
     * @param  array<int, string>  $headings
     * @param  iterable<int, array<int, scalar|null>>  $rows  values are re-indexed to a list for openspout
     */
    public function csv(string $filename, array $headings, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $rows): void {
            $writer = new CsvWriter;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(array_values($headings)));

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues(array_values($row)));
            }

            $writer->close();
        }, $filename, [
            'Content-Type' => 'text/csv',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * PDF via dompdf.
     *
     * The view must carry its own hex/table stylesheet: dompdf cannot parse
     * oklch(), flexbox or grid, and Tailwind 4's theme is built on all three,
     * so sharing the app stylesheet renders a blank page.
     *
     * @param  array<string, mixed>  $data
     */
    public function pdf(string $view, array $data, string $filename): Response
    {
        $pdf = Pdf::loadView($view, $data)
            ->setPaper('a4')
            // No remote fetching. A report should never make the PDF renderer
            // reach out to the network on the strength of a src attribute.
            ->setOption('isRemoteEnabled', false);

        return $pdf->download($filename);
    }
}
