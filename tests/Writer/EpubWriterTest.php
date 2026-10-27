<?php

namespace Pandoc\Tests\Writer;

use PHPUnit\Framework\TestCase;
use Pandoc\Reader\LatexReader;
use Pandoc\Writer\EpubWriter;

class EpubWriterTest extends TestCase
{
    private const SOURCE = <<<'TEX'
\documentclass{article}
\title{Book}
\author{Ann}
\begin{document}
\section{First}\label{one}
Text with a note.\footnote{Foot} See \ref{two}.

\section{Second}\label{two}
Back to \ref{one}. \includegraphics{missing.png}

\subsection{Sub}
Deep.
\end{document}
TEX;

    private function build(array $options = []): \ZipArchive
    {
        $doc = (new LatexReader())->read(self::SOURCE);
        $path = tempnam(sys_get_temp_dir(), 'epubtest');
        (new EpubWriter($options))->writeToFile($doc, $path);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        unlink($path);
        return $zip;
    }

    public function testContainerStructure(): void
    {
        $zip = $this->build();
        $this->assertSame('mimetype', $zip->getNameIndex(0));
        $this->assertSame('application/epub+zip', $zip->getFromName('mimetype'));
        $this->assertSame(\ZipArchive::CM_STORE, $zip->statName('mimetype')['comp_method']);
        foreach (['META-INF/container.xml', 'EPUB/content.opf', 'EPUB/nav.xhtml', 'EPUB/toc.ncx', 'EPUB/text/ch001.xhtml', 'EPUB/text/ch002.xhtml', 'EPUB/text/title_page.xhtml'] as $f) {
            $this->assertNotFalse($zip->locateName($f), "missing $f");
        }
    }

