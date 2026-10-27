<?php

namespace Pandoc\Tests\Writer;

use PHPUnit\Framework\TestCase;
use Pandoc\AST\{Alignment, Attr, BulletList, Caption, Cell, Cite, CodeBlock, DefinitionList, Div, Emph, Figure, Header, Image, LineBreak, Link, ListAttributes, ListNumberDelim, ListNumberStyle, Math, MathType, Meta, Note, OrderedList, Pandoc, Para, Plain, Quoted, QuoteType, Row, SmallCaps, Space, Span, Str, Strikeout, Strong, Table, TableBody, TableFoot, TableHead, Target, Underline};
use Pandoc\Reader\{LatexReader, OdtReader};
use Pandoc\Writer\{LatexWriter, OdtWriter};

class LatexWriterUpgradeTest extends TestCase
{
    private function body(array $blocks, array $meta = [], array $options = []): string
    {
        return (new LatexWriter($options))->write(new Pandoc(new Meta($meta), $blocks), false);
    }

    private function full(array $blocks, array $meta = []): string
    {
        return (new LatexWriter())->write(new Pandoc(new Meta($meta), $blocks), true);
    }

    private function words(string $s): Para
    {
        $in = [];
        foreach (explode(' ', $s) as $k => $w) {
            if ($k) {
                $in[] = new Space();
            }
            $in[] = new Str($w);
        }
        return new Para($in);
    }

    private function table(array $rows, array $head = [], array $colSpecs = [], ?Caption $caption = null, string $id = ''): Table
    {
        return new Table(new Attr($id), $caption ?? new Caption(), $colSpecs, new TableHead(new Attr(), $head), [new TableBody(new Attr(), 0, [], $rows)], new TableFoot(new Attr(), []));
    }

    private function cell(string $text, Alignment $a = Alignment::AlignDefault, int $span = 1): Cell
    {
        return new Cell(new Attr(), $a, 1, $span, [new Plain([new Str($text)])]);
    }

    // ---- labels and links ---------------------------------------------------------------------------

    public function testLabelsAreWrittenOnlyForIdsThatAreLinkedTo(): void
    {
        $blocks = [new Header(1, new Attr('plain'), [new Str('A')]), new Header(1, new Attr('target'), [new Str('B')]),
            new Para([new Link(new Attr(), [new Str('see')], new Target('#target'))])];
        $out = $this->body($blocks);
        $this->assertStringContainsString("\\section{A}\n", $out . "\n");
        $this->assertStringNotContainsString('\label{plain}', $out);
        $this->assertStringContainsString('\section{B}\label{target}', $out);
        $this->assertStringContainsString('\hyperref[target]{see}', $out);
    }

    public function testReferencesToNumberedThingsBecomeRefAndOthersKeepTheirText(): void
    {
        $ref = fn(string $id, string $text) => new Link(new Attr('', [], [['reference-type', 'ref'], ['reference', $id]]), [new Str($text)], new Target('#' . $id));
        $blocks = [new Header(1, new Attr('sec'), [new Str('S')]), new Div(new Attr('thm'), [$this->words('Theorem body')]),
            new Para([new Str('See'), new Space(), $ref('sec', '1'), new Space(), $ref('thm', '2')])];
        $out = $this->body($blocks);
        $this->assertStringContainsString('See \ref{sec} \hyperref[thm]{2}', $out);
        $this->assertStringContainsString('\label{thm}', $out);
    }

    public function testHeadingLevelsAboveThreeAreNoLongerSections(): void
    {
        $out = $this->body([new Header(4, new Attr(), [new Str('D')]), new Header(5, new Attr(), [new Str('E')]), new Header(2, new Attr('', ['unnumbered']), [new Str('F')])]);
        $this->assertStringContainsString('\paragraph{D}', $out);
        $this->assertStringContainsString('\subparagraph{E}', $out);
        $this->assertStringContainsString('\subsection*{F}', $out);
    }

    public function testLetterNumberedHeadingStartsTheAppendix(): void
    {
        $h = fn($n, $t) => new Header(1, new Attr('', [], [['number', $n]]), [new Str($t)]);
        $out = $this->body([$h('1', 'Body'), $h('A', 'First'), $h('B', 'Second')]);
        $this->assertSame(1, substr_count($out, '\appendix'));
        $this->assertLessThan(strpos($out, '\section{First}'), strpos($out, '\appendix'));
        $this->assertGreaterThan(strpos($out, '\section{Body}'), strpos($out, '\appendix'));
    }

    // ---- figures, images, tables ----------------------------------------------------------------------

