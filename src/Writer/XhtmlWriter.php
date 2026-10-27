<?php

namespace Pandoc\Writer;

use Pandoc\AST\Alignment;
use Pandoc\AST\Attr;
use Pandoc\AST\Block;
use Pandoc\AST\BlockQuote;
use Pandoc\AST\BulletList;
use Pandoc\AST\Caption;
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
use Pandoc\AST\ListNumberStyle;
use Pandoc\AST\Math;
use Pandoc\AST\MathType;
use Pandoc\AST\Note;
use Pandoc\AST\OrderedList;
use Pandoc\AST\Para;
use Pandoc\AST\Plain;
use Pandoc\AST\Quoted;
use Pandoc\AST\QuoteType;
use Pandoc\AST\RawBlock;
use Pandoc\AST\RawInline;
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

/**
 * Writes the AST as well-formed XHTML fragments (EPUB 3 flavour).
 *
 * Math is converted to MathML when latex2mathml-php is installed and the formula converts;
 * otherwise it is emitted as TeX inside <span class="math">.
 */
class XhtmlWriter
{
    private int $noteCounter = 0;
    /** @var array<int, string> rendered footnote bodies keyed by note number */
    private array $notes = [];
    private string $currentFile = '';
    private bool $usedMathMl = false;

    /**
     * @param array<string, string>      $idMap    element id => chapter file name, used to rewrite '#id' links
     * @param array<string, string>|null $imageMap image URL => final href; when set, unknown local images become alt text
     */
    public function __construct(
        private array $idMap = [],
        private ?array $imageMap = null,
        private bool $mathMl = true,
        private bool $numberSections = true
    ) {}

    /** Whether MathML was emitted since the last call (EPUB needs a manifest property for such pages). */
    public function takeMathMlFlag(): bool
    {
        $used = $this->usedMathMl;
        $this->usedMathMl = false;
        return $used;
    }

    public function setCurrentFile(string $file): void
    {
        $this->currentFile = $file;
    }

