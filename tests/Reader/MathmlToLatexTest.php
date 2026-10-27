<?php

namespace Pandoc\Tests\Reader;

use PHPUnit\Framework\TestCase;
use Pandoc\Reader\Odt\MathmlToLatex;
use Pandoc\Writer\MathMl;

class MathmlToLatexTest extends TestCase
{
    private function tex(string $mathml): string
    {
        return MathmlToLatex::fromString('<math xmlns="http://www.w3.org/1998/Math/MathML">' . $mathml . '</math>');
    }

    /** LaTeX -> MathML -> LaTeX -> MathML must give the same MathML. */
    private function roundTrip(string $tex): void
    {
        $m1 = MathMl::fromTex($tex, false);
        $this->assertNotNull($m1, $tex);
        $back = MathmlToLatex::fromString($m1);
        $this->assertNotSame('', $back, $tex);
        $this->assertSame(
            preg_replace('/\s+/', '', $m1),
            preg_replace('/\s+/', '', (string) MathMl::fromTex($back, false)),
            "$tex came back as $back"
        );
    }

    public function testCommonConstructsRoundTrip(): void
    {
        foreach (['a+b=c', '\frac{a}{b}', 'x^{2}+y_{i}', '\sqrt{x}', '\sqrt[3]{x}', '\sum_{i=1}^{n} x_{i}', '\int_{0}^{1}f(x)\,dx',
                  '\alpha+\beta\le\gamma', '\mathbb{R}^{n}', 'A\cup B\subset C', '\lim_{x\to 0} f(x)', '\hat{x}+\vec{v}+\bar{y}'] as $tex) {
            $this->roundTrip($tex);
        }
    }

    public function testAnnotationWithTexIsUsedAsIs(): void
    {
        $this->assertSame('\frac{1}{2}', $this->tex('<semantics><mrow><mn>1</mn></mrow><annotation encoding="application/x-tex">\frac{1}{2}</annotation></semantics>'));
    }

    public function testStarMathAnnotationIsIgnoredAndMathMlIsConverted(): void
    {
        $this->assertSame('a<0', $this->tex('<semantics><mrow><mi>a</mi><mo>&lt;</mo><mn>0</mn></mrow><annotation encoding="StarMath 5.0">a &lt; 0</annotation></semantics>'));
    }

    public function testSymbolsAndSets(): void
    {
        $this->assertSame('(a,b)\in\mathbb{Z}^{2}', $this->tex('<mrow><mo>(</mo><mi>a</mi><mo>,</mo><mi>b</mi><mo>)</mo><mo>∈</mo><msup><mi>ℤ</mi><mn>2</mn></msup></mrow>'));
        $this->assertSame('A\setminus B', $this->tex('<mrow><mi>A</mi><mo>⧵</mo><mi>B</mi></mrow>'));
    }

    public function testStretchyFencesBecomeLeftRight(): void
    {
        $out = $this->tex('<mrow><mo fence="true" stretchy="true" form="prefix">[</mo><mi>x</mi><mo fence="true" stretchy="true" form="postfix">)</mo></mrow>');
        $this->assertSame('\left[ x \right)', $out);
    }

    public function testMatrixWithSurroundingParenthesesBecomesPmatrix(): void
    {
        $out = $this->tex('<mrow><mo>(</mo><mtable><mtr><mtd><mn>1</mn></mtd><mtd><mn>2</mn></mtd></mtr><mtr><mtd><mn>3</mn></mtd><mtd><mn>4</mn></mtd></mtr></mtable><mo>)</mo></mrow>');
        $this->assertSame('\begin{pmatrix} 1 & 2 \\\\ 3 & 4 \end{pmatrix}', $out);
    }

    public function testScriptOnAScriptedBaseIsBraced(): void
    {
        $out = $this->tex('<msub><mrow><msub><mi>D</mi><mi>t</mi></msub></mrow><mn>0</mn></msub>');
        $this->assertSame('{D_{t}}_{0}', $out);
    }

    public function testRunsOfUprightLettersAreOneCommand(): void
    {
        $out = $this->tex('<mrow><mi mathvariant="normal">P</mi><mi mathvariant="normal">C</mi><mi mathvariant="normal">A</mi></mrow>');
        $this->assertSame('\mathrm{PCA}', $out);
    }

    public function testNamedSpacesAndFunctionNames(): void
    {
        $this->assertSame('a\;b', $this->tex('<mrow><mi>a</mi><mspace width="0.278em"/><mi>b</mi></mrow>'));
        $this->assertSame('\sin x', $this->tex('<mrow><mi>sin</mi><mi>x</mi></mrow>'));
    }

    public function testLoneSymbolInMtextIsNotText(): void
    {
        $this->assertSame('a_{+}', $this->tex('<msub><mi>a</mi><mtext>+</mtext></msub>'));
    }

    /** trim() with a multi-byte character list strips single bytes and used to break "…" and "→". */
    public function testMultiByteCharactersInTextSurvive(): void
    {
        $out = $this->tex('<mtext>a … b → c</mtext>');
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
        $this->assertSame('\text{a … b → c}', $out);
        $this->assertSame('\ldots', $this->tex('<mtext>…</mtext>'));
    }

    public function testMalformedInputGivesEmptyString(): void
    {
        $this->assertSame('', MathmlToLatex::fromString('<math>'));
    }

    public function testScriptAndFrakturLettersOfTheLetterlikeBlock(): void
    {
        $this->assertSame('\mathcal{B}=\mathcal{L}\mathcal{E}_{1}', $this->tex('<mrow><mi>ℬ</mi><mo>=</mo><mi>ℒ</mi><msub><mi>ℰ</mi><mn>1</mn></msub></mrow>'));
        $this->assertSame('\mathfrak{C}', $this->tex('<mi>ℭ</mi>'));
    }

    public function testStrayClosingBraceFromAMisparsedArrowIsRepaired(): void
    {
        $this->assertSame('z\overset{\;x\;}{\to}y', MathmlToLatex::balanced('z\overset{\;x\;\}{\to}y'));
        $this->assertSame('\{x\}+1', MathmlToLatex::balanced('\{x\}+1'), 'balanced input is left alone');
        $this->assertSame('a\\b', MathmlToLatex::balanced('a\\b'));
    }

    public function testStarAndKappaSymbols(): void
    {
        $this->assertSame('a\star b', $this->tex('<mrow><mi>a</mi><mo>⋆</mo><mi>b</mi></mrow>'));
        $this->assertSame('\varkappa', $this->tex('<mi>ϰ</mi>'));
    }

    public function testLibreOfficeEmptyArgumentPlaceholderIsAnEmptyGroup(): void
    {
        // LibreOffice Math draws an empty argument as the square U+2751
        $this->assertSame('x{}_{\infty}', $this->tex('<msub><mrow><mi>x</mi><mo>❑</mo></mrow><mo>∞</mo></msub>'));
    }
}
