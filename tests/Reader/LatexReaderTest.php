<?php

namespace Pandoc\Tests\Reader;

use PHPUnit\Framework\TestCase;
use Pandoc\AST\Alignment;
use Pandoc\AST\BulletList;
use Pandoc\AST\CodeBlock;
use Pandoc\AST\DefinitionList;
use Pandoc\AST\Emph;
use Pandoc\AST\Figure;
use Pandoc\AST\Header;
use Pandoc\AST\Link;
use Pandoc\AST\Math;
use Pandoc\AST\MathType;
use Pandoc\AST\Note;
use Pandoc\AST\OrderedList;
use Pandoc\AST\Para;
use Pandoc\AST\Str;
use Pandoc\AST\Strong;
use Pandoc\AST\Table;
use Pandoc\Reader\LatexReader;
use Pandoc\Reader\ReaderFactory;
use Pandoc\Writer\XhtmlWriter;

class LatexReaderTest extends TestCase
{
    private function read(string $body, string $preamble = ''): \Pandoc\AST\Pandoc
    {
        return (new LatexReader())->read("\\documentclass{article}\n$preamble\\begin{document}\n$body\n\\end{document}");
    }

    private function text(array $inlines): string
    {
        return XhtmlWriter::stringify($inlines);
    }

    public function testFactoryCreatesLatexReader(): void
    {
        $this->assertInstanceOf(LatexReader::class, ReaderFactory::createForExtension('tex'));
    }

    public function testParagraphsAndInlineMarkup(): void
    {
        $doc = $this->read("Hello \\emph{big} \\textbf{world}.\n\nSecond para.");
        $this->assertCount(2, $doc->blocks);
        $this->assertInstanceOf(Para::class, $doc->blocks[0]);
        $this->assertInstanceOf(Emph::class, $doc->blocks[0]->content[2]);
        $this->assertInstanceOf(Strong::class, $doc->blocks[0]->content[4]);
        $this->assertSame('Hello big world.', $this->text($doc->blocks[0]->content));
    }

    public function testTypographyAndAccents(): void
    {
        $doc = $this->read("``quoted'' -- dash --- em caf\\'e na\\\"ive 50\\% \\& more");
        $this->assertSame('“quoted” – dash — em café naïve 50% & more', $this->text($doc->blocks[0]->content));
    }

    public function testCommentsAreStrippedButNotInVerbatim(): void
    {
        $doc = $this->read("keep % drop\nthis\n\n\\begin{verbatim}\n100% sure\n\\end{verbatim}");
        $this->assertSame('keep this', $this->text($doc->blocks[0]->content));
        $this->assertInstanceOf(CodeBlock::class, $doc->blocks[1]);
        $this->assertSame('100% sure', $doc->blocks[1]->text);
    }

    public function testSectionsLevelsAndLabels(): void
    {
        $doc = $this->read("\\section{One}\\label{a}\n\\subsection*{Two}\nText");
        $this->assertInstanceOf(Header::class, $doc->blocks[0]);
        $this->assertSame(1, $doc->blocks[0]->level);
        $this->assertSame('a', $doc->blocks[0]->attr->identifier);
        $this->assertSame(2, $doc->blocks[1]->level);
        $this->assertSame(['unnumbered'], $doc->blocks[1]->attr->classes);
        $this->assertSame('two', $doc->blocks[1]->attr->identifier);
    }

    public function testChapterShiftsLevels(): void
    {
        $doc = $this->read("\\chapter{C}\n\\section{S}");
        $this->assertSame([1, 2], [$doc->blocks[0]->level, $doc->blocks[1]->level]);
    }

    public function testMetadataAndLanguage(): void
    {
        $doc = $this->read(
            'x',
            "\\usepackage[english,french]{babel}\n\\title{My Title}\n\\author{A \\and B}\n\\date{2020-01-02}\n"
        );
        $this->assertSame('My Title', $this->text($doc->meta->map['title']));
        $this->assertCount(2, $doc->meta->map['author']);
        $this->assertSame('fr', $doc->meta->map['lang']);
    }

