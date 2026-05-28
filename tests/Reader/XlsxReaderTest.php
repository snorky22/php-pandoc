<?php

namespace Pandoc\Tests\Reader;

use PHPUnit\Framework\TestCase;
use Pandoc\Reader\XlsxReader;
use Pandoc\AST\Header;
use Pandoc\AST\Str;
use Pandoc\AST\Strong;
use Pandoc\AST\Plain;
use Pandoc\AST\Space;

class XlsxReaderTest extends TestCase
{
    private XlsxReader $reader;

    protected function setUp(): void
    {
        $this->reader = new XlsxReader();
    }

    private function getTestDataPath(string $filename): string
    {
        return __DIR__ . '/../../../test/xlsx-reader/' . $filename;
    }

    private function getInlinesText(array $inlines): string
    {
        $text = '';
        foreach ($inlines as $inline) {
            if ($inline instanceof Str) {
                $text .= $inline->text;
            } elseif ($inline instanceof Space) {
                $text .= ' ';
            } elseif (property_exists($inline, 'content') && is_array($inline->content)) {
                $text .= $this->getInlinesText($inline->content);
            }
        }
        return $text;
    }

    public function testBasicSheetHeaders(): void
    {
        $path = $this->getTestDataPath('basic.xlsx');
        if (!file_exists($path)) {
            $this->markTestSkipped("Test file basic.xlsx not found at $path");
        }

        $doc = $this->reader->read($path);

        $headers = array_values(array_filter($doc->blocks, fn($b) => $b instanceof Header));
        $this->assertCount(2, $headers, "Two sheets should produce two headers");

        $this->assertEquals(2, $headers[0]->level);
        $this->assertEquals('Main', $this->getInlinesText($headers[0]->content));

        $this->assertEquals(2, $headers[1]->level);
        $this->assertEquals('Secondary', $this->getInlinesText($headers[1]->content));
    }

    public function testBasicTableStructure(): void
    {
        $path = $this->getTestDataPath('basic.xlsx');
        if (!file_exists($path)) {
            $this->markTestSkipped("Test file basic.xlsx not found at $path");
        }

        $doc = $this->reader->read($path);

        $tables = array_values(array_filter($doc->blocks, fn($b) => $b instanceof \Pandoc\AST\Table));
        $this->assertCount(2, $tables, "Two sheets should produce two tables");

        $first = $tables[0];
        $this->assertNotEmpty($first->head->rows, "First table should have a header row");

        $headerCells = $first->head->rows[0]->cells;
        $this->assertCount(3, $headerCells, "Header row should have 3 columns");
    }

    public function testBoldHeaderCells(): void
    {
        $path = $this->getTestDataPath('basic.xlsx');
        if (!file_exists($path)) {
            $this->markTestSkipped("Test file basic.xlsx not found at $path");
        }

        $doc = $this->reader->read($path);

        $tables = array_values(array_filter($doc->blocks, fn($b) => $b instanceof \Pandoc\AST\Table));
        $first = $tables[0];
        $headerCells = $first->head->rows[0]->cells;

        // Header cells in basic.xlsx are bold
        $firstCellInlines = $this->getCellInlines($headerCells[0]);
        $this->assertInstanceOf(Strong::class, $firstCellInlines[0], "Header cells should be bold");
        $this->assertEquals('Person', $this->getInlinesText($firstCellInlines));
    }

    public function testBodyRowText(): void
    {
        $path = $this->getTestDataPath('basic.xlsx');
        if (!file_exists($path)) {
            $this->markTestSkipped("Test file basic.xlsx not found at $path");
        }

        $doc = $this->reader->read($path);

        $tables = array_values(array_filter($doc->blocks, fn($b) => $b instanceof \Pandoc\AST\Table));
        $first = $tables[0];
        $bodyRows = $first->bodies[0]->rows;

        $this->assertNotEmpty($bodyRows);

        // First body row: "Anton Antich", "23.0", "Switzerland"
        $row0Cells = $bodyRows[0]->cells;
        $this->assertEquals('Anton Antich', $this->getInlinesText($this->getCellInlines($row0Cells[0])));
        $this->assertEquals('23.0', $this->getInlinesText($this->getCellInlines($row0Cells[1])));
        $this->assertEquals('Switzerland', $this->getInlinesText($this->getCellInlines($row0Cells[2])));
    }

    public function testNumberFormatting(): void
    {
        $path = $this->getTestDataPath('basic.xlsx');
        if (!file_exists($path)) {
            $this->markTestSkipped("Test file basic.xlsx not found at $path");
        }

        $doc = $this->reader->read($path);

        $tables = array_values(array_filter($doc->blocks, fn($b) => $b instanceof \Pandoc\AST\Table));
        $first = $tables[0];

        // Age column (index 1) should show "23.0" not "23"
        $ageText = $this->getInlinesText($this->getCellInlines($first->bodies[0]->rows[0]->cells[1]));
        $this->assertEquals('23.0', $ageText, "Integer floats should be formatted with .0 suffix");
    }

    public function testTrailingEmptyRowsDropped(): void
    {
        $path = $this->getTestDataPath('basic.xlsx');
        if (!file_exists($path)) {
            $this->markTestSkipped("Test file basic.xlsx not found at $path");
        }

        $doc = $this->reader->read($path);

        $tables = array_values(array_filter($doc->blocks, fn($b) => $b instanceof \Pandoc\AST\Table));
        $first = $tables[0];
        $bodyRows = $first->bodies[0]->rows;

        // The last body row should not be all-empty (trailing empty rows are stripped)
        $lastRow = end($bodyRows);
        $hasContent = false;
        foreach ($lastRow->cells as $cell) {
            if (!empty($this->getCellInlines($cell))) {
                $hasContent = true;
                break;
            }
        }
        $this->assertTrue($hasContent, "Last body row should not be all-empty after trailing row stripping");
    }

    private function getCellInlines(\Pandoc\AST\Cell $cell): array
    {
        if (empty($cell->content)) {
            return [];
        }
        $first = $cell->content[0];
        return $first instanceof Plain ? $first->content : [];
    }
}
