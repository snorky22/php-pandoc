<?php

namespace Pandoc\Tests\Writer;

use PHPUnit\Framework\TestCase;
use Pandoc\AST\Pandoc;
use Pandoc\AST\Para;
use Pandoc\AST\Str;
use Pandoc\AST\Space;
use Pandoc\AST\Header;
use Pandoc\AST\Attr;
use Pandoc\AST\BulletList;
use Pandoc\AST\Plain;
use Pandoc\AST\Span;
use Pandoc\Writer\LatexWriter;

class LatexWriterTest extends TestCase
{
    private LatexWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new LatexWriter();
    }

    private function assertLatex(string $expected, Pandoc $doc): void
    {
        $output = $this->writer->write($doc);
        // Extract body between \begin{document} and \end{document}
        if (preg_match('/\\\\begin\{document\}\s+(.*)\s+\\\\end\{document\}/s', $output, $matches)) {
            $body = trim($matches[1]);
            $this->assertEquals($expected, $body);
        } else {
            $this->fail("Could not find document body in LaTeX output");
        }
    }

    public function testPara(): void
    {
        $doc = new Pandoc(blocks: [new Para([new Str("Hello"), new Space(), new Str("World")])]);
        $this->assertLatex("Hello World", $doc);
    }

    public function testHeader(): void
    {
        $doc = new Pandoc(blocks: [new Header(1, new Attr(), [new Str("Title")])]);
        $this->assertLatex("\\section{Title}", $doc);
    }

    public function testBulletList(): void
    {
        $doc = new Pandoc(blocks: [
            new BulletList([
                [new Plain([new Str("item 1")])],
                [new Plain([new Str("item 2")])]
            ])
        ]);
        $expected = "\\begin{itemize}\n\\tightlist\n\\item item 1\n\\item item 2\n\\end{itemize}";
        $this->assertLatex($expected, $doc);
    }

    public function testEscaping(): void
    {
        $doc = new Pandoc(blocks: [new Para([new Str("100% & $")])]);
        $this->assertLatex("100\\% \\& \\$", $doc);
    }

    public function testColorSpan(): void
    {
        $doc = new Pandoc(blocks: [
            new Para([
                new Span(new Attr(attributes: [['color', '#FF0000']]), [new Str("Red")]),
                new Space(),
                new Span(new Attr(attributes: [['color', 'blue']]), [new Str("Blue")]),
                new Space(),
                new Span(new Attr(attributes: [['background-color', '#FFFF00']]), [new Str("Yellow BG")]),
                new Space(),
                new Span(new Attr(attributes: [['background-color', 'green']]), [new Str("Green BG")])
            ])
        ]);
        $expected = "\\textcolor[HTML]{FF0000}{Red} \\textcolor{blue}{Blue} \\colorbox[HTML]{FFFF00}{Yellow BG} \\colorbox{green}{Green BG}";
        $this->assertLatex($expected, $doc);
    }
}
