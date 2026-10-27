<?php

namespace Pandoc\Tests\Reader;

use PHPUnit\Framework\TestCase;
use Pandoc\AST\{BlockQuote, BulletList, CodeBlock, DefinitionList, Div, Emph, Figure, Header, Image, Link, Math, MathType, Note, OrderedList, Para, Plain, Strong, Table};
use Pandoc\Reader\{LatexReader, OdtReader, ReaderFactory};
use Pandoc\Writer\{OdtWriter, XhtmlWriter};

class OdtReaderTest extends TestCase
{
    /** @var string[] */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }

    /** LaTeX -> ODT (OdtWriter) -> AST (OdtReader). */
    private function roundTrip(string $body, string $preamble = '', array $options = []): \Pandoc\AST\Pandoc
    {
        $latex = "\\documentclass{article}\n$preamble\\begin{document}\n$body\n\\end{document}";
        $bytes = (new OdtWriter($options))->write((new LatexReader())->read($latex));
        return (new OdtReader())->read($bytes);
    }

    private function text(array $inlines): string
    {
        return XhtmlWriter::stringify($inlines);
    }

    /** Builds an .odt from raw content.xml / styles.xml bodies, as another application would write it. */
    private function handMade(string $bodyXml, string $automaticStyles = '', string $officeStyles = ''): string
    {
        $ns = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" '
            . 'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" '
            . 'xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" '
            . 'xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" office:version="1.3"';
        $path = tempnam(sys_get_temp_dir(), 'odthand');
        $this->tmp[] = $path;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.text');
        $zip->addFromString('content.xml', "<?xml version=\"1.0\"?><office:document-content $ns><office:automatic-styles>$automaticStyles</office:automatic-styles>"
            . "<office:body><office:text>$bodyXml</office:text></office:body></office:document-content>");
        $zip->addFromString('styles.xml', "<?xml version=\"1.0\"?><office:document-styles $ns><office:styles>$officeStyles</office:styles></office:document-styles>");
        $zip->close();
        return $path;
    }

    public function testFactoryCreatesOdtReader(): void
    {
        $this->assertInstanceOf(OdtReader::class, ReaderFactory::createForExtension('odt'));
    }

    public function testRejectsGarbage(): void
    {
        $this->expectException(\RuntimeException::class);
        (new OdtReader())->read('this is not a zip file');
    }

    public function testHeadingsAndParagraphs(): void
    {
        $doc = $this->roundTrip('\section{One}Hello \emph{big} \textbf{world}.\subsection{Two}More.');
        $this->assertInstanceOf(Header::class, $doc->blocks[0]);
        $this->assertSame(1, $doc->blocks[0]->level);
        $this->assertSame('1 One', $this->text($doc->blocks[0]->content), 'the manual number written by OdtWriter stays in the text');
        $this->assertSame(2, $doc->blocks[2]->level);
        $para = $doc->blocks[1];
        $this->assertInstanceOf(Para::class, $para);
        $this->assertInstanceOf(Emph::class, $para->content[2]);
        $this->assertInstanceOf(Strong::class, $para->content[4]);
        $this->assertSame('Hello big world.', $this->text($para->content));
    }

    public function testTitleAuthorsDateAndLanguageBecomeMeta(): void
    {
        $doc = $this->roundTrip('Body.', "\\title{My Book}\\author{Ann \\and Bob}\\date{2024-03-05}\\usepackage[french]{babel}\n");
        $this->assertSame('My Book', $this->text($doc->meta->map['title']));
        $this->assertCount(2, $doc->meta->map['author']);
        $this->assertSame('Ann', $this->text($doc->meta->map['author'][0]));
        $this->assertSame('fr', $doc->meta->map['lang']);
        $this->assertCount(1, $doc->blocks, 'the title block is not repeated in the body');
    }

    public function testListsKeepTheirKindAndNesting(): void
    {
        $doc = $this->roundTrip("\\begin{itemize}\\item A\\item B\\begin{enumerate}\\item B1\\end{enumerate}\\end{itemize}");
        $this->assertInstanceOf(BulletList::class, $doc->blocks[0]);
        $this->assertCount(2, $doc->blocks[0]->items);
        $this->assertInstanceOf(OrderedList::class, $doc->blocks[0]->items[1][1]);
        $this->assertInstanceOf(Plain::class, $doc->blocks[0]->items[0][0]);
    }

    public function testOrderedListFormatAndStart(): void
    {
        $doc = $this->roundTrip("\\begin{enumerate}[(a)]\\item x\\item y\\end{enumerate}");
        $list = $doc->blocks[0];
        $this->assertInstanceOf(OrderedList::class, $list);
        $this->assertSame(\Pandoc\AST\ListNumberStyle::LowerAlpha, $list->attr->style);
        $this->assertSame(\Pandoc\AST\ListNumberDelim::TwoParens, $list->attr->delim);
    }

    public function testLinksFootnotesAndBookmarks(): void
    {
        $doc = $this->roundTrip('\section{Intro}\label{sec:i} See Section~\ref{sec:i} and \href{http://e.org}{site}.\footnote{A note.}');
        $this->assertSame('sec:i', $doc->blocks[0]->attr->identifier);
        $inl = $doc->blocks[1]->content;
        $links = array_values(array_filter($inl, fn($i) => $i instanceof Link));
        $this->assertSame('#sec:i', $links[0]->target->url);
        $this->assertSame([['reference-type', 'ref'], ['reference', 'sec:i']], $links[0]->attr->attributes, 'a number linking to a bookmark is a reference');
        $this->assertSame('http://e.org', $links[1]->target->url);
        $notes = array_values(array_filter($inl, fn($i) => $i instanceof Note));
        $this->assertSame('A note.', $this->text($notes[0]->content[0]->content));
    }

    public function testTableWithHeaderSpanAndCaption(): void
    {
        $doc = $this->roundTrip("\\begin{table}\\caption{Cap}\\label{t:1}\\begin{tabular}{lr}\\toprule A & B \\\\\\midrule \\multicolumn{2}{c}{wide} \\\\\\bottomrule\\end{tabular}\\end{table}");
        $t = $doc->blocks[0];
        $this->assertInstanceOf(Table::class, $t);
        $this->assertSame('t:1', $t->attr->identifier);
        $this->assertSame('Cap', $this->text($t->caption->content[0]->content), 'the "Table 1:" label is removed from the caption');
        $this->assertCount(1, $t->head->rows);
        $this->assertSame(2, $t->bodies[0]->rows[0]->cells[0]->colSpan);
        $this->assertSame(\Pandoc\AST\Alignment::AlignRight, $t->head->rows[0]->cells[1]->align);
    }

    public function testFigureWithCaptionAndImage(): void
    {
        $dir = sys_get_temp_dir() . '/odtrd_' . uniqid();
        mkdir($dir);
        imagepng(imagecreatetruecolor(400, 200), $dir . '/pic.png');
        file_put_contents($dir . '/main.tex', "\\begin{document}\\begin{figure}\\includegraphics[width=0.5\\textwidth]{pic.png}\\caption{A pic}\\label{f:1}\\end{figure}\\end{document}");
        $bytes = (new OdtWriter(['resourcePath' => [$dir]]))->write((new LatexReader())->read($dir . '/main.tex'));
        $doc = (new OdtReader())->read($bytes);
        $fig = $doc->blocks[0];
        $this->assertInstanceOf(Figure::class, $fig);
        $this->assertSame('f:1', $fig->attr->identifier);
        $this->assertSame('A pic', $this->text($fig->caption->content[0]->content));
        $img = $fig->content[0]->content[0];
        $this->assertInstanceOf(Image::class, $img);
        $this->assertSame([['width', '50%']], $img->attr->attributes);
        $this->assertNotNull($doc->mediaBag->lookup($img->target->url));
        $this->assertSame('image/png', $doc->mediaBag->lookup($img->target->url)['mime']);
        unlink($dir . '/pic.png');
        unlink($dir . '/main.tex');
        rmdir($dir);
    }

    public function testSubfiguresComeBackAsSubfigures(): void
    {
        $dir = sys_get_temp_dir() . '/odtsubrd_' . uniqid();
        mkdir($dir);
        foreach (['a', 'b'] as $n) {
            imagepng(imagecreatetruecolor(100, 80), "$dir/$n.png");
        }
        $sub = fn($n) => "\\begin{subfigure}{0.4\\textwidth}\\includegraphics[width=\\textwidth]{{$n}.png}\\caption{{$n}}\\end{subfigure}";
        file_put_contents($dir . '/main.tex', '\\begin{document}\\begin{figure}' . $sub('a') . $sub('b') . '\\caption{Both}\\label{f:both}\\end{figure}\\end{document}');
        $doc = (new OdtReader())->read((new OdtWriter(['resourcePath' => [$dir]]))->write((new LatexReader())->read($dir . '/main.tex')));
        $fig = $doc->blocks[0];
        $this->assertInstanceOf(Figure::class, $fig);
        $this->assertSame('f:both', $fig->attr->identifier);
        $this->assertSame('Both', $this->text($fig->caption->content[0]->content));
        $this->assertCount(2, $fig->content);
        $this->assertSame(['subfigure'], $fig->content[0]->attr->classes);
        $this->assertSame('a', $this->text($fig->content[0]->caption->content[0]->content), 'the "(a)" prefix is removed');
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }

    public function testFormulasAreConvertedToLatexAndDisplayIsDetected(): void
    {
        $doc = $this->roundTrip("Inline \$E=mc^{2}\$ here.\n\n\\[ \\frac{a}{b} \\]");
        $inline = array_values(array_filter($doc->blocks[0]->content, fn($i) => $i instanceof Math))[0];
        $this->assertSame(MathType::InlineMath, $inline->type);
        $this->assertSame('E=mc^{2}', $inline->text);
        $display = $doc->blocks[1]->content[0];
        $this->assertSame(MathType::DisplayMath, $display->type);
        $this->assertSame('\frac{a}{b}', $display->text);
    }

    public function testCodeQuoteDefinitionAndAbstract(): void
    {
        $doc = $this->roundTrip("\\begin{verbatim}\nif x:\n    y = 1\n\\end{verbatim}\\begin{quote}Quoted\\end{quote}\\begin{description}\\item[Term] Def\\end{description}\\begin{abstract}Abs\\end{abstract}");
        $this->assertInstanceOf(CodeBlock::class, $doc->blocks[0]);
        $this->assertSame("if x:\n    y = 1", $doc->blocks[0]->text);
        $this->assertInstanceOf(BlockQuote::class, $doc->blocks[1]);
        $this->assertInstanceOf(DefinitionList::class, $doc->blocks[2]);
        $this->assertSame('Term', $this->text($doc->blocks[2]->items[0][0]));
        $this->assertInstanceOf(Div::class, $doc->blocks[3]);
        $this->assertSame(['abstract'], $doc->blocks[3]->attr->classes);
    }

    public function testBibliographyEntriesAndCitations(): void
    {
        $doc = $this->roundTrip("See \\cite{k1}.\n\\begin{thebibliography}{9}\\bibitem{k1} A. Author, Title.\\end{thebibliography}");
        $link = array_values(array_filter($doc->blocks[0]->content, fn($i) => $i instanceof Link))[0];
        $this->assertSame('#ref-k1', $link->target->url);
        $this->assertSame(['citation'], $link->attr->classes);
        $refs = $doc->blocks[1];
        $this->assertSame('refs', $refs->attr->identifier);
        $this->assertSame('ref-k1', $refs->content[0]->attr->identifier);
    }

    public function testAlgorithmLinesKeepTheirIndentation(): void
    {
        $doc = $this->roundTrip("\\begin{algorithm}\\caption{Demo}\\begin{algorithmic}\\FOR{i}\\STATE work\\ENDFOR\\end{algorithmic}\\end{algorithm}");
        $alg = array_values(array_filter($doc->blocks, fn($b) => $b instanceof Div && in_array('algorithmic', $b->attr->classes, true)))[0];
        $classes = array_map(fn($d) => $d->attr->classes[1], $alg->content);
        $this->assertSame(['alg-i0', 'alg-i1', 'alg-i0'], $classes);
    }

    public function testNoMultiByteCharacterIsSplit(): void
    {
        $text = 'Università Ångström déjà vu — naïve café';
        $doc = $this->roundTrip($text . '.');
        $out = $this->text($doc->blocks[0]->content);
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
        $this->assertSame($text . '.', $out);
    }

    // ---- files written by other applications ----------------------------------------------------------

    public function testDirectFormattingThroughAutomaticStyles(): void
    {
        $file = $this->handMade(
            '<text:p text:style-name="P1">Plain <text:span text:style-name="T1">bold</text:span> and <text:span text:style-name="T2">italic</text:span> '
            . '<text:span text:style-name="T3">sup</text:span> <text:span text:style-name="T4">code</text:span></text:p>',
            '<style:style style:name="P1" style:family="paragraph"/>'
            . '<style:style style:name="T1" style:family="text"><style:text-properties fo:font-weight="bold"/></style:style>'
            . '<style:style style:name="T2" style:family="text"><style:text-properties fo:font-style="italic"/></style:style>'
            . '<style:style style:name="T3" style:family="text"><style:text-properties style:text-position="super 58%"/></style:style>'
            . '<style:style style:name="T4" style:family="text"><style:text-properties style:font-name="Liberation Mono"/></style:style>'
        );
        $in = (new OdtReader())->read($file)->blocks[0]->content;
        $kinds = array_map(fn($i) => (new \ReflectionClass($i))->getShortName(), $in);
        $this->assertContains('Strong', $kinds);
        $this->assertContains('Emph', $kinds);
        $this->assertContains('Superscript', $kinds);
        $this->assertContains('Code', $kinds);
        $this->assertSame('Plain bold and italic sup code', $this->text($in));
    }

    public function testBoldHeadingStyleDoesNotWrapEveryHeadingInStrong(): void
    {
        $file = $this->handMade(
            '<text:h text:style-name="Heading_20_1" text:outline-level="1">Title</text:h>',
            '',
            '<style:style style:name="Heading_20_1" style:display-name="Heading 1" style:family="paragraph"><style:text-properties fo:font-weight="bold"/></style:style>'
        );
        $h = (new OdtReader())->read($file)->blocks[0];
        $this->assertNotInstanceOf(Strong::class, $h->content[0]);
        $this->assertSame('Title', $this->text($h->content));
    }

    public function testAdjacentRunsWithTheSameFormattingAreMerged(): void
    {
        $file = $this->handMade(
            '<text:p><text:span text:style-name="B">one</text:span><text:span text:style-name="B"> two</text:span> <text:span text:style-name="B">three</text:span></text:p>',
            '<style:style style:name="B" style:family="text"><style:text-properties fo:font-weight="bold"/></style:style>'
        );
        $in = (new OdtReader())->read($file)->blocks[0]->content;
        $this->assertCount(1, $in);
        $this->assertInstanceOf(Strong::class, $in[0]);
        $this->assertSame('one two three', $this->text($in));
    }

    public function testHeadingInsideAShapeIsRecoveredAndEmptyHeadingsAreDropped(): void
    {
        $file = $this->handMade(
            '<text:h text:outline-level="1"/><text:h text:outline-level="1"><draw:custom-shape><text:h text:outline-level="1">Real title</text:h></draw:custom-shape></text:h>'
            . '<text:p>Body</text:p>'
        );
        $blocks = (new OdtReader())->read($file)->blocks;
        $this->assertCount(2, $blocks);
        $this->assertInstanceOf(Header::class, $blocks[0]);
        $this->assertSame('Real title', $this->text($blocks[0]->content));
    }

    public function testLibreOfficeStyleSequenceCaption(): void
    {
        $file = $this->handMade(
            '<text:p text:style-name="Illustration">Figure <text:sequence text:name="Illustration">1</text:sequence>: The caption</text:p>'
            . '<text:p text:style-name="P0">After.</text:p>',
            '<style:style style:name="P0" style:family="paragraph"/>',
            '<style:style style:name="Illustration" style:family="paragraph"/>'
        );
        $blocks = (new OdtReader())->read($file)->blocks;
        // no picture precedes it, so the caption stays a plain paragraph with its full text
        $this->assertSame('Figure 1: The caption', $this->text($blocks[0]->content));
    }

    public function testContentOfTableOfContentsIsIgnored(): void
    {
        $file = $this->handMade(
            '<text:table-of-content><text:index-body><text:p>1 Intro ........ 3</text:p></text:index-body></text:table-of-content><text:p>Real text.</text:p>'
        );
        $blocks = (new OdtReader())->read($file)->blocks;
        $this->assertCount(1, $blocks);
        $this->assertSame('Real text.', $this->text($blocks[0]->content));
    }

    public function testRunsOfSpacesAndTabsCollapse(): void
    {
        $file = $this->handMade("<text:p>a   b<text:s text:c=\"3\"/>c<text:tab/>d\n  e</text:p>");
        $this->assertSame('a b c d e', $this->text((new OdtReader())->read($file)->blocks[0]->content));
    }

    /** @param array<int, array{0:int, 1:string}> $headings [outline level, text] */
    private function headingsFile(array $headings): string
    {
        $body = '';
        foreach ($headings as [$level, $text]) {
            $body .= '<text:h text:outline-level="' . $level . '">' . htmlspecialchars($text) . '</text:h><text:p>Text.</text:p>';
        }
        return $this->handMade($body);
    }

    private function headingLevelsAndNumbers(string $file): array
    {
        $out = [];
        foreach ((new OdtReader())->read($file)->blocks as $b) {
            if ($b instanceof Header) {
                $out[] = [$b->level, $b->attr->attributes[0][1] ?? null, $this->text($b->content)];
            }
        }
        return $out;
    }

    public function testManualHeadingNumbersMoveToAnAttributeAndLevelsFollowTheirDepth(): void
    {
        // a Word-style document: every heading is "Heading 1", the numbers say what they really are
        $h = $this->headingLevelsAndNumbers($this->headingsFile([[1, 'Title of the note'], [1, '1. Introduction'], [1, '2. Method'], [1, '2.1 Details'], [1, '2.2 More'], [1, '3. Results']]));
        $this->assertSame([[1, null, 'Title of the note'], [1, '1', 'Introduction'], [1, '2', 'Method'], [2, '2.1', 'Details'], [2, '2.2', 'More'], [1, '3', 'Results']], $h);
    }

    public function testAppendixLettersAreStrippedOnlyAsASequence(): void
    {
        $h = $this->headingLevelsAndNumbers($this->headingsFile([[1, '1 Intro'], [1, '2 Body'], [1, '3 End'], [1, 'A First appendix'], [2, 'A.1 Detail'], [1, 'B Second appendix']]));
        $this->assertSame(['1', '2', '3', 'A', 'A.1', 'B'], array_column($h, 1));
        $this->assertSame('First appendix', $h[3][2]);
        $this->assertSame(2, $h[4][0]);
    }

    public function testATitleThatStartsWithALetterIsNotTakenForAnAppendix(): void
    {
        $h = $this->headingLevelsAndNumbers($this->headingsFile([[1, '1 Intro'], [1, '2 Body'], [1, '3 End'], [1, 'A Study of Everything']]));
        $this->assertSame('A Study of Everything', $h[3][2]);
        $this->assertNull($h[3][1]);
    }

    public function testHeadingsWithoutSystematicNumbersAreLeftAlone(): void
    {
        $in = [[1, '2024 Annual Report'], [1, 'Overview'], [1, '3 Reasons to read'], [2, 'Summary']];
        $this->assertSame(array_map(fn($x) => $x[1], $in), array_column($this->headingLevelsAndNumbers($this->headingsFile($in)), 2));
    }

    public function testTheFileCreationDateIsNotTheDocumentDate(): void
    {
        $doc = $this->roundTrip('Body.');
        $this->assertArrayNotHasKey('date', $doc->meta->map);
    }

    public function testTableCellWithOnlyAFormulaStaysInline(): void
    {
        $doc = $this->roundTrip("\\begin{tabular}{l}\$x^{2}\$\\end{tabular}");
        $cell = $doc->blocks[0]->bodies[0]->rows[0]->cells[0];
        $this->assertSame(MathType::InlineMath, $cell->content[0]->content[0]->type);
    }

    public function testAListMadeOfHeadingsIsDissolved(): void
    {
        // Word numbers headings through a list: the list items hold text:h
        $file = $this->handMade(
            '<text:list text:style-name="WW"><text:list-item><text:h text:outline-level="1">First part</text:h><text:p>Intro.</text:p>'
            . '<text:list><text:list-item><text:h text:outline-level="2">Sub part</text:h><text:p>Detail.</text:p></text:list-item></text:list></text:list-item></text:list>'
        );
        $blocks = (new OdtReader())->read($file)->blocks;
        $kinds = array_map(fn($b) => (new \ReflectionClass($b))->getShortName(), $blocks);
        $this->assertSame(['Header', 'Para', 'Header', 'Para'], $kinds);
        $this->assertSame(2, $blocks[2]->level);
        $this->assertSame('Sub part', $this->text($blocks[2]->content));
    }

    public function testOrdinaryListsAreNotDissolved(): void
    {
        $file = $this->handMade('<text:list><text:list-item><text:p>One</text:p></text:list-item><text:list-item><text:p>Two</text:p></text:list-item></text:list>');
        $this->assertInstanceOf(BulletList::class, (new OdtReader())->read($file)->blocks[0]);
    }
}