    public function testImageSizeOptions(): void
    {
        $img = fn(array $attrs) => new Para([new Image(new Attr('', [], $attrs), [], new Target('a.png'))]);
        $this->assertStringContainsString('\includegraphics[width=0.5\textwidth]{a.png}', $this->body([$img([['width', '50%']])]));
        $this->assertStringContainsString('\includegraphics[width=\textwidth]{a.png}', $this->body([$img([['width', '100%']])]));
        $this->assertStringContainsString('\includegraphics[width=8cm]{a.png}', $this->body([$img([['width', '8cm']])]));
        $this->assertStringContainsString('\includegraphics{a.png}', $this->body([$img([])]), 'no attributes, no options');
    }

    public function testFigureWithCaptionAndLabel(): void
    {
        $fig = new Figure(new Attr('f1'), new Caption(null, [new Plain([new Str('A'), new Space(), new Str('pic')])]),
            [new Plain([new Image(new Attr('', [], [['width', '60%']]), [], new Target('p.png'))])]);
        $out = $this->body([$fig, new Para([new Link(new Attr(), [new Str('x')], new Target('#f1'))])]);
        $this->assertStringContainsString("\\begin{figure}[ht]\n\\centering\n\\includegraphics[width=0.6\\textwidth]{p.png}\n\\caption{A pic}\n\\label{f1}\n\\end{figure}", $out);
    }

    public function testSubfiguresNeedSubcaptionOnlyWhenPresent(): void
    {
        $sub = fn($id, $cap) => new Figure(new Attr($id, ['subfigure'], [['width', '45%']]), new Caption(null, [new Plain([new Str($cap)])]),
            [new Plain([new Image(new Attr(), [], new Target("$cap.png"))])]);
        $fig = new Figure(new Attr(), new Caption(null, [new Plain([new Str('Both')])]), [$sub('', 'a'), $sub('', 'b')]);
        $out = $this->full([$fig]);
        $this->assertStringContainsString('\usepackage{subcaption}', $out);
        $this->assertStringContainsString('\begin{subfigure}{0.45\textwidth}', $out);
        $this->assertStringContainsString("\\hfill\n", $out);
        $this->assertStringContainsString('\caption{Both}', $out);
        $this->assertStringNotContainsString('subcaption', $this->full([$this->words('plain')]));
    }

    public function testTableCaptionAlignmentSpansAndRules(): void
    {
        $t = $this->table(
            [new Row(new Attr(), [$this->cell('wide', Alignment::AlignDefault, 2)]), new Row(new Attr(), [$this->cell('x'), $this->cell('y', Alignment::AlignRight)])],
            [new Row(new Attr(), [$this->cell('A'), $this->cell('B')])],
            [[Alignment::AlignLeft, null], [Alignment::AlignRight, null]],
            new Caption(null, [new Plain([new Str('Cap')])]),
            't1'
        );
        $out = $this->body([$t, new Para([new Link(new Attr(), [new Str('x')], new Target('#t1'))])]);
        $this->assertStringContainsString('\caption{Cap}\label{t1}', $out);
        $this->assertStringContainsString('\begin{tabular}{@{}lr@{}}', $out);
        $this->assertStringContainsString('\multicolumn{2}{l}{wide} \\\\', $out);
        $this->assertStringContainsString("A & B \\\\\n\\midrule", $out);
    }

    public function testDisplayMathInATableCellIsInline(): void
    {
        $cell = new Cell(new Attr(), Alignment::AlignDefault, 1, 1, [new Plain([new Math(MathType::DisplayMath, 'x^2')])]);
        $out = $this->body([$this->table([new Row(new Attr(), [$cell])])]);
        $this->assertStringContainsString('$x^2$', $out);
        $this->assertStringNotContainsString('\[', $out);
    }

    public function testWideTablesAreOnlyCompactedOnRequest(): void
    {
        $row = new Row(new Attr(), array_map(fn($i) => $this->cell("c$i"), range(1, 8)));
        $this->assertStringNotContainsString('\small', $this->body([$this->table([$row])]));
        $this->assertStringContainsString('\small', $this->body([$this->table([$row])], [], ['compactWideTables' => true]));
    }

    // ---- lists, notes, inline ---------------------------------------------------------------------------