    public function testMacroExpansion(): void
    {
        $doc = $this->read('\\greet{Bob} and \\name', "\\newcommand{\\greet}[1]{Hi \\emph{#1}}\n\\def\\name{Alice}\n");
        $this->assertSame('Hi Bob and Alice', $this->text($doc->blocks[0]->content));
    }

    public function testNestedLists(): void
    {
        $doc = $this->read("\\begin{itemize}\n\\item A\n\\item B\n\\begin{enumerate}\n\\item B1\n\\end{enumerate}\n\\end{itemize}");
        $this->assertInstanceOf(BulletList::class, $doc->blocks[0]);
        $this->assertCount(2, $doc->blocks[0]->items);
        $this->assertInstanceOf(OrderedList::class, $doc->blocks[0]->items[1][1]);
    }

    public function testDescriptionList(): void
    {
        $doc = $this->read("\\begin{description}\n\\item[T] Def\n\\end{description}");
        $this->assertInstanceOf(DefinitionList::class, $doc->blocks[0]);
        $this->assertSame('T', $this->text($doc->blocks[0]->items[0][0]));
    }

    public function testMathAndFootnotesAndLinks(): void
    {
        $doc = $this->read("Inline \$x^2\$ and\\footnote{note} \\href{http://e.org}{site}.\n\n\\[ y = 1 \\]");
        $inl = $doc->blocks[0]->content;
        $this->assertInstanceOf(Math::class, $inl[2]);
        $this->assertSame(MathType::InlineMath, $inl[2]->type);
        $this->assertInstanceOf(Note::class, $inl[5]);
        $this->assertInstanceOf(Link::class, $inl[7]);
        $this->assertSame('http://e.org', $inl[7]->target->url);
        $this->assertSame(MathType::DisplayMath, $doc->blocks[1]->content[0]->type);
    }

    public function testTableWithHeaderAndColspan(): void
    {
        $doc = $this->read(
            "\\begin{table}[h]\\caption{Cap}\\label{t}\n\\begin{tabular}{lr}\n\\toprule\nA & B \\\\\n\\midrule\n\\multicolumn{2}{c}{wide} \\\\\n\\bottomrule\n\\end{tabular}\n\\end{table}"
        );
        $t = $doc->blocks[0];
        $this->assertInstanceOf(Table::class, $t);
        $this->assertSame('t', $t->attr->identifier);
        $this->assertCount(1, $t->head->rows);
        $this->assertSame(2, $t->bodies[0]->rows[0]->cells[0]->colSpan);
        $this->assertSame(Alignment::AlignCenter, $t->bodies[0]->rows[0]->cells[0]->align);
        $this->assertSame('Table 1: Cap', $this->text($t->caption->content[0]->content));
    }

    public function testFigureWithCaption(): void
    {
        $doc = $this->read("\\begin{figure}[ht]\n\\includegraphics[width=0.5\\textwidth]{nofile.png}\n\\caption{Cap}\\label{f}\n\\end{figure}");
        $fig = $doc->blocks[0];
        $this->assertInstanceOf(Figure::class, $fig);
        $this->assertSame('f', $fig->attr->identifier);
        $img = $fig->content[0]->content[0];
        $this->assertSame('nofile.png', $img->target->url);
        $this->assertSame([['width', '50%']], $img->attr->attributes);
    }

    public function testUnknownEnvironmentBecomesDiv(): void
    {
        $doc = $this->read("\\begin{theorem}\nTrue.\n\\end{theorem}");
        $this->assertSame(['theorem'], $doc->blocks[0]->attr->classes);
    }

