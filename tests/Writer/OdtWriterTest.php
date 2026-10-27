<?php

namespace Pandoc\Tests\Writer;

use PHPUnit\Framework\TestCase;
use Pandoc\Reader\LatexReader;
use Pandoc\Writer\OdtWriter;

class OdtWriterTest extends TestCase
{
    private const NS = [
        'office' => 'urn:oasis:names:tc:opendocument:xmlns:office:1.0',
        'text' => 'urn:oasis:names:tc:opendocument:xmlns:text:1.0',
        'table' => 'urn:oasis:names:tc:opendocument:xmlns:table:1.0',
        'draw' => 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0',
        'xlink' => 'http://www.w3.org/1999/xlink',
        'style' => 'urn:oasis:names:tc:opendocument:xmlns:style:1.0',
        'svg' => 'urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0',
        'manifest' => 'urn:oasis:names:tc:opendocument:xmlns:manifest:1.0',
        'dc' => 'http://purl.org/dc/elements/1.1/',
    ];

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

    private function odtBytes(string $latex, array $options = []): string
    {
        return (new OdtWriter($options))->write((new LatexReader())->read($latex));
    }

    private function zip(string $bytes): \ZipArchive
    {
        $path = tempnam(sys_get_temp_dir(), 'odttest');
        $this->tmp[] = $path;
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        return $zip;
    }

    /** @return array{0:\ZipArchive, 1:\DOMXPath} */
    private function build(string $body, string $preamble = '', array $options = []): array
    {
        $zip = $this->zip($this->odtBytes("\\documentclass{article}\n$preamble\\begin{document}\n$body\n\\end{document}", $options));
        return [$zip, $this->xpath($zip->getFromName('content.xml'))];
    }

    private function xpath(string $xml): \DOMXPath
    {
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
        $x = new \DOMXPath($dom);
        foreach (self::NS as $p => $u) {
            $x->registerNamespace($p, $u);
        }
        return $x;
    }

    private function n(\DOMXPath $x, string $q): int
    {
        return $x->query($q)->length;
    }

