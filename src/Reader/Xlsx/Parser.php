<?php

namespace Pandoc\Reader\Xlsx;

use ZipArchive;
use DOMDocument;
use DOMXPath;

class Parser
{
    private const R_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public function parse(string $filePath): XlsxDocument
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \Exception("Could not open XLSX file: $filePath");
        }

        try {
            $sharedStrings = $this->parseSharedStrings($zip);
            [$fonts, $cellXfs] = $this->parseStyles($zip);
            $sheetRefs = $this->parseWorkbook($zip);
            $workbookRels = $this->parseRelationships($zip, 'xl/workbook.xml');

            $sheets = [];
            foreach ($sheetRefs as [$id, $name, $relId]) {
                $target = $workbookRels[$relId] ?? null;
                if ($target === null) {
                    continue;
                }
                $sheetPath = 'xl/' . $target;
                $sheets[] = $this->parseSheet($zip, $id, $name, $sheetPath, $sharedStrings, $fonts, $cellXfs);
            }
        } finally {
            $zip->close();
        }

        return new XlsxDocument($sheets);
    }

    private function loadXml(ZipArchive $zip, string $path): ?DOMXPath
    {
        $content = $zip->getFromName($path);
        if ($content === false) {
            return null;
        }
        $dom = new DOMDocument();
        if (!@$dom->loadXML($content)) {
            return null;
        }
        return new DOMXPath($dom);
    }

    private function parseWorkbook(ZipArchive $zip): array
    {
        $xpath = $this->loadXml($zip, 'xl/workbook.xml');
        if ($xpath === null) {
            throw new \Exception("Invalid XLSX: xl/workbook.xml not found or invalid.");
        }

        $sheets = [];
        $idx = 1;
        foreach ($xpath->query('//*[local-name()="sheet"]') as $node) {
            $name = $node->getAttribute('name') ?: "Sheet$idx";
            $relId = $node->getAttributeNS(self::R_NS, 'id');
            if (!$relId) {
                $relId = $node->getAttribute('r:id');
            }
            $sheets[] = [$idx, $name, $relId];
            $idx++;
        }

        return $sheets;
    }

    private function parseRelationships(ZipArchive $zip, string $partPath): array
    {
        $dir = dirname($partPath);
        $base = basename($partPath);
        $relsPath = $dir . '/_rels/' . $base . '.rels';

        $xpath = $this->loadXml($zip, $relsPath);
        if ($xpath === null) {
            return [];
        }

        $rels = [];
        foreach ($xpath->query('//*[local-name()="Relationship"]') as $node) {
            $id = $node->getAttribute('Id');
            $target = $node->getAttribute('Target');
            if ($id && $target) {
                $rels[$id] = $target;
            }
        }
        return $rels;
    }

    private function parseSharedStrings(ZipArchive $zip): array
    {
        $xpath = $this->loadXml($zip, 'xl/sharedStrings.xml');
        if ($xpath === null) {
            return [];
        }

        $strings = [];
        foreach ($xpath->query('//*[local-name()="si"]') as $siNode) {
            // Simple <si><t>text</t></si>
            $tNodes = $xpath->query('*[local-name()="t"]', $siNode);
            if ($tNodes->length > 0) {
                $strings[] = $tNodes->item(0)->textContent;
            } else {
                // Rich text: concatenate all <t> from <r> children
                $allT = $xpath->query('.//*[local-name()="t"]', $siNode);
                $text = '';
                foreach ($allT as $t) {
                    $text .= $t->textContent;
                }
                $strings[] = $text;
            }
        }
        return $strings;
    }

    private function parseStyles(ZipArchive $zip): array
    {
        $xpath = $this->loadXml($zip, 'xl/styles.xml');
        if ($xpath === null) {
            return [[], []];
        }

        $fonts = [];
        foreach ($xpath->query('//*[local-name()="fonts"]/*[local-name()="font"]') as $fontNode) {
            $bold = $xpath->query('*[local-name()="b"]', $fontNode)->length > 0;
            $italic = $xpath->query('*[local-name()="i"]', $fontNode)->length > 0;
            $underline = $xpath->query('*[local-name()="u"]', $fontNode)->length > 0;
            $fonts[] = ['bold' => $bold, 'italic' => $italic, 'underline' => $underline];
        }

        // cellXfs maps xf index → fontId
        $cellXfs = [];
        foreach ($xpath->query('//*[local-name()="cellXfs"]/*[local-name()="xf"]') as $xfNode) {
            $cellXfs[] = (int)$xfNode->getAttribute('fontId');
        }

        return [$fonts, $cellXfs];
    }

    private function parseSheet(
        ZipArchive $zip,
        int $id,
        string $name,
        string $path,
        array $sharedStrings,
        array $fonts,
        array $cellXfs
    ): XlsxSheet {
        $xpath = $this->loadXml($zip, $path);
        if ($xpath === null) {
            return new XlsxSheet($id, $name, []);
        }

        $cells = [];
        $query = '//*[local-name()="sheetData"]/*[local-name()="row"]/*[local-name()="c"]';
        foreach ($xpath->query($query) as $cNode) {
            $refStr = $cNode->getAttribute('r');
            if (!$refStr) {
                continue;
            }

            [$col, $row] = $this->parseCellRef($refStr);
            if ($col === null) {
                continue;
            }

            $cellType = $cNode->getAttribute('t');
            $styleAttr = $cNode->getAttribute('s');

            $vNodes = $xpath->query('*[local-name()="v"]', $cNode);
            $vText = $vNodes->length > 0 ? $vNodes->item(0)->textContent : '';

            $type = 'empty';
            $text = '';
            $number = null;

            if ($cellType === 's') {
                $idx = (int)$vText;
                if (isset($sharedStrings[$idx])) {
                    $type = 'text';
                    $text = $sharedStrings[$idx];
                }
            } elseif ($vText !== '') {
                if (is_numeric($vText)) {
                    $type = 'number';
                    $number = (float)$vText;
                } else {
                    $type = 'text';
                    $text = $vText;
                }
            }

            $bold = false;
            $italic = false;
            if ($styleAttr !== '' && isset($cellXfs[(int)$styleAttr])) {
                $fontId = $cellXfs[(int)$styleAttr];
                if (isset($fonts[$fontId])) {
                    $bold = $fonts[$fontId]['bold'];
                    $italic = $fonts[$fontId]['italic'];
                }
            }

            $cells["$col,$row"] = new XlsxCell($col, $row, $type, $text, $number, $bold, $italic);
        }

        return new XlsxSheet($id, $name, $cells);
    }

    private function parseCellRef(string $ref): array
    {
        if (!preg_match('/^([A-Z]+)(\d+)$/i', $ref, $m)) {
            return [null, null];
        }
        $colStr = strtoupper($m[1]);
        $row = (int)$m[2];

        $col = 0;
        for ($i = 0, $len = strlen($colStr); $i < $len; $i++) {
            $col = $col * 26 + (ord($colStr[$i]) - 64);
        }

        return [$col, $row];
    }
}

readonly class XlsxDocument
{
    /** @param XlsxSheet[] $sheets */
    public function __construct(public array $sheets) {}
}

readonly class XlsxSheet
{
    /** @param array<string, XlsxCell> $cells keyed by "col,row" */
    public function __construct(
        public int $id,
        public string $name,
        public array $cells
    ) {}
}

readonly class XlsxCell
{
    public function __construct(
        public int $col,
        public int $row,
        public string $type,
        public string $text,
        public ?float $number,
        public bool $bold,
        public bool $italic
    ) {}
}
