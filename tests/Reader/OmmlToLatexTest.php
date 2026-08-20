<?php

namespace Pandoc\Tests\Reader;

use DOMDocument;
use DOMElement;
use PHPUnit\Framework\TestCase;
use Pandoc\Reader\Docx\OmmlToLatex;

class OmmlToLatexTest extends TestCase
{
    private OmmlToLatex $converter;

    protected function setUp(): void
    {
        $this->converter = new OmmlToLatex();
    }

    private function parse(string $inner): DOMElement
    {
        $xml = '<m:root xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math" '
            . 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . $inner . '</m:root>';
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        /** @var DOMElement $oMath */
        $oMath = $dom->documentElement->firstChild;
        return $oMath;
    }

    private function convert(string $inner): string
    {
        return $this->converter->convert($this->parse($inner));
    }

    public function testSimpleRunsAndOperator(): void
    {
        $latex = $this->convert('<m:oMath><m:r><m:t>x+y</m:t></m:r></m:oMath>');
        $this->assertSame('x+y', $latex);
    }

    public function testSuperscript(): void
    {
        $latex = $this->convert(
            '<m:oMath><m:sSup><m:e><m:r><m:t>x</m:t></m:r></m:e><m:sup><m:r><m:t>2</m:t></m:r></m:sup></m:sSup></m:oMath>'
        );
        $this->assertSame('{x}^{2}', $latex);
    }

    public function testSubscript(): void
    {
        $latex = $this->convert(
            '<m:oMath><m:sSub><m:e><m:r><m:t>a</m:t></m:r></m:e><m:sub><m:r><m:t>n</m:t></m:r></m:sub></m:sSub></m:oMath>'
        );
        $this->assertSame('{a}_{n}', $latex);
    }

    public function testFraction(): void
    {
        $latex = $this->convert(
            '<m:oMath><m:f><m:num><m:r><m:t>1</m:t></m:r></m:num><m:den><m:r><m:t>2</m:t></m:r></m:den></m:f></m:oMath>'
        );
        $this->assertSame('\\frac{1}{2}', $latex);
    }

    public function testSquareRoot(): void
    {
        $latex = $this->convert(
            '<m:oMath><m:rad><m:deg/><m:e><m:r><m:t>2</m:t></m:r></m:e></m:rad></m:oMath>'
        );
        $this->assertSame('\\sqrt{2}', $latex);
    }

    public function testNthRoot(): void
    {
        $latex = $this->convert(
            '<m:oMath><m:rad><m:deg><m:r><m:t>3</m:t></m:r></m:deg><m:e><m:r><m:t>8</m:t></m:r></m:e></m:rad></m:oMath>'
        );
        $this->assertSame('\\sqrt[3]{8}', $latex);
    }

    public function testIntegralWithLimitsAndDelimiter(): void
    {
        // y=\int_0^t x(s) ds  (same shape as php-pandoc/test/docx/Hello.docx)
        $xml = '<m:oMath>'
            . '<m:r><m:t>y=</m:t></m:r>'
            . '<m:nary>'
            . '<m:naryPr><m:limLoc m:val="subSup"/></m:naryPr>'
            . '<m:sub><m:r><m:t>0</m:t></m:r></m:sub>'
            . '<m:sup><m:r><m:t>t</m:t></m:r></m:sup>'
            . '<m:e>'
            . '<m:r><m:t>x</m:t></m:r>'
            . '<m:d><m:dPr/><m:e><m:r><m:t>s</m:t></m:r></m:e></m:d>'
            . '<m:r><m:t>ds</m:t></m:r>'
            . '</m:e>'
            . '</m:nary>'
            . '</m:oMath>';
        $this->assertSame('y=\\int_{0}^{t}{x\\left(s\\right)ds}', $this->convert($xml));
    }

    public function testDelimiterWithCustomBraces(): void
    {
        $xml = '<m:oMath><m:d><m:dPr><m:begChr m:val="{"/><m:endChr m:val="}"/></m:dPr>'
            . '<m:e><m:r><m:t>x</m:t></m:r></m:e></m:d></m:oMath>';
        $this->assertSame('\\left\\{x\\right\\}', $this->convert($xml));
    }

    public function testFunctionName(): void
    {
        $xml = '<m:oMath><m:func><m:fName><m:r><m:t>sin</m:t></m:r></m:fName>'
            . '<m:e><m:r><m:t>x</m:t></m:r></m:e></m:func></m:oMath>';
        $this->assertSame('\\sin \\left(x\\right)', $this->convert($xml));
    }

    public function testCustomFunctionNameUsesOperatorname(): void
    {
        $xml = '<m:oMath><m:func><m:fName><m:r><m:t>foo</m:t></m:r></m:fName>'
            . '<m:e><m:r><m:t>x</m:t></m:r></m:e></m:func></m:oMath>';
        $this->assertSame('\\operatorname{foo}\\left(x\\right)', $this->convert($xml));
    }

    public function testGreekLettersAndRelations(): void
    {
        $latex = $this->convert('<m:oMath><m:r><m:t>α≤β</m:t></m:r></m:oMath>');
        $this->assertSame('\\alpha \\leq \\beta ', $latex);
    }

    public function testInlineOMathDirectly(): void
    {
        // OmmlToLatex::convert() also accepts a bare m:oMath (for inline equations).
        $latex = $this->convert('<m:oMath><m:r><m:t>a</m:t></m:r></m:oMath>');
        $this->assertSame('a', $latex);
    }

    public function testOMathParaWrapsDisplayEquation(): void
    {
        $latex = $this->convert('<m:oMathPara><m:oMath><m:r><m:t>a=b</m:t></m:r></m:oMath></m:oMathPara>');
        $this->assertSame('a=b', $latex);
    }
}