    public function testPackageLayoutAndWellFormedParts(): void
    {
        [$zip] = $this->build('\section{A}Text with $x^2$ and a note.\footnote{Foot}');
        $this->assertSame('mimetype', $zip->getNameIndex(0));
        $this->assertSame('application/vnd.oasis.opendocument.text', $zip->getFromName('mimetype'));
        $this->assertSame(\ZipArchive::CM_STORE, $zip->statName('mimetype')['comp_method']);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_ends_with($zip->getNameIndex($i), '.xml')) {
                $this->assertTrue((new \DOMDocument())->loadXML($zip->getFromIndex($i)), $zip->getNameIndex($i) . ' is not well-formed');
            }
        }
        foreach (['content.xml', 'styles.xml', 'meta.xml', 'META-INF/manifest.xml'] as $f) {
            $this->assertNotFalse($zip->locateName($f), "missing $f");
        }
    }

    public function testManifestListsEveryPartExceptItself(): void
    {
        [$zip] = $this->build('Inline $a$ and display \[ b \]');
        $m = $this->xpath($zip->getFromName('META-INF/manifest.xml'));
        $listed = [];
        foreach ($m->query('//manifest:file-entry/@manifest:full-path') as $a) {
            $listed[$a->value] = true;
        }
        $this->assertArrayHasKey('Object 1/', $listed);
        $this->assertArrayHasKey('Object 1/content.xml', $listed);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!in_array($name, ['mimetype', 'META-INF/manifest.xml'], true)) {
                $this->assertArrayHasKey($name, $listed, "$name is not in the manifest");
            }
        }
    }

    public function testHeadingsKeepOutlineLevelAndSectionNumbers(): void
    {
        [, $x] = $this->build('\section{One}\subsection{Two}\section*{Plain}');
        $this->assertSame(3, $this->n($x, '//text:h'));
        $h1 = $x->query('//text:h[@text:outline-level="1"]')->item(0);
        $this->assertSame('1One', $h1->textContent, 'number and title (the gap between them is a <text:s/> element)');
        $this->assertSame(1, $this->n($x, '//text:h[@text:outline-level="1"][1]/text:s'));
        $this->assertSame(1, $this->n($x, '//text:h[@text:outline-level="2"][starts-with(normalize-space(.),"1.1")]'));
        $this->assertStringContainsString('Plain', $x->query('//text:h')->item(2)->textContent);
        $this->assertDoesNotMatchRegularExpression('/^\d/', $x->query('//text:h')->item(2)->textContent);

        [, $y] = $this->build('\section{One}', '', ['numberSections' => false]);
        $this->assertSame('One', $y->query('//text:h')->item(0)->textContent);
    }

    public function testInlineStyling(): void
    {
        [, $x] = $this->build('\emph{e} \textbf{b} \underline{u} \sout{s} \texttt{code} \textsc{sc} x\textsuperscript{2}');
        foreach (['Emphasis', 'Strong_20_Emphasis', 'T_Underline', 'T_Strike', 'Source_20_Text', 'T_SmallCaps', 'T_Super'] as $style) {
            $this->assertSame(1, $this->n($x, "//text:span[@text:style-name='$style']"), $style);
        }
    }

    public function testLinksAndBookmarksResolve(): void
    {
        [, $x] = $this->build('\section{Intro}\label{sec:i} See Section~\ref{sec:i} and \href{http://e.org}{site}.');
        $this->assertSame(1, $this->n($x, '//text:bookmark[@text:name="sec:i"]'));
        $this->assertSame(1, $this->n($x, '//text:a[@xlink:href="#sec:i"]'));
        $this->assertSame(1, $this->n($x, '//text:a[@xlink:href="http://e.org"]'));
    }

    public function testFootnoteBecomesRealNote(): void
    {
        [, $x] = $this->build('Text.\footnote{The note \emph{here}.}');
        $this->assertSame(1, $this->n($x, '//text:note[@text:note-class="footnote"]'));
        $this->assertSame('1', $x->query('//text:note-citation')->item(0)->textContent);
        $this->assertStringContainsString('The note here.', $x->query('//text:note-body')->item(0)->textContent);
    }

    public function testListsBulletedNumberedAndNested(): void
    {
        [, $x] = $this->build("\\begin{itemize}\\item A\\item B \\begin{enumerate}[a)]\\item B1\\end{enumerate}\\end{itemize}");
        $this->assertSame(1, $this->n($x, '//text:list[@text:style-name="L_Bullet"]'));
        $this->assertSame(3, $this->n($x, '//text:list-item'));
        $this->assertSame(1, $this->n($x, '//text:list//text:list'));
    }

    public function testOrderedListStyleCarriesFormatAndStart(): void
    {
        $zip = $this->zip($this->odtBytes("\\begin{document}\\begin{enumerate}[a)]\\item x\\item y\\end{enumerate}\\end{document}"));
        $x = $this->xpath($zip->getFromName('content.xml'));
        $style = $x->query('//text:list/@text:style-name')->item(0)->value;
        $this->assertSame(1, $this->n($x, "//text:list-style[@style:name='$style']/text:list-level-style-number[@text:level='1'][@style:num-format='a'][@style:num-suffix=')']"));
    }

    public function testTableWithHeaderSpanAndAlignment(): void
    {
        [, $x] = $this->build(
            "\\begin{table}\\caption{Cap}\\label{t:1}\\begin{tabular}{lr}\\toprule A & B \\\\\\midrule \\multicolumn{2}{c}{wide} \\\\\\bottomrule\\end{tabular}\\end{table}\nSee \\ref{t:1}."
        );
        $this->assertSame(1, $this->n($x, '//table:table'));
        $this->assertSame(2, $this->n($x, '//table:table-column'));
        $this->assertSame(1, $this->n($x, '//table:table-header-rows/table:table-row'));
        $this->assertSame(1, $this->n($x, '//table:table-cell[@table:number-columns-spanned="2"]'));
        $this->assertSame(1, $this->n($x, '//table:covered-table-cell'));
        $this->assertSame(1, $this->n($x, '//text:p[@text:style-name="THR"]'), 'right-aligned header cell');
        $this->assertStringContainsString('Table 1: Cap', $x->query('//text:p[@text:style-name="Caption_20_Table"]')->item(0)->textContent);
        $this->assertSame(1, $this->n($x, '//text:bookmark[@text:name="t:1"]'));
    }

    public function testTableColumnsAreAsWideAsTheirLongestWord(): void
    {
        [$zip, $x] = $this->build("\\begin{tabular}{ll}Decoder & 0.876 \\\\ VQ-GAN & 0.132\\end{tabular}");
        $col = $x->query('//table:table-column/@table:style-name')->item(0)->value;
        $width = $this->xpath($zip->getFromName('content.xml'))->query("//style:style[@style:name='$col']/style:table-column-properties/@style:column-width")->item(0)->value;
        $this->assertGreaterThan(1.5, (float) $width, 'a 7-letter word needs more than 1.5 cm');
    }

    public function testCodeBlockKeepsSpacesAndLineBreaks(): void
    {
        [, $x] = $this->build("\\begin{verbatim}\nif x:\n    y = 1\n\\end{verbatim}");
        $p = $x->query('//text:p[@text:style-name="Preformatted_20_Text"]')->item(0);
        $this->assertSame(1, $this->n($x, '//text:p[@text:style-name="Preformatted_20_Text"]/text:line-break'));
        $this->assertSame('4', $x->query('.//text:s/@text:c', $p)->item(0)->value);
    }

    public function testImagesAreEmbeddedWithSizeFromTheirWidthAttribute(): void
    {
        $dir = sys_get_temp_dir() . '/odtimg_' . uniqid();
        mkdir($dir);
        (function () use ($dir) {
            $im = imagecreatetruecolor(400, 200);
            imagepng($im, $dir . '/pic.png');
        })();
        file_put_contents($dir . '/main.tex', "\\begin{document}\\begin{figure}\\includegraphics[width=0.5\\textwidth]{pic.png}\\caption{A pic}\\label{f:1}\\end{figure} See \\ref{f:1}.\\end{document}");
        $zip = $this->zip((new OdtWriter(['resourcePath' => [$dir]]))->write((new LatexReader())->read($dir . '/main.tex')));
        $x = $this->xpath($zip->getFromName('content.xml'));
        $this->assertNotFalse($zip->locateName('Pictures/image1.png'));
        $frame = $x->query('//draw:frame[draw:image]')->item(0);
        $this->assertSame('8.000cm', $frame->getAttribute('svg:width'));
        $this->assertSame('4.000cm', $frame->getAttribute('svg:height'));
        $this->assertStringContainsString('Figure 1: A pic', $x->query('//text:p[@text:style-name="Caption"]')->item(0)->textContent);
        $this->assertSame(1, $this->n($x, '//text:p[@text:style-name="Illustration_20_Captioned"]'));
        unlink($dir . '/pic.png');
        unlink($dir . '/main.tex');
        rmdir($dir);
    }

    public function testMissingImageFallsBackToNothingInsteadOfBrokenLink(): void
    {
        [$zip, $x] = $this->build('\includegraphics{nowhere.png} text');
        $this->assertSame(0, $this->n($x, '//draw:image'));
        $this->assertFalse($zip->locateName('Pictures/image1.png'));
    }

    public function testFormulasAreEmbeddedMathMlObjectsWithoutDeclaredSize(): void
    {
        [$zip, $x] = $this->build("Inline \$E=mc^2\$.\n\n\\[ \\frac{a}{b} \\]");
        $this->assertSame(2, $this->n($x, '//draw:object'));
        $this->assertSame(0, $this->n($x, '//draw:frame[draw:object][@svg:width or @svg:height]'), 'LibreOffice must size formulas itself');
        $this->assertStringContainsString('<mfrac>', $zip->getFromName('Object 2/content.xml'));
        $this->assertSame(1, $this->n($x, '//text:p[@text:style-name="Math_20_Display"]'), 'a paragraph with only a display formula is centred');
    }

    public function testFormulasFallBackToTexTextWhenMathMlIsOff(): void
    {
        [$zip, $x] = $this->build('Inline $x^2$.', '', ['mathml' => false]);
        $this->assertSame(0, $this->n($x, '//draw:object'));
        $this->assertFalse($zip->locateName('Object 1/content.xml'));
        $this->assertStringContainsString('$x^2$', $x->query('//text:span[@text:style-name="Source_20_Text"]')->item(0)->textContent);
    }

    public function testTitleBlockAndMetadata(): void
    {
        [$zip, $x] = $this->build('Body.', "\\title{My Book}\\author{Ann \\and Bob}\\date{2024-03-05}\\usepackage[french]{babel}\n");
        $this->assertSame('My Book', $x->query('//text:p[@text:style-name="Title"]')->item(0)->textContent);
        $this->assertSame(2, $this->n($x, '//text:p[@text:style-name="Author"]'));
        $meta = $this->xpath($zip->getFromName('meta.xml'));
        $this->assertSame('My Book', $meta->query('//dc:title')->item(0)->textContent);
        $this->assertSame('Ann, Bob', $meta->query('//dc:creator')->item(0)->textContent);
        $this->assertSame('fr', $meta->query('//dc:language')->item(0)->textContent);
        $this->assertStringContainsString('fo:language="fr"', $zip->getFromName('styles.xml'));
    }

    public function testCoverImageIsCentredAboveTheTitle(): void
    {
        $dir = sys_get_temp_dir() . '/odtcover_' . uniqid();
        mkdir($dir);
        $im = imagecreatetruecolor(300, 400);
        imagepng($im, $dir . '/front.png');
        file_put_contents($dir . '/main.tex', "\\title{T}\\cover{front.png}\\begin{document}Body\\end{document}");
        $zip = $this->zip((new OdtWriter(['resourcePath' => [$dir]]))->write((new LatexReader())->read($dir . '/main.tex')));
        $x = $this->xpath($zip->getFromName('content.xml'));
        $first = $x->query('//office:text/*')->item(0);
        $this->assertSame('Illustration', $first->getAttribute('text:style-name'));
        $this->assertSame(1, $this->n($x, '//draw:image'));
        $this->assertNotFalse($zip->locateName('Pictures/image1.png'));
        unlink($dir . '/front.png');
        unlink($dir . '/main.tex');
        rmdir($dir);
    }

    public function testSubfiguresFormSideBySideRowsAndWrap(): void
    {
        $dir = sys_get_temp_dir() . '/odtsub_' . uniqid();
        mkdir($dir);
        $im = imagecreatetruecolor(100, 80);
        foreach (['a', 'b', 'c', 'd'] as $n) {
            imagepng($im, "$dir/$n.png");
        }
        $sub = fn(string $n, string $w) => "\\begin{subfigure}{{$w}\\textwidth}\\includegraphics[width=\\textwidth]{{$n}.png}\\caption{{$n}}\\end{subfigure}";
        $tex = "\\begin{document}\\begin{figure}" . $sub('a', '0.3') . $sub('b', '0.3') . $sub('c', '0.3') . "\\caption{Three}\\end{figure}"
            . "\\begin{figure}" . $sub('a', '0.45') . $sub('b', '0.45') . $sub('c', '0.45') . $sub('d', '0.45') . "\\caption{Four}\\end{figure}\\end{document}";
        file_put_contents($dir . '/main.tex', $tex);
        $zip = $this->zip((new OdtWriter(['resourcePath' => [$dir]]))->write((new LatexReader())->read($dir . '/main.tex')));
        $x = $this->xpath($zip->getFromName('content.xml'));
        // 3 panels fit one row; 4 panels at 45% fit two per row
        $this->assertSame(3, $this->n($x, '//table:table'));
        $this->assertSame(3, $this->n($x, '//table:table[1]//table:table-cell'));
        $this->assertSame(1, $this->n($x, '//table:table[1]//table:table-row'));
        $this->assertSame(2, $this->n($x, '//table:table[2]//table:table-cell'));
        $this->assertSame(7, $this->n($x, '//text:p[@text:style-name="Caption"][starts-with(.,"(")]'), 'every sub-caption is kept');
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }

    public function testBlockQuoteAbstractAndDefinitionList(): void
    {
        [, $x] = $this->build("\\begin{quote}Quoted\\end{quote}\\begin{abstract}Abs\\end{abstract}\\begin{description}\\item[Term] Def\\end{description}");
        $this->assertSame(1, $this->n($x, '//text:p[@text:style-name="Quotations"]'));
        $this->assertSame(1, $this->n($x, '//text:p[@text:style-name="Abstract"]'));
        $this->assertSame(1, $this->n($x, '//text:p[@text:style-name="Definition_20_Term"]'));
        $this->assertSame(1, $this->n($x, '//text:p[@text:style-name="Definition"]'));
    }

    public function testAlgorithmLinesAreIndentedParagraphs(): void
    {
        [, $x] = $this->build("\\begin{algorithm}\\caption{Demo}\\begin{algorithmic}\\FOR{i}\\STATE work\\ENDFOR\\end{algorithmic}\\end{algorithm}");
        $this->assertStringContainsString('Algorithm 1: Demo', $x->query('//text:p')->item(0)->textContent);
        $this->assertSame(1, $this->n($x, '//text:p[@text:style-name="Alg1"]'));
        $this->assertGreaterThanOrEqual(1, $this->n($x, '//text:p[@text:style-name="Alg0"]'));
    }

    public function testEveryUsedStyleIsDefined(): void
    {
        [$zip, $x] = $this->build(
            "\\section{S}\\begin{itemize}\\item a\\end{itemize}\\begin{tabular}{l}x\\end{tabular}\\emph{e} \\textcolor{red}{c} \$x\$ \\footnote{n}"
            . "\\begin{verbatim}\ncode\n\\end{verbatim}"
        );
        $defined = [];
        $styles = $this->xpath($zip->getFromName('styles.xml'));
        foreach ([$styles, $x] as $doc) {
            foreach ($doc->query('//style:style/@style:name | //text:list-style/@style:name') as $a) {
                $defined[$a->value] = true;
            }
        }
        foreach ($x->query('//@text:style-name | //@table:style-name | //@draw:style-name') as $a) {
            $this->assertArrayHasKey($a->value, $defined, 'undefined style ' . $a->value);
        }
    }

    /**
     * Opens a document with the real LibreOffice. Skipped when it is not installed; set LIBREOFFICE_BIN
     * to point at a non-standard soffice.
     */
    public function testLibreOfficeOpensTheDocumentAndShowsNoMissingOperandMarker(): void
    {
        $soffice = getenv('LIBREOFFICE_BIN') ?: '';
        foreach (['/Applications/LibreOffice.app/Contents/MacOS/soffice', '/usr/bin/soffice', '/usr/local/bin/soffice', '/opt/homebrew/bin/soffice'] as $c) {
            if ($soffice === '' && is_executable($c)) {
                $soffice = $c;
            }
        }
        if ($soffice === '' || !is_executable($soffice)) {
            $this->markTestSkipped('LibreOffice is not installed');
        }
        $dir = sys_get_temp_dir() . '/odtlo_' . uniqid();
        mkdir($dir);
        $body = "\\section{Maths}\n\\begin{align}x(t+1) &= A x(t) \\\\ y &\\leq& |z|_\\infty + J^{*} \\\\ & & \\end{align}\n"
            . "A half-open interval \$t\\in[a,b)\$, a norm \$|x(t)|\\le 1\$ and \$\\ge 0.3\$.\n\n\\begin{tabular}{ll}\\toprule A & B \\\\\\midrule 1 & 2 \\\\\\bottomrule\\end{tabular}";
        file_put_contents($dir . '/doc.odt', $this->odtBytes("\\documentclass{article}\\begin{document}\n$body\n\\end{document}"));
        $cmd = escapeshellarg($soffice) . ' -env:UserInstallation=file://' . $dir . '/profile --headless --convert-to txt:Text --outdir '
            . escapeshellarg($dir) . ' ' . escapeshellarg($dir . '/doc.odt') . ' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertFileExists($dir . '/doc.txt', 'LibreOffice did not produce output: ' . implode("\n", $out));
        $this->assertStringContainsString('Maths', (string) file_get_contents($dir . '/doc.txt'));

        // the PDF text layer shows the red "¿" LibreOffice draws for formulas it cannot parse
        if (is_executable('/opt/homebrew/bin/gs') || is_executable('/usr/bin/gs')) {
            exec($cmd = escapeshellarg($soffice) . ' -env:UserInstallation=file://' . $dir . '/profile --headless --convert-to pdf --outdir '
                . escapeshellarg($dir) . ' ' . escapeshellarg($dir . '/doc.odt') . ' 2>&1');
            $gs = is_executable('/opt/homebrew/bin/gs') ? '/opt/homebrew/bin/gs' : '/usr/bin/gs';
            $text = (string) shell_exec(escapeshellarg($gs) . ' -q -dNOPAUSE -dBATCH -sDEVICE=txtwrite -sOutputFile=- ' . escapeshellarg($dir . '/doc.pdf') . ' 2>/dev/null');
            $this->assertStringNotContainsString('¿', $text, 'LibreOffice could not parse a formula');
        }
        array_map('unlink', array_filter(array_merge(glob($dir . '/*') ?: [], glob($dir . '/profile/*/*') ?: []), 'is_file'));
    }
}
