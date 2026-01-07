<?php

namespace Pandoc\Reader;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Pandoc\AST\Attr;
use Pandoc\AST\Block;
use Pandoc\AST\BlockQuote;
use Pandoc\AST\BulletList;
use Pandoc\AST\Caption;
use Pandoc\AST\Cell;
use Pandoc\AST\Code;
use Pandoc\AST\CodeBlock;
use Pandoc\AST\Div;
use Pandoc\AST\Emph;
use Pandoc\AST\Header;
use Pandoc\AST\HorizontalRule;
use Pandoc\AST\Image;
use Pandoc\AST\Inline;
use Pandoc\AST\Link;
use Pandoc\AST\ListAttributes;
use Pandoc\AST\Meta;
use Pandoc\AST\OrderedList;
use Pandoc\AST\Pandoc;
use Pandoc\AST\Para;
use Pandoc\AST\Plain;
use Pandoc\AST\Row;
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
use Pandoc\AST\MediaBag;

class HtmlReader implements ReaderInterface
{
    use HasMediaBag;

    public function __construct()
    {
        $this->initMediaBag();
    }

    public function read(string $html): Pandoc
    {
        $this->initMediaBag();
        $dom = new DOMDocument();
        // Use @ to suppress warnings about malformed HTML
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $blocks = $this->parseBlocks($dom);

        return new Pandoc(new Meta(), $blocks, $this->mediaBag);
    }

    /**
     * @return Block[]
     */
    protected function parseBlocks(DOMNode $parentNode): array
    {
        $blocks = [];
        foreach ($parentNode->childNodes as $node) {
            $block = $this->nodeToBlock($node);
            if ($block) {
                if (is_array($block)) {
                    $blocks = array_merge($blocks, $block);
                } else {
                    $blocks[] = $block;
                }
            }
        }
        return $blocks;
    }

    protected function nodeToBlock(DOMNode $node): Block|array|null
    {
        if ($node instanceof DOMText) {
            $text = $node->textContent;
            if (trim($text) === '') {
                return null;
            }
            return new Para($this->parseInlines($node));
        }

        if (!($node instanceof DOMElement)) {
            return null;
        }

        $tagName = strtolower($node->tagName);

        return match ($tagName) {
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => new Header((int)$tagName[1], $this->getAttr($node), $this->parseInlines($node)),
            'p' => new Para($this->parseInlines($node)),
            'ul' => new BulletList($this->parseListItems($node)),
            'ol' => new OrderedList(new ListAttributes(), $this->parseListItems($node)),
            'blockquote' => new BlockQuote($this->parseBlocks($node)),
            'pre' => new CodeBlock(new Attr(), $node->textContent),
            'hr' => new HorizontalRule(),
            'table' => $this->parseTable($node),
            'div' => new Div($this->getAttr($node), $this->parseBlocks($node)),
            // If it's an inline-only element at block level, wrap it in Para
            'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'del', 'sup', 'sub', 'a', 'img', 'code', 'span'
                => new Para($this->parseInlines($node)),
            default => $this->parseBlocks($node) // Flatten unknown elements
        };
    }

    /**
     * @return array[] Array of Block arrays
     */
    protected function parseListItems(DOMElement $listNode): array
    {
        $items = [];
        foreach ($listNode->childNodes as $node) {
            if ($node instanceof DOMElement && strtolower($node->tagName) === 'li') {
                $items[] = $this->parseBlocks($node);
            }
        }
        return $items;
    }

    protected function parseTable(DOMElement $tableNode): Table
    {
        $headRows = [];
        $bodyRows = [];
        $footRows = [];

        foreach ($tableNode->childNodes as $node) {
            if (!($node instanceof DOMElement)) continue;
            $tag = strtolower($node->tagName);
            if ($tag === 'thead') {
                $headRows = array_merge($headRows, $this->parseTableRows($node));
            } elseif ($tag === 'tbody') {
                $bodyRows = array_merge($bodyRows, $this->parseTableRows($node));
            } elseif ($tag === 'tfoot') {
                $footRows = array_merge($footRows, $this->parseTableRows($node));
            } elseif ($tag === 'tr') {
                $row = $this->parseTableRow($node);
                // Heuristic: if first row and contains <th>, make it a head row
                if (empty($headRows) && empty($bodyRows) && $this->isHeaderRow($node)) {
                    $headRows[] = $row;
                } else {
                    $bodyRows[] = $row;
                }
            }
        }

        return new Table(
            $this->getAttr($tableNode),
            new Caption(null, []),
            [],
            new TableHead(new Attr(), $headRows),
            [new TableBody(new Attr(), 0, [], $bodyRows)],
            new TableFoot(new Attr(), $footRows)
        );
    }

