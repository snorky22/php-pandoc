<?php

namespace Pandoc\Writer;

use Pandoc\AST\Alignment;
use Pandoc\AST\Block;
use Pandoc\AST\BlockQuote;
use Pandoc\AST\BulletList;
use Pandoc\AST\Cite;
use Pandoc\AST\Code;
use Pandoc\AST\CodeBlock;
use Pandoc\AST\DefinitionList;
use Pandoc\AST\Div;
use Pandoc\AST\Emph;
use Pandoc\AST\Figure;
use Pandoc\AST\Header;
use Pandoc\AST\HorizontalRule;
use Pandoc\AST\Image;
use Pandoc\AST\Inline;
use Pandoc\AST\LineBreak;
use Pandoc\AST\Link;
use Pandoc\AST\ListNumberDelim;
use Pandoc\AST\ListNumberStyle;
use Pandoc\AST\Math;
use Pandoc\AST\MathType;
use Pandoc\AST\Note;
use Pandoc\AST\OrderedList;
use Pandoc\AST\Pandoc;
use Pandoc\AST\Para;
use Pandoc\AST\Plain;
use Pandoc\AST\Quoted;
use Pandoc\AST\QuoteType;
use Pandoc\AST\RawBlock;
use Pandoc\AST\SmallCaps;
use Pandoc\AST\SoftBreak;
use Pandoc\AST\Space;
use Pandoc\AST\Span;
use Pandoc\AST\Str;
use Pandoc\AST\Strikeout;
use Pandoc\AST\Strong;
use Pandoc\AST\Subscript;
use Pandoc\AST\Superscript;
use Pandoc\AST\Table;
use Pandoc\AST\Underline;
use ZipArchive;

/**
 * Writes an OpenDocument Text (.odt, ODF 1.3) file; pure PHP (ext-zip, ext-dom), no LibreOffice needed.
 *
 * Equations become embedded MathML objects (editable in LibreOffice); headings keep their
 * outline level, so the Navigator works. Section numbers are written as text.
 *
 * Options:
 *  - numberSections (bool, default true)  show the 'number' heading attribute
 *  - titlePage      (bool, default true)  title, authors and date at the top
 *  - coverImage     (string|null)         path of a cover image; overrides the document's \cover{file}
 *  - resourcePath   (string[]|string)     folders searched for images missing from the MediaBag
 *  - language       (string|null)         BCP 47 tag, defaults to the 'lang' metadata or en-US
 *  - mathml         (bool, default true)  embed equations as MathML objects (else TeX source as text)
 */
class OdtWriter
{
    private const TEXT_WIDTH_CM = 16.0;
    private const MAX_HEIGHT_CM = 22.0;

