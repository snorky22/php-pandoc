<?php

namespace Pandoc\Tests\Reader;

use PHPUnit\Framework\TestCase;
use Pandoc\Reader\MarkdownReader;
use Pandoc\AST\Para;
use Pandoc\AST\Str;
use Pandoc\AST\Header;
use Pandoc\AST\Strong;
use Pandoc\AST\Emph;

class MarkdownReaderTest extends TestCase
{
    private MarkdownReader $reader;

    protected function setUp(): void
    {
        $this->reader = new MarkdownReader();
    }

    public function testHeader(): void
    {
        $doc = $this->reader->read("# Title");
        $this->assertCount(1, $doc->blocks);
        $this->assertInstanceOf(Header::class, $doc->blocks[0]);
        $this->assertEquals(1, $doc->blocks[0]->level);
    }

    public function testPara(): void
    {
        $doc = $this->reader->read("Hello World");
        $this->assertCount(1, $doc->blocks);
        $this->assertInstanceOf(Para::class, $doc->blocks[0]);
    }

    public function testStrongEmph(): void
    {
        $doc = $this->reader->read("**bold** *italic*");
        $para = $doc->blocks[0];
        $this->assertInstanceOf(Strong::class, $para->content[0]);
        $this->assertInstanceOf(Emph::class, $para->content[2]);
    }
}
