<?php

namespace Pandoc\Reader;

use Pandoc\AST\Alignment;
use Pandoc\AST\Attr;
use Pandoc\AST\Block;
use Pandoc\AST\BlockQuote;
use Pandoc\AST\BulletList;
use Pandoc\AST\Caption;
use Pandoc\AST\Cell;
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
use Pandoc\AST\ListAttributes;
use Pandoc\AST\ListNumberDelim;
use Pandoc\AST\ListNumberStyle;
use Pandoc\AST\Math;
use Pandoc\AST\MathType;
use Pandoc\AST\Meta;
use Pandoc\AST\Note;
use Pandoc\AST\OrderedList;
use Pandoc\AST\Pandoc;
use Pandoc\AST\Para;
use Pandoc\AST\Plain;
use Pandoc\AST\Row;
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
use Pandoc\AST\TableBody;
use Pandoc\AST\TableFoot;
use Pandoc\AST\TableHead;
use Pandoc\AST\Target;
use Pandoc\AST\Underline;
use Pandoc\Reader\Odt\MathmlToLatex;
use Pandoc\Reader\Odt\OdtStyles;

/**
 * Reader for OpenDocument text (.odt) files, from LibreOffice or from {@see \Pandoc\Writer\OdtWriter}.
 *
 * Formatting is resolved through the style chain, so only what differs from the paragraph's own
 * style becomes Strong/Emph/etc. Formulas are converted from their embedded MathML to LaTeX.
 * Meta: title and date are Inline[], author is Inline[][], lang is a string, cover-image a MediaBag key.
 */
class OdtReader implements ReaderInterface
{
    use HasMediaBag;

