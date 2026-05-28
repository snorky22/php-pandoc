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

        [$charts, $images] = $this->parseSheetDrawings($zip, $path);
        return new XlsxSheet($id, $name, $cells, $charts, $images);
    }

    // -----------------------------------------------------------------------
    // Drawings: charts and embedded images
    // -----------------------------------------------------------------------

    private function parseSheetDrawings(ZipArchive $zip, string $sheetPath): array
    {
        $charts = [];
        $images = [];

        $drawingTargets = $this->parseRelationshipsByType($zip, $sheetPath, '/drawing');
        foreach ($drawingTargets as $target) {
            $drawingPath = $this->normalizePath(dirname($sheetPath) . '/' . $target);

            // Charts inside this drawing
            $chartTargets = $this->parseRelationshipsByType($zip, $drawingPath, '/chart');
            foreach ($chartTargets as $cTarget) {
                $chartPath = $this->normalizePath(dirname($drawingPath) . '/' . $cTarget);
                $chart = $this->parseChart($zip, $chartPath);
                if ($chart !== null) {
                    $charts[] = $chart;
                }
            }

            // Embedded images inside this drawing
            $imageTargets = $this->parseRelationshipsByType($zip, $drawingPath, '/image');
            foreach ($imageTargets as $iTarget) {
                $imgPath = $this->normalizePath(dirname($drawingPath) . '/' . $iTarget);
                $data = $zip->getFromName($imgPath);
                if ($data === false) {
                    continue;
                }
                $ext  = strtolower(pathinfo($imgPath, PATHINFO_EXTENSION));
                $mime = match ($ext) {
                    'png'        => 'image/png',
                    'jpg', 'jpeg' => 'image/jpeg',
                    'gif'        => 'image/gif',
                    'svg'        => 'image/svg+xml',
                    default      => 'image/png',
                };
                $images[] = new XlsxImage(basename($imgPath), $mime, $data);
            }
        }

        return [$charts, $images];
    }

    private function parseRelationshipsByType(ZipArchive $zip, string $partPath, string $typeFragment): array
    {
        $dir = dirname($partPath);
        $base = basename($partPath);
        $relsPath = $dir . '/_rels/' . $base . '.rels';

        $xpath = $this->loadXml($zip, $relsPath);
        if ($xpath === null) {
            return [];
        }

        $targets = [];
        foreach ($xpath->query('//*[local-name()="Relationship"]') as $node) {
            if (str_contains($node->getAttribute('Type'), $typeFragment)) {
                $targets[] = $node->getAttribute('Target');
            }
        }
        return $targets;
    }

    private function parseChart(ZipArchive $zip, string $chartPath): ?XlsxChart
    {
        $xpath = $this->loadXml($zip, $chartPath);
        if ($xpath === null) {
            return null;
        }

        $title = $this->extractChartText($xpath,
            '//*[local-name()="chartSpace"]/*[local-name()="chart"]/*[local-name()="title"]');

        // Detect chart type from the plot area child element
        $ooXmlTypes = [
            'barChart'      => 'bar',
            'lineChart'     => 'line',
            'pieChart'      => 'pie',
            'doughnutChart' => 'doughnut',
            'scatterChart'  => 'scatter',
            'areaChart'     => 'area',
            'radarChart'    => 'radar',
            'bubbleChart'   => 'bubble',
        ];
        $chartType   = 'bar';
        $orientation = 'vertical';
        $stacked     = false;

        foreach ($ooXmlTypes as $ooxml => $jsType) {
            $typeNodes = $xpath->query('//*[local-name()="' . $ooxml . '"]');
            if ($typeNodes->length === 0) {
                continue;
            }
            $chartType = $jsType;
            if ($ooxml === 'barChart') {
                $barDir = $xpath->query('//*[local-name()="barDir"]')->item(0);
                if ($barDir && $barDir->getAttribute('val') === 'bar') {
                    $orientation = 'horizontal';
                }
            }
            $grouping = $xpath->query('//*[local-name()="grouping"]')->item(0);
            if ($grouping) {
                $stacked = in_array($grouping->getAttribute('val'), ['stacked', 'percentStacked'], true);
            }
            break;
        }

        $xAxisTitle = $this->extractChartText($xpath, '//*[local-name()="catAx"]/*[local-name()="title"]');
        $yAxisTitle = $this->extractChartText($xpath, '//*[local-name()="valAx"]/*[local-name()="title"]');

        $series = [];
        foreach ($xpath->query('//*[local-name()="ser"]') as $serNode) {
            $label = $this->extractSeriesText($xpath, $serNode, 'tx');

            $ptCount = (int)($xpath->query(
                '*[local-name()="cat"]//*[local-name()="ptCount"]', $serNode
            )->item(0)?->getAttribute('val') ?? 0);

            $catMap = $this->parseIndexedPts($xpath, $serNode, 'cat');
            $valMap = $this->parseIndexedPts($xpath, $serNode, 'val');

            // Also handle scatter xVal/yVal
            if (empty($valMap)) {
                $valMap = $this->parseIndexedPts($xpath, $serNode, 'yVal');
                if (empty($catMap)) {
                    $catMap = $this->parseIndexedPts($xpath, $serNode, 'xVal');
                }
            }

            $count = max($ptCount, count($catMap), count($valMap));
            $cats = [];
            $vals = [];
            for ($i = 0; $i < $count; $i++) {
                $cats[] = $catMap[$i] ?? '';
                $vals[] = isset($valMap[$i]) ? (float)$valMap[$i] : null;
            }

            if ($count > 0) {
                $series[] = new XlsxChartSeries($label, $cats, $vals);
            }
        }

        return new XlsxChart(
            basename($chartPath, '.xml'),
            $chartType,
            $title,
            $orientation,
            $stacked,
            $xAxisTitle,
            $yAxisTitle,
            $series
        );
    }

    private function extractChartText(DOMXPath $xpath, string $contextQuery): string
    {
        $nodes = $xpath->query($contextQuery);
        if ($nodes->length === 0) {
            return '';
        }
        $ctx = $nodes->item(0);
        // Rich text <a:t> nodes
        $parts = [];
        foreach ($xpath->query('.//*[local-name()="t"]', $ctx) as $t) {
            $parts[] = $t->textContent;
        }
        if (!empty($parts)) {
            return trim(implode('', $parts));
        }
        // Cached string value
        foreach ($xpath->query('.//*[local-name()="v"]', $ctx) as $v) {
            return trim($v->textContent);
        }
        return '';
    }

    private function extractSeriesText(DOMXPath $xpath, \DOMNode $serNode, string $childName): string
    {
        $nodes = $xpath->query('*[local-name()="' . $childName . '"]', $serNode);
        if ($nodes->length === 0) {
            return '';
        }
        $tNodes = $xpath->query('.//*[local-name()="t"]', $nodes->item(0));
        if ($tNodes->length > 0) {
            return trim($tNodes->item(0)->textContent);
        }
        $vNodes = $xpath->query('.//*[local-name()="v"]', $nodes->item(0));
        if ($vNodes->length > 0) {
            return trim($vNodes->item(0)->textContent);
        }
        return '';
    }

    private function parseIndexedPts(DOMXPath $xpath, \DOMNode $serNode, string $containerName): array
    {
        $values = [];
        foreach ($xpath->query(
            '*[local-name()="' . $containerName . '"]//*[local-name()="pt"]',
            $serNode
        ) as $pt) {
            $idx = (int)$pt->getAttribute('idx');
            $vNodes = $xpath->query('*[local-name()="v"]', $pt);
            if ($vNodes->length > 0) {
                $values[$idx] = $vNodes->item(0)->textContent;
            }
        }
        return $values;
    }

    private function normalizePath(string $path): string
    {
        $parts = explode('/', $path);
        $result = [];
        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($result);
            } elseif ($part !== '' && $part !== '.') {
                $result[] = $part;
            }
        }
        return implode('/', $result);
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
    /**
     * @param array<string, XlsxCell> $cells   keyed by "col,row"
     * @param XlsxChart[]             $charts
     * @param XlsxImage[]             $images
     */
    public function __construct(
        public int $id,
        public string $name,
        public array $cells,
        public array $charts = [],
        public array $images = []
    ) {}
}

readonly class XlsxChart
{
    /** @param XlsxChartSeries[] $series */
    public function __construct(
        public string $id,
        public string $type,
        public string $title,
        public string $orientation,
        public bool   $stacked,
        public string $xAxisTitle,
        public string $yAxisTitle,
        public array  $series
    ) {}
}

readonly class XlsxChartSeries
{
    /** @param string[] $categories  @param (float|null)[] $values */
    public function __construct(
        public string $label,
        public array  $categories,
        public array  $values
    ) {}
}

readonly class XlsxImage
{
    public function __construct(
        public string $filename,
        public string $mime,
        public string $data
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