    public function testReadsFileAndLoadsImages(): void
    {
        $dir = sys_get_temp_dir() . '/latexreader_' . uniqid();
        mkdir($dir . '/img', 0777, true);
        file_put_contents($dir . '/img/p.png', 'PNGDATA');
        file_put_contents($dir . '/part.tex', 'Included text.');
        file_put_contents(
            $dir . '/main.tex',
            "\\documentclass{article}\\graphicspath{{img/}}\\begin{document}\n\\input{part}\n\n\\includegraphics{p}\n\\end{document}"
        );
        $doc = (new LatexReader())->read($dir . '/main.tex');
        $this->assertSame('Included text.', $this->text($doc->blocks[0]->content));
        $this->assertNotNull($doc->mediaBag->lookup('p.png'));
        $this->assertSame('PNGDATA', $doc->mediaBag->lookup('p.png')['contents']);
        array_map('unlink', [$dir . '/img/p.png', $dir . '/part.tex', $dir . '/main.tex']);
        rmdir($dir . '/img');
        rmdir($dir);
    }

    public function testReferencesResolveToNumbers(): void
    {
        $doc = $this->read(
            "\\section{A}\\label{s:a}\n\\subsection{B}\\label{s:b}\n\\appendix\n\\section{C}\\label{s:c}\n"
            . "\\begin{figure}\\caption{F1}\\label{f:1}\\end{figure}\n"
            . "\\begin{table}\\caption{T1}\\label{t:1}\\end{table}\n"
            . "\\begin{equation}\\label{e:1} x \\end{equation}\n"
            . "See \\ref{s:b}, \\ref{s:c}, Fig.~\\ref{f:1}, \\autoref{t:1}, \\eqref{e:1}, \\ref{nope}."
        );
        $last = $doc->blocks[count($doc->blocks) - 1];
        $this->assertSame("See 1.1, A, Fig.\u{00A0}1, Table 1, (1), [nope].", $this->text($last->content));
        $this->assertSame([['number', '1.1']], $doc->blocks[1]->attr->attributes);
        $this->assertSame([['number', 'A']], $doc->blocks[2]->attr->attributes);
        $this->assertSame('Figure 1: F1', $this->text($doc->blocks[3]->caption->content[0]->content));
    }

    public function testCellColorCommandsDoNotLeakIntoText(): void
    {
        $doc = $this->read("\\begin{tabular}{ll}\\cellcolor[HTML]{DBDBDB}A & \\cellcolor{gray}B\\\\\\end{tabular}");
        $cells = $doc->blocks[0]->bodies[0]->rows[0]->cells;
        $this->assertSame('A', $this->text($cells[0]->content[0]->content));
        $this->assertSame('B', $this->text($cells[1]->content[0]->content));
    }

    public function testImageWidthRelativeToTextWidth(): void
    {
        $doc = $this->read('\\includegraphics[width=\\textwidth]{a.png} \\includegraphics[width=.25\\linewidth]{b.png}');
        $this->assertSame([['width', '100%']], $doc->blocks[0]->content[0]->attr->attributes);
        $this->assertSame([['width', '25%']], $doc->blocks[0]->content[2]->attr->attributes);
    }

    public function testBibliographyWithoutWidthArgument(): void
    {
        $doc = $this->read("Cited \\cite{a,b}.\n\\begin{thebibliography}\n\\bibitem[1]{a} First.\n\\bibitem{b} Second.\n\\end{thebibliography}");
        $refs = $doc->blocks[1];
        $this->assertCount(2, $refs->content);
        $this->assertSame('ref-a', $refs->content[0]->attr->identifier);
        $this->assertSame('Cited [1; 2].', $this->text($doc->blocks[0]->content));
    }

    public function testTheoremEnvironmentsAreNumberedAndLabelled(): void
    {
        $doc = $this->read(
            "\\section{A}\n\\begin{theorem}[Main]\\label{th:m} Body.\\end{theorem}\n"
            . "\\begin{lemma}\\label{le:1} L.\\end{lemma}\n\\begin{proof} Done.\\end{proof}\n\\autoref{le:1}, \\ref{th:m}",
            "\\newtheorem{theorem}{Theorem}[section]\n\\newtheorem{lemma}[theorem]{Lemma}\n"
        );
        $this->assertSame('th:m', $doc->blocks[1]->attr->identifier);
        $this->assertSame('Theorem 1.1 (Main). Body.', $this->text($doc->blocks[1]->content[0]->content));
        $this->assertSame('Lemma 1.2. L.', $this->text($doc->blocks[2]->content[0]->content));
        $this->assertSame('Proof. Done.', $this->text($doc->blocks[3]->content[0]->content));
        $this->assertSame('Lemma 1.2, 1.1', $this->text($doc->blocks[4]->content));
    }