    private const MIME = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
        'webp' => 'image/webp', 'bmp' => 'image/bmp', 'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'wmf' => 'image/x-wmf',
        'emf' => 'image/x-emf', 'pdf' => 'application/pdf',
    ];

    private const INTERNAL_BOOKMARK = '/^(__Ref|_Toc|__DdeLink|__UnoMark|_GoBack|OLE_LINK|__RefHeading)/';
    private const CAPTION_LABEL = '/^\s*(Figure|Fig\.?|Illustration|Table|Tableau|Tabelle|Tabla|Abbildung|Figura|Algorithm|Algorithme|Algorithmus)\s*(\d+(?:[.\-]\d+)*)\s*[:.\-–—]?\s*/iu';

    private \ZipArchive $zip;
    private OdtStyles $st;
    private array $meta = [];
    /** @var array<string, bool> */
    private array $usedIds = [];
    private bool $bodySeen = false;
    private bool $frontAuthors = false;
    private int $cellDepth = 0;
    /** @var array{style:?string, level:int} */
    private array $listCtx = ['style' => null, 'level' => 0];

    public function __construct()
    {
        $this->initMediaBag();
    }

    /** @param string $source path of an .odt file, or its binary contents */
    public function read(string $source): Pandoc
    {
        $this->initMediaBag();
        $this->meta = [];
        $this->usedIds = [];
        $this->bodySeen = false;
        $this->frontAuthors = false;
        $this->cellDepth = 0;
        $this->listCtx = ['style' => null, 'level' => 0];

        $tmp = null;
        $path = $source;
        if (strlen($source) >= 4096 || str_contains($source, "\0") || !is_file($source)) {
            $tmp = tempnam(sys_get_temp_dir(), 'odtread');
            file_put_contents($tmp, $source);
            $path = $tmp;
        }
        try {
            $this->zip = new \ZipArchive();
            if ($this->zip->open($path) !== true) {
                throw new \RuntimeException('Not a valid ODT file');
            }
            $content = $this->dom('content.xml');
            if ($content === null) {
                throw new \RuntimeException('The ODT file has no content.xml');
            }
            $this->st = new OdtStyles();
            if (($styles = $this->dom('styles.xml')) !== null) {
                $this->st->load($styles);
            }
            $this->st->load($content);

            $this->readMeta($this->dom('meta.xml'));
            $x = OdtStyles::xpath($content);
            $text = $x->query('/office:document-content/office:body/office:text')->item(0);
            $blocks = $text instanceof \DOMElement ? $this->stripManualNumbers($this->assemble($this->items($text))) : [];
            if (!isset($this->meta['lang']) && $this->st->language !== null) {
                $this->meta['lang'] = $this->st->language;
            }
            $this->zip->close();
        } finally {
            if ($tmp !== null && is_file($tmp)) {
                unlink($tmp);
            }
        }
        return new Pandoc(new Meta($this->meta), $blocks, $this->mediaBag);
    }

    private function dom(string $name): ?\DOMDocument
    {
        $xml = $this->zip->getFromName($name);
        if ($xml === false) {
            return null;
        }
        $d = new \DOMDocument();
        return @$d->loadXML($xml, LIBXML_NONET) ? $d : null;
    }

    private function readMeta(?\DOMDocument $d): void
    {
        if ($d === null) {
            return;
        }
        $x = OdtStyles::xpath($d);
        $get = fn(string $q) => trim((string) $x->query($q)->item(0)?->textContent);
        if (($t = $get('//dc:title')) !== '') {
            $this->meta['title'] = $this->words($t);
        }
        if (($a = $get('//dc:creator')) !== '' || ($a = $get('//meta:initial-creator')) !== '') {
            $this->meta['author'] = [$this->words($a)];
        }
        // the file's own dates say when it was saved, not the document's date: only a visible Date paragraph counts
        if (($l = $get('//dc:language')) !== '') {
            $this->meta['lang'] = $l;
        }
    }

    /** @return Inline[] plain text as Str/Space */
    private function words(string $s): array
    {
        $out = [];
        foreach (preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if ($out) {
                $out[] = new Space();
            }
            $out[] = new Str($w);
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Block structure
    // ------------------------------------------------------------------

    private function a(\DOMElement $e, string $prefix, string $name): ?string
    {
        return OdtStyles::attr($e, $prefix, $name);
    }

    /** @return array<int, array<string, mixed>> */
    private function items(\DOMElement $parent): array
    {
        $items = [];
        foreach ($parent->childNodes as $c) {
            if (!$c instanceof \DOMElement) {
                continue;
            }
            switch ($c->localName) {
                case 'h':
                    foreach ($this->shapes($c) as $shape) {
                        array_push($items, ...$this->items($shape));
                    }
                    $h = $this->heading($c);
                    if ($h->content) {
                        $this->bodySeen = true;
                        $items[] = ['k' => 'block', 'b' => $h];
                    }
                    break;
                case 'p':
                    array_push($items, ...$this->paragraph($c));
                    break;
                case 'list':
                    $this->bodySeen = true;
                    $list = $this->listBlock($c);
                    // a list made of headings (Word numbers headings that way) is dissolved into its blocks
                    foreach (is_array($list) ? $list : [$list] as $b) {
                        $items[] = ['k' => 'block', 'b' => $b];
                    }
                    break;
                case 'table':
                    $this->bodySeen = true;
                    $items[] = $this->table($c);
                    break;
                case 'section':
                case 'index-body':
                    array_push($items, ...$this->items($c));
                    break;
                case 'bibliography':
                    foreach ($c->getElementsByTagNameNS(OdtStyles::NS['text'], 'index-body') as $body) {
                        array_push($items, ...$this->items($body));
                    }
                    break;
                case 'frame':
                    if (($img = $this->frameInline($c)) !== null) {
                        $this->bodySeen = true;
                        $items[] = ['k' => 'imgpara', 'b' => new Para([$img])];
                    }
                    break;
                default:
                    // table-of-content, indexes, declarations, tracked changes, ...
                    break;
            }
        }
        return $items;
    }

    private function heading(\DOMElement $h): Header
    {
        $level = max(1, min(6, (int) ($this->a($h, 'text', 'outline-level') ?? 1)));
        $inlines = $this->inlineContent($h, null);
        $id = null;
        foreach ($inlines as $k => $i) {
            if ($i instanceof Span && !$i->content && $i->attr->identifier !== '') {
                $id ??= $i->attr->identifier;
                unset($inlines[$k]);
            }
        }
        $inlines = $this->trimInlines(array_values($inlines));
        $id = $id !== null ? $this->claimId($id) : $this->autoId($inlines);
        return new Header($level, new Attr($id), $inlines);
    }

    /**
     * Headings numbered by hand ("1 Introduction", "2.1 Method", "A.1 Details") lose their number, which is
     * kept in a "number" attribute, so that LaTeX's own numbering does not double it; their level becomes
     * the depth of the number. This only happens when the numbering is clearly systematic: most headings
     * carry a number and the top-level numbers go up. Letters count only as an appendix sequence (A, B, ...
     * or A.1), so that a title such as "A Study of X" is left alone. Documents whose numbers come from
     * LibreOffice's automatic numbering have none in the text and are not touched.
     *
     * @param Block[] $blocks
     * @return Block[]
     */
    private function stripManualNumbers(array $blocks): array
    {
        $headings = array_filter($blocks, fn($b) => $b instanceof Header);
        if (count($headings) < 3) {
            return $blocks;
        }
        $cands = [];
        foreach ($headings as $k => $h) {
            $plain = $this->plain($h->content);
            if (preg_match('/^((?:\d+|[A-Z])(?:\.\d+)*)\.?\s+(\S.*)$/u', $plain, $m)) {
                $cands[$k] = [
                    'number' => $m[1],
                    'len' => mb_strlen($plain) - mb_strlen($m[2]),
                    'depth' => substr_count($m[1], '.') + 1,
                    'letter' => !ctype_digit($m[1][0]),
                ];
            }
        }
        // letters are accepted as an appendix sequence only
        $tops = array_values(array_filter($cands, fn($c) => $c['letter'] && $c['depth'] === 1));
        $hasSub = (bool) array_filter($cands, fn($c) => $c['letter'] && $c['depth'] > 1);
        $consecutive = true;
        foreach ($tops as $i => $c) {
            $consecutive = $consecutive && $c['number'] === chr(65 + $i);
        }
        if (!$consecutive || (count($tops) < 2 && !$hasSub)) {
            $cands = array_filter($cands, fn($c) => !$c['letter']);
        }
        if (count($cands) < 0.6 * count($headings)) {
            return $blocks;
        }
        $digits = array_values(array_map(fn($c) => (int) $c['number'], array_filter($cands, fn($c) => !$c['letter'] && $c['depth'] === 1)));
        $rising = 0;
        for ($i = 1; $i < count($digits); $i++) {
            $rising += $digits[$i] >= $digits[$i - 1] ? 1 : 0;
        }
        if (count($digits) > 1 && $rising < 0.8 * (count($digits) - 1)) {
            return $blocks;
        }
        foreach ($cands as $k => $c) {
            $h = $blocks[$k];
            $blocks[$k] = new Header(
                min(6, $c['depth']),
                new Attr($h->attr->identifier, $h->attr->classes, array_merge($h->attr->attributes, [['number', $c['number']]])),
                $this->trimInlines($this->stripPrefix($h->content, $c['len']))
            );
        }
        return $blocks;
    }

    private function claimId(string $id): string
    {
        $this->usedIds[$id] = true;
        return $id;
    }

    private function autoId(array $inlines): string
    {
        $text = mb_strtolower($this->plain($inlines));
        $text = preg_replace('/[^\p{L}\p{N}_\-.\s]/u', '', $text);
        $text = preg_replace('/^[^\p{L}]+/u', '', $text);
        $base = trim(preg_replace('/\s+/u', '-', trim($text)), '-') ?: 'section';
        $id = $base;
        for ($k = 1; isset($this->usedIds[$id]); $k++) {
            $id = $base . '-' . $k;
        }
        return $this->claimId($id);
    }

    /** @return array<int, array<string, mixed>> */
    private function paragraph(\DOMElement $p): array
    {
        $style = $this->a($p, 'text', 'style-name');
        $chain = $this->st->chain($style);
        $is = fn(string ...$n) => (bool) array_intersect($chain, $n);

        // a paragraph that only holds a text box (LibreOffice captions, framed figures)
        $shapes = $this->shapes($p);
        if ($shapes && trim($this->rawNoFrames($p)) === '') {
            $this->bodySeen = true;
            $items = [];
            foreach ($shapes as $shape) {
                array_push($items, ...$this->items($shape));
            }
            return $items;
        }
        if ($is('contents heading', 'index heading') || preg_match('/^(contents|index|user index|illustration index|table index) ?\d*$/', implode('|', $chain))
            || array_filter($chain, fn($c) => preg_match('/^(contents|index|illustration index|table index) \d$/', $c))) {
            return [];
        }

        // front matter written by OdtWriter: Title / Author / Date paragraphs
        if (!$this->bodySeen && $is('title', 'author', 'date', 'subtitle')) {
            $in = $this->inlineContent($p, $style);
            if ($is('title')) {
                $this->meta['title'] = $in;
            } elseif ($is('author')) {
                if (!$this->frontAuthors) {
                    $this->meta['author'] = [];
                    $this->frontAuthors = true;
                }
                $this->meta['author'][] = $in;
            } elseif ($is('date')) {
                $this->meta['date'] = $in;
            }
            return [];
        }
        if (preg_match('/^heading (\d)$/', implode('|', array_filter($chain, fn($c) => str_starts_with($c, 'heading '))), $m)) {
            $this->bodySeen = true;
            $h = $this->heading($p);
            return [['k' => 'block', 'b' => new Header((int) $m[1], $h->attr, $h->content)]];
        }
        if ($is('horizontal line')) {
            return [['k' => 'hr']];
        }
        if ($is('preformatted text', 'source code', 'source text block')) {
            $this->bodySeen = true;
            return [['k' => 'code', 't' => $this->rawText($p)]];
        }
        if (preg_match('/^Alg(\d)$/', (string) $style, $m)) {
            $this->bodySeen = true;
            return [['k' => 'alg', 'lvl' => (int) $m[1], 'in' => $this->inlineContent($p, $style)]];
        }

        $inlines = $this->inlineContent($p, $style);
        if (!$inlines) {
            return [];
        }
        if ($is('quotations', 'quotation', 'block quotation')) {
            $this->bodySeen = true;
            return [['k' => 'quote', 'bs' => [new Para($inlines)]]];
        }
        if ($is('definition term')) {
            return [['k' => 'defterm', 'in' => $inlines]];
        }
        if ($is('definition')) {
            return [['k' => 'defbody', 'bs' => [new Para($inlines)]]];
        }
        if ($is('abstract')) {
            $this->bodySeen = true;
            return [['k' => 'abstract', 'b' => new Para($inlines)]];
        }
        if ($is('caption', 'illustration', 'table', 'figure', 'drawing', 'caption table', 'caption figure')
            && ($cap = $this->caption($inlines)) !== null) {
            $this->bodySeen = true;
            return [$cap];
        }

        // a cover picture written by OdtWriter, before the title
        if (!$this->bodySeen && count($inlines) === 1 && $inlines[0] instanceof Image && $this->isCover($p)) {
            $this->meta['cover-image'] = $inlines[0]->target->url;
            return [];
        }
        $this->bodySeen = true;

        if ($this->onlyImages($inlines)) {
            return [['k' => 'imgpara', 'b' => new Para($inlines)]];
        }
        if ($this->cellDepth === 0 && $this->onlyMath($inlines)) { // in a table cell a lone formula stays inline
            foreach ($inlines as $k => $i) {
                if ($i instanceof Math) {
                    $inlines[$k] = new Math(MathType::DisplayMath, $i->text);
                }
            }
            return [['k' => 'block', 'b' => new Para($this->dropSpaces($inlines))]];
        }
        // a bibliography entry: bookmarks (container "refs", entry "ref-key") followed by "[n] ..."
        $lead = 0;
        $refId = null;
        while (isset($inlines[$lead]) && $inlines[$lead] instanceof Span && !$inlines[$lead]->content) {
            if (str_starts_with($inlines[$lead]->attr->identifier, 'ref-')) {
                $refId = $inlines[$lead]->attr->identifier;
            }
            $lead++;
        }
        if ($refId !== null) {
            return [['k' => 'ref', 'id' => $refId, 'b' => new Para(array_slice($inlines, $lead))]];
        }
        return [['k' => 'block', 'b' => new Para($inlines)]];
    }

    /** Text boxes and custom shapes inside $e that hold paragraphs or headings (outermost only). */
    private function shapes(\DOMElement $e): array
    {
        $found = [];
        foreach (['text-box', 'custom-shape'] as $tag) {
            foreach ($e->getElementsByTagNameNS(OdtStyles::NS['draw'], $tag) as $shape) {
                $nested = false;
                for ($anc = $shape->parentNode; $anc instanceof \DOMElement && $anc !== $e; $anc = $anc->parentNode) {
                    if ($anc->namespaceURI === OdtStyles::NS['draw'] && in_array($anc->localName, ['text-box', 'custom-shape'], true)) {
                        $nested = true;
                        break;
                    }
                }
                $holdsText = false;
                foreach ($shape->childNodes as $c) {
                    if ($c instanceof \DOMElement && in_array($c->localName, ['p', 'h', 'list', 'table'], true)) {
                        $holdsText = true;
                        break;
                    }
                }
                if (!$nested && $holdsText) {
                    $found[] = $shape;
                }
            }
        }
        return $found;
    }

    /** @return array{0:string, 1:Inline[]} the first bookmark id among the inlines, and the inlines without bookmarks */
    private function takeIds(array $inlines): array
    {
        $id = '';
        $rest = [];
        foreach ($inlines as $i) {
            if ($i instanceof Span && !$i->content && $i->attr->identifier !== '') {
                $id = $id !== '' ? $id : $i->attr->identifier;
            } else {
                $rest[] = $i;
            }
        }
        return [$id, $this->trimInlines($rest)];
    }

    private function isCover(\DOMElement $p): bool
    {
        foreach ($p->getElementsByTagNameNS(OdtStyles::NS['svg'], 'desc') as $d) {
            return trim($d->textContent) === 'Cover';
        }
        return false;
    }

    private function onlyImages(array $in): bool
    {
        $found = false;
        foreach ($in as $i) {
            if ($i instanceof Image) {
                $found = true;
            } elseif (!($i instanceof Space || $i instanceof SoftBreak || ($i instanceof Span && !$i->content))) {
                return false;
            }
        }
        return $found;
    }

    private function onlyMath(array $in): bool
    {
        $found = false;
        foreach ($in as $i) {
            if ($i instanceof Math) {
                $found = true;
            } elseif (!($i instanceof Space || $i instanceof SoftBreak || ($i instanceof Span && !$i->content))) {
                return false;
            }
        }
        return $found;
    }

    private function dropSpaces(array $in): array
    {
        return array_values(array_filter($in, fn($i) => !($i instanceof Space || $i instanceof SoftBreak)));
    }

    /** @return array<string, mixed>|null a caption item when the text starts with a label such as "Figure 3:" */
    private function caption(array $inlines): ?array
    {
        $plain = $this->plain($inlines);
        if (!preg_match(self::CAPTION_LABEL, $plain, $m)) {
            return null;
        }
        $kind = match (true) {
            (bool) preg_match('/^(table|tableau|tabelle|tabla)/i', $m[1]) => 'table',
            (bool) preg_match('/^algorithm/i', $m[1]) => 'algorithm',
            default => 'figure',
        };
        $id = '';
        $rest = [];
        foreach ($inlines as $i) {
            if ($i instanceof Span && !$i->content && $i->attr->identifier !== '') {
                $id = $id !== '' ? $id : $i->attr->identifier;
            } else {
                $rest[] = $i;
            }
        }
        $stripped = $this->stripPrefix($rest, mb_strlen($m[0]));
        return ['k' => 'caption', 'kind' => $kind, 'id' => $id, 'in' => $this->trimInlines($stripped), 'full' => $inlines];
    }

    /** Removes the first $n characters of plain text from the inlines. */
    private function stripPrefix(array $inlines, int $n): array
    {
        $out = [];
        foreach ($inlines as $i) {
            if ($n <= 0) {
                $out[] = $i;
                continue;
            }
            if ($i instanceof Str) {
                $len = mb_strlen($i->text);
                if ($len <= $n) {
                    $n -= $len;
                } else {
                    $out[] = new Str(mb_substr($i->text, $n));
                    $n = 0;
                }
            } elseif ($i instanceof Space || $i instanceof SoftBreak) {
                $n--;
            } elseif (is_object($i) && property_exists($i, 'content') && is_array($i->content) && !($i instanceof Note)) {
                $before = mb_strlen($this->plain($i->content));
                $inner = $this->stripPrefix($i->content, $n);
                $n = max(0, $n - $before);
                if ($inner) {
                    $out[] = $this->rewrap($i, $inner);
                }
            } else {
                $out[] = $i;
            }
        }
        return $out;
    }

    private function rewrap(Inline $i, array $content): Inline
    {
        return match (true) {
            $i instanceof Strong => new Strong($content),
            $i instanceof Emph => new Emph($content),
            $i instanceof Underline => new Underline($content),
            $i instanceof Strikeout => new Strikeout($content),
            $i instanceof Superscript => new Superscript($content),
            $i instanceof Subscript => new Subscript($content),
            $i instanceof SmallCaps => new SmallCaps($content),
            $i instanceof Span => new Span($i->attr, $content),
            $i instanceof Link => new Link($i->attr, $content, $i->target),
            default => $i,
        };
    }

    /** @param array<int, array<string, mixed>> $items @return Block[] */
    private function assemble(array $items): array
    {
        $blocks = [];
        $used = [];
        $n = count($items);
        for ($i = 0; $i < $n; $i++) {
            if (isset($used[$i])) {
                continue;
            }
            $it = $items[$i];
            switch ($it['k']) {
                case 'block':
                    $blocks[] = $it['b'];
                    break;
                case 'hr':
                    $blocks[] = new HorizontalRule();
                    break;
                case 'imgpara':
                    [$pictureId, $pictures] = $this->takeIds($it['b']->content);
                    $next = $items[$i + 1] ?? null;
                    if ($next !== null && $next['k'] === 'caption' && $next['kind'] !== 'table' && !isset($used[$i + 1])) {
                        $used[$i + 1] = true;
                        $blocks[] = new Figure(new Attr($next['id'] !== '' ? $next['id'] : $pictureId), new Caption(null, [new Plain($next['in'])]), [new Plain($pictures)]);
                    } else {
                        $blocks[] = $pictureId !== '' ? new Para(array_merge([new Span(new Attr($pictureId), [])], $pictures)) : $it['b'];
                    }
                    break;
                case 'figgrid':
                    // consecutive rows of panels belong to one figure
                    $subs = $it['b']->content;
                    while (isset($items[$i + 1]) && $items[$i + 1]['k'] === 'figgrid') {
                        array_push($subs, ...$items[++$i]['b']->content);
                    }
                    $next = $items[$i + 1] ?? null;
                    if ($next !== null && $next['k'] === 'caption' && !isset($used[$i + 1])) {
                        $used[$i + 1] = true;
                        $blocks[] = new Figure(new Attr($next['id']), new Caption(null, [new Plain($next['in'])]), $subs);
                    } else {
                        $blocks[] = new Figure(new Attr(), new Caption(), $subs);
                    }
                    break;
                case 'table':
                    $t = $it['b'];
                    $prev = $items[$i - 1] ?? null;
                    $next = $items[$i + 1] ?? null;
                    $cap = null;
                    if ($prev !== null && $prev['k'] === 'caption' && $prev['kind'] === 'table' && !isset($used[$i - 1]) && ($blocks[array_key_last($blocks)] ?? null) !== null) {
                        $cap = $prev;
                        array_pop($blocks); // the caption paragraph was emitted as plain text; replace it
                    } elseif ($next !== null && $next['k'] === 'caption' && !isset($used[$i + 1])) {
                        $cap = $next;
                        $used[$i + 1] = true;
                    }
                    $blocks[] = $cap === null ? $t : new Table(
                        new Attr($cap['id']),
                        new Caption(null, [new Plain($cap['in'])]),
                        $t->colSpecs,
                        $t->head,
                        $t->bodies,
                        $t->foot
                    );
                    break;
                case 'caption':
                    // a caption directly before a table is attached when the table comes; otherwise plain text
                    $next = $items[$i + 1] ?? null;
                    if ($it['kind'] === 'table' && $next !== null && $next['k'] === 'table') {
                        $blocks[] = new Para($it['full']); // popped again by the table case
                    } else {
                        $blocks[] = new Para($it['full']);
                    }
                    break;
                case 'code':
                    $lines = [$it['t']];
                    while (isset($items[$i + 1]) && $items[$i + 1]['k'] === 'code') {
                        $lines[] = $items[++$i]['t'];
                    }
                    $blocks[] = new CodeBlock(new Attr(), implode("\n", $lines));
                    break;
                case 'quote':
                    $bs = $it['bs'];
                    while (isset($items[$i + 1]) && $items[$i + 1]['k'] === 'quote') {
                        array_push($bs, ...$items[++$i]['bs']);
                    }
                    $blocks[] = new BlockQuote($bs);
                    break;
                case 'defterm':
                case 'defbody':
                    $defs = [];
                    for (; $i < $n && in_array($items[$i]['k'], ['defterm', 'defbody'], true); $i++) {
                        if ($items[$i]['k'] === 'defterm') {
                            $defs[] = [$items[$i]['in'], []];
                        } elseif ($defs) {
                            $defs[array_key_last($defs)][1][] = [$items[$i]['b'] ?? $items[$i]['bs'][0]];
                            $defs[array_key_last($defs)][1] = array_values($defs[array_key_last($defs)][1]);
                        }
                    }
                    $i--;
                    $blocks[] = new DefinitionList($defs);
                    break;
                case 'alg':
                    $lines = [];
                    for (; $i < $n && $items[$i]['k'] === 'alg'; $i++) {
                        $lines[] = new Div(new Attr('', ['alg-line', 'alg-i' . $items[$i]['lvl']]), [new Plain($items[$i]['in'])]);
                    }
                    $i--;
                    $blocks[] = new Div(new Attr('', ['algorithmic']), $lines);
                    break;
                case 'abstract':
                    $paras = [];
                    for (; $i < $n && $items[$i]['k'] === 'abstract'; $i++) {
                        $paras[] = $items[$i]['b'];
                    }
                    $i--;
                    $blocks[] = new Div(new Attr('', ['abstract']), $paras);
                    break;
                case 'ref':
                    $entries = [];
                    for (; $i < $n && $items[$i]['k'] === 'ref'; $i++) {
                        $entries[] = new Div(new Attr($items[$i]['id'], ['reference']), [$items[$i]['b']]);
                    }
                    $i--;
                    $blocks[] = new Div(new Attr('refs', ['references']), $entries);
                    break;
            }
        }
        return $blocks;
    }

    // ------------------------------------------------------------------
    // Lists and tables
    // ------------------------------------------------------------------

    /** @return Block|Block[] a list, or the blocks of a list that holds headings */
    private function listBlock(\DOMElement $l): Block|array
    {
        $saved = $this->listCtx;
        $style = $this->a($l, 'text', 'style-name') ?? $saved['style'];
        $level = $saved['level'] + 1;
        $this->listCtx = ['style' => $style, 'level' => $level];

        $items = [];
        $start = null;
        foreach ($l->childNodes as $li) {
            if (!$li instanceof \DOMElement || !in_array($li->localName, ['list-item', 'list-header'], true)) {
                continue;
            }
            if ($start === null) {
                $sv = $this->a($li, 'text', 'start-value');
                $start = $sv !== null ? (int) $sv : null;
            }
            $blocks = $this->assemble($this->items($li));
            $items[] = count($blocks) === 1 && $blocks[0] instanceof Para ? [new Plain($blocks[0]->content)] : $blocks;
        }
        $this->listCtx = $saved;

        foreach ($items as $blocks) {
            foreach ($blocks as $b) {
                if ($b instanceof Header) {
                    $flat = [];
                    foreach ($items as $item) {
                        foreach ($item as $x) {
                            $flat[] = $x instanceof Plain ? new Para($x->content) : $x;
                        }
                    }
                    return $flat;
                }
            }
        }

        $info = $this->st->listLevel($style, $level);
        if ($info === null || $info['type'] !== 'number') {
            return new BulletList($items);
        }
        $fmt = match ($info['format']) {
            'a' => ListNumberStyle::LowerAlpha,
            'A' => ListNumberStyle::UpperAlpha,
            'i' => ListNumberStyle::LowerRoman,
            'I' => ListNumberStyle::UpperRoman,
            default => ListNumberStyle::Decimal,
        };
        $delim = match (true) {
            $info['prefix'] === '(' && $info['suffix'] === ')' => ListNumberDelim::TwoParens,
            $info['suffix'] === ')' => ListNumberDelim::OneParen,
            default => ListNumberDelim::Period,
        };
        return new OrderedList(new ListAttributes($start ?? $info['start'], $fmt, $delim), $items);
    }

    /** @return array<string, mixed> a 'table' item, or a 'figgrid' item for a row of pictures */
    private function table(\DOMElement $t): array
    {
        $ns = OdtStyles::NS['table'];
        $cols = 0;
        foreach ($t->getElementsByTagNameNS($ns, 'table-column') as $c) {
            $cols += (int) ($this->a($c, 'table', 'number-columns-repeated') ?? 1);
        }
        $headRows = [];
        $bodyRows = [];
        $walk = function (\DOMElement $parent, bool $head) use (&$walk, &$headRows, &$bodyRows): void {
            foreach ($parent->childNodes as $r) {
                if (!$r instanceof \DOMElement) {
                    continue;
                }
                if ($r->localName === 'table-row') {
                    $row = $this->tableRow($r);
                    $head ? $headRows[] = $row : $bodyRows[] = $row;
                } elseif (in_array($r->localName, ['table-header-rows', 'table-row-group', 'table-rows'], true)) {
                    $walk($r, $head || $r->localName === 'table-header-rows');
                }
            }
        };
        $walk($t, false);

        if (($grid = $this->figureGrid($t)) !== null) {
            return ['k' => 'figgrid', 'b' => $grid];
        }

        $aligns = [];
        foreach (array_merge($headRows, $bodyRows) as $row) {
            $c = 0;
            foreach ($row->cells as $cell) {
                $aligns[$c] ??= $cell->align;
                $c += $cell->colSpan;
            }
        }
        $width = max($cols, count($aligns));
        $colSpecs = [];
        for ($c = 0; $c < $width; $c++) {
            $colSpecs[] = [$aligns[$c] ?? Alignment::AlignDefault, null];
        }
        return ['k' => 'table', 'b' => new Table(
            new Attr($this->bookmarkInside($t)),
            new Caption(),
            $colSpecs,
            new TableHead(new Attr(), $headRows),
            [new TableBody(new Attr(), 0, [], $bodyRows)],
            new TableFoot(new Attr(), [])
        )];
    }

    private function bookmarkInside(\DOMElement $e): string
    {
        return '';
    }

    private function tableRow(\DOMElement $r): Row
    {
        $cells = [];
        foreach ($r->childNodes as $c) {
            if (!$c instanceof \DOMElement || $c->localName !== 'table-cell') {
                continue;
            }
            $repeat = min(50, (int) ($this->a($c, 'table', 'number-columns-repeated') ?? 1));
            $this->cellDepth++;
            $blocks = $this->assemble($this->items($c));
            $this->cellDepth--;
            $blocks = array_map(fn($b) => $b instanceof Para ? new Plain($b->content) : $b, $blocks);
            $align = Alignment::AlignDefault;
            foreach ($c->getElementsByTagNameNS(OdtStyles::NS['text'], 'p') as $p) {
                $al = $this->st->para($this->a($p, 'text', 'style-name'))['align'] ?? null;
                $align = match ($al) {
                    'center' => Alignment::AlignCenter,
                    'right' => Alignment::AlignRight,
                    'left' => Alignment::AlignLeft,
                    default => Alignment::AlignDefault,
                };
                break;
            }
            $cell = new Cell(
                new Attr(),
                $align,
                max(1, (int) ($this->a($c, 'table', 'number-rows-spanned') ?? 1)),
                max(1, (int) ($this->a($c, 'table', 'number-columns-spanned') ?? 1)),
                $blocks
            );
            for ($k = 0; $k < ($blocks ? 1 : $repeat); $k++) {
                $cells[] = $cell;
            }
        }
        return new Row(new Attr(), $cells);
    }

    /** A one-row table whose cells each hold a picture becomes sub-figures (OdtWriter's side-by-side layout). */
    private function figureGrid(\DOMElement $t): ?Figure
    {
        $rows = [];
        foreach ($t->getElementsByTagNameNS(OdtStyles::NS['table'], 'table-row') as $r) {
            $rows[] = $r;
        }
        if (count($rows) !== 1) {
            return null;
        }
        $subs = [];
        foreach ($rows[0]->childNodes as $c) {
            if (!$c instanceof \DOMElement || $c->localName !== 'table-cell') {
                continue;
            }
            if ($c->getElementsByTagNameNS(OdtStyles::NS['draw'], 'image')->length === 0) {
                return null;
            }
            $items = $this->items($c);
            $kinds = array_column($items, 'k');
            if ($kinds !== ['imgpara'] && $kinds !== ['imgpara', 'block'] && $kinds !== ['imgpara', 'caption']) {
                return null;
            }
            [$panelId, $pictures] = $this->takeIds($items[0]['b']->content);
            $capIn = [];
            if (isset($items[1])) {
                $capIn = $items[1]['k'] === 'caption' ? $items[1]['in'] : ($items[1]['b'] instanceof Para ? $items[1]['b']->content : []);
                $plain = $this->plain($capIn);
                if (preg_match('/^\s*\(([a-z])\)\s*/', $plain, $m)) {
                    $capIn = $this->trimInlines($this->stripPrefix($capIn, mb_strlen($m[0])));
                }
            }
            $f = new Figure(new Attr($panelId), new Caption(null, $capIn ? [new Plain($capIn)] : []), [new Plain($pictures)]);
            $caption = $f->caption->content;
            $attrs = [];
            foreach ($pictures as $i) {
                if ($i instanceof Image) {
                    foreach ($i->attr->attributes as $pair) {
                        if ($pair[0] === 'width') {
                            $attrs[] = $pair;
                        }
                    }
                }
            }
            $subs[] = new Figure(new Attr($f->attr->identifier, ['subfigure'], $attrs), new Caption(null, $caption), $f->content);
        }
        return $subs ? new Figure(new Attr(), new Caption(), $subs) : null;
    }

    // ------------------------------------------------------------------
    // Inlines
    // ------------------------------------------------------------------

    /** Inlines of a paragraph or heading, with formatting relative to the paragraph's own style. */
    private function inlineContent(\DOMElement $p, ?string $style): array
    {
        $base = $this->st->text($style);
        if ($this->st->isAutomatic($style)) {
            $base = $this->st->text($this->st->parent($style));
        }
        $out = $this->inlines($p, $base);
        // direct formatting of the whole paragraph (an automatic paragraph style)
        if ($this->st->isAutomatic($style)) {
            $out = $this->wrap($out, $this->st->text($style), $base);
        }
        return $this->trimInlines($this->mergeAdjacent($out));
    }

    private function push(array &$out, Inline $i): void
    {
        if ($i instanceof Space && $out && (end($out) instanceof Space || end($out) instanceof LineBreak || end($out) instanceof SoftBreak)) {
            return;
        }
        $out[] = $i;
    }

    private function addText(array &$out, string $s): void
    {
        foreach (preg_split('/([ \t\r\n]+)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
            if (trim($part) === '') {
                $this->push($out, new Space());
            } else {
                $out[] = new Str($part);
            }
        }
    }

    /** @return Inline[] */
    private function inlines(\DOMNode $node, array $active): array
    {
        $out = [];
        foreach ($node->childNodes as $c) {
            if ($c instanceof \DOMText) {
                $this->addText($out, $c->nodeValue);
                continue;
            }
            if (!$c instanceof \DOMElement) {
                continue;
            }
            switch ($c->localName) {
                case 's':
                case 'tab':
                    $this->push($out, new Space());
                    break;
                case 'line-break':
                    $out[] = new LineBreak();
                    break;
                case 'span':
                    $style = $this->a($c, 'text', 'style-name');
                    $own = $this->st->text($style);
                    $inner = $this->inlines($c, array_replace($active, $own));
                    array_push($out, ...$this->wrap($inner, array_replace($active, $own), $active));
                    break;
                case 'a':
                    $inner = $this->inlines($c, $active);
                    array_push($out, ...$this->link($c, $inner));
                    break;
                case 'note':
                    $body = $c->getElementsByTagNameNS(OdtStyles::NS['text'], 'note-body')->item(0);
                    $blocks = $body instanceof \DOMElement ? $this->assemble($this->items($body)) : [];
                    $out[] = new Note($blocks);
                    break;
                case 'bookmark':
                case 'bookmark-start':
                    $name = $this->a($c, 'text', 'name') ?? '';
                    if ($name !== '' && !preg_match(self::INTERNAL_BOOKMARK, $name)) {
                        $out[] = new Span(new Attr($this->claimId($name)), []);
                    }
                    break;
                case 'frame':
                    if (($i = $this->frameInline($c)) !== null) {
                        $out[] = $i;
                    }
                    break;
                case 'sequence':
                    $this->addText($out, $c->textContent);
                    break;
                case 'bookmark-ref':
                case 'reference-ref':
                case 'sequence-ref':
                    $name = ($this->a($c, 'text', 'ref-name') ?? '');
                    $label = trim($c->textContent);
                    $out[] = $name !== '' && $label !== ''
                        ? new Link(new Attr('', [], [['reference-type', 'ref'], ['reference', $name]]), [new Str($label)], new Target('#' . $name))
                        : new Str($label);
                    break;
                case 'ruby':
                case 'meta':
                case 'meta-field':
                case 'user-defined':
                case 'title':
                case 'subject':
                case 'initial-creator':
                case 'creator':
                    array_push($out, ...$this->inlines($c, $active));
                    break;
                case 'ruby-text':
                case 'annotation':
                case 'annotation-end':
                case 'tracked-changes':
                case 'change-start':
                case 'change-end':
                case 'change':
                case 'soft-page-break':
                case 'page-number':
                case 'bookmark-end':
                    break;
                default:
                    if ($c->namespaceURI === OdtStyles::NS['text']) {
                        array_push($out, ...$this->inlines($c, $active)); // fields and marks: keep their text
                    }
            }
        }
        return $out;
    }

    /** Wraps $inner for every property that is on in $props but was not on in $active. */
    private function wrap(array $inner, array $props, array $active): array
    {
        if (!$inner || !array_filter($inner, fn($i) => !($i instanceof Space))) {
            return $inner;
        }
        $on = fn(string $k) => !empty($props[$k]) && empty($active[$k]);
        if ($on('mono')) {
            return [new Code(new Attr(), $this->plain($inner))];
        }
        $lead = [];
        $trail = [];
        while ($inner && $inner[0] instanceof Space) {
            $lead[] = array_shift($inner);
        }
        while ($inner && end($inner) instanceof Space) {
            $trail[] = array_pop($inner);
        }
        if ($on('smallcaps')) {
            $inner = [new SmallCaps($inner)];
        }
        if (($props['position'] ?? null) !== ($active['position'] ?? null)) {
            $inner = match ($props['position'] ?? null) {
                'super' => [new Superscript($inner)],
                'sub' => [new Subscript($inner)],
                default => $inner,
            };
        }
        if ($on('strike')) {
            $inner = [new Strikeout($inner)];
        }
        if ($on('underline')) {
            $inner = [new Underline($inner)];
        }
        if ($on('italic')) {
            $inner = [new Emph($inner)];
        }
        if ($on('bold')) {
            $inner = [new Strong($inner)];
        }
        $attrs = [];
        $color = $props['color'] ?? null;
        if ($color !== null && $color !== ($active['color'] ?? null) && !in_array($color, ['#000000', '#ffffff'], true)) {
            $attrs[] = ['color', $color];
        }
        $bg = $props['background'] ?? null;
        if ($bg !== null && $bg !== ($active['background'] ?? null) && $bg !== '#ffffff') {
            $attrs[] = ['background-color', $bg];
        }
        if ($attrs) {
            $inner = [new Span(new Attr('', [], $attrs), $inner)];
        }
        return array_merge($lead, $inner, array_reverse($trail));
    }

    /** @return Inline[] */
    private function link(\DOMElement $a, array $inner): array
    {
        $href = $this->a($a, 'xlink', 'href') ?? '';
        if ($href === '') {
            return $inner;
        }
        if (str_starts_with($href, '#')) {
            $target = explode('|', substr($href, 1))[0];
            if (preg_match(self::INTERNAL_BOOKMARK, $target)) {
                return $inner;
            }
            $text = $this->plain($inner);
            $attrs = [];
            $classes = [];
            if (str_starts_with($target, 'ref-')) {
                $classes[] = 'citation';
            } elseif (preg_match('/^[A-Z]?\d+(?:\.\d+)*[a-z]?$/', trim($text))) {
                $attrs = [['reference-type', 'ref'], ['reference', $target]];
            }
            return [new Link(new Attr('', $classes, $attrs), $inner, new Target('#' . $target))];
        }
        return [new Link(new Attr(), $inner, new Target($href))];
    }

    // ------------------------------------------------------------------
    // Frames: pictures and formulas
    // ------------------------------------------------------------------

    private function frameInline(\DOMElement $f): ?Inline
    {
        foreach ($f->childNodes as $c) {
            if (!$c instanceof \DOMElement) {
                continue;
            }
            if ($c->localName === 'image') {
                return $this->image($f, $c);
            }
            if ($c->localName === 'object') {
                return $this->formula($c);
            }
        }
        return null;
    }

    private function image(\DOMElement $frame, \DOMElement $img): ?Inline
    {
        $href = ltrim($this->a($img, 'xlink', 'href') ?? '', './');
        if ($href === '' || preg_match('#^[a-z]+:#i', $href)) {
            return null;
        }
        $bytes = $this->zip->getFromName($href);
        if ($bytes === false) {
            return null;
        }
        $ext = strtolower(pathinfo($href, PATHINFO_EXTENSION));
        if ($this->mediaBag->lookup($href) === null) {
            $this->mediaBag->insert($href, self::MIME[$ext] ?? 'application/octet-stream', $bytes);
        }
        $alt = '';
        foreach (['title', 'desc'] as $tag) {
            foreach ($frame->getElementsByTagNameNS(OdtStyles::NS['svg'], $tag) as $d) {
                $alt = $alt !== '' ? $alt : trim($d->textContent);
            }
        }
        $attrs = [];
        $w = OdtStyles::cm($this->a($frame, 'svg', 'width') ?? '');
        if ($w !== null && $w > 0) {
            $pct = $w / $this->st->textWidthCm * 100;
            $attrs[] = ['width', $pct <= 100.5 ? round(min($pct, 100), 1) . '%' : round($w, 2) . 'cm'];
        }
        return new Image(new Attr('', [], $attrs), $alt !== '' && $alt !== 'Cover' ? [new Str($alt)] : [], new Target($href));
    }

    private function formula(\DOMElement $obj): ?Inline
    {
        $href = trim(ltrim($this->a($obj, 'xlink', 'href') ?? '', './'), '/');
        $xml = $href !== '' ? $this->zip->getFromName($href . '/content.xml') : false;
        if ($xml === false) {
            return null;
        }
        $d = new \DOMDocument();
        if (!@$d->loadXML($xml, LIBXML_NONET)) {
            return null;
        }
        $tex = MathmlToLatex::fromDocument($d);
        return $tex === '' ? null : new Math(MathType::InlineMath, $tex);
    }

    // ------------------------------------------------------------------
    // Text helpers
    // ------------------------------------------------------------------

    /** Text of a preformatted paragraph, keeping spaces, tabs and line breaks. */
    private function rawText(\DOMNode $n): string
    {
        $out = '';
        foreach ($n->childNodes as $c) {
            if ($c instanceof \DOMText) {
                $out .= preg_replace('/[\r\n]+/', ' ', $c->nodeValue);
            } elseif ($c instanceof \DOMElement) {
                $out .= match ($c->localName) {
                    's' => str_repeat(' ', (int) ($this->a($c, 'text', 'c') ?? 1)),
                    'tab' => "\t",
                    'line-break' => "\n",
                    default => $this->rawText($c),
                };
            }
        }
        return $out;
    }

    private function rawNoFrames(\DOMElement $p): string
    {
        $out = '';
        foreach ($p->childNodes as $c) {
            if ($c instanceof \DOMText) {
                $out .= $c->nodeValue;
            } elseif ($c instanceof \DOMElement && $c->localName !== 'frame' && $c->localName !== 'bookmark' && $c->localName !== 'bookmark-start' && $c->localName !== 'bookmark-end') {
                $out .= $c->textContent;
            }
        }
        return $out;
    }

    /** @param Inline[] $in */
    private function plain(array $in): string
    {
        $s = '';
        foreach ($in as $i) {
            $s .= match (true) {
                $i instanceof Str => $i->text,
                $i instanceof Space, $i instanceof SoftBreak, $i instanceof LineBreak => ' ',
                $i instanceof Code, $i instanceof Math => $i->text,
                $i instanceof Note => '',
                is_object($i) && property_exists($i, 'content') && is_array($i->content) => $this->plain($i->content),
                default => '',
            };
        }
        return $s;
    }

    private function trimInlines(array $in): array
    {
        while ($in && ($in[0] instanceof Space || $in[0] instanceof SoftBreak || $in[0] instanceof LineBreak)) {
            array_shift($in);
        }
        while ($in && (end($in) instanceof Space || end($in) instanceof SoftBreak || end($in) instanceof LineBreak)) {
            array_pop($in);
        }
        return array_values($in);
    }

    /** Merges neighbouring runs with the same formatting: Strong[a] Strong[b] and Strong[a] Space Strong[b]. */
    private function mergeAdjacent(array $in): array
    {
        $out = [];
        $count = count($in);
        for ($i = 0; $i < $count; $i++) {
            $cur = $in[$i];
            if ($this->mergeable($cur)) {
                while (true) {
                    $next = $in[$i + 1] ?? null;
                    $afterSpace = ($next instanceof Space && isset($in[$i + 2]) && $this->sameKind($cur, $in[$i + 2])) ? $in[$i + 2] : null;
                    if ($next !== null && $this->sameKind($cur, $next)) {
                        $cur = $this->rewrap($cur, array_merge($cur->content, $next->content));
                        $i++;
                    } elseif ($afterSpace !== null) {
                        $cur = $this->rewrap($cur, array_merge($cur->content, [new Space()], $afterSpace->content));
                        $i += 2;
                    } else {
                        break;
                    }
                }
                $cur = $this->rewrap($cur, $this->mergeAdjacent($cur->content));
            }
            $out[] = $cur;
        }
        return $out;
    }

    private function mergeable(Inline $i): bool
    {
        return $i instanceof Strong || $i instanceof Emph || $i instanceof Underline || $i instanceof Strikeout
            || $i instanceof Superscript || $i instanceof Subscript || $i instanceof SmallCaps;
    }

    private function sameKind(Inline $a, Inline $b): bool
    {
        return $this->mergeable($a) && $a::class === $b::class;
    }
}