    private const MIME_BY_EXT = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'svg' => 'image/svg+xml', 'webp' => 'image/webp',
    ];

    private const NS = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
        . ' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
        . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
        . ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
        . ' xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0"'
        . ' xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"'
        . ' xmlns:xlink="http://www.w3.org/1999/xlink"'
        . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
        . ' xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0"'
        . ' xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0"'
        . ' xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0"';

    /** @var array<int, array{name:string, contents:string, mime:string}> */
    private array $pictures = [];
    /** @var array<string, int> image url => index in $pictures */
    private array $pictureIndex = [];
    /** @var string[] serialized MathML documents, one per Object N */
    private array $objects = [];
    /** @var string[] anchors waiting for the next paragraph */
    private array $pending = [];
    /** @var array<string, bool> */
    private array $usedAnchors = [];
    /** @var array<string, string> style name => automatic style XML (lists, tables, colours) */
    private array $autoStyles = [];
    private int $noteCounter = 0;
    private int $tableCounter = 0;
    private int $frameCounter = 0;
    private bool $mathMl = true;
    /** Widest picture allowed, in cm (reduced while writing a sub-figure cell). */
    private float $imageMaxWidth = self::TEXT_WIDTH_CM;
    /** What 100% means for a picture width, in cm (the cell width inside a sub-figure row). */
    private float $percentBase = self::TEXT_WIDTH_CM;
    private Pandoc $doc;

    public function __construct(private array $options = []) {}

    public function writeToFile(Pandoc $doc, string $path): void
    {
        file_put_contents($path, $this->write($doc));
    }

    /** @return string binary contents of the .odt file */
    public function write(Pandoc $doc): string
    {
        $this->doc = $doc;
        $this->pictures = $this->pictureIndex = $this->objects = $this->pending = $this->usedAnchors = $this->autoStyles = [];
        $this->noteCounter = $this->tableCounter = $this->frameCounter = 0;
        $this->mathMl = (bool) ($this->options['mathml'] ?? true);
        $this->imageMaxWidth = $this->percentBase = self::TEXT_WIDTH_CM;

        $title = $this->metaText($doc->meta->map['title'] ?? '');
        $authors = $this->metaAuthors($doc->meta->map['author'] ?? []);
        $date = $this->metaText($doc->meta->map['date'] ?? '');
        $lang = (string) ($this->options['language'] ?? ($this->metaText($doc->meta->map['lang'] ?? '') ?: 'en-US'));

        $body = '';
        if ($this->options['titlePage'] ?? true) {
            $body .= $this->titleBlock($title, $authors, $date);
        }
        $body .= $this->writeBlocks($doc->blocks, 'body');

        $content = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<office:document-content ' . self::NS . ' office:version="1.3">'
            . '<office:automatic-styles>' . $this->automaticStyles() . implode('', $this->autoStyles) . '</office:automatic-styles>'
            . '<office:body><office:text>' . $body . '</office:text></office:body></office:document-content>';

        $files = [
            'content.xml' => $content,
            'styles.xml' => $this->stylesXml($lang),
            'meta.xml' => $this->metaXml($title, $authors, $date, $lang),
        ];
        foreach ($this->pictures as $pic) {
            $files['Pictures/' . $pic['name']] = $pic['contents'];
        }
        foreach ($this->objects as $i => $mathml) {
            $files['Object ' . ($i + 1) . '/content.xml'] = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $mathml;
        }
        $files['META-INF/manifest.xml'] = $this->manifestXml($files);

        return $this->zip($files);
    }

    // ------------------------------------------------------------------
    // Blocks
    // ------------------------------------------------------------------

    /** @param Block[] $blocks */
    private function writeBlocks(array $blocks, string $ctx): string
    {
        $out = '';
        foreach ($blocks as $b) {
            $out .= $this->writeBlock($b, $ctx);
        }
        return $out;
    }

    private function writeBlock(Block $b, string $ctx): string
    {
        return match (true) {
            $b instanceof Para, $b instanceof Plain => $this->writeParagraph($b->content, $ctx),
            $b instanceof Header => $this->writeHeader($b),
            $b instanceof BulletList => $this->writeBulletList($b),
            $b instanceof OrderedList => $this->writeOrderedList($b),
            $b instanceof DefinitionList => $this->writeDefinitionList($b),
            $b instanceof BlockQuote => $this->writeBlocks($b->content, 'quote'),
            $b instanceof CodeBlock => $this->writeCodeBlock($b),
            $b instanceof HorizontalRule => '<text:p text:style-name="Horizontal_20_Line"/>',
            $b instanceof Div => $this->writeDiv($b, $ctx),
            $b instanceof Figure => $this->writeFigure($b, $ctx),
            $b instanceof Table => $this->writeTable($b),
            $b instanceof RawBlock => '',
            default => '',
        };
    }

    private function paragraphStyle(string $ctx): string
    {
        return match (true) {
            $ctx === 'list' => 'List_20_Contents',
            $ctx === 'quote' => 'Quotations',
            $ctx === 'note' => 'Footnote',
            $ctx === 'abstract' => 'Abstract',
            $ctx === 'def' => 'Definition',
            str_starts_with($ctx, 'cell:') => 'TC' . substr($ctx, 5),
            str_starts_with($ctx, 'head:') => 'TH' . substr($ctx, 5),
            str_starts_with($ctx, 'alg:') => 'Alg' . substr($ctx, 4),
            default => 'Text_20_body',
        };
    }

    /** @param Inline[] $inlines */
    private function writeParagraph(array $inlines, string $ctx): string
    {
        if ($this->isDisplayMath($inlines)) {
            return '<text:p text:style-name="Math_20_Display">' . $this->takeAnchors() . $this->writeInlines($inlines) . '</text:p>';
        }
        if ($this->isImagesOnly($inlines)) {
            return '<text:p text:style-name="Illustration">' . $this->takeAnchors() . $this->writeInlines($inlines) . '</text:p>';
        }
        return '<text:p text:style-name="' . $this->paragraphStyle($ctx) . '">' . $this->takeAnchors() . $this->writeInlines($inlines) . '</text:p>';
    }

    private function isDisplayMath(array $inlines): bool
    {
        $found = false;
        foreach ($inlines as $i) {
            if ($i instanceof Space || $i instanceof SoftBreak) {
                continue;
            }
            if ($i instanceof Span && $this->isDisplayMath($i->content)) {
                $found = true;
                continue;
            }
            if ($i instanceof Span && !$i->content) {
                continue;
            }
            if ($i instanceof Math && $i->type === MathType::DisplayMath) {
                $found = true;
                continue;
            }
            return false;
        }
        return $found;
    }

    private function isImagesOnly(array $inlines): bool
    {
        $found = false;
        foreach ($inlines as $i) {
            if ($i instanceof Space || $i instanceof SoftBreak) {
                continue;
            }
            if ($i instanceof Image) {
                $found = true;
                continue;
            }
            return false;
        }
        return $found;
    }

    private function writeHeader(Header $h): string
    {
        $level = max(1, min(6, $h->level));
        $this->pushAnchor($h->attr->identifier);
        $number = '';
        if ($this->options['numberSections'] ?? true) {
            foreach ($this->attrPairs($h->attr) as [$k, $v]) {
                if ($k === 'number') {
                    $number = self::esc($v) . '<text:s text:c="2"/>';
                }
            }
        }
        return '<text:h text:style-name="Heading_20_' . $level . '" text:outline-level="' . $level . '">'
            . $this->takeAnchors() . $number . $this->writeInlines($h->content) . '</text:h>';
    }

    private function writeDiv(Div $d, string $ctx): string
    {
        $this->pushAnchor($d->attr->identifier);
        if (in_array('alg-line', $d->attr->classes, true)) {
            $indent = 0;
            foreach ($d->attr->classes as $c) {
                if (preg_match('/^alg-i(\d)$/', $c, $m)) {
                    $indent = (int) $m[1];
                }
            }
            return $this->writeBlocks($d->content, 'alg:' . $indent);
        }
        $childCtx = in_array('abstract', $d->attr->classes, true) ? 'abstract' : $ctx;
        return $this->writeBlocks($d->content, $childCtx);
    }

    private function writeFigure(Figure $f, string $ctx): string
    {
        $caption = $this->captionInlines($f->caption->content);
        $subs = array_values(array_filter($f->content, fn($b) => $b instanceof Figure && in_array('subfigure', $b->attr->classes, true)));
        $grid = count($subs) >= 2 && count($subs) === count($f->content);
        if (!$grid) {
            $this->pushAnchor($f->attr->identifier);
        }
        $out = $grid ? $this->subfigureRows($subs) : $this->writeBlocks($f->content, $ctx);
        if ($caption) {
            if (!$grid) {
                // keep only the last picture with its caption, not every picture of the figure
                $marker = 'text:style-name="Illustration"';
                $pos = strrpos($out, $marker);
                if ($pos !== false) {
                    $out = substr_replace($out, 'text:style-name="Illustration_20_Captioned"', $pos, strlen($marker));
                }
            }
            if ($grid) {
                $this->pushAnchor($f->attr->identifier); // the figure's own bookmark sits on its caption, not on the first panel
            }
            $out .= '<text:p text:style-name="Caption">' . $this->takeAnchors() . $this->writeInlines($caption) . '</text:p>';
        }
        return $out;
    }

    /**
     * Lays sub-figures out side by side, as in the PDF: one borderless table row per line of
     * sub-figures, each cell as wide as its picture; a new row starts when the next one no longer fits.
     *
     * @param Figure[] $subs
     */
    private function subfigureRows(array $subs): string
    {
        $pad = 0.3;
        $default = self::TEXT_WIDTH_CM / count($subs) - $pad;
        $rows = [];
        $cur = [];
        $curW = 0.0;
        foreach ($subs as $s) {
            $w = min($this->subfigureWidth($s) ?? $default, self::TEXT_WIDTH_CM - $pad) + $pad;
            if ($cur && $curW + $w > self::TEXT_WIDTH_CM + 0.01) {
                $rows[] = $cur;
                $cur = [];
                $curW = 0.0;
            }
            $cur[] = [$s, $w];
            $curW += $w;
        }
        if ($cur) {
            $rows[] = $cur;
        }

        $out = '';
        foreach ($rows as $row) {
            $name = 'TblSub' . ++$this->tableCounter;
            $total = array_sum(array_column($row, 1));
            $this->autoStyles[$name] = '<style:style style:name="' . $name . '" style:family="table"><style:table-properties style:width="'
                . number_format($total, 3, '.', '') . 'cm" table:align="center" fo:margin-top="0.1cm" fo:margin-bottom="0.1cm"/></style:style>';
            $out .= '<table:table table:name="' . $name . '" table:style-name="' . $name . '">';
            foreach ($row as $c => [, $w]) {
                $cn = $name . '.C' . $c;
                $this->autoStyles[$cn] = '<style:style style:name="' . $cn . '" style:family="table-column"><style:table-column-properties style:column-width="'
                    . number_format($w, 3, '.', '') . 'cm"/></style:style>';
                $out .= '<table:table-column table:style-name="' . $cn . '"/>';
            }
            $out .= '<table:table-row>';
            [$savedMax, $savedBase] = [$this->imageMaxWidth, $this->percentBase];
            foreach ($row as [$s, $w]) {
                $this->imageMaxWidth = $this->percentBase = $w - $pad;
                $out .= '<table:table-cell table:style-name="CellPlain" office:value-type="string">' . $this->writeFigure($s, 'body') . '</table:table-cell>';
            }
            [$this->imageMaxWidth, $this->percentBase] = [$savedMax, $savedBase];
            $out .= '</table:table-row></table:table>';
        }
        return $out;
    }

    /** Declared width (cm) of the first picture of a sub-figure, or null. */
    private function subfigureWidth(Figure $s): ?float
    {
        foreach ($this->attrPairs($s->attr) as [$k, $v]) {
            if ($k === 'width') {
                return $this->toCm($v, self::TEXT_WIDTH_CM);
            }
        }
        foreach ($s->content as $b) {
            if (!($b instanceof Plain || $b instanceof Para)) {
                continue;
            }
            foreach ($b->content as $i) {
                if ($i instanceof Image) {
                    foreach ($this->attrPairs($i->attr) as [$k, $v]) {
                        if ($k === 'width') {
                            return $this->toCm($v, self::TEXT_WIDTH_CM);
                        }
                    }
                }
            }
        }
        return null;
    }

    /** @param Block[] $blocks @return Inline[] */
    private function captionInlines(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $b) {
            if ($b instanceof Plain || $b instanceof Para) {
                if ($out) {
                    $out[] = new Space();
                }
                array_push($out, ...$b->content);
            }
        }
        return $out;
    }

    private function writeCodeBlock(CodeBlock $cb): string
    {
        $this->pushAnchor($cb->attr->identifier);
        $lines = array_map([$this, 'preformat'], explode("\n", $cb->text));
        return '<text:p text:style-name="Preformatted_20_Text">' . $this->takeAnchors() . implode('<text:line-break/>', $lines) . '</text:p>';
    }

    /** Escapes text and keeps runs of spaces and tabs, which ODF would otherwise collapse. */
    private function preformat(string $line): string
    {
        $out = '';
        foreach (preg_split('/( +|\t)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
            if ($part === "\t") {
                $out .= '<text:tab/>';
            } elseif (trim($part) === '') {
                // a single space inside a line survives as it is; leading and repeated spaces need <text:s/>
                $out .= strlen($part) === 1 && $out !== '' ? ' ' : '<text:s text:c="' . strlen($part) . '"/>';
            } else {
                $out .= self::esc($part);
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Lists
    // ------------------------------------------------------------------

    private function writeBulletList(BulletList $l): string
    {
        return '<text:list text:style-name="L_Bullet">' . $this->listItems($l->items) . '</text:list>';
    }

    private function writeOrderedList(OrderedList $l): string
    {
        [$format, $prefix, $suffix] = [
            match ($l->attr->style) {
                ListNumberStyle::LowerAlpha => 'a',
                ListNumberStyle::UpperAlpha => 'A',
                ListNumberStyle::LowerRoman => 'i',
                ListNumberStyle::UpperRoman => 'I',
                default => '1',
            },
            $l->attr->delim === ListNumberDelim::TwoParens ? '(' : '',
            match ($l->attr->delim) {
                ListNumberDelim::OneParen, ListNumberDelim::TwoParens => ')',
                default => '.',
            },
        ];
        $name = 'L_' . ($format === '1' ? 'n' : ($format === 'a' ? 'a' : ($format === 'A' ? 'A' : ($format === 'i' ? 'r' : 'R'))))
            . ($prefix !== '' ? 'p' : '') . ($suffix === ')' ? 's' : '');
        if (!isset($this->autoStyles[$name])) {
            $levels = '';
            for ($lv = 1; $lv <= 6; $lv++) {
                $pos = number_format(0.8 * $lv, 2, '.', '');
                $levels .= '<text:list-level-style-number text:level="' . $lv . '" style:num-format="' . $format . '"'
                    . ' style:num-prefix="' . $prefix . '" style:num-suffix="' . $suffix . '">'
                    . '<style:list-level-properties text:list-level-position-and-space-mode="label-alignment">'
                    . '<style:list-level-label-alignment text:label-followed-by="listtab" text:list-tab-stop-position="' . $pos . 'cm"'
                    . ' fo:text-indent="-0.7cm" fo:margin-left="' . $pos . 'cm"/></style:list-level-properties>'
                    . '</text:list-level-style-number>';
            }
            $this->autoStyles[$name] = '<text:list-style style:name="' . $name . '">' . $levels . '</text:list-style>';
        }
        return '<text:list text:style-name="' . $name . '">' . $this->listItems($l->items, $l->attr->start) . '</text:list>';
    }

    /** @param Block[][] $items */
    private function listItems(array $items, int $start = 1): string
    {
        $out = '';
        foreach ($items as $k => $item) {
            $startAttr = $k === 0 && $start !== 1 ? ' text:start-value="' . $start . '"' : '';
            $body = $this->writeBlocks($item, 'list');
            $out .= '<text:list-item' . $startAttr . '>' . ($body !== '' ? $body : '<text:p text:style-name="List_20_Contents"/>') . '</text:list-item>';
        }
        return $out;
    }

    private function writeDefinitionList(DefinitionList $l): string
    {
        $out = '';
        foreach ($l->items as [$term, $defs]) {
            $out .= '<text:p text:style-name="Definition_20_Term">' . $this->takeAnchors() . $this->writeInlines($term) . '</text:p>';
            foreach ($defs as $def) {
                $out .= $this->writeBlocks(is_array($def) ? $def : [$def], 'def');
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Tables
    // ------------------------------------------------------------------

    private function writeTable(Table $t): string
    {
        $n = ++$this->tableCounter;
        $this->pushAnchor($t->attr->identifier);
        $out = '';
        $caption = $this->captionInlines($t->caption->content);
        if ($caption) {
            $out .= '<text:p text:style-name="Caption_20_Table">' . $this->takeAnchors() . $this->writeInlines($caption) . '</text:p>';
        }

        $headRows = $t->head->rows;
        $bodyRows = [];
        foreach ($t->bodies as $body) {
            array_push($bodyRows, ...$body->head, ...$body->rows);
        }
        array_push($bodyRows, ...$t->foot->rows);
        $allRows = array_merge($headRows, $bodyRows);
        if (!$allRows) {
            return $out;
        }

        // Auto layout: every column gets at least the width of its longest word; the remaining
        // page width is shared in proportion to how much more text each column wants.
        $cols = 0;
        $minC = [];
        $prefC = [];
        foreach ($allRows as $row) {
            $c = 0;
            foreach ($row->cells as $cell) {
                if ($cell->colSpan === 1) {
                    $text = $this->blocksText($cell->content);
                    $longest = 0;
                    foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $word) {
                        $longest = max($longest, mb_strlen($word));
                    }
                    $minC[$c] = max($minC[$c] ?? 0, $longest * 0.19 + 0.35);
                    $prefC[$c] = max($prefC[$c] ?? 0, min(40, mb_strlen($text)) * 0.19 + 0.35);
                }
                $c += $cell->colSpan;
            }
            $cols = max($cols, $c);
        }
        $widths = [];
        for ($c = 0; $c < $cols; $c++) {
            $min = max($minC[$c] ?? 0.8, 0.8);
            $widths[$c] = [$min, max($prefC[$c] ?? $min, $min)];
        }
        $sumMin = array_sum(array_column($widths, 0));
        $sumPref = array_sum(array_column($widths, 1));
        $W = self::TEXT_WIDTH_CM;
        $colWidths = [];
        foreach ($widths as $c => [$min, $pref]) {
            $colWidths[$c] = match (true) {
                $sumPref <= $W => $pref * $W / $sumPref,
                $sumMin >= $W => $min * $W / $sumMin,
                default => $min + ($W - $sumMin) * (($pref - $min) / max($sumPref - $sumMin, 0.0001)),
            };
        }

        $styleName = 'Tbl' . $n;
        $this->autoStyles[$styleName] = '<style:style style:name="' . $styleName . '" style:family="table">'
            . '<style:table-properties style:width="' . self::TEXT_WIDTH_CM . 'cm" table:align="margins" fo:margin-top="0.1cm" fo:margin-bottom="0.3cm"/></style:style>';
        $out .= '<table:table table:name="' . $styleName . '" table:style-name="' . $styleName . '">';
        foreach ($colWidths as $c => $w) {
            $cn = $styleName . '.C' . $c;
            $this->autoStyles[$cn] = '<style:style style:name="' . $cn . '" style:family="table-column">'
                . '<style:table-column-properties style:column-width="' . number_format($w, 3, '.', '') . 'cm"/></style:style>';
            $out .= '<table:table-column table:style-name="' . $cn . '"/>';
        }

        $total = count($allRows);
        $rowIndex = 0;
        if ($headRows) {
            $out .= '<table:table-header-rows>';
            foreach ($headRows as $row) {
                $out .= $this->writeRow($row, true, 'CellHead', $cols);
                $rowIndex++;
            }
            $out .= '</table:table-header-rows>';
        }
        foreach ($bodyRows as $k => $row) {
            $last = $rowIndex + $k === $total - 1;
            $first = !$headRows && $k === 0;
            $out .= $this->writeRow($row, false, $last ? ($first ? 'CellOnly' : 'CellLast') : ($first ? 'CellFirst' : 'CellBody'), $cols);
        }
        return $out . '</table:table>';
    }

    private function writeRow(\Pandoc\AST\Row $row, bool $head, string $cellStyle, int $cols): string
    {
        $out = '<table:table-row>';
        foreach ($row->cells as $cell) {
            $align = match ($cell->align) {
                Alignment::AlignCenter => 'C',
                Alignment::AlignRight => 'R',
                default => 'L',
            };
            $span = $cell->colSpan > 1 ? ' table:number-columns-spanned="' . $cell->colSpan . '"' : '';
            $rspan = $cell->rowSpan > 1 ? ' table:number-rows-spanned="' . $cell->rowSpan . '"' : '';
            $body = $this->writeBlocks($cell->content, ($head ? 'head:' : 'cell:') . $align);
            if ($body === '') {
                $body = '<text:p text:style-name="TC' . $align . '"/>';
            }
            $out .= '<table:table-cell table:style-name="' . $cellStyle . '" office:value-type="string"' . $span . $rspan . '>' . $body . '</table:table-cell>';
            for ($i = 1; $i < $cell->colSpan; $i++) {
                $out .= '<table:covered-table-cell/>';
            }
        }
        return $out . '</table:table-row>';
    }

    /** Plain text of the paragraphs of $blocks, with formulas reduced to roughly their rendered length. */
    private function blocksText(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $b) {
            if ($b instanceof Plain || $b instanceof Para) {
                $parts[] = $this->plainText($b->content);
            }
        }
        return trim(implode(' ', $parts));
    }

    /** @param Inline[] $inlines */
    private function plainText(array $inlines): string
    {
        $out = '';
        foreach ($inlines as $i) {
            $out .= match (true) {
                $i instanceof Str => $i->text,
                $i instanceof Space, $i instanceof SoftBreak, $i instanceof LineBreak => ' ',
                $i instanceof Code => $i->text,
                $i instanceof Math => preg_replace('/[{}_^\s]/', '', preg_replace('/\\\\[a-zA-Z]+/', 'x', $i->text)),
                $i instanceof Note => '',
                is_object($i) && property_exists($i, 'content') && is_array($i->content) => $this->plainText($i->content),
                default => '',
            };
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Inlines
    // ------------------------------------------------------------------

    /** @param Inline[] $inlines */
    private function writeInlines(array $inlines): string
    {
        $out = '';
        foreach ($inlines as $i) {
            $out .= $this->writeInline($i);
        }
        return $out;
    }

    private function span(string $style, array $content): string
    {
        return '<text:span text:style-name="' . $style . '">' . $this->writeInlines($content) . '</text:span>';
    }

    private function writeInline(Inline $i): string
    {
        return match (true) {
            $i instanceof Str => self::esc($i->text),
            $i instanceof Space, $i instanceof SoftBreak => ' ',
            $i instanceof LineBreak => '<text:line-break/>',
            $i instanceof Emph => $this->span('Emphasis', $i->content),
            $i instanceof Strong => $this->span('Strong_20_Emphasis', $i->content),
            $i instanceof Underline => $this->span('T_Underline', $i->content),
            $i instanceof Strikeout => $this->span('T_Strike', $i->content),
            $i instanceof Superscript => $this->span('T_Super', $i->content),
            $i instanceof Subscript => $this->span('T_Sub', $i->content),
            $i instanceof SmallCaps => $this->span('T_SmallCaps', $i->content),
            $i instanceof Quoted => ($i->type === QuoteType::SingleQuote ? "\u{2018}" : "\u{201C}")
                . $this->writeInlines($i->content) . ($i->type === QuoteType::SingleQuote ? "\u{2019}" : "\u{201D}"),
            $i instanceof Code => '<text:span text:style-name="Source_20_Text">' . $this->preformat($i->text) . '</text:span>',
            $i instanceof Math => $this->writeMath($i),
            $i instanceof Link => $this->writeLink($i),
            $i instanceof Image => $this->writeImage($i),
            $i instanceof Span => $this->writeSpan($i),
            $i instanceof Note => $this->writeNote($i),
            $i instanceof Cite => $this->writeInlines($i->content),
            default => '',
        };
    }

    private function writeLink(Link $l): string
    {
        $url = $l->target->url;
        $href = str_starts_with($url, '#') ? '#' . substr($url, 1) : $url;
        return '<text:a xlink:type="simple" xlink:href="' . self::esc($href) . '" text:style-name="Internet_20_link" text:visited-style-name="Internet_20_link">'
            . $this->writeInlines($l->content) . '</text:a>';
    }

    private function writeSpan(Span $s): string
    {
        $anchor = $s->attr->identifier !== '' && !isset($this->usedAnchors[$s->attr->identifier])
            ? $this->bookmark($s->attr->identifier) : '';
        $style = [];
        foreach ($this->attrPairs($s->attr) as [$k, $v]) {
            if (in_array($k, ['color', 'background-color'], true)) {
                $hex = $this->cssColor($v);
                if ($hex !== null) {
                    $style[$k] = $hex;
                }
            }
        }
        $content = $this->writeInlines($s->content);
        if (!$style) {
            return $anchor . $content;
        }
        $name = 'T_C' . md5(json_encode($style));
        if (!isset($this->autoStyles[$name])) {
            $this->autoStyles[$name] = '<style:style style:name="' . $name . '" style:family="text"><style:text-properties'
                . (isset($style['color']) ? ' fo:color="' . $style['color'] . '"' : '')
                . (isset($style['background-color']) ? ' fo:background-color="' . $style['background-color'] . '"' : '')
                . '/></style:style>';
        }
        return $anchor . '<text:span text:style-name="' . $name . '">' . $content . '</text:span>';
    }

    private function cssColor(string $c): ?string
    {
        $c = trim($c);
        if (preg_match('/^#([0-9a-f]{6})$/i', $c)) {
            return strtolower($c);
        }
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $c, $m)) {
            return strtolower('#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3]);
        }
        $named = ['red' => '#ff0000', 'green' => '#008000', 'blue' => '#0000ff', 'black' => '#000000', 'white' => '#ffffff',
            'gray' => '#808080', 'grey' => '#808080', 'yellow' => '#ffff00', 'orange' => '#ffa500', 'purple' => '#800080',
            'cyan' => '#00ffff', 'magenta' => '#ff00ff', 'brown' => '#a52a2a'];
        return $named[strtolower($c)] ?? null;
    }

    private function writeNote(Note $n): string
    {
        $num = ++$this->noteCounter;
        $blocks = $n->content;
        if ($blocks && $blocks[0] instanceof Inline) {
            $blocks = [new Para($blocks)];
        }
        $saved = $this->pending;
        $this->pending = [];
        $body = $this->writeBlocks($blocks, 'note');
        $this->pending = $saved;
        return '<text:note text:id="ftn' . $num . '" text:note-class="footnote"><text:note-citation>' . $num . '</text:note-citation>'
            . '<text:note-body>' . ($body !== '' ? $body : '<text:p text:style-name="Footnote"/>') . '</text:note-body></text:note>';
    }

    private function writeMath(Math $m): string
    {
        $display = $m->type === MathType::DisplayMath;
        if ($this->mathMl && ($mathml = MathMl::fromTex($m->text, $display)) !== null) {
            $this->objects[] = MathMl::forOffice($mathml);
            $n = count($this->objects);
            // No width/height on purpose: LibreOffice then sizes the formula itself.
            return '<draw:frame draw:style-name="fr_math" draw:name="Formula' . $n . '" text:anchor-type="as-char">'
                . '<draw:object xlink:href="./Object ' . $n . '" xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad"/></draw:frame>';
        }
        return '<text:span text:style-name="Source_20_Text">' . self::esc($display ? '\\[' . $m->text . '\\]' : '$' . $m->text . '$') . '</text:span>';
    }

    private function writeImage(Image $img): string
    {
        $pic = $this->picture($img->target->url);
        if ($pic === null) {
            $alt = XhtmlWriter::stringify($img->content);
            return $alt !== '' ? self::esc($alt) : '';
        }
        [$w, $h] = $this->imageSize($pic, $img);
        return $this->imageFrame($pic, $w, $h, XhtmlWriter::stringify($img->content));
    }

    private function imageFrame(array $pic, float $w, float $h, string $alt = ''): string
    {
        $n = ++$this->frameCounter;
        return '<draw:frame draw:style-name="fr_img" draw:name="Image' . $n . '" text:anchor-type="as-char"'
            . ' svg:width="' . number_format($w, 3, '.', '') . 'cm" svg:height="' . number_format($h, 3, '.', '') . 'cm">'
            . '<draw:image xlink:href="Pictures/' . self::esc($pic['name']) . '" xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad"/>'
            . ($alt !== '' ? '<svg:desc>' . self::esc($alt) . '</svg:desc>' : '') . '</draw:frame>';
    }

    /** @return array{0:float, 1:float} width and height in cm */
    private function imageSize(array $pic, Image $img): array
    {
        [$pw, $ph] = $this->pixelSize($pic);
        $ratio = $pw > 0 ? $ph / $pw : 0.75;
        $width = null;
        $height = null;
        foreach ($this->attrPairs($img->attr) as [$k, $v]) {
            if ($k === 'width') {
                $width = $this->toCm($v, $this->percentBase);
            } elseif ($k === 'height') {
                $height = $this->toCm($v, self::MAX_HEIGHT_CM);
            }
        }
        if ($width === null && $height === null) {
            $width = min($this->imageMaxWidth, $pw / 96 * 2.54);
        }
        if ($width === null) {
            $width = $height / $ratio;
        }
        $width = min($width, $this->imageMaxWidth);
        $height = $width * $ratio;
        if ($height > self::MAX_HEIGHT_CM) {
            $height = self::MAX_HEIGHT_CM;
            $width = $height / $ratio;
        }
        return [max($width, 0.5), max($height, 0.5)];
    }

    private function toCm(string $v, float $full): ?float
    {
        if (!preg_match('/^([0-9.]+)\s*(%|cm|mm|in|pt|px)?$/', trim($v), $m)) {
            return null;
        }
        $n = (float) $m[1];
        return match ($m[2] ?? '') {
            '%' => $full * $n / 100,
            'cm' => $n,
            'mm' => $n / 10,
            'in' => $n * 2.54,
            'pt' => $n / 72 * 2.54,
            default => $n / 96 * 2.54,
        };
    }

    /** @return array{0:int, 1:int} */
    private function pixelSize(array $pic): array
    {
        if ($pic['mime'] === 'image/svg+xml') {
            if (preg_match('/<svg[^>]*\sviewBox="[\d.\s-]*?([\d.]+)\s+([\d.]+)"/i', $pic['contents'], $m)) {
                return [(int) $m[1], (int) $m[2]];
            }
            return [640, 480];
        }
        $info = @getimagesizefromstring($pic['contents']);
        return $info ? [(int) $info[0], (int) $info[1]] : [640, 480];
    }

    /** @return array{name:string, contents:string, mime:string}|null */
    private function picture(string $url): ?array
    {
        if (isset($this->pictureIndex[$url])) {
            return $this->pictures[$this->pictureIndex[$url]];
        }
        $found = $this->loadMedia($url);
        if ($found === null) {
            return null;
        }
        $ext = array_search($found['mime'], self::MIME_BY_EXT, true) ?: 'png';
        $pic = ['name' => 'image' . (count($this->pictures) + 1) . '.' . $ext, 'contents' => $found['contents'], 'mime' => $found['mime']];
        $this->pictures[] = $pic;
        $this->pictureIndex[$url] = count($this->pictures) - 1;
        return $pic;
    }

    /** @return array{contents:string, mime:string}|null */
    private function loadMedia(string $url): ?array
    {
        if (($item = $this->doc->mediaBag->lookup($url)) !== null) {
            return ['contents' => $item['contents'], 'mime' => $item['mime']];
        }
        if (preg_match('#^data:([^;,]+);base64,(.+)$#s', $url, $m)) {
            $data = base64_decode($m[2], true);
            return $data !== false ? ['contents' => $data, 'mime' => $m[1]] : null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            return null;
        }
        foreach ((array) ($this->options['resourcePath'] ?? ['.']) as $dir) {
            $file = str_starts_with($url, '/') ? $url : rtrim($dir, '/') . '/' . $url;
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (is_file($file) && isset(self::MIME_BY_EXT[$ext])) {
                return ['contents' => (string) file_get_contents($file), 'mime' => self::MIME_BY_EXT[$ext]];
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Anchors (bookmarks)
    // ------------------------------------------------------------------

    private function pushAnchor(string $id): void
    {
        if ($id !== '' && !isset($this->usedAnchors[$id])) {
            $this->pending[] = $id;
        }
    }

    private function takeAnchors(): string
    {
        $out = '';
        foreach ($this->pending as $id) {
            $out .= $this->bookmark($id);
        }
        $this->pending = [];
        return $out;
    }

    private function bookmark(string $id): string
    {
        if (isset($this->usedAnchors[$id])) {
            return '';
        }
        $this->usedAnchors[$id] = true;
        return '<text:bookmark text:name="' . self::esc($id) . '"/>';
    }

    // ------------------------------------------------------------------
    // Title block, metadata
    // ------------------------------------------------------------------

    private function titleBlock(string $title, array $authors, string $date): string
    {
        $out = '';
        if ($cover = $this->loadCover()) {
            $pic = $this->picture($cover);
            if ($pic !== null) {
                [$pw, $ph] = $this->pixelSize($pic);
                $w = min(self::TEXT_WIDTH_CM * 0.8, 12.0);
                $h = $w * ($pw > 0 ? $ph / $pw : 0.75);
                if ($h > 14.0) {
                    $h = 14.0;
                    $w = $h * ($ph > 0 ? $pw / $ph : 1.0);
                }
                $out .= '<text:p text:style-name="Illustration">' . $this->imageFrame($pic, $w, $h, 'Cover') . '</text:p>';
            }
        }
        if ($title !== '') {
            $out .= '<text:p text:style-name="Title">' . self::esc($title) . '</text:p>';
            foreach ($authors as $a) {
                $out .= '<text:p text:style-name="Author">' . self::esc($a) . '</text:p>';
            }
            if ($date !== '') {
                $out .= '<text:p text:style-name="Date">' . self::esc($date) . '</text:p>';
            }
        }
        return $out;
    }

    /** Returns a media url (registered through loadMedia) for the cover, or null. */
    private function loadCover(): ?string
    {
        $ref = $this->options['coverImage'] ?? ($this->doc->meta->map['cover-image'] ?? null);
        if (!is_string($ref) || $ref === '') {
            return null;
        }
        if (isset($this->options['coverImage'])) {
            $ext = strtolower(pathinfo($ref, PATHINFO_EXTENSION));
            if (!is_file($ref) || !isset(self::MIME_BY_EXT[$ext])) {
                return null;
            }
            $key = 'cover:' . $ref;
            if (!isset($this->pictureIndex[$key])) {
                $this->pictures[] = [
                    'name' => 'image' . (count($this->pictures) + 1) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext),
                    'contents' => (string) file_get_contents($ref),
                    'mime' => self::MIME_BY_EXT[$ext],
                ];
                $this->pictureIndex[$key] = count($this->pictures) - 1;
            }
            return $key;
        }
        return $this->loadMedia($ref) !== null ? $ref : null;
    }

    private function metaText(mixed $v): string
    {
        $text = match (true) {
            is_string($v) => $v,
            is_array($v) => XhtmlWriter::stringify(array_filter($v, 'is_object')),
            default => '',
        };
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** @return string[] */
    private function metaAuthors(mixed $v): array
    {
        if (is_string($v)) {
            return $v !== '' ? [$v] : [];
        }
        if (!is_array($v) || !$v) {
            return [];
        }
        $authors = is_array(reset($v)) ? array_map(fn($a) => $this->metaText($a), $v) : [$this->metaText($v)];
        return array_values(array_filter(array_map('trim', $authors), fn($a) => $a !== ''));
    }

    private function metaXml(string $title, array $authors, string $date, string $lang): string
    {
        $now = gmdate('Y-m-d\TH:i:s');
        $ts = $date !== '' ? strtotime($date) : false;
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<office:document-meta ' . self::NS . ' office:version="1.3"><office:meta>'
            . '<meta:generator>Pandoc PHP</meta:generator>'
            . ($title !== '' ? '<dc:title>' . self::esc($title) . '</dc:title>' : '')
            . ($authors ? '<meta:initial-creator>' . self::esc(implode(', ', $authors)) . '</meta:initial-creator><dc:creator>' . self::esc(implode(', ', $authors)) . '</dc:creator>' : '')
            . '<meta:creation-date>' . ($ts !== false ? gmdate('Y-m-d\TH:i:s', $ts) : $now) . '</meta:creation-date>'
            . '<dc:date>' . $now . '</dc:date>'
            . '<dc:language>' . self::esc($lang) . '</dc:language>'
            . '</office:meta></office:document-meta>';
    }

    // ------------------------------------------------------------------
    // Styles
    // ------------------------------------------------------------------

    private function automaticStyles(): string
    {
        $s = '';
        // table paragraphs, one per alignment (body and header)
        foreach (['L' => 'left', 'C' => 'center', 'R' => 'right'] as $k => $align) {
            $s .= '<style:style style:name="TC' . $k . '" style:family="paragraph" style:parent-style-name="Table_20_Contents">'
                . '<style:paragraph-properties fo:text-align="' . $align . '"/></style:style>';
            $s .= '<style:style style:name="TH' . $k . '" style:family="paragraph" style:parent-style-name="Table_20_Contents">'
                . '<style:paragraph-properties fo:text-align="' . $align . '"/><style:text-properties fo:font-weight="bold"/></style:style>';
        }
        // algorithm lines, indented by nesting depth
        for ($i = 0; $i <= 8; $i++) {
            $s .= '<style:style style:name="Alg' . $i . '" style:family="paragraph" style:parent-style-name="Standard">'
                . '<style:paragraph-properties fo:margin-left="' . number_format(0.2 + 0.7 * $i, 2, '.', '') . 'cm" fo:margin-top="0.04cm" fo:margin-bottom="0.04cm"/></style:style>';
        }
        // table cells (booktabs-like rules)
        $rule = 'fo:border-top="0.9pt solid #000000"';
        $thin = 'fo:border-bottom="0.5pt solid #000000"';
        $none = 'fo:border-left="none" fo:border-right="none"';
        foreach ([
            'CellHead' => "$rule $thin",
            'CellBody' => 'fo:border-top="none" fo:border-bottom="none"',
            'CellFirst' => "$rule fo:border-bottom=\"none\"",
            'CellLast' => 'fo:border-top="none" fo:border-bottom="0.9pt solid #000000"',
            'CellOnly' => "$rule fo:border-bottom=\"0.9pt solid #000000\"",
        ] as $name => $borders) {
            $s .= '<style:style style:name="' . $name . '" style:family="table-cell"><style:table-cell-properties fo:padding="0.08cm" '
                . $none . ' ' . $borders . '/></style:style>';
        }
        $s .= '<style:style style:name="CellPlain" style:family="table-cell"><style:table-cell-properties fo:padding="0.05cm" fo:border="none"/></style:style>';
        // inline formatting
        $s .= '<style:style style:name="T_Underline" style:family="text"><style:text-properties style:text-underline-style="solid" style:text-underline-width="auto" style:text-underline-color="font-color"/></style:style>'
            . '<style:style style:name="T_Strike" style:family="text"><style:text-properties style:text-line-through-style="solid"/></style:style>'
            . '<style:style style:name="T_Super" style:family="text"><style:text-properties style:text-position="super 58%"/></style:style>'
            . '<style:style style:name="T_Sub" style:family="text"><style:text-properties style:text-position="sub 58%"/></style:style>'
            . '<style:style style:name="T_SmallCaps" style:family="text"><style:text-properties fo:font-variant="small-caps"/></style:style>';
        // frames
        $s .= '<style:style style:name="fr_img" style:family="graphic"><style:graphic-properties style:wrap="none" style:vertical-pos="top" style:vertical-rel="baseline" fo:padding="0cm" fo:border="none"/></style:style>'
            . '<style:style style:name="fr_math" style:family="graphic"><style:graphic-properties style:wrap="none" style:vertical-pos="middle" style:vertical-rel="text" fo:padding="0cm" fo:border="none" style:run-through="foreground"/></style:style>';
        return $s;
    }

    private function stylesXml(string $lang): string
    {
        [$language, $country] = array_pad(explode('-', $lang, 2), 2, '');
        $country = $country !== '' ? ' fo:country="' . self::esc(strtoupper($country)) . '"' : '';

        $headings = '';
        foreach ([1 => 18, 2 => 14, 3 => 12, 4 => 11, 5 => 11, 6 => 11] as $lv => $size) {
            $headings .= '<style:style style:name="Heading_20_' . $lv . '" style:display-name="Heading ' . $lv . '" style:family="paragraph" style:parent-style-name="Heading" style:next-style-name="Text_20_body" style:default-outline-level="' . $lv . '">'
                . '<style:paragraph-properties fo:margin-top="' . ($lv === 1 ? '0.5' : '0.35') . 'cm" fo:margin-bottom="0.15cm"/>'
                . '<style:text-properties fo:font-size="' . $size . 'pt" fo:font-weight="bold"' . ($lv >= 5 ? ' fo:font-style="italic"' : '') . '/></style:style>';
        }

        $bullets = '';
        $chars = ["\u{2022}", "\u{25E6}", "\u{25AA}"];
        for ($lv = 1; $lv <= 6; $lv++) {
            $pos = number_format(0.8 * $lv, 2, '.', '');
            $bullets .= '<text:list-level-style-bullet text:level="' . $lv . '" text:bullet-char="' . $chars[($lv - 1) % 3] . '">'
                . '<style:list-level-properties text:list-level-position-and-space-mode="label-alignment"><style:list-level-label-alignment text:label-followed-by="listtab" text:list-tab-stop-position="' . $pos . 'cm" fo:text-indent="-0.6cm" fo:margin-left="' . $pos . 'cm"/></style:list-level-properties>'
                . '</text:list-level-style-bullet>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<office:document-styles ' . self::NS . ' office:version="1.3">'
            . '<office:font-face-decls>'
            . '<style:font-face style:name="Liberation Serif" svg:font-family="&apos;Liberation Serif&apos;, &apos;Times New Roman&apos;, serif" style:font-family-generic="roman"/>'
            . '<style:font-face style:name="Liberation Mono" svg:font-family="&apos;Liberation Mono&apos;, &apos;Courier New&apos;, monospace" style:font-family-generic="modern"/>'
            . '</office:font-face-decls>'
            . '<office:styles>'
            . '<style:default-style style:family="paragraph"><style:paragraph-properties fo:hyphenation-ladder-count="no-limit" style:writing-mode="page"/>'
            . '<style:text-properties style:font-name="Liberation Serif" fo:font-size="11pt" fo:language="' . self::esc($language) . '"' . $country . '/></style:default-style>'
            . '<style:style style:name="Standard" style:family="paragraph" style:class="text"/>'
            . '<style:style style:name="Heading" style:family="paragraph" style:parent-style-name="Standard" style:next-style-name="Text_20_body" style:class="text">'
            . '<style:paragraph-properties fo:keep-with-next="always"/></style:style>'
            . '<style:style style:name="Text_20_body" style:display-name="Text body" style:family="paragraph" style:parent-style-name="Standard" style:class="text">'
            . '<style:paragraph-properties fo:margin-top="0cm" fo:margin-bottom="0.21cm" fo:line-height="115%" fo:text-align="justify" fo:orphans="2" fo:widows="2"/></style:style>'
            . $headings
            . '<style:style style:name="Title" style:family="paragraph" style:parent-style-name="Standard" style:class="chapter"><style:paragraph-properties fo:margin-top="1.2cm" fo:margin-bottom="0.5cm" fo:text-align="center"/><style:text-properties fo:font-size="22pt" fo:font-weight="bold"/></style:style>'
            . '<style:style style:name="Author" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:margin-bottom="0.1cm" fo:text-align="center"/><style:text-properties fo:font-size="12pt"/></style:style>'
            . '<style:style style:name="Date" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:margin-top="0.2cm" fo:margin-bottom="0.8cm" fo:text-align="center"/><style:text-properties fo:font-size="11pt" fo:font-style="italic"/></style:style>'
            . '<style:style style:name="Abstract" style:family="paragraph" style:parent-style-name="Text_20_body"><style:paragraph-properties fo:margin-left="1cm" fo:margin-right="1cm"/><style:text-properties fo:font-size="10pt"/></style:style>'
            . '<style:style style:name="Quotations" style:family="paragraph" style:parent-style-name="Text_20_body"><style:paragraph-properties fo:margin-left="1cm" fo:margin-right="1cm"/></style:style>'
            . '<style:style style:name="List_20_Contents" style:display-name="List Contents" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:margin-top="0cm" fo:margin-bottom="0.08cm" fo:text-align="start"/></style:style>'
            . '<style:style style:name="Definition_20_Term" style:display-name="Definition Term" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:margin-top="0.1cm" fo:keep-with-next="always"/><style:text-properties fo:font-weight="bold"/></style:style>'
            . '<style:style style:name="Definition" style:family="paragraph" style:parent-style-name="Text_20_body"><style:paragraph-properties fo:margin-left="1cm"/></style:style>'
            . '<style:style style:name="Preformatted_20_Text" style:display-name="Preformatted Text" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:margin-top="0.1cm" fo:margin-bottom="0.2cm" fo:padding="0.1cm" fo:background-color="#f2f2f2" fo:text-align="start"/><style:text-properties style:font-name="Liberation Mono" fo:font-size="9pt"/></style:style>'
            . '<style:style style:name="Table_20_Contents" style:display-name="Table Contents" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:margin-top="0cm" fo:margin-bottom="0cm" fo:text-align="start"/><style:text-properties fo:font-size="9.5pt"/></style:style>'
            . '<style:style style:name="Caption" style:family="paragraph" style:parent-style-name="Standard" style:class="extra"><style:paragraph-properties fo:margin-top="0.1cm" fo:margin-bottom="0.35cm" fo:text-align="center"/><style:text-properties fo:font-size="10pt" fo:font-style="italic"/></style:style>'
            . '<style:style style:name="Caption_20_Table" style:display-name="Caption Table" style:family="paragraph" style:parent-style-name="Caption"><style:paragraph-properties fo:margin-top="0.35cm" fo:margin-bottom="0.1cm" fo:keep-with-next="always"/></style:style>'
            . '<style:style style:name="Illustration" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:margin-top="0.2cm" fo:margin-bottom="0.1cm" fo:text-align="center"/></style:style>'
            . '<style:style style:name="Illustration_20_Captioned" style:display-name="Illustration Captioned" style:family="paragraph" style:parent-style-name="Illustration"><style:paragraph-properties fo:keep-with-next="always"/></style:style>'
            . '<style:style style:name="Math_20_Display" style:display-name="Math Display" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:margin-top="0.15cm" fo:margin-bottom="0.25cm" fo:text-align="center"/></style:style>'
            . '<style:style style:name="Footnote" style:family="paragraph" style:parent-style-name="Standard" style:class="extra"><style:paragraph-properties fo:margin-left="0cm" fo:margin-bottom="0.05cm"/><style:text-properties fo:font-size="9pt"/></style:style>'
            . '<style:style style:name="Horizontal_20_Line" style:display-name="Horizontal Line" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:margin-top="0.2cm" fo:margin-bottom="0.2cm" fo:border-bottom="0.5pt solid #000000"/></style:style>'
            . '<style:style style:name="Footer" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:text-align="center"/><style:text-properties fo:font-size="9pt"/></style:style>'
            . '<style:style style:name="Emphasis" style:family="text"><style:text-properties fo:font-style="italic"/></style:style>'
            . '<style:style style:name="Strong_20_Emphasis" style:display-name="Strong Emphasis" style:family="text"><style:text-properties fo:font-weight="bold"/></style:style>'
            . '<style:style style:name="Source_20_Text" style:display-name="Source Text" style:family="text"><style:text-properties style:font-name="Liberation Mono" fo:font-size="9.5pt"/></style:style>'
            . '<style:style style:name="Internet_20_link" style:display-name="Internet link" style:family="text"><style:text-properties fo:color="#000080" style:text-underline-style="solid" style:text-underline-width="auto" style:text-underline-color="font-color"/></style:style>'
            . '<text:list-style style:name="L_Bullet">' . $bullets . '</text:list-style>'
            . '</office:styles>'
            . '<office:automatic-styles>'
            . '<style:page-layout style:name="Mpm1"><style:page-layout-properties fo:page-width="21cm" fo:page-height="29.7cm" style:print-orientation="portrait" fo:margin-top="2.5cm" fo:margin-bottom="1.5cm" fo:margin-left="2.5cm" fo:margin-right="2.5cm"/>'
            . '<style:footer-style><style:header-footer-properties fo:min-height="0.6cm" fo:margin-top="0.5cm"/></style:footer-style></style:page-layout>'
            . '</office:automatic-styles>'
            . '<office:master-styles><style:master-page style:name="Standard" style:page-layout-name="Mpm1">'
            . '<style:footer><text:p text:style-name="Footer"><text:page-number text:select-page="current"/></text:p></style:footer></style:master-page></office:master-styles>'
            . '</office:document-styles>';
    }

    // ------------------------------------------------------------------
    // Packaging
    // ------------------------------------------------------------------

    /** @param array<string, string> $files */
    private function manifestXml(array $files): string
    {
        $m = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<manifest:manifest ' . self::NS . ' manifest:version="1.3">'
            . '<manifest:file-entry manifest:full-path="/" manifest:version="1.3" manifest:media-type="application/vnd.oasis.opendocument.text"/>';
        foreach ($files as $path => $_) {
            if (preg_match('#^Object (\d+)/content\.xml$#', $path, $o)) {
                $m .= '<manifest:file-entry manifest:full-path="Object ' . $o[1] . '/" manifest:media-type="application/vnd.oasis.opendocument.formula"/>';
                $m .= '<manifest:file-entry manifest:full-path="' . $path . '" manifest:media-type="text/xml"/>';
            } elseif (str_starts_with($path, 'Pictures/')) {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $m .= '<manifest:file-entry manifest:full-path="' . self::esc($path) . '" manifest:media-type="' . (self::MIME_BY_EXT[$ext] ?? 'application/octet-stream') . '"/>';
            } elseif ($path !== 'META-INF/manifest.xml') {
                $m .= '<manifest:file-entry manifest:full-path="' . $path . '" manifest:media-type="text/xml"/>';
            }
        }
        return $m . '</manifest:manifest>';
    }

    /** @param array<string, string> $files */
    private function zip(array $files): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'odt');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create ODT archive');
        }
        // The mimetype entry must come first and be stored uncompressed.
        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.text');
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
        foreach ($files as $path => $contents) {
            $zip->addFromString($path, $contents);
        }
        $zip->close();
        $data = (string) file_get_contents($tmp);
        unlink($tmp);
        return $data;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** XML-escapes and drops characters that are not allowed in XML 1.0. */
    private static function esc(string $s): string
    {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @return array<int, array{0:string, 1:string}> attributes normalised to [key, value] pairs */
    private function attrPairs(\Pandoc\AST\Attr $a): array
    {
        $pairs = [];
        foreach ($a->attributes as $k => $v) {
            $pairs[] = is_array($v) ? [(string) $v[0], (string) ($v[1] ?? '')] : [(string) $k, (string) $v];
        }
        return $pairs;
    }
}