    public function testOrderedListStyleAndStart(): void
    {
        $items = [[new Plain([new Str('x')])], [new Plain([new Str('y')])]];
        $plain = $this->body([new OrderedList(new ListAttributes(), $items)]);
        $this->assertStringNotContainsString('renewcommand', $plain, 'the default list is written as before');
        $custom = $this->body([new OrderedList(new ListAttributes(3, ListNumberStyle::UpperRoman, ListNumberDelim::OneParen), $items)]);
        $this->assertStringContainsString('\renewcommand{\labelenumi}{\Roman{enumi})}', $custom);
        $this->assertStringContainsString('\setcounter{enumi}{2}', $custom);
        $this->assertStringContainsString('\renewcommand{\labelenumi}{\arabic{enumi}.}', $custom, 'the default is restored afterwards');
    }

    public function testNestedOrderedListUsesTheNextCounter(): void
    {
        $nested = fn(ListAttributes $a) => $this->body([new OrderedList(new ListAttributes(), [[new Plain([new Str('o')]),
            new OrderedList($a, [[new Plain([new Str('i')])]])]])]);
        $this->assertStringNotContainsString('renewcommand', $nested(new ListAttributes()), 'unspecified: LaTeX numbers it (a)');
        $this->assertStringNotContainsString('renewcommand', $nested(new ListAttributes(1, ListNumberStyle::LowerAlpha, ListNumberDelim::TwoParens)), 'explicitly the LaTeX default');
        $decimal = $nested(new ListAttributes(1, ListNumberStyle::Decimal, ListNumberDelim::Period));
        $this->assertStringContainsString('\renewcommand{\labelenumii}{\arabic{enumii}.}', $decimal);
        $this->assertStringContainsString('\renewcommand{\labelenumii}{(\alph{enumii})}', $decimal, 'and the default comes back after the list');
    }

    public function testDefinitionList(): void
    {
        $out = $this->body([new DefinitionList([[[new Str('Term')], [[new Plain([new Str('Meaning')])]]]])]);
        $this->assertStringContainsString("\\begin{description}\n\\item[Term] Meaning\n\\end{description}", $out);
    }

    public function testFootnoteWithBlocksAndWithInlines(): void
    {
        $this->assertStringContainsString('\footnote{Block note}', $this->body([new Para([new Note([$this->words('Block note')])])]));
        $this->assertStringContainsString('\footnote{inline}', $this->body([new Para([new Note([new Str('inline')])])]));
    }

    public function testCitationsAreGroupedAndBibliographyBecomesThebibliography(): void
    {
        $c = fn($k, $n) => new Link(new Attr('', ['citation']), [new Str($n)], new Target('#ref-' . $k));
        $para = new Para([new Str('See'), new Space(), new Str('['), $c('a', '1'), new Str(';'), new Space(), $c('b', '2'), new Str('].')]);
        $bib = new Div(new Attr('refs', ['references']), [
            new Div(new Attr('ref-a', ['reference']), [new Para([new Str('[1]'), new Space(), new Str('First'), new Space(), new Str('entry')])]),
            new Div(new Attr('ref-b', ['reference']), [new Para([new Str('[2]'), new Space(), new Str('Second')])]),
        ]);
        $out = $this->body([$para, $bib]);
        $this->assertStringContainsString('See \cite{a,b}.', $out);
        $this->assertStringContainsString("\\begin{thebibliography}{99}\n\\bibitem{a} First entry\n\\bibitem{b} Second\n\\end{thebibliography}", $out);
    }

    public function testNewInlineNodes(): void
    {
        $out = $this->body([new Para([new SmallCaps([new Str('sc')]), new Space(), new Quoted(QuoteType::DoubleQuote, [new Str('q')]), new Space(),
            new Quoted(QuoteType::SingleQuote, [new Str('s')]), new LineBreak(), new Str('next')])]);
        $this->assertSame("\\textsc{sc} ``q'' `s'\\\\next", $out);
    }

    public function testUnderlineUsesSoulOnlyForSimpleText(): void
    {
        $simple = $this->body([new Para([new Underline([new Str('plain'), new Space(), new Strong([new Str('bold')])])])]);
        $this->assertSame('\ul{plain \textbf{bold}}', $simple);
        $link = $this->body([new Para([new Underline([new Link(new Attr(), [new Str('x')], new Target('#t'))])])]);
        $this->assertStringStartsWith('\underline{', $link, 'soul cannot hold a link');
        $struck = $this->body([new Para([new Strikeout([new Math(MathType::InlineMath, 'x')])])]);
        $this->assertSame('$x$', $struck);
    }

