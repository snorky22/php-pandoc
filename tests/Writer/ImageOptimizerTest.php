<?php

namespace Pandoc\Tests\Writer;

use PHPUnit\Framework\TestCase;
use Pandoc\Reader\LatexReader;
use Pandoc\Writer\EpubWriter;
use Pandoc\Writer\ImageOptimizer;

class ImageOptimizerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!ImageOptimizer::isAvailable()) {
            $this->markTestSkipped('Imagick is not available');
        }
    }

    /** A noisy gradient: many colours, so it looks photographic. */
    private function photo(int $w, int $h, bool $transparent = false): string
    {
        $im = new \Imagick();
        $im->newPseudoImage($w, $h, 'plasma:fractal');
        $im->setImageFormat('png');
        if ($transparent) {
            $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_SET);
            $im->evaluateImage(\Imagick::EVALUATE_MULTIPLY, 0.5, \Imagick::CHANNEL_ALPHA);
        }
        return $im->getImageBlob();
    }

    private function dimensions(string $blob): array
    {
        $im = new \Imagick();
        $im->readImageBlob($blob);
        return [$im->getImageWidth(), $im->getImageHeight()];
    }

    public function testOpaquePhotographicPngBecomesSmallerJpegAndIsDownscaled(): void
    {
        $png = $this->photo(2400, 1200);
        $result = (new ImageOptimizer(1600, 85))->optimize($png, 'image/png');
        $this->assertNotNull($result);
        $this->assertSame('image/jpeg', $result['mime']);
        $this->assertLessThan(strlen($png), strlen($result['contents']));
        $this->assertSame([1600, 800], $this->dimensions($result['contents']));
    }

    public function testTransparentPngStaysPng(): void
    {
        $png = $this->photo(800, 400, true);
        $result = (new ImageOptimizer(1600, 85))->optimize($png, 'image/png');
        $this->assertNotSame('image/jpeg', $result['mime'] ?? 'image/png', 'transparency must not be flattened to JPEG');
        if ($result !== null) {
            $im = new \Imagick();
            $im->readImageBlob($result['contents']);
            $this->assertTrue((bool) $im->getImageAlphaChannel());
        }
    }

    public function testFlatDiagramStaysPng(): void
    {
        $im = new \Imagick();
        $im->newImage(1000, 600, 'white');
        $draw = new \ImagickDraw();
        $draw->setFillColor('#336699');
        $draw->rectangle(100, 100, 600, 400);
        $im->drawImage($draw);
        $im->setImageFormat('png');
        $im->setOption('png:compression-level', '0');
        $result = (new ImageOptimizer(1600, 85))->optimize($im->getImageBlob(), 'image/png');
        $this->assertNotNull($result);
        $this->assertSame('image/png', $result['mime']);
    }

    public function testSvgAndGifAreLeftAlone(): void
    {
        $o = new ImageOptimizer();
        $this->assertNull($o->optimize('<svg xmlns="http://www.w3.org/2000/svg"/>', 'image/svg+xml'));
        $this->assertNull($o->optimize('GIF89a', 'image/gif'));
        $this->assertNull($o->optimize('not an image', 'image/png'));
    }

    public function testEpubEmbedsOptimizedImageOnlyWhenRequested(): void
    {
        $dir = sys_get_temp_dir() . '/epubopt_' . uniqid();
        mkdir($dir);
        $png = $this->photo(2400, 1200);
        file_put_contents($dir . '/big.png', $png);
        $doc = (new LatexReader())->read('\\begin{document}\\includegraphics{big.png}\\end{document}');

        $zipOf = function (array $options) use ($doc, $dir): \ZipArchive {
            $path = tempnam(sys_get_temp_dir(), 'epubopt');
            file_put_contents($path, (new EpubWriter(['resourcePath' => [$dir]] + $options))->write($doc));
            $zip = new \ZipArchive();
            $zip->open($path);
            unlink($path);
            return $zip;
        };
        $optimized = $zipOf(['optimizeImages' => true]);
        $this->assertNotFalse($optimized->locateName('EPUB/media/image1.jpg'));
        $this->assertStringContainsString('media-type="image/jpeg"', $optimized->getFromName('EPUB/content.opf'));
        $this->assertStringContainsString('src="../media/image1.jpg"', $optimized->getFromName('EPUB/text/ch001.xhtml'));
        $this->assertLessThan(strlen($png), strlen($optimized->getFromName('EPUB/media/image1.jpg')));

        $original = $zipOf([]);
        $this->assertSame($png, $original->getFromName('EPUB/media/image1.png'));

        unlink($dir . '/big.png');
        rmdir($dir);
    }
}
