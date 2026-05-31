<?php

namespace Pandoc\Reader;

use Pandoc\AST\Attr;
use Pandoc\AST\Emph;
use Pandoc\AST\Header;
use Pandoc\AST\Meta;
use Pandoc\AST\Pandoc;
use Pandoc\AST\Plain;
use Pandoc\AST\Space;
use Pandoc\AST\Str;
use Pandoc\AST\Strong;
use Pandoc\AST\RawBlock;
use Pandoc\AST\Para;
use Pandoc\AST\Image;
use Pandoc\AST\Target;
use Pandoc\Reader\Xlsx\Parser;
use Pandoc\Reader\Xlsx\XlsxChart;
use Pandoc\Reader\Xlsx\XlsxChartSeries;
use Pandoc\Reader\Xlsx\XlsxLocale;
use Pandoc\Reader\Xlsx\XlsxSheet;
use Pandoc\Reader\Xlsx\XlsxCell;

class XlsxReader implements ReaderInterface
{
    use HasMediaBag;

    private Parser $parser;

    public function __construct()
    {
        $this->parser = new Parser();
        $this->initMediaBag();
    }

    public function read(string $filePath): Pandoc
    {
        $this->initMediaBag();
        $doc = $this->parser->parse($filePath);
        $locale = $doc->locale;
        $blocks = [];
        $sheetNames = [];

        foreach ($doc->sheets as $sheet) {
            $blocks[] = new Header(2, new Attr("sheet-{$sheet->id}", [], []), [new Str($sheet->name)]);
            $sheetNames[] = $sheet->name;

            $table = $this->sheetToTable($sheet);
            if ($table !== null) {
                $blocks[] = $table;
            }

            // Embedded images
            foreach ($sheet->images as $img) {
                $this->mediaBag->insert($img->filename, $img->mime, $img->data);
                $blocks[] = new Para([
                    new Image(new Attr(), [], new Target($img->filename))
                ]);
            }

            // Sheet → locale-aware CSV in MediaBag
            $sheetCsv = $this->sheetToCsv($sheet, $locale);
            if ($sheetCsv !== '') {
                $safeName = preg_replace('/[^A-Za-z0-9_-]/', '_', $sheet->name);
                $this->mediaBag->insert("sheet-{$safeName}.csv", 'text/csv', $sheetCsv);
            }

            // Charts → JSON + locale-aware CSV in MediaBag, marker in LaTeX
            foreach ($sheet->charts as $chart) {
                $json = $this->chartToJson($chart);
                $csv  = $this->chartToCsv($chart, $locale);
                $this->mediaBag->insert("{$chart->id}.json", 'application/json', $json);
                $this->mediaBag->insert("{$chart->id}.csv", 'text/csv', $csv);
                $blocks[] = new RawBlock('latex', "% [pandoc-chart: {$chart->id}.json]");
            }
        }

        $this->mediaBag->insert('metadata.json', 'application/json', $this->buildMetadata($locale, $sheetNames));

        return new Pandoc(new Meta(), $blocks, $this->mediaBag);
    }