    public function testSymbolsThatPdflatexRejectAreMapped(): void
    {
        $out = $this->body([new Para([new Str('a → b ≤ c α'), new Space(), new Str("x\u{200B}y"), new Space(), new Str('✓')])]);
        $this->assertStringContainsString('\ensuremath{\rightarrow}', $out);
        $this->assertStringContainsString('\ensuremath{\leq}', $out);
        $this->assertStringContainsString('\ensuremath{\alpha}', $out);
        $this->assertStringContainsString('\ensuremath{\checkmark}', $out);
        $this->assertStringContainsString('xy', $out, 'zero-width space is dropped');
        $this->assertStringContainsString('é à ç', $this->body([new Para([new Str('é à ç')])]), 'what inputenc handles is left alone');
    }

    public function testLabelledEquationKeepsItsNumberThroughTag(): void
    {
        $eq = new Para([new Span(new Attr('eq1'), [new Math(MathType::DisplayMath, 'a=b \qquad (7)')])]);
        $out = $this->body([$eq, new Para([new Link(new Attr(), [new Str('7')], new Target('#eq1'))])]);
        $this->assertStringContainsString("\\begin{equation}\\label{eq1}\\tag{7}\na=b\n\\end{equation}", $out);
        // an unreferenced display formula is written as before, tag included
        $this->assertSame('\[a=b \qquad (7)\]', $this->body([new Para([new Math(MathType::DisplayMath, 'a=b \qquad (7)')])]));
    }

    public function testSeveralLabelsOfOneEquationFollowTheEnvironment(): void
    {
        $eq = new Para([new Span(new Attr('r1'), []), new Span(new Attr('r2'), []), new Math(MathType::DisplayMath, 'x')]);
        $link = fn($id) => new Link(new Attr(), [new Str($id)], new Target('#' . $id));
        $out = $this->body([$eq, new Para([$link('r1'), $link('r2')])]);
        $this->assertSame(1, substr_count(substr($out, 0, strpos($out, '\end{equation}')), '\label'), 'amsmath allows one label inside');
        $this->assertStringContainsString("\\end{equation}\n\\label{r2}", $out);
    }

    public function testLinkToAnIdThatDoesNotExistKeepsOnlyItsText(): void
    {
        $dead = new Link(new Attr('', [], [['reference-type', 'ref'], ['reference', 'gone']]), [new Str('[gone]')], new Target('#gone'));
        $out = $this->body([new Para([new Str('see'), new Space(), $dead])]);
        $this->assertSame('see [gone]', $out, 'no \\ref or \\hyperref to a label nothing defines');
    }

    public function testImagesPdflatexCannotReadBecomeAVisibleMarkInsteadOfAnError(): void
    {
        $img = fn(string $u) => $this->body([new Para([new Image(new Attr(), [], new Target($u))])]);
        $this->assertSame('\fbox{[image: scheme.emf]}', $img('Pictures/scheme.emf'));
        $this->assertSame('\fbox{[image: a\_b.svg]}', $img('a_b.svg'));
        $this->assertSame('\\fbox{[image: x.GIF]}', $img('x.GIF'), 'the extension test ignores case and the real name is shown');
        $this->assertSame('\includegraphics{Pictures/ok.png}', $img('Pictures/ok.png'));
        $this->assertSame('\includegraphics{ok.jpg}', $img('ok.jpg'));
    }

    public function testUnicodeSuperscriptsSubscriptsAndProofBoxes(): void
    {
        $out = $this->body([new Para([new Str('Fe³⁺, H₂O, SO₄²⁻, x⁻¹ ❑ ◻ ■')])]);
        $this->assertStringContainsString('Fe³\textsuperscript{+}', $out, '³ is native to inputenc and left as it is');
        $this->assertStringContainsString('H\textsubscript{2}O', $out);
        $this->assertStringContainsString('SO\textsubscript{4}²\textsuperscript{-}', $out);
        $this->assertSame(2, substr_count($out, '\\ensuremath{\\square}'), '❑ and ◻ are both squares');
        $this->assertStringContainsString('\ensuremath{\blacksquare}', $out);
    }

    // ---- preamble ------------------------------------------------------------------------------------

    public function testPreambleOnlyGrowsWhenTheDocumentNeedsIt(): void
    {
        $plain = $this->full([$this->words('Hello world')]);
        $this->assertStringNotContainsString('amsmath', $plain);
        $this->assertStringNotContainsString('\maketitle', $plain);
        $this->assertStringNotContainsString('babel', $plain);
        $math = $this->full([new Para([new Math(MathType::InlineMath, 'x')])]);
        $this->assertStringContainsString('\usepackage{amsmath}', $math);
        $this->assertStringContainsString('\usepackage{amssymb}', $math);
    }