    public function testHeaderRowsBetweenHlines(): void
    {
        $doc = $this->read("\\begin{tabular}{ll}\\hline H1 & H2 \\\\ \\hline a & b \\\\ c & d \\\\ \\hline\\end{tabular}");
        $this->assertCount(1, $doc->blocks[0]->head->rows);
        $this->assertCount(2, $doc->blocks[0]->bodies[0]->rows);
    }

    public function testEveryLabelInAMultiRowEquationIsAnAnchor(): void
    {
        $doc = $this->read("\\begin{align} a &= 1 \\label{r1} \\\\ b &= 2 \\label{r2}\\end{align}\nSee \\ref{r1}.");
        $ids = array_map(fn($i) => $i->attr->identifier ?? '', $doc->blocks[0]->content);
        $this->assertContains('r1', $ids);
        $this->assertContains('r2', $ids);
        $this->assertSame('See 1.', $this->text($doc->blocks[1]->content));
    }

    public function testAlgorithmicBlocks(): void
    {
        $doc = $this->read(
            "\\begin{algorithm}\\label{alg:a}\\begin{algorithmic}[1]\\caption{Demo}\\REQUIRE{Input \$x\$}\n"
            . "\\FOR{\$i=1,\\dots,n\$}\\STATE update \\COMMENT{step}\n\\IF{ok}\\STATE \\RETURN \$x\$\\ENDIF\\ENDFOR\\end{algorithmic}\\end{algorithm}\nSee \\autoref{alg:a}."
        );
        $alg = $doc->blocks[0];
        $this->assertSame('alg:a', $alg->attr->identifier);
        $this->assertSame('Algorithm 1: Demo', $this->text($alg->content[0]->content[0]->content));
        $lines = $alg->content[1]->content;
        $texts = array_map(fn($l) => $this->text($l->content[0]->content), $lines);
        $this->assertSame(['1: Require: Input x', '2: for i=1,\\dots,n do', '3: update ▷ step', '4: if ok then', '5: return x', '6: end if', '7: end for'], $texts);
        $this->assertSame(['alg-line', 'alg-i1'], $lines[2]->attr->classes);
        $this->assertSame(['alg-line', 'alg-i2'], $lines[4]->attr->classes);
        $this->assertSame('See Algorithm 1.', $this->text($doc->blocks[1]->content));
    }

    public function testColorAndHypersetupDoNotLeak(): void
    {
        $doc = $this->read('{\\hypersetup{citecolor=red}\\color{red}Plain}');
        $this->assertSame('Plain', $this->text($doc->blocks[0]->content));
    }

    public function testSubfiguresKeepParentLabelAndGetLetters(): void
    {
        $doc = $this->read(
            "\\begin{figure}\n\\begin{subfigure}[b]{0.5\\textwidth}\\includegraphics{a.png}\\caption{Left}\\label{sa}\\end{subfigure}\n"
            . "\\begin{subfigure}\\includegraphics{b.png}\\label{sb}\\end{subfigure}\n\\caption{Both}\\label{fig:both}\n\\end{figure}\n"
            . "See \\ref{fig:both}, \\ref{sa}, \\ref{sb}."
        );
        $fig = $doc->blocks[0];
        $this->assertSame('fig:both', $fig->attr->identifier);
        $this->assertSame('Figure 1: Both', $this->text($fig->caption->content[0]->content));
        $this->assertSame(['subfigure'], $fig->content[0]->attr->classes);
        $this->assertSame('sa', $fig->content[0]->attr->identifier);
        $this->assertSame('(a) Left', $this->text($fig->content[0]->caption->content[0]->content));
        $this->assertSame('See 1, 1a, 1b.', $this->text($doc->blocks[1]->content));
    }

