<?php

namespace Pandoc\Tests\Reader;

use PHPUnit\Framework\TestCase;
use Pandoc\Reader\HtmlReader;
use Pandoc\AST\Para;
use Pandoc\AST\Str;
use Pandoc\AST\Header;
use Pandoc\AST\BulletList;
use Pandoc\AST\OrderedList;
use Pandoc\AST\Strong;
use Pandoc\AST\Emph;
use Pandoc\AST\Link;
use Pandoc\AST\Table;
use Pandoc\AST\BlockQuote;
use Pandoc\AST\CodeBlock;
use Pandoc\AST\HorizontalRule;

class HtmlReaderTest extends TestCase
{
    private HtmlReader $reader;

    protected function setUp(): void
    {
        $this->reader = new HtmlReader();
    }

    public function testBasicHtml(): void
    {
        $html = "<h1>Title</h1><p>Hello <b>World</b>!</p>";
        $doc = $this->reader->read($html);

        $this->assertCount(2, $doc->blocks);
        $this->assertInstanceOf(Header::class, $doc->blocks[0]);
        $this->assertEquals(1, $doc->blocks[0]->level);

        $this->assertInstanceOf(Para::class, $doc->blocks[1]);
        $para = $doc->blocks[1];
        $this->assertCount(4, $para->content); // Hello, Space, Strong(World), !
    }

    public function testLists(): void
    {
        $html = "<ul><li>Item 1</li><li>Item 2</li></ul>";
        $doc = $this->reader->read($html);

        $this->assertCount(1, $doc->blocks);
        $this->assertInstanceOf(BulletList::class, $doc->blocks[0]);
        $this->assertCount(2, $doc->blocks[0]->items);
    }

    public function testLinksAndImages(): void
    {
        $html = '<p><a href="https://example.com">Link</a> <img src="img.png" title="Image"></p>';
        $doc = $this->reader->read($html);

        $para = $doc->blocks[0];
        $this->assertInstanceOf(Link::class, $para->content[0]);
        $this->assertEquals("https://example.com", $para->content[0]->target->url);

        $this->assertInstanceOf(\Pandoc\AST\Space::class, $para->content[1]);

        $this->assertInstanceOf(\Pandoc\AST\Image::class, $para->content[2]);
        $this->assertEquals("img.png", $para->content[2]->target->url);
        $this->assertEquals("Image", $para->content[2]->target->title);
    }

    public function testTable(): void
    {
        $html = "<table><tr><th>Header</th></tr><tr><td>Data</td></tr></table>";
        $doc = $this->reader->read($html);

        $this->assertInstanceOf(Table::class, $doc->blocks[0]);
        $table = $doc->blocks[0];
        $this->assertCount(1, $table->head->rows);
        $this->assertCount(1, $table->bodies[0]->rows);
    }

    public function testFormatting(): void
    {
        $html = "<p><i>Italic</i> <u>Underline</u> <s>Strike</s> <sup>Super</sup> <sub>Sub</sub></p>";
        $doc = $this->reader->read($html);

        $para = $doc->blocks[0];
        $this->assertInstanceOf(Emph::class, $para->content[0]);
        $this->assertInstanceOf(\Pandoc\AST\Underline::class, $para->content[2]);
        $this->assertInstanceOf(\Pandoc\AST\Strikeout::class, $para->content[4]);
        $this->assertInstanceOf(\Pandoc\AST\Superscript::class, $para->content[6]);
        $this->assertInstanceOf(\Pandoc\AST\Subscript::class, $para->content[8]);
    }

    public function testComplexBlocks(): void
    {
        $html = "<blockquote><p>Quote</p></blockquote><pre>Code</pre><hr>";
        $doc = $this->reader->read($html);

        $this->assertInstanceOf(BlockQuote::class, $doc->blocks[0]);
        $this->assertInstanceOf(CodeBlock::class, $doc->blocks[1]);
        $this->assertInstanceOf(HorizontalRule::class, $doc->blocks[2]);
    }
}
