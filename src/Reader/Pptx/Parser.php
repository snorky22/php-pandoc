<?php

namespace Pandoc\Reader\Pptx;

use ZipArchive;
use DOMDocument;
use DOMXPath;
use DOMNode;

class Parser
{
    private const R_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public function parse(string $filePath): PptxDocument
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \Exception("Could not open PPTX file: $filePath");
        }

        try {
            $slideRelIds = $this->parsePresentation($zip);
            $presRels = $this->parseRelationships($zip, 'ppt/presentation.xml');

            $slides = [];
            $slideIndex = 0;
            foreach ($slideRelIds as $relId) {
                $slideIndex++;
                $target = $presRels[$relId] ?? null;
                if ($target === null) {
                    continue;
                }
                // target is relative to ppt/, e.g. "slides/slide1.xml"
                $slidePath = 'ppt/' . $target;
                $slideRels = $this->parseRelationships($zip, $slidePath);
                $slideXpath = $this->loadXml($zip, $slidePath);
                if ($slideXpath === null) {
                    continue;
                }
                $shapes = $this->parseSlide($slideXpath, $slideRels, $zip, $slidePath);
                $slides[] = new PptxSlide($slideIndex, $shapes);
            }

            $mediaFiles = $this->collectMedia($zip);
        } finally {
            $zip->close();
        }

        return new PptxDocument($slides, $mediaFiles);
    }

    private function collectMedia(ZipArchive $zip): array
    {
        $media = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!str_starts_with($name, 'ppt/media/')) {
                continue;
            }
            $data = $zip->getFromIndex($i);
            if ($data === false) {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'png'        => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'gif'        => 'image/gif',
                'svg'        => 'image/svg+xml',
                default      => 'application/octet-stream',
            };
            $media[] = new PptxMediaFile(basename($name), $mime, $data);
        }
        return $media;
    }

    private function parsePresentation(ZipArchive $zip): array
    {
        $xpath = $this->loadXml($zip, 'ppt/presentation.xml');
        if ($xpath === null) {
            throw new \Exception("Invalid PPTX: ppt/presentation.xml not found.");
        }

        $relIds = [];
        foreach ($xpath->query('//*[local-name()="sldIdLst"]/*[local-name()="sldId"]') as $node) {
            $relId = $node->getAttributeNS(self::R_NS, 'id');
            if (!$relId) {
                $relId = $node->getAttribute('r:id');
            }
            if ($relId) {
                $relIds[] = $relId;
            }
        }
        return $relIds;
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

    private function parseSlide(DOMXPath $xpath, array $rels, ZipArchive $zip, string $slidePath): array
    {
        $shapes = [];
        $slideDir = dirname($slidePath);

        foreach ($xpath->query('//*[local-name()="spTree"]/*[local-name()="sp"]') as $spNode) {
            $shape = $this->parseTextShape($xpath, $spNode);
            if ($shape !== null) {
                $shapes[] = $shape;
            }
        }

        foreach ($xpath->query('//*[local-name()="spTree"]/*[local-name()="pic"]') as $picNode) {
            $shape = $this->parsePicture($xpath, $picNode, $rels, $zip, $slideDir);
            if ($shape !== null) {
                $shapes[] = $shape;
            }
        }

        foreach ($xpath->query('//*[local-name()="spTree"]/*[local-name()="graphicFrame"]') as $gfNode) {
            $shape = $this->parseGraphicFrame($xpath, $gfNode);
            if ($shape !== null) {
                $shapes[] = $shape;
            }
        }

        return $shapes;
    }

    private function parseTextShape(DOMXPath $xpath, DOMNode $spNode): ?PptxTextShape
    {
        $phType = '';
        $phNodes = $xpath->query(
            '*[local-name()="nvSpPr"]/*[local-name()="nvPr"]/*[local-name()="ph"]',
            $spNode
        );
        if ($phNodes->length > 0) {
            $phType = $phNodes->item(0)->getAttribute('type');
        }

        $txBody = $xpath->query('*[local-name()="txBody"]', $spNode)->item(0);
        if ($txBody === null) {
            return null;
        }

        $paragraphs = [];
        foreach ($xpath->query('*[local-name()="p"]', $txBody) as $pNode) {
            $para = $this->parseParagraph($xpath, $pNode);
            if ($para !== null) {
                $paragraphs[] = $para;
            }
        }

        if (empty($paragraphs)) {
            return null;
        }

        return new PptxTextShape($phType, $paragraphs);
    }

    private function parseParagraph(DOMXPath $xpath, DOMNode $pNode): ?PptxParagraph
    {
        $level = 0;
        $bulletType = 'none';

        $pPrNodes = $xpath->query('*[local-name()="pPr"]', $pNode);
        if ($pPrNodes->length > 0) {
            $pPr = $pPrNodes->item(0);
            $lvl = $pPr->getAttribute('lvl');
            if ($lvl !== '') {
                $level = (int)$lvl;
            }
            if ($xpath->query('*[local-name()="buNone"]', $pPr)->length > 0) {
                $bulletType = 'none';
            } elseif ($xpath->query('*[local-name()="buAutoNum"]', $pPr)->length > 0) {
                $bulletType = 'number';
            } elseif ($xpath->query('*[local-name()="buChar"]', $pPr)->length > 0 ||
                      $xpath->query('*[local-name()="buFont"]', $pPr)->length > 0 ||
                      $xpath->query('*[local-name()="buClr"]', $pPr)->length > 0) {
                $bulletType = 'bullet';
            }
        }

        $runs = [];
        foreach ($xpath->query('*[local-name()="r"]', $pNode) as $rNode) {
            $tNodes = $xpath->query('*[local-name()="t"]', $rNode);
            if ($tNodes->length === 0) {
                continue;
            }
            $text = $tNodes->item(0)->textContent;
            if ($text === '') {
                continue;
            }

            $bold = false;
            $italic = false;
            $rPrNodes = $xpath->query('*[local-name()="rPr"]', $rNode);
            if ($rPrNodes->length > 0) {
                $rPr = $rPrNodes->item(0);
                $b = $rPr->getAttribute('b');
                $bold = ($b === '1' || $b === 'true');
                $i = $rPr->getAttribute('i');
                $italic = ($i === '1' || $i === 'true');
            }

            $runs[] = new PptxRun($text, $bold, $italic);
        }

        if (empty($runs)) {
            return null;
        }

        return new PptxParagraph($level, $bulletType, $runs);
    }

    private function parsePicture(
        DOMXPath $xpath,
        DOMNode $picNode,
        array $rels,
        ZipArchive $zip,
        string $slideDir
    ): ?PptxPicture {
        $blipNodes = $xpath->query('.//*[local-name()="blip"]', $picNode);
        if ($blipNodes->length === 0) {
            return null;
        }

        $blip = $blipNodes->item(0);
        $relId = $blip->getAttributeNS(self::R_NS, 'embed');
        if (!$relId) {
            $relId = $blip->getAttribute('r:embed');
        }
        if (!$relId) {
            return null;
        }

        $target = $rels[$relId] ?? null;
        if ($target === null) {
            return null;
        }

        // target is relative to the slide dir, e.g. "../media/image1.png"
        $mediaPath = $this->normalizePath($slideDir . '/' . $target);

        $data = $zip->getFromName($mediaPath);
        if ($data === false) {
            return null;
        }

        $ext = strtolower(pathinfo($mediaPath, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png'       => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'       => 'image/gif',
            'svg'       => 'image/svg+xml',
            default     => 'image/png',
        };

        $altText = '';
        $title = '';
        $cNvPrNodes = $xpath->query('.//*[local-name()="cNvPr"]', $picNode);
        if ($cNvPrNodes->length > 0) {
            $altText = $cNvPrNodes->item(0)->getAttribute('descr');
            $title = $cNvPrNodes->item(0)->getAttribute('title');
            if (!$altText) {
                $altText = $cNvPrNodes->item(0)->getAttribute('name');
            }
        }

        return new PptxPicture(basename($mediaPath), $mime, $data, $title, $altText);
    }

    private function parseGraphicFrame(DOMXPath $xpath, DOMNode $gfNode): PptxTable|PptxDiagram|null
    {
        $tblNodes = $xpath->query('.//*[local-name()="tbl"]', $gfNode);
        if ($tblNodes->length > 0) {
            return $this->parseTable($xpath, $tblNodes->item(0));
        }

        // SmartArt / chart: extract all text as fallback
        $texts = [];
        foreach ($xpath->query('.//*[local-name()="t"]', $gfNode) as $tNode) {
            $t = trim($tNode->textContent);
            if ($t !== '') {
                $texts[] = $t;
            }
        }
        return !empty($texts) ? new PptxDiagram($texts) : null;
    }

    private function parseTable(DOMXPath $xpath, DOMNode $tblNode): PptxTable
    {
        $rows = [];
        foreach ($xpath->query('*[local-name()="tr"]', $tblNode) as $trNode) {
            $cells = [];
            foreach ($xpath->query('*[local-name()="tc"]', $trNode) as $tcNode) {
                $text = '';
                foreach ($xpath->query('.//*[local-name()="t"]', $tcNode) as $tNode) {
                    $text .= $tNode->textContent;
                }
                $cells[] = $text;
            }
            if (!empty($cells)) {
                $rows[] = $cells;
            }
        }
        return new PptxTable($rows);
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
}

// ---------------------------------------------------------------------------
// Intermediate data classes
// ---------------------------------------------------------------------------

readonly class PptxDocument
{
    /**
     * @param PptxSlide[]     $slides
     * @param PptxMediaFile[] $mediaFiles  all ppt/media/* files (including master/layout assets)
     */
    public function __construct(public array $slides, public array $mediaFiles = []) {}
}

readonly class PptxMediaFile
{
    public function __construct(
        public string $filename,
        public string $mime,
        public string $data
    ) {}
}

readonly class PptxSlide
{
    /** @param PptxShape[] $shapes */
    public function __construct(
        public int $index,
        public array $shapes
    ) {}
}

class PptxShape {}

readonly class PptxTextShape
{
    /** @param PptxParagraph[] $paragraphs */
    public function __construct(
        public string $placeholderType,
        public array $paragraphs
    ) {}
}

readonly class PptxParagraph
{
    /** @param PptxRun[] $runs */
    public function __construct(
        public int $level,
        public string $bulletType,
        public array $runs
    ) {}
}

readonly class PptxRun
{
    public function __construct(
        public string $text,
        public bool $bold,
        public bool $italic
    ) {}
}

readonly class PptxPicture
{
    public function __construct(
        public string $filename,
        public string $mime,
        public string $data,
        public string $title,
        public string $altText
    ) {}
}

readonly class PptxTable
{
    /** @param array<array<string>> $rows */
    public function __construct(public array $rows) {}
}

readonly class PptxDiagram
{
    /** @param string[] $texts */
    public function __construct(public array $texts) {}
}
