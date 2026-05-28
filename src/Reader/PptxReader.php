<?php

namespace Pandoc\Reader;

use Pandoc\AST\Attr;
use Pandoc\AST\BulletList;
use Pandoc\AST\Caption;
use Pandoc\AST\Cell;
use Pandoc\AST\Emph;
use Pandoc\AST\Header;
use Pandoc\AST\Image;
use Pandoc\AST\ListAttributes;
use Pandoc\AST\Meta;
use Pandoc\AST\OrderedList;
use Pandoc\AST\Pandoc;
use Pandoc\AST\Para;
use Pandoc\AST\Plain;
use Pandoc\AST\Row;
use Pandoc\AST\Space;
use Pandoc\AST\Str;
use Pandoc\AST\Strong;
use Pandoc\AST\Table;
use Pandoc\AST\TableBody;
use Pandoc\AST\TableFoot;
use Pandoc\AST\TableHead;
use Pandoc\AST\Target;
use Pandoc\AST\Alignment;
use Pandoc\Reader\Pptx\Parser;
use Pandoc\Reader\Pptx\PptxDiagram;
use Pandoc\Reader\Pptx\PptxParagraph;
use Pandoc\Reader\Pptx\PptxPicture;
use Pandoc\Reader\Pptx\PptxSlide;
use Pandoc\Reader\Pptx\PptxTable;
use Pandoc\Reader\Pptx\PptxTextShape;

class PptxReader implements ReaderInterface
{
    use HasMediaBag;

    public function __construct()
    {
        $this->initMediaBag();
    }

    public function read(string $filePath): Pandoc
    {
        $this->initMediaBag();
        $doc = (new Parser())->parse($filePath);
        $blocks = [];

        foreach ($doc->slides as $slide) {
            foreach ($this->slideToBlocks($slide) as $block) {
                $blocks[] = $block;
            }
        }

        return new Pandoc(new Meta(), $blocks, $this->mediaBag);
    }

    private function slideToBlocks(PptxSlide $slide): array
    {
        // First pass: find the title placeholder
        $titleInlines = null;
        foreach ($slide->shapes as $shape) {
            if ($shape instanceof PptxTextShape &&
                in_array($shape->placeholderType, ['title', 'ctrTitle'], true)) {
                $titleInlines = $this->shapeToInlines($shape);
                break;
            }
        }

        if ($titleInlines === null || empty($titleInlines)) {
            $titleInlines = [new Str("Slide {$slide->index}")];
        }

        $blocks = [new Header(2, new Attr(), $titleInlines)];

        // Second pass: content shapes
        foreach ($slide->shapes as $shape) {
            if ($shape instanceof PptxTextShape) {
                if (in_array($shape->placeholderType, ['title', 'ctrTitle'], true)) {
                    continue;
                }
                foreach ($this->textShapeToBlocks($shape) as $block) {
                    $blocks[] = $block;
                }
            } elseif ($shape instanceof PptxPicture) {
                $this->mediaBag->insert($shape->filename, $shape->mime, $shape->data);
                $alt = $shape->altText !== '' ? [new Str($shape->altText)] : [];
                $blocks[] = new Para([
                    new Image(new Attr(), $alt, new Target($shape->filename, $shape->title))
                ]);
            } elseif ($shape instanceof PptxTable) {
                $table = $this->tableToAst($shape);
                if ($table !== null) {
                    $blocks[] = $table;
                }
            } elseif ($shape instanceof PptxDiagram) {
                foreach ($shape->texts as $text) {
                    $inlines = $this->textToInlines($text);
                    if (!empty($inlines)) {
                        $blocks[] = new Para($inlines);
                    }
                }
            }
        }

        return $blocks;
    }

    private function shapeToInlines(PptxTextShape $shape): array
    {
        $inlines = [];
        foreach ($shape->paragraphs as $para) {
            $inlines = array_merge($inlines, $this->paragraphToInlines($para));
        }
        return $inlines;
    }

    private function textShapeToBlocks(PptxTextShape $shape): array
    {
        $blocks = [];
        $listBuffer = [];
        $currentListType = null;

        foreach ($shape->paragraphs as $para) {
            if ($para->bulletType !== 'none') {
                if ($currentListType !== $para->bulletType) {
                    if (!empty($listBuffer)) {
                        $blocks[] = $this->makeList($currentListType, $listBuffer);
                        $listBuffer = [];
                    }
                    $currentListType = $para->bulletType;
                }
                $listBuffer[] = $para;
            } else {
                if (!empty($listBuffer)) {
                    $blocks[] = $this->makeList($currentListType, $listBuffer);
                    $listBuffer = [];
                    $currentListType = null;
                }
                $inlines = $this->paragraphToInlines($para);
                if (!empty($inlines)) {
                    $blocks[] = new Para($inlines);
                }
            }
        }

        if (!empty($listBuffer)) {
            $blocks[] = $this->makeList($currentListType, $listBuffer);
        }

        return $blocks;
    }

    private function makeList(string $type, array $paras): BulletList|OrderedList
    {
        $items = array_map(
            fn(PptxParagraph $p) => [new Plain($this->paragraphToInlines($p))],
            $paras
        );

        if ($type === 'number') {
            return new OrderedList(new ListAttributes(), $items);
        }
        return new BulletList($items);
    }

    private function paragraphToInlines(PptxParagraph $para): array
    {
        $inlines = [];
        foreach ($para->runs as $run) {
            $base = $this->textToInlines($run->text);
            if ($run->bold && !empty($base)) {
                $base = [new Strong($base)];
            }
            if ($run->italic && !empty($base)) {
                $base = [new Emph($base)];
            }
            foreach ($base as $inline) {
                $inlines[] = $inline;
            }
        }
        return $inlines;
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
            $inlines[] = preg_match('/^\s+$/u', $part) ? new Space() : new Str($part);
        }
        return $inlines;
    }

    private function tableToAst(PptxTable $shape): ?Table
    {
        if (empty($shape->rows)) {
            return null;
        }

        $rows = $shape->rows;
        $headerRow = array_shift($rows);

        $makeRow = fn(array $cells) => new Row(
            new Attr(),
            array_map(
                fn(string $text) => new Cell(
                    new Attr(),
                    Alignment::AlignDefault,
                    1,
                    1,
                    [new Plain($this->textToInlines($text))]
                ),
                $cells
            )
        );

        $head = new TableHead(new Attr(), [$makeRow($headerRow)]);
        $bodies = [new TableBody(new Attr(), 0, [], array_map($makeRow, $rows))];

        return new Table(
            new Attr(),
            new Caption(null, []),
            [],
            $head,
            $bodies,
            new TableFoot(new Attr(), [])
        );
    }
}