    public function testAllXmlPartsAreWellFormed(): void
    {
        $zip = $this->build();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('/\.(xhtml|opf|ncx|xml)$/', $name)) {
                $dom = new \DOMDocument();
                $this->assertTrue($dom->loadXML($zip->getFromIndex($i)), "$name is not well-formed");
            }
        }
    }

    public function testOpfMetadataAndSpine(): void
    {
        $opf = $this->build()->getFromName('EPUB/content.opf');
        $this->assertStringContainsString('<dc:title>Book</dc:title>', $opf);
        $this->assertStringContainsString('<dc:creator id="creator1">Ann</dc:creator>', $opf);
        $this->assertStringContainsString('properties="nav"', $opf);
        $this->assertMatchesRegularExpression('/<itemref idref="ch002" \/>/', $opf);
    }

    public function testSplitLevelControlsChapters(): void
    {
        $zip = $this->build(['splitLevel' => 2]);
        $this->assertNotFalse($zip->locateName('EPUB/text/ch003.xhtml'));
        $this->assertFalse($this->build()->locateName('EPUB/text/ch003.xhtml'));
    }

    public function testCrossChapterLinksAndFootnotes(): void
    {
        $zip = $this->build();
        $ch1 = $zip->getFromName('EPUB/text/ch001.xhtml');
        $ch2 = $zip->getFromName('EPUB/text/ch002.xhtml');
        $this->assertStringContainsString('href="ch002.xhtml#two"', $ch1);
        $this->assertStringContainsString('href="ch001.xhtml#one"', $ch2);
        $this->assertStringContainsString('epub:type="footnote"', $ch1);
        $this->assertStringContainsString('href="#fn1"', $ch1);
    }

    public function testNavListsNestedHeadings(): void
    {
        $nav = $this->build()->getFromName('EPUB/nav.xhtml');
        $this->assertStringContainsString('<a href="text/ch001.xhtml">1 First</a>', $nav);
        $this->assertStringContainsString('<a href="text/ch002.xhtml#sub">2.1 Sub</a>', $nav);
        $this->assertSame(2, substr_count($nav, '<ol>'));
    }

    public function testMissingImageFallsBackToAltText(): void
    {
        $ch2 = $this->build()->getFromName('EPUB/text/ch002.xhtml');
        $this->assertStringNotContainsString('<img', $ch2);
    }

    public function testEmbedsImageFromResourcePath(): void
    {
        $dir = sys_get_temp_dir() . '/epubimg_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/missing.png', 'PNGDATA');
        $zip = $this->build(['resourcePath' => [$dir]]);
        $this->assertSame('PNGDATA', $zip->getFromName('EPUB/media/image1.png'));
        $this->assertStringContainsString('src="../media/image1.png"', $zip->getFromName('EPUB/text/ch002.xhtml'));
        $this->assertStringContainsString('media-type="image/png"', $zip->getFromName('EPUB/content.opf'));
        unlink($dir . '/missing.png');
        rmdir($dir);
    }

    public function testMathIsWrittenAsMathMlWhenAvailable(): void
    {
        $tex = "\\documentclass{article}\\begin{document}\\section{M}\nInline \$\\frac{a}{b}\$ and\n\\begin{align}x &= 1 \\\\ y &= 2\\end{align}\n\\end{document}";
        $doc = (new LatexReader())->read($tex);
        $zip = $this->zipOf((new EpubWriter())->write($doc));
        $ch = $zip->getFromName('EPUB/text/ch001.xhtml');
        $this->assertStringContainsString('<mfrac>', $ch);
        $this->assertStringContainsString('display="block"', $ch);
        $this->assertStringContainsString('properties="mathml"', $zip->getFromName('EPUB/content.opf'));
        $this->assertTrue((new \DOMDocument())->loadXML($ch));
    }

    public function testMathFallsBackToTexWhenDisabled(): void
    {
        $doc = (new LatexReader())->read('\\begin{document}Inline $x^2$.\\end{document}');
        $zip = $this->zipOf((new EpubWriter(['mathml' => false]))->write($doc));
        $this->assertStringContainsString('<span class="math inline">\\(x^2\\)</span>', $zip->getFromName('EPUB/text/ch001.xhtml'));
        $this->assertStringNotContainsString('properties="mathml"', $zip->getFromName('EPUB/content.opf'));
    }

    public function testArgOperatorDoesNotLeakIntoMathMl(): void
    {
        $doc = (new LatexReader())->read('\\begin{document}Opt $\\arg\\max_x f(x)$.\\end{document}');
        $ch = $this->zipOf((new EpubWriter())->write($doc))->getFromName('EPUB/text/ch001.xhtml');
        $this->assertStringContainsString('<mo>arg</mo>', $ch);
        $this->assertStringNotContainsString('\\arg', $ch);
    }

    public function testCoverIsShownInsideTheTitlePageAboveTitleAndAuthors(): void
    {
        $dir = sys_get_temp_dir() . '/epubcover_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/front.jpg', 'JPEGDATA');
        file_put_contents($dir . '/main.tex', "\\\\documentclass{article}\\title{T}\\author{Ann}\\cover{front.jpg}\\begin{document}\\section{S}Text.\\end{document}");
        $doc = (new LatexReader())->read($dir . '/main.tex');
        $zip = $this->zipOf((new EpubWriter())->write($doc));

        $this->assertSame('JPEGDATA', $zip->getFromName('EPUB/media/cover.jpg'));
        $opf = $zip->getFromName('EPUB/content.opf');
        $this->assertStringContainsString('properties="cover-image"', $opf);
        $this->assertStringContainsString('<meta name="cover" content="cover-image" />', $opf);

        // no separate cover page: the image lives in the title page
        $this->assertFalse($zip->locateName('EPUB/text/cover.xhtml'));
        $this->assertStringNotContainsString('idref="cover"', $opf);
        $page = $zip->getFromName('EPUB/text/title_page.xhtml');
        $img = strpos($page, '<img src="../media/cover.jpg"');
        $this->assertNotFalse($img);
        $this->assertLessThan(strpos($page, '<h1 class="title">'), $img, 'image above the title');
        $this->assertLessThan(strpos($page, 'class="author"'), strpos($page, '<h1 class="title">'), 'authors below the title');
        $this->assertStringContainsString('div.cover img { display: block; margin: 0 auto;', $zip->getFromName('EPUB/styles/stylesheet1.css'));
        $this->assertCount(1, array_filter(range(0, $zip->numFiles - 1), fn($i) => str_contains($zip->getNameIndex($i), 'media/')));

        unlink($dir . '/front.jpg');
        unlink($dir . '/main.tex');
        rmdir($dir);
    }

    public function testCoverGetsItsOwnPageWhenThereIsNoTitlePage(): void
    {
        $dir = sys_get_temp_dir() . '/epubcover3_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/front.png', 'PNGDATA');
        file_put_contents($dir . '/main.tex', "\\\\cover{front.png}\\begin{document}\\section{S}Text.\\end{document}");
        $doc = (new LatexReader())->read($dir . '/main.tex');
        $zip = $this->zipOf((new EpubWriter())->write($doc));

        $opf = $zip->getFromName('EPUB/content.opf');
        $this->assertStringContainsString('src="../media/cover.png"', $zip->getFromName('EPUB/text/cover.xhtml'));
        $this->assertStringContainsString('<itemref idref="cover" linear="yes" />', $opf);
        $this->assertFalse($zip->locateName('EPUB/text/title_page.xhtml'));

        unlink($dir . '/front.png');
        unlink($dir . '/main.tex');
        rmdir($dir);
    }

    public function testCoverImageOptionOverridesLatexCover(): void
    {
        $dir = sys_get_temp_dir() . '/epubcover2_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/a.png', 'A');
        file_put_contents($dir . '/b.png', 'B');
        file_put_contents($dir . '/main.tex', "\\\\cover{a.png}\\begin{document}Text.\\end{document}");
        $doc = (new LatexReader())->read($dir . '/main.tex');
        $zip = $this->zipOf((new EpubWriter(['coverImage' => $dir . '/b.png']))->write($doc));
        $this->assertSame('B', $zip->getFromName('EPUB/media/cover.png'));
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }

    public function testMissingCoverIsSilentlyOmitted(): void
    {
        $doc = (new LatexReader())->read('\\cover{nope.png}\\begin{document}Text.\\end{document}');
        $zip = $this->zipOf((new EpubWriter())->write($doc));
        $this->assertFalse($zip->locateName('EPUB/text/cover.xhtml'));
        $this->assertStringNotContainsString('cover-image', $zip->getFromName('EPUB/content.opf'));
    }

    private function zipOf(string $data): \ZipArchive
    {
        $path = tempnam(sys_get_temp_dir(), 'epubtest');
        file_put_contents($path, $data);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        unlink($path);
        return $zip;
    }
}
