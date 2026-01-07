<?php

namespace Pandoc\Tests\AST;

use PHPUnit\Framework\TestCase;
use Pandoc\AST\Para;
use Pandoc\AST\Str;
use Pandoc\AST\Space;
use Pandoc\AST\Header;
use Pandoc\AST\Attr;
use Pandoc\AST\Pandoc;

class ASTTest extends TestCase
{
    public function testParaConstruction(): void
    {
        $para = new Para([new Str("Hello"), new Space(), new Str("World")]);
        $this->assertCount(3, $para->content);
        $this->assertInstanceOf(Str::class, $para->content[0]);
        $this->assertEquals("Hello", $para->content[0]->text);
    }

    public function testHeaderConstruction(): void
    {
        $header = new Header(1, new Attr("id", ["class"], [["key", "value"]]), [new Str("Title")]);
        $this->assertEquals(1, $header->level);
        $this->assertEquals("id", $header->attr->identifier);
        $this->assertContains("class", $header->attr->classes);
        $this->assertEquals("Title", $header->content[0]->text);
    }

    public function testPandocConstruction(): void
    {
        $doc = new Pandoc(blocks: [new Para([new Str("test")])]);
        $this->assertCount(1, $doc->blocks);
        $this->assertInstanceOf(Para::class, $doc->blocks[0]);
    }
}
