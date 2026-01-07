<?php

namespace Pandoc\Tests\Writer;

use PHPUnit\Framework\TestCase;
use Pandoc\AST\Pandoc;
use Pandoc\AST\Block;
use Pandoc\AST\Inline;
use Pandoc\AST\Str;
use Pandoc\AST\Space;
use Pandoc\AST\Emph;
use Pandoc\AST\Strong;
use Pandoc\AST\Para;
use Pandoc\AST\Plain;
use Pandoc\AST\Header;
use Pandoc\AST\Attr;
use Pandoc\AST\Code;
use Pandoc\AST\Image;
use Pandoc\AST\Target;
use Pandoc\AST\DefinitionList;
use Pandoc\AST\BulletList;
use Pandoc\AST\OrderedList;
use Pandoc\AST\ListAttributes;
use Pandoc\AST\BlockQuote;
use Pandoc\AST\HorizontalRule;
use Pandoc\Writer\HtmlWriter;

class HtmlWriterTest extends TestCase
{
    private HtmlWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new HtmlWriter();
    }

    protected function assertHtml(string $expected, Pandoc|Block|Inline|array $input): void
    {
        if ($input instanceof Pandoc) {
            $doc = $input;
        } elseif ($input instanceof Block) {
            $doc = new Pandoc(blocks: [$input]);
        } elseif ($input instanceof Inline) {
            $doc = new Pandoc(blocks: [new Plain([$input])]);
        } elseif (is_array($input)) {
            if (empty($input) || $input[0] instanceof Block) {
                $doc = new Pandoc(blocks: $input);
            } else {
                $doc = new Pandoc(blocks: [new Plain($input)]);
            }
        } else {
            throw new \InvalidArgumentException("Invalid input type");
        }

        $this->assertEquals($expected, $this->writer->write($doc));
    }

    public function testInlineCodeBasic(): void
    {
        $this->assertHtml('<code>@&amp;</code>', new Code(new Attr(), "@&"));
    }

    public function testImagesAltWithFormatting(): void
    {
        $this->assertHtml(
            '<img src="/url" title="title" alt="my image" />',
            new Image(new Attr(), [new Str("my "), new Emph([new Str("image")])], new Target("/url", "title"))
        );
    }

    public function testDefinitionListWithEmptyDt(): void
    {
        $this->assertHtml(
            "<dl>\n<dt></dt>\n<dd>\n<p>foo bar</p>\n</dd>\n</dl>",
            new DefinitionList([
                [[], [new Para([new Str("foo bar")])]]
            ])
        );
    }

    public function testBulletList(): void
    {
        $this->assertHtml(
            "<ul>\n  <li>item 1</li>\n  <li>item 2</li>\n</ul>",
            new BulletList([
                [new Plain([new Str("item 1")])],
                [new Plain([new Str("item 2")])]
            ])
        );
    }

    public function testBlockQuote(): void
    {
        $this->assertHtml(
            "<blockquote>\n<p>quote</p>\n</blockquote>",
            new BlockQuote([new Para([new Str("quote")])])
        );
    }

    public function testHorizontalRule(): void
    {
        $this->assertHtml("<hr />", new HorizontalRule());
    }
}