    /**
     * @return Row[]
     */
    protected function parseTableRows(DOMElement $container): array
    {
        $rows = [];
        foreach ($container->childNodes as $node) {
            if ($node instanceof DOMElement && strtolower($node->tagName) === 'tr') {
                $rows[] = $this->parseTableRow($node);
            }
        }
        return $rows;
    }

    protected function parseTableRow(DOMElement $trNode): Row
    {
        $cells = [];
        foreach ($trNode->childNodes as $node) {
            if ($node instanceof DOMElement && ($tag = strtolower($node->tagName)) && ($tag === 'td' || $tag === 'th')) {
                $cells[] = new Cell(new Attr(), \Pandoc\AST\Alignment::AlignDefault, 1, 1, $this->parseBlocks($node));
            }
        }
        return new Row(new Attr(), $cells);
    }

    protected function isHeaderRow(DOMElement $trNode): bool
    {
        foreach ($trNode->childNodes as $node) {
            if ($node instanceof DOMElement && strtolower($node->tagName) === 'th') {
                return true;
            }
        }
        return false;
    }

    /**
     * @return Inline[]
     */
    protected function parseInlines(DOMNode $parentNode): array
    {
        $inlines = [];
        foreach ($parentNode->childNodes as $node) {
            $inline = $this->nodeToInline($node);
            if ($inline) {
                if (is_array($inline)) {
                    $inlines = array_merge($inlines, $inline);
                } else {
                    $inlines[] = $inline;
                }
            }
        }
        return $inlines;
    }

    protected function nodeToInline(DOMNode $node): Inline|array|null
    {
        if ($node instanceof DOMText) {
            return $this->textToInlines($node->textContent);
        }

        if (!($node instanceof DOMElement)) {
            return null;
        }

        $tagName = strtolower($node->tagName);

        return match ($tagName) {
            'b', 'strong' => new Strong($this->parseInlines($node)),
            'i', 'em' => new Emph($this->parseInlines($node)),
            'u' => new Underline($this->parseInlines($node)),
            's', 'strike', 'del' => new Strikeout($this->parseInlines($node)),
            'sup' => new Superscript($this->parseInlines($node)),
            'sub' => new Subscript($this->parseInlines($node)),
            'a' => new Link($this->getAttr($node), $this->parseInlines($node), new Target($node->getAttribute('href'), $node->getAttribute('title'))),
            'img' => $this->parseImage($node),
            'code', 'kbd', 'samp', 'var' => new Code($this->getAttr($node), $node->textContent),
            'span' => new Span($this->getAttr($node), $this->parseInlines($node)),
            'br' => new \Pandoc\AST\LineBreak(),
            default => $this->parseInlines($node) // Flatten unknown elements
        };
    }

    protected function parseImage(DOMElement $node): Image
    {
        $src = $node->getAttribute('src');
        $title = $node->getAttribute('title');
        $alt = $node->getAttribute('alt');

        if (str_starts_with($src, 'data:')) {
            $data = $this->parseDataUri($src);
            if ($data) {
                $this->mediaBag->insert($data['filename'], $data['mime'], $data['contents']);
                $src = $data['filename'];
            }
        }

        return new Image($this->getAttr($node), $this->textToInlines($alt), new Target($src, $title));
    }

    protected function textToInlines(string $text): array
    {
        if ($text === '') return [];

        $inlines = [];
        // Handle whitespace similarly to MarkdownReader
        $parts = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $part) {
            if ($part === '') continue;
            if (preg_match('/^\s+$/u', $part)) {
                $inlines[] = new Space();
            } else {
                $inlines[] = new Str($part);
            }
        }
        return $inlines;
    }

    protected function getAttr(DOMElement $node): Attr
    {
        $id = $node->getAttribute('id');
        $classes = array_filter(explode(' ', $node->getAttribute('class')));
        $attributes = [];
        // We could extract all attributes, but for now just common ones if needed
        return new Attr($id, $classes, $attributes);
    }
}
