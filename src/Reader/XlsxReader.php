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
use Pandoc\Reader\Xlsx\Parser;
use Pandoc\Reader\Xlsx\XlsxSheet;
use Pandoc\Reader\Xlsx\XlsxCell;

class XlsxReader implements ReaderInterface
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = new Parser();
    }

    public function read(string $filePath): Pandoc
    {
        $doc = $this->parser->parse($filePath);
        $blocks = [];

        foreach ($doc->sheets as $sheet) {
            $blocks[] = new Header(2, new Attr("sheet-{$sheet->id}", [], []), [new Str($sheet->name)]);

            $table = $this->sheetToTable($sheet);
            if ($table !== null) {
                $blocks[] = $table;
            }
        }

        return new Pandoc(new Meta(), $blocks);
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
}