    private function buildMetadata(XlsxLocale $locale, array $sheetNames): string
    {
        return json_encode([
            'language'          => $locale->language,
            'decimalSeparator'  => $locale->decimalSep,
            'thousandsSeparator'=> $locale->thousandsSep,
            'columnDelimiter'   => $locale->columnDelim,
            'quoteCharacter'    => $locale->quoteChar,
            'sheets'            => $sheetNames,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private function sheetToTable(XlsxSheet $sheet): ?\Pandoc\AST\Table
    {
        if (empty($sheet->cells)) {
            return null;
        }

        $minCol = PHP_INT_MAX;
        $maxCol = PHP_INT_MIN;
        $minRow = PHP_INT_MAX;
        $maxRow = PHP_INT_MIN;

        foreach ($sheet->cells as $cell) {
            $minCol = min($minCol, $cell->col);
            $maxCol = max($maxCol, $cell->col);
            $minRow = min($minRow, $cell->row);
            $maxRow = max($maxRow, $cell->row);
        }

        // Build dense grid; missing cells → null (empty)
        $grid = [];
        for ($row = $minRow; $row <= $maxRow; $row++) {
            $gridRow = [];
            for ($col = $minCol; $col <= $maxCol; $col++) {
                $gridRow[] = $sheet->cells["$col,$row"] ?? null;
            }
            $grid[] = $gridRow;
        }

        $headerRow = array_shift($grid);
        $bodyRows = $grid;

        // Drop trailing all-empty rows (same heuristic as Haskell)
        while (!empty($bodyRows) && $this->isEmptyRow(end($bodyRows))) {
            array_pop($bodyRows);
        }

        $makeRow = fn(array $row) => new \Pandoc\AST\Row(
            new Attr(),
            array_map([$this, 'makeCell'], $row)
        );

        $head = new \Pandoc\AST\TableHead(new Attr(), [$makeRow($headerRow)]);
        $bodies = [new \Pandoc\AST\TableBody(
            new Attr(),
            0,
            [],
            array_map($makeRow, $bodyRows)
        )];

        return new \Pandoc\AST\Table(
            new Attr(),
            new \Pandoc\AST\Caption(null, []),
            [],
            $head,
            $bodies,
            new \Pandoc\AST\TableFoot(new Attr(), [])
        );
    }

    public function makeCell(?XlsxCell $cell): \Pandoc\AST\Cell
    {
        $inlines = $cell !== null ? $this->cellToInlines($cell) : [];
        return new \Pandoc\AST\Cell(
            new Attr(),
            \Pandoc\AST\Alignment::AlignDefault,
            1,
            1,
            [new Plain($inlines)]
        );
    }

    private function cellToInlines(XlsxCell $cell): array
    {
        $base = match ($cell->type) {
            'text'   => $this->textToInlines($cell->text),
            'number' => $this->numberToInlines($cell->number),
            default  => [],
        };

        if ($cell->bold && !empty($base)) {
            $base = [new Strong($base)];
        }
        if ($cell->italic && !empty($base)) {
            $base = [new Emph($base)];
        }

        return $base;
    }

    private function textToInlines(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $parts = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $inlines = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^\s+$/u', $part)) {
                $inlines[] = new Space();
            } else {
                $inlines[] = new Str($part);
            }
        }
        return $inlines;
    }

    private function numberToInlines(?float $n): array
    {
        if ($n === null) {
            return [];
        }
        // Match Haskell's `show` for Double: integers get ".0" suffix
        $text = fmod($n, 1.0) === 0.0
            ? number_format($n, 1, '.', '')
            : (string)$n;
        return [new Str($text)];
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && $cell->type !== 'empty') {
                return false;
            }
        }
        return true;
    }

    private function sheetToCsv(XlsxSheet $sheet, XlsxLocale $locale): string
    {
        if (empty($sheet->cells)) {
            return '';
        }

        $minCol = PHP_INT_MAX; $maxCol = PHP_INT_MIN;
        $minRow = PHP_INT_MAX; $maxRow = PHP_INT_MIN;

        foreach ($sheet->cells as $cell) {
            $minCol = min($minCol, $cell->col); $maxCol = max($maxCol, $cell->col);
            $minRow = min($minRow, $cell->row); $maxRow = max($maxRow, $cell->row);
        }

        // Build full text grid
        $grid = [];
        for ($row = $minRow; $row <= $maxRow; $row++) {
            $rowData = [];
            for ($col = $minCol; $col <= $maxCol; $col++) {
                $cell = $sheet->cells["$col,$row"] ?? null;
                $rowData[] = $cell !== null ? $this->cellToText($cell, $locale) : '';
            }
            $grid[] = $rowData;
        }

        // Strip trailing empty rows
        while (!empty($grid) && $this->isEmptyTextRow(end($grid))) {
            array_pop($grid);
        }

        if (empty($grid)) {
            return '';
        }

        // Strip trailing empty columns (check across all rows)
        $colCount = count($grid[0]);
        while ($colCount > 0) {
            $allEmpty = true;
            foreach ($grid as $row) {
                if (($row[$colCount - 1] ?? '') !== '') {
                    $allEmpty = false;
                    break;
                }
            }
            if (!$allEmpty) {
                break;
            }
            $colCount--;
            $grid = array_map(fn(array $r) => array_slice($r, 0, $colCount), $grid);
        }

        if ($colCount === 0) {
            return '';
        }

        $rows = array_map(fn(array $r) => $this->csvRow($r, $locale), $grid);
        return implode("\n", $rows) . "\n";
    }

    private function isEmptyTextRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== '') {
                return false;
            }
        }
        return true;
    }

    private function cellToText(XlsxCell $cell, XlsxLocale $locale): string
    {
        if ($cell->type !== 'number' || $cell->number === null) {
            return $cell->text;
        }
        $text = fmod($cell->number, 1.0) === 0.0
            ? number_format($cell->number, 0, '.', '')
            : str_replace('.', $locale->decimalSep, (string)$cell->number);
        return $text;
    }

    private function chartToJson(XlsxChart $chart): string
    {
        $data = [
            'type'     => $chart->type,
            'title'    => $chart->title,
            'dataFile' => "{$chart->id}.csv",
            'options' => [
                'indexAxis' => $chart->orientation === 'horizontal' ? 'y' : 'x',
                'scales' => [
                    'x' => [
                        'title'   => ['display' => $chart->xAxisTitle !== '', 'text' => $chart->xAxisTitle],
                        'stacked' => $chart->stacked,
                    ],
                    'y' => [
                        'title'   => ['display' => $chart->yAxisTitle !== '', 'text' => $chart->yAxisTitle],
                        'stacked' => $chart->stacked,
                    ],
                ],
            ],
            'series' => array_map(fn(XlsxChartSeries $s) => ['label' => $s->label], $chart->series),
        ];
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private function chartToCsv(XlsxChart $chart, XlsxLocale $locale): string
    {
        if (empty($chart->series)) {
            return '';
        }

        $categories = $chart->series[0]->categories;
        $seriesLabels = array_map(fn(XlsxChartSeries $s) => $s->label, $chart->series);

        $rows = [];
        $rows[] = $this->csvRow(array_merge(['Category'], $seriesLabels), $locale);

        foreach ($categories as $i => $cat) {
            $row = [$cat];
            foreach ($chart->series as $series) {
                $val = $series->values[$i] ?? null;
                if ($val !== null) {
                    $row[] = fmod($val, 1.0) === 0.0
                        ? number_format($val, 0, '.', '')
                        : str_replace('.', $locale->decimalSep, (string)$val);
                } else {
                    $row[] = '';
                }
            }
            $rows[] = $this->csvRow($row, $locale);
        }

        return implode("\n", $rows) . "\n";
    }

    private function csvRow(array $fields, XlsxLocale $locale): string
    {
        $delim = $locale->columnDelim;
        $quote = $locale->quoteChar;
        return implode($delim, array_map(function (string $f) use ($delim, $quote): string {
            if (str_contains($f, $delim) || str_contains($f, $quote) || str_contains($f, "\n")) {
                return $quote . str_replace($quote, $quote . $quote, $f) . $quote;
            }
            return $f;
        }, $fields));
    }
}
