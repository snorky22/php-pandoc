<?php

namespace Pandoc\Tests\Writer;

use PHPUnit\Framework\TestCase;
use Pandoc\Writer\MathMl;

/**
 * MathMl::forOffice() works around constructs LibreOffice cannot parse (it draws a red "¿" for them).
 */
class MathMlOfficeTest extends TestCase
{
    private function office(string $tex, bool $display = false): \DOMXPath
    {
        $mathml = MathMl::fromTex($tex, $display);
        $this->assertNotNull($mathml, "latex2mathml could not convert $tex");
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML(MathMl::forOffice($mathml)));
        return new \DOMXPath($dom);
    }

    private function nodes(\DOMXPath $x, string $query): int
    {
        return $x->query($query)->length;
    }

    public function testRunsOfUprightLettersAreMerged(): void
    {
        $x = $this->office('\mathrm{ReLU}(x)');
        $this->assertSame(1, $this->nodes($x, '//*[local-name()="mi"][.="ReLU"]'));
    }

    public function testMultiLetterOperatorsBecomeUprightIdentifiers(): void
    {
        $x = $this->office('\arg\max_x f');
        $this->assertSame(0, $this->nodes($x, '//*[local-name()="mo"][.="arg" or .="max"]'));
        $this->assertSame(1, $this->nodes($x, '//*[local-name()="mi"][@mathvariant="normal"][.="arg"]'));
    }

    public function testPlainBarsArePairedIntoFencedGroups(): void
    {
        $x = $this->office('|x(t)| \le 2 |g|_\infty');
        $this->assertSame(2, $this->nodes($x, '//*[local-name()="mo"][@form="prefix"][.="|"]'));
        $this->assertSame(2, $this->nodes($x, '//*[local-name()="mo"][@form="postfix"][.="|"]'));
        // the closing bar of |g|_inf stays inside the base of the subscript
        $this->assertSame(1, $this->nodes($x, '//*[local-name()="msub"]/*[1][local-name()="mrow"]'));
    }

    public function testSingleBarOfASetBuilderIsLeftAlone(): void
    {
        $x = $this->office('\{x | x>0\}');
        $this->assertSame(0, $this->nodes($x, '//*[@fence="true"]'));
        $this->assertSame(1, $this->nodes($x, '//*[local-name()="mo"][.="|"]'));
    }

    public function testAlignedColumnStartingWithARelationGetsAnEmptyOperand(): void
    {
        $x = $this->office("\\begin{aligned}x &= 1 \\\\ y &= 2\\end{aligned}", true);
        $this->assertSame(2, $this->nodes($x, '//*[local-name()="mtd"]/*[1][local-name()="mrow"][not(*)]'));
    }

    public function testCellHoldingOnlyARelationBecomesText(): void
    {
        $x = $this->office("\\begin{aligned}a &\\leq& b \\\\ c &=& d\\end{aligned}", true);
        $this->assertSame(0, $this->nodes($x, '//*[local-name()="mo"][.="≤" or .="="]'));
        $this->assertSame(2, $this->nodes($x, '//*[local-name()="mtext"]'));
    }

    public function testEmptyTableCellGetsAnEmptyGroup(): void
    {
        $x = $this->office("\\begin{aligned}a &  & b \\\\ c & & d\\end{aligned}", true);
        $this->assertSame(0, $this->nodes($x, '//*[local-name()="mtd"][not(*)]'));
    }

    public function testFormulaStartingWithARelation(): void
    {
        $x = $this->office('\ge 0.3');
        $this->assertSame(1, $this->nodes($x, '//*[local-name()="math"]/*[local-name()="mrow"]/*[1][local-name()="mrow"][not(*)]'));
    }

    public function testLoneOperatorAsScriptBecomesText(): void
    {
        foreach (['J^{*}', '\mathbb{R}_{+}'] as $tex) {
            $x = $this->office($tex);
            $this->assertSame(0, $this->nodes($x, '//*[local-name()="mo"][.="*" or .="+"]'), $tex);
            $this->assertSame(1, $this->nodes($x, '//*[local-name()="mtext"]'), $tex);
        }
    }

    public function testUnmatchedBracketsBecomeTextButBalancedOnesStay(): void
    {
        $half = $this->office('t \in [a,b)');
        $this->assertSame(0, $this->nodes($half, '//*[local-name()="mo"][.="[" or .=")"]'));
        $this->assertSame(2, $this->nodes($half, '//*[local-name()="mtext"]'));

        $balanced = $this->office('f(x) + g[y]');
        $this->assertSame(4, $this->nodes($balanced, '//*[local-name()="mo"][.="(" or .=")" or .="[" or .="]"]'));
    }

    public function testBracketWithAnExponentStillPairsWithItsOpeningBracket(): void
    {
        $x = $this->office('(H_{uu})^{-1} \ge 0');
        $this->assertSame(0, $this->nodes($x, '//*[local-name()="mtext"]'));
        $this->assertSame(2, $this->nodes($x, '//*[local-name()="mo"][.="(" or .=")"]'));
    }

    public function testOutputIsStillWellFormedMathMl(): void
    {
        foreach (['\frac{a}{b}', '\sum_{i=1}^{n} x_i^2', '\begin{pmatrix}1&2\\3&4\end{pmatrix}'] as $tex) {
            $this->assertNotSame('', MathMl::forOffice(MathMl::fromTex($tex, true)), $tex);
        }
    }
}
