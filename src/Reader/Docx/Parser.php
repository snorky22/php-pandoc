<?php

namespace Pandoc\Reader\Docx;

use ZipArchive;
use DOMDocument;
use DOMXPath;

class Parser
{
    public array $media = [];
    private array $namespaces = [
        'w' => 'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
        'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'wp' => 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing',
        'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main',
        'pic' => 'http://schemas.openxmlformats.org/drawingml/2006/picture',
        'v' => 'urn:schemas-microsoft-com:vml',
    ];

    private array $currentRels = [];

    private function setupXPath(string $xmlContent): DOMXPath
    {
        $dom = new DOMDocument();
        // Silence errors and load XML
        if (!@$dom->loadXML($xmlContent)) {
             throw new \Exception("Failed to load XML content.");
        }
        $xpath = new DOMXPath($dom);
        foreach ($this->namespaces as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }
        return $xpath;
    }

    public function parse(string $filePath): Document
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \Exception("Could not open DOCX file: $filePath");
        }

        $xmlContent = $zip->getFromName('word/document.xml');
        if (!$xmlContent) {
            $zip->close();
            throw new \Exception("Invalid DOCX: word/document.xml not found.");
        }

        try {
            $xpath = $this->setupXPath($xmlContent);
        } catch (\Exception $e) {
            $zip->close();
            throw $e;
        }

        $numbering = $this->parseNumbering($zip);
        $styles = $this->parseStyles($zip);

        // Find all parts that might have relationships (document, headers, footers)
        $partsWithRels = ['word/document.xml'];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('/word\/(header|footer)\d+\.xml$/', $name)) {
                $partsWithRels[] = $name;
            }
        }

        $this->media = [];
        $relationships = [];
        foreach ($partsWithRels as $partPath) {
            $rels = $this->parseRelationships($zip, $partPath);
            $relationships[$partPath] = $rels;
            $this->media = array_merge($this->media, $this->extractMedia($zip, $rels));
        }

        // Low-level IR extraction
        $this->currentRels = $relationships['word/document.xml'] ?? [];
        $body = $this->parseBody($xpath);

        $headers = [];
        $footers = [];
        foreach ($partsWithRels as $partPath) {
            if (str_contains($partPath, 'header')) {
                $this->currentRels = $relationships[$partPath] ?? [];
                $headers[] = $this->parsePart($zip, $partPath);
            } elseif (str_contains($partPath, 'footer')) {
                $this->currentRels = $relationships[$partPath] ?? [];
                $footers[] = $this->parsePart($zip, $partPath);
            }
        }

        $zip->close();

        return new Document($body, $numbering, $styles, $relationships, $this->media, $headers, $footers);
    }

    private function parsePart(ZipArchive $zip, string $partPath): Body
    {
        $xmlContent = $zip->getFromName($partPath);
        if (!$xmlContent) {
            return new Body([]);
        }
        try {
            $xpath = $this->setupXPath($xmlContent);
        } catch (\Exception $e) {
            return new Body([]);
        }
        $root = str_contains($partPath, 'header') ? '/w:hdr/*' : '/w:ftr/*';
        return $this->parseBody($xpath, $root);
    }

    private function parseRelationships(ZipArchive $zip, string $partPath = 'word/document.xml'): array
    {
        $dir = dirname($partPath);
        $base = basename($partPath);
        $relPath = $dir . '/_rels/' . $base . '.rels';

        $xmlContent = $zip->getFromName($relPath);
        if (!$xmlContent) {
            return [];
        }

        $dom = new DOMDocument();
        if (!@$dom->loadXML($xmlContent)) {
            return [];
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');

        $rels = [];
        foreach ($xpath->query('//rel:Relationship') as $relNode) {
            $id = $relNode->getAttribute('Id');
            $target = $relNode->getAttribute('Target');
            $type = $relNode->getAttribute('Type');
            $rels[$id] = ['target' => $target, 'type' => $type];
        }
        return $rels;
    }

    private function extractMedia(ZipArchive $zip, array $relationships): array
    {
        $media = [];
        $mimeMap = [
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
            'tiff' => 'image/tiff',
        ];

        foreach ($relationships as $id => $rel) {
            if (str_contains($rel['type'], '/image')) {
                $target = $rel['target'];
                // Targets are usually relative to 'word/'
                $zipPath = 'word/' . $target;
                if (!str_starts_with($target, 'media/')) {
                    // Sometimes they are absolute within the zip, or relative differently.
                    // But in most docx they are in word/media/
                    // Let's try direct if word/ doesn't work
                    if (!$zip->locateName($zipPath)) {
                        $zipPath = $target;
                    }
                }

                $content = $zip->getFromName($zipPath);
                if ($content !== false) {
                    $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
                    $mime = $mimeMap[$ext] ?? 'image/unknown';
                    // We use the ID as key, but it will be overwritten if same ID exists in different parts.
                    // However, we want the media to be available by its ID in the context of the part.
                    // To handle collisions, we use a unique key in the global media array.
                    $uniqueId = $id . '_' . md5($target);
                    $media[$uniqueId] = [
                        'filename' => basename($target),
                        'contents' => $content,
                        'original_target' => $target,
                        'mime' => $mime
                    ];
                }
            }
        }
        return $media;
    }

    private function parseStyles(ZipArchive $zip): array
    {
        $xmlContent = $zip->getFromName('word/styles.xml');
        if (!$xmlContent) {
            return [];
        }

        try {
            $xpath = $this->setupXPath($xmlContent);
        } catch (\Exception $e) {
            return [];
        }

        $styles = [];
        foreach ($xpath->query('//w:style') as $styleNode) {
            $id = $xpath->evaluate('string(@w:styleId)', $styleNode);
            $basedOn = $xpath->evaluate('string(w:basedOn/@w:val)', $styleNode);
            $name = $xpath->evaluate('string(w:name/@w:val)', $styleNode);
            $styles[$id] = ['name' => $name, 'basedOn' => $basedOn];
        }
        return $styles;
    }

    private function parseNumbering(ZipArchive $zip): array
    {
        $xmlContent = $zip->getFromName('word/numbering.xml');
        if (!$xmlContent) {
            return [];
        }

        try {
            $xpath = $this->setupXPath($xmlContent);
        } catch (\Exception $e) {
            return [];
        }

        $numbering = [];
        // This is a simplified map of numId to abstractNumId and levels
        // In reality, it's more complex (abstractNum -> levels -> formats)
        return $numbering;
    }

    private function parseBody(DOMXPath $xpath, string $query = '/w:document/w:body/*'): Body
    {
        $nodes = $xpath->query($query);
        $parts = [];
        if ($nodes) {
            foreach ($nodes as $node) {
                if ($node->nodeName === 'w:p') {
                    $parts[] = $this->parseParagraph($node, $xpath);
                } elseif ($node->nodeName === 'w:tbl') {
                    $parts[] = $this->parseTable($node, $xpath);
                }
            }
        }
        return new Body($parts);
    }

    private function parseTable(\DOMNode $node, DOMXPath $xpath): Table
    {
        $rows = [];
        foreach ($xpath->query('w:tr', $node) as $trNode) {
            $cells = [];
            foreach ($xpath->query('w:tc', $trNode) as $tcNode) {
                $cellBody = $this->parseBodyFragment($tcNode, $xpath);
                $cells[] = new Cell($cellBody);
            }
            $rows[] = new Row($cells);
        }
        return new Table($rows);
    }

    private function parseBodyFragment(\DOMNode $parentNode, DOMXPath $xpath): Body
    {
        $nodes = $xpath->query('w:p | w:tbl', $parentNode);
        $parts = [];
        foreach ($nodes as $node) {
            if ($node->nodeName === 'w:p') {
                $parts[] = $this->parseParagraph($node, $xpath);
            } elseif ($node->nodeName === 'w:tbl') {
                $parts[] = $this->parseTable($node, $xpath);
            }
        }
        return new Body($parts);
    }

    private function parseParagraph(\DOMNode $node, DOMXPath $xpath): Paragraph
    {
        $style = $xpath->evaluate('string(w:pPr/w:pStyle/@w:val)', $node);
        $numId = (int)$xpath->evaluate('string(w:pPr/w:numPr/w:numId/@w:val)', $node);
        $ilvl = (int)$xpath->evaluate('string(w:pPr/w:numPr/w:ilvl/@w:val)', $node);

        $runs = [];
        foreach ($xpath->query('w:r', $node) as $runNode) {
            $runs[] = $this->parseRun($runNode, $xpath);
        }
        return new Paragraph($style, $runs, $numId, $ilvl);
    }

    private function parseRun(\DOMNode $node, DOMXPath $xpath): Run
    {
        $text = $xpath->evaluate('string(w:t)', $node);
        $isBold = $xpath->query('w:rPr/w:b', $node)->length > 0;
        $isItalic = $xpath->query('w:rPr/w:i', $node)->length > 0;
        $isUnderline = $xpath->query('w:rPr/w:u', $node)->length > 0;
        $isStrikeout = $xpath->query('w:rPr/w:strike | w:rPr/w:dstrike', $node)->length > 0;
        $vertAlign = $xpath->evaluate('string(w:rPr/w:vertAlign/@w:val)', $node);
        $color = $xpath->evaluate('string(w:rPr/w:color/@w:val)', $node);

        $backgroundColor = $xpath->evaluate('string(w:rPr/w:shd/@w:fill)', $node);
        if (!$backgroundColor || $backgroundColor === 'auto') {
            $backgroundColor = $xpath->evaluate('string(w:rPr/w:highlight/@w:val)', $node);
        }

        $drawingId = $xpath->evaluate('string(w:drawing//a:blip/@r:embed)', $node);
        if (!$drawingId) {
            $drawingId = $xpath->evaluate('string(.//v:imagedata/@r:id)', $node);
        }

        if ($drawingId && isset($this->currentRels[$drawingId])) {
            $target = $this->currentRels[$drawingId]['target'];
            foreach ($this->media as $id => $m) {
                if ($m['original_target'] === $target) {
                    $drawingId = $id;
                    break;
                }
            }
        }

        return new Run($text, $isBold, $isItalic, $isUnderline, $isStrikeout, $vertAlign, $color, $backgroundColor, $drawingId);
    }
}

readonly class Document { public function __construct(public Body $body, public array $numbering = [], public array $styles = [], public array $relationships = [], public array $media = [], public array $headers = [], public array $footers = []) {} }
readonly class Body { public function __construct(public array $parts) {} }
readonly class Paragraph { public function __construct(public string $style, public array $runs, public int $numId = 0, public int $ilvl = 0) {} }
readonly class Table { public function __construct(public array $rows) {} }
readonly class Row { public function __construct(public array $cells) {} }
readonly class Cell { public function __construct(public Body $body) {} }
readonly class Run {
    public function __construct(
        public string $text,
        public bool $isBold,
        public bool $isItalic,
        public bool $isUnderline,
        public bool $isStrikeout,
        public string $vertAlign = '',
        public string $color = '',
        public string $backgroundColor = '',
        public string $drawingId = ''
    ) {}
}
