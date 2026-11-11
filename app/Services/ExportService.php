<?php

namespace App\Services;

use Illuminate\Support\LazyCollection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportService
{
    /**
     * @param  list<string>  $columns
     * @param  LazyCollection<int, list<mixed>>  $rows
     */
    public function download(string $basename, string $format, array $columns, LazyCollection $rows): StreamedResponse
    {
        if ($format === 'xlsx') {
            return response()->streamDownload(function () use ($columns, $rows): void {
                $spreadsheet = new Spreadsheet;
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->fromArray($columns, null, 'A1');
                $sheet->getStyle('1:1')->getFont()->setBold(true);
                $line = 2;

                foreach ($rows as $row) {
                    $sheet->fromArray(array_map(fn ($value) => $this->safe($value), $row), null, 'A'.$line++);
                }

                foreach (range(1, count($columns)) as $column) {
                    $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
                }

                $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
                (new Xlsx($spreadsheet))->save('php://output');
            }, $basename.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return response()->streamDownload(function () use ($columns, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns, ';', '"', '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($value) => $this->safe($value), $row), ';', '"', '');
            }

            fclose($out);
        }, $basename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Prevents CSV/formula injection when the file is opened in a spreadsheet application.
     */
    protected function safe(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