    public function testParagraphHeadingsDoNotShiftSectionNumbers(): void
    {
        $doc = $this->read(
            "\\section{A}\n\\paragraph{P1} Text.\n\\subparagraph{P2} Text.\n\\section{B}\\label{b}\n\\subsection{C}\n\\paragraph{P3}\nSee \\ref{b}."
        );
        $numbers = [];
        foreach ($doc->blocks as $b) {
            if ($b instanceof Header) {
                $numbers[] = $b->attr->attributes[0][1] ?? '-';
            }
        }
        $this->assertSame(['1', '-', '-', '2', '2.1', '-'], $numbers);
        $this->assertSame('See 2.', $this->text($doc->blocks[count($doc->blocks) - 1]->content));
    }

    private function projectWithCover(string $tex): string
    {
        $dir = sys_get_temp_dir() . '/latexcover_' . uniqid();
        mkdir($dir . '/img', 0777, true);
        file_put_contents($dir . '/img/front.png', 'COVERDATA');
        file_put_contents($dir . '/main.tex', $tex);
        return $dir;
    }

    private function cleanup(string $dir): void
    {
        array_map('unlink', glob($dir . '/img/*'));
        @unlink($dir . '/main.tex');
        rmdir($dir . '/img');
        rmdir($dir);
    }

    public function testCoverCommandInPreambleLoadsImageIntoMediaBag(): void
    {
        $dir = $this->projectWithCover(
            "\\documentclass{article}\\providecommand{\\cover}[1]{}\\graphicspath{{img/}}\\cover{front}\n\\begin{document}Hello\\end{document}"
        );
        $doc = (new LatexReader())->read($dir . '/main.tex');
        $this->assertSame('front.png', $doc->meta->map['cover-image']);
        $this->assertSame('COVERDATA', $doc->mediaBag->lookup('front.png')['contents']);
        $this->assertSame('Hello', $this->text($doc->blocks[0]->content));
        $this->cleanup($dir);
    }

    public function testCoverCommandInBodyLeavesNoTextBehind(): void
    {
        $dir = $this->projectWithCover("\\begin{document}\\cover[ignored]{img/front.png}\nBody text.\\end{document}");
        $doc = (new LatexReader())->read($dir . '/main.tex');
        $this->assertSame('img/front.png', $doc->meta->map['cover-image']);
        $this->assertCount(1, $doc->blocks);
        $this->assertSame('Body text.', $this->text($doc->blocks[0]->content));
        $this->cleanup($dir);
    }

    public function testMissingCoverFileIsNotAnError(): void
    {
        $doc = $this->read('Text.', "\\cover{nothing/here}\n");
        $this->assertSame('nothing/here', $doc->meta->map['cover-image']);
        $this->assertNull($doc->mediaBag->lookup('nothing/here'));
    }

    public function testCitationKeysMatchBibliographyCaseInsensitively(): void
    {
        $doc = $this->read("See \\cite{Abbott2016, other}.\n\\begin{thebibliography}{9}\n\\bibitem{ABBOTT2016} A.\n\\end{thebibliography}");
        $inlines = $doc->blocks[0]->content;
        $link = array_values(array_filter($inlines, fn($i) => $i instanceof \Pandoc\AST\Link))[0];
        $this->assertSame('#ref-ABBOTT2016', $link->target->url);
        $this->assertSame('See [1; other].', $this->text($inlines));
    }

    public function testSubfigureKeepsItsDeclaredWidth(): void
    {
        $doc = $this->read("\\begin{figure}\\begin{subfigure}[b]{0.3\\textwidth}\\includegraphics[width=\\linewidth]{a.png}\\end{subfigure}\\begin{subfigure}{4cm}\\includegraphics{b.png}\\end{subfigure}\\end{figure}");
        $subs = $doc->blocks[0]->content;
        $this->assertSame([['width', '30%']], $subs[0]->attr->attributes);
        $this->assertSame([['width', '4cm']], $subs[1]->attr->attributes);
        $this->assertSame([['width', '100%']], $subs[0]->content[0]->content[0]->attr->attributes);
    }
}