    public function testTitleBlockLanguageAndCover(): void
    {
        $out = $this->full([$this->words('Body')], [
            'title' => [new Str('My'), new Space(), new Str('Book')],
            'author' => [[new Str('Ann')], [new Str('Bob')]],
            'date' => [new Str('2024')],
            'lang' => 'fr-FR',
            'cover-image' => 'Pictures/front.png',
        ]);
        $this->assertStringContainsString('\usepackage[french]{babel}', $out);
        $this->assertStringContainsString('\title{My Book}', $out);
        $this->assertStringContainsString('\author{Ann \and Bob}', $out);
        $this->assertStringContainsString('\date{2024}', $out);
        $this->assertStringContainsString("\\providecommand{\\cover}[1]{}\n\\cover{Pictures/front.png}", $out);
        $this->assertLessThan(strpos($out, 'Body'), strpos($out, '\maketitle'));
    }

    // ---- the whole loop ----------------------------------------------------------------------------------

    private const SOURCE = <<<'TEX'
\documentclass{article}
\title{Loop test}\author{Ann \and Bob}\date{2024}
\begin{document}
\section{Intro}\label{sec:i}
Text with \emph{emphasis}, \textbf{bold}, a formula $E=mc^{2}$ and a note.\footnote{The note.}
See Section~\ref{sec:i}, Table~\ref{tab:t} and \cite{k1}. Università, Ångström, déjà vu.

\begin{equation}\label{eq:one} a^2+b^2=c^2 \end{equation}
Equation~\ref{eq:one} holds.

\begin{itemize}\item A \item B\begin{enumerate}\item B1\end{enumerate}\end{itemize}

\begin{table}\caption{Data}\label{tab:t}\begin{tabular}{lr}\toprule A & B \\\midrule 1 & 2 \\\bottomrule\end{tabular}\end{table}

\section{Second}
More.
\section{Third}
End.
\begin{thebibliography}{9}\bibitem{k1} A. Author, Title.\end{thebibliography}
\end{document}
TEX;

    public function testLatexToOdtToLatexKeepsTheStructure(): void
    {
        $odt = (new OdtWriter())->write((new LatexReader())->read(self::SOURCE));
        $tex = (new LatexWriter())->write((new OdtReader())->read($odt));
        $this->assertStringContainsString('\section{Intro}\label{sec:i}', $tex);
        $this->assertStringContainsString('\ref{sec:i}', $tex);
        $this->assertStringContainsString('\cite{k1}', $tex);
        $this->assertStringContainsString('\footnote{The note.}', $tex);
        $this->assertStringContainsString('\begin{table}', $tex);
        $this->assertStringContainsString('\caption{Data}\label{tab:t}', $tex);
        $this->assertStringContainsString('\begin{thebibliography}', $tex);
        $this->assertStringContainsString('Università, Ångström', $tex);
        $this->assertStringContainsString('\begin{equation}\label{eq:one}', $tex);

        // and the result is read back as the same document
        $again = (new LatexReader())->read($tex);
        $original = (new LatexReader())->read(self::SOURCE);
        $headings = fn($d) => array_map(fn($h) => $h->level, array_values(array_filter($d->blocks, fn($b) => $b instanceof Header)));
        $this->assertSame($headings($original), $headings($again));
        $this->assertSame('Loop test', \Pandoc\Writer\XhtmlWriter::stringify($again->meta->map['title']));
    }

    public function testGeneratedLatexCompilesWithPdflatex(): void
    {
        $pdflatex = is_executable('/Library/TeX/texbin/pdflatex') ? '/Library/TeX/texbin/pdflatex' : (trim((string) shell_exec('command -v pdflatex 2>/dev/null')) ?: '');
        if ($pdflatex === '') {
            $this->markTestSkipped('pdflatex is not installed');
        }
        $odt = (new OdtWriter())->write((new LatexReader())->read(self::SOURCE));
        $dir = sys_get_temp_dir() . '/latexgate_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/doc.tex', (new LatexWriter())->write((new OdtReader())->read($odt)));
        exec('cd ' . escapeshellarg($dir) . ' && ' . escapeshellarg($pdflatex) . ' -interaction=nonstopmode -halt-on-error doc.tex 2>&1', $out, $code);
        $log = (string) @file_get_contents($dir . '/doc.log');
        $this->assertSame(0, $code, "pdflatex failed:\n" . implode("\n", array_slice($out, -25)));
        $this->assertFileExists($dir . '/doc.pdf');
        $this->assertStringNotContainsString("\n!", $log);
        array_map('unlink', array_filter((array) glob($dir . '/*'), 'is_file'));
        rmdir($dir);
    }
}