    public static function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param Block[] $blocks */
    public function writeBlocks(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $b) {
            $html = $this->writeBlock($b);
            if ($html !== '') {
                $parts[] = $html;
            }
        }
        return implode("\n", $parts);
    }

    /** Returns the footnotes collected since the last call, as an XHTML section, and resets the list. */
    public function takeNotes(): string
    {
        if (!$this->notes) {
            return '';
        }
        $out = "<section class=\"footnotes\" epub:type=\"footnotes\" role=\"doc-endnotes\">\n<hr />\n";
        foreach ($this->notes as $n => $html) {
            $back = "<a href=\"#fnref{$n}\" class=\"footnote-back\" epub:type=\"backlink\" role=\"doc-backlink\">\u{21A9}\u{FE0E}</a>";
            $html = str_ends_with($html, '</p>')
                ? substr($html, 0, -4) . ' ' . $back . '</p>'
                : $html . "\n<p>$back</p>";
            $out .= "<aside id=\"fn{$n}\" epub:type=\"footnote\" role=\"doc-footnote\">\n$html\n</aside>\n";
        }
        $this->notes = [];
        return $out . '</section>';
    }

    protected function writeBlock(Block $block): string
    {
        return match (true) {
            $block instanceof Plain => $this->writeInlines($block->content),
            $block instanceof Para => '<p>' . $this->writeInlines($block->content) . '</p>',
            $block instanceof Header => $this->writeHeader($block),
            $block instanceof BulletList => $this->writeList('ul', '', $block->items),
            $block instanceof OrderedList => $this->writeOrderedList($block),
            $block instanceof DefinitionList => $this->writeDefinitionList($block),
            $block instanceof BlockQuote => "<blockquote>\n" . $this->writeBlocks($block->content) . "\n</blockquote>",
            $block instanceof CodeBlock => $this->writeCodeBlock($block),
            $block instanceof RawBlock => in_array($block->format, ['html', 'html5', 'epub', 'xhtml'], true) ? $block->text : '',
            $block instanceof HorizontalRule => '<hr />',
            $block instanceof Div => '<div' . $this->attrs($block->attr) . ">\n" . $this->writeBlocks($block->content) . "\n</div>",
            $block instanceof Figure => $this->writeFigure($block),
            $block instanceof Table => $this->writeTable($block),
            default => '<!-- Unsupported Block: ' . self::escape(get_class($block)) . ' -->',
        };
    }

    protected function writeHeader(Header $h): string
    {
        $level = max(1, min(6, $h->level));
        $number = '';
        if ($this->numberSections) {
            foreach ($this->attrPairs($h->attr) as [$k, $v]) {
                if ($k === 'number') {
                    $number = '<span class="header-section-number">' . self::escape($v) . '</span> ';
                }
            }
        }
        return "<h{$level}" . $this->attrs($h->attr) . '>' . $number . $this->writeInlines($h->content) . "</h{$level}>";
    }

    /** @param Block[][] $items */
    protected function writeList(string $tag, string $tagAttrs, array $items): string
    {
        $lis = array_map(fn(array $item) => '<li>' . $this->writeBlocks($item) . '</li>', $items);
        return "<{$tag}{$tagAttrs}>\n" . implode("\n", $lis) . "\n</{$tag}>";
    }

    protected function writeOrderedList(OrderedList $list): string
    {
        $type = match ($list->attr->style) {
            ListNumberStyle::Decimal => '1',
            ListNumberStyle::LowerAlpha => 'a',
            ListNumberStyle::UpperAlpha => 'A',
            ListNumberStyle::LowerRoman => 'i',
            ListNumberStyle::UpperRoman => 'I',
            default => '',
        };
        $attrs = ($list->attr->start !== 1 ? ' start="' . $list->attr->start . '"' : '')
            . ($type !== '' ? ' type="' . $type . '"' : '');
        return $this->writeList('ol', $attrs, $list->items);
    }

    protected function writeDefinitionList(DefinitionList $list): string
    {
        $out = "<dl>\n";
        foreach ($list->items as [$term, $defs]) {
            $out .= '<dt>' . $this->writeInlines($term) . "</dt>\n";
            foreach ($defs as $def) {
                $out .= "<dd>\n" . $this->writeBlocks(is_array($def) ? $def : [$def]) . "\n</dd>\n";
            }
        }
        return $out . '</dl>';
    }

    protected function writeCodeBlock(CodeBlock $cb): string
    {
        return '<pre' . $this->attrs($cb->attr) . '><code>' . self::escape($cb->text) . '</code></pre>';
    }

    protected function writeFigure(Figure $fig): string
    {
        $out = '<figure' . $this->attrs($fig->attr) . ">\n" . $this->writeBlocks($fig->content);
        $caption = $this->writeBlocks($fig->caption->content);
        if ($caption !== '') {
            $out .= "\n<figcaption>" . $caption . '</figcaption>';
        }
        return $out . "\n</figure>";
    }

    protected function writeTable(Table $t): string
    {
        $out = '<table' . $this->attrs($t->attr) . ">\n";
        $caption = $this->writeBlocks($t->caption->content);
        if ($caption !== '') {
            $out .= "<caption>$caption</caption>\n";
        }
        if ($t->head->rows) {
            $out .= "<thead>\n" . $this->writeRows($t->head->rows, 'th') . "</thead>\n";
        }
        foreach ($t->bodies as $body) {
            $out .= "<tbody>\n" . $this->writeRows(array_merge($body->head, $body->rows), 'td') . "</tbody>\n";
        }
        if ($t->foot->rows) {
            $out .= "<tfoot>\n" . $this->writeRows($t->foot->rows, 'td') . "</tfoot>\n";
        }
        return $out . '</table>';
    }

    /** @param \Pandoc\AST\Row[] $rows */
    private function writeRows(array $rows, string $cellTag): string
    {
        $out = '';
        foreach ($rows as $row) {
            $out .= "<tr>\n";
            foreach ($row->cells as $cell) {
                $attrs = $this->attrs($cell->attr);
                if ($cell->colSpan > 1) {
                    $attrs .= ' colspan="' . $cell->colSpan . '"';
                }
                if ($cell->rowSpan > 1) {
                    $attrs .= ' rowspan="' . $cell->rowSpan . '"';
                }
                $align = match ($cell->align) {
                    Alignment::AlignLeft => 'left',
                    Alignment::AlignRight => 'right',
                    Alignment::AlignCenter => 'center',
                    default => null,
                };
                if ($align !== null) {
                    $attrs .= ' style="text-align: ' . $align . ';"';
                }
                $out .= "<{$cellTag}{$attrs}>" . $this->writeBlocks($cell->content) . "</{$cellTag}>\n";
            }
            $out .= "</tr>\n";
        }
        return $out;
    }

    /** @param Inline[] $inlines */
    public function writeInlines(array $inlines): string
    {
        $out = '';
        foreach ($inlines as $i) {
            $out .= $this->writeInline($i);
        }
        return $out;
    }

    protected function writeInline(Inline $i): string
    {
        return match (true) {
            $i instanceof Str => self::escape($i->text),
            $i instanceof Space => ' ',
            $i instanceof SoftBreak => "\n",
            $i instanceof LineBreak => "<br />\n",
            $i instanceof Emph => '<em>' . $this->writeInlines($i->content) . '</em>',
            $i instanceof Strong => '<strong>' . $this->writeInlines($i->content) . '</strong>',
            $i instanceof Underline => '<span class="underline">' . $this->writeInlines($i->content) . '</span>',
            $i instanceof Strikeout => '<del>' . $this->writeInlines($i->content) . '</del>',
            $i instanceof Superscript => '<sup>' . $this->writeInlines($i->content) . '</sup>',
            $i instanceof Subscript => '<sub>' . $this->writeInlines($i->content) . '</sub>',
            $i instanceof SmallCaps => '<span class="smallcaps">' . $this->writeInlines($i->content) . '</span>',
            $i instanceof Quoted => $this->writeQuoted($i),
            $i instanceof Code => '<code' . $this->attrs($i->attr) . '>' . self::escape($i->text) . '</code>',
            $i instanceof Math => $this->writeMath($i),
            $i instanceof Link => $this->writeLink($i),
            $i instanceof Image => $this->writeImage($i),
            $i instanceof Span => $this->writeSpan($i),
            $i instanceof Note => $this->writeNote($i),
            $i instanceof Cite => $this->writeInlines($i->content),
            $i instanceof RawInline => in_array($i->format, ['html', 'html5', 'epub', 'xhtml'], true) ? $i->text : '',
            default => '',
        };
    }

    private function writeQuoted(Quoted $q): string
    {
        [$open, $close] = $q->type === QuoteType::SingleQuote ? ["\u{2018}", "\u{2019}"] : ["\u{201C}", "\u{201D}"];
        return $open . $this->writeInlines($q->content) . $close;
    }

    private function writeMath(Math $m): string
    {
        $display = $m->type === MathType::DisplayMath;
        if ($this->mathMl && ($mathml = MathMl::fromTex($m->text, $display)) !== null) {
            $this->usedMathMl = true;
            return $mathml;
        }
        return $display
            ? '<span class="math display">\\[' . self::escape($m->text) . '\\]</span>'
            : '<span class="math inline">\\(' . self::escape($m->text) . '\\)</span>';
    }

    private function writeLink(Link $l): string
    {
        $title = $l->target->title !== '' ? ' title="' . self::escape($l->target->title) . '"' : '';
        return '<a href="' . self::escape($this->href($l->target->url)) . '"' . $this->attrs($l->attr) . $title . '>'
            . $this->writeInlines($l->content) . '</a>';
    }

    private function href(string $url): string
    {
        if (str_starts_with($url, '#')) {
            $id = substr($url, 1);
            $file = $this->idMap[$id] ?? null;
            if ($file !== null && $file !== $this->currentFile) {
                return $file . '#' . $id;
            }
        }
        return $url;
    }

    private function writeImage(Image $img): string
    {
        $alt = self::stringify($img->content);
        $url = $img->target->url;
        if ($this->imageMap !== null) {
            if (!isset($this->imageMap[$url])) {
                return $alt !== '' ? '<span class="missing-image">' . self::escape($alt) . '</span>' : '';
            }
            $url = $this->imageMap[$url];
        }
        $extra = '';
        $style = [];
        foreach ($this->attrPairs($img->attr) as [$k, $v]) {
            if (in_array($k, ['width', 'height'], true)) {
                if (preg_match('/^\d+(\.\d+)?(%|px)?$/', $v)) {
                    $extra .= ' ' . $k . '="' . self::escape(preg_replace('/px$/', '', $v)) . '"';
                } else {
                    $style[] = $k . ': ' . $v;
                }
            }
        }
        if ($style) {
            $extra .= ' style="' . self::escape(implode('; ', $style)) . ';"';
        }
        $title = $img->target->title !== '' ? ' title="' . self::escape($img->target->title) . '"' : '';
        return '<img src="' . self::escape($url) . '" alt="' . self::escape($alt) . '"' . $title . $extra . ' />';
    }

    private function writeSpan(Span $s): string
    {
        $style = [];
        foreach ($this->attrPairs($s->attr) as [$k, $v]) {
            if (in_array($k, ['color', 'background-color'], true) && preg_match('/^[#A-Za-z0-9(),.% -]+$/', $v)) {
                $style[] = $k . ': ' . $v;
            }
        }
        $attrs = $this->attrs($s->attr);
        if ($style) {
            $attrs .= ' style="' . self::escape(implode('; ', $style)) . ';"';
        }
        $content = $this->writeInlines($s->content);
        return $attrs === '' ? $content : '<span' . $attrs . '>' . $content . '</span>';
    }

    private function writeNote(Note $n): string
    {
        $num = ++$this->noteCounter;
        $blocks = $n->content;
        if ($blocks && $blocks[0] instanceof Inline) {
            $blocks = [new Para($blocks)];
        }
        $this->notes[$num] = $this->writeBlocks(array_map(fn($b) => $b instanceof Plain ? new Para($b->content) : $b, $blocks));
        return "<a href=\"#fn{$num}\" class=\"footnote-ref\" id=\"fnref{$num}\" epub:type=\"noteref\" role=\"doc-noteref\"><sup>{$num}</sup></a>";
    }

    /** @return array<int, array{0:string, 1:string}> attributes normalised to [key, value] pairs */
    private function attrPairs(Attr $a): array
    {
        $pairs = [];
        foreach ($a->attributes as $k => $v) {
            $pairs[] = is_array($v) ? [(string) $v[0], (string) ($v[1] ?? '')] : [(string) $k, (string) $v];
        }
        return $pairs;
    }

    private function attrs(Attr $a): string
    {
        $s = '';
        if ($a->identifier !== '') {
            $s .= ' id="' . self::escape($a->identifier) . '"';
        }
        if ($a->classes) {
            $s .= ' class="' . self::escape(implode(' ', $a->classes)) . '"';
        }
        foreach ($this->attrPairs($a) as [$k, $v]) {
            if (in_array($k, ['color', 'background-color', 'width', 'height', 'reference-type', 'reference', 'number'], true)) {
                continue;
            }
            $key = preg_match('/^[a-z][a-z0-9_.:-]*$/i', $k) ? (str_starts_with($k, 'data-') ? $k : 'data-' . $k) : null;
            if ($key !== null) {
                $s .= ' ' . $key . '="' . self::escape($v) . '"';
            }
        }
        return $s;
    }

    /** @param Inline[] $inlines */
    public static function stringify(array $inlines): string
    {
        $out = '';
        foreach ($inlines as $i) {
            $out .= match (true) {
                $i instanceof Str => $i->text,
                $i instanceof Space, $i instanceof SoftBreak, $i instanceof LineBreak => ' ',
                $i instanceof Code => $i->text,
                $i instanceof Math => $i->text,
                $i instanceof Note => '',
                $i instanceof Quoted => ($i->type === QuoteType::SingleQuote ? "\u{2018}" : "\u{201C}")
                    . self::stringify($i->content)
                    . ($i->type === QuoteType::SingleQuote ? "\u{2019}" : "\u{201D}"),
                is_object($i) && property_exists($i, 'content') && is_array($i->content) => self::stringify($i->content),
                default => '',
            };
        }
        return $out;
    }
}
