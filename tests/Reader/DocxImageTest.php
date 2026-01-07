<?php

namespace Pandoc\Tests\Reader;

use PHPUnit\Framework\TestCase;
use Pandoc\Reader\DocxReader;
use Pandoc\AST\Para;
use Pandoc\AST\Image;

class DocxImageTest extends TestCase
{
    private DocxReader $reader;

    protected function setUp(): void
    {
        $this->reader = new DocxReader();
    }

    public function testImageExtraction(): void
    {
        $path = __DIR__ . '/../../../test/docx/image.docx';
        if (!file_exists($path)) {
            $this->markTestSkipped("Test file image.docx not found");
        }

        $doc = $this->reader->read($path);

        $images = [];
        foreach ($doc->blocks as $block) {
            if ($block instanceof Para) {
                foreach ($block->content as $inline) {
                    if ($inline instanceof Image) {
                        $images[] = $inline;
                    }
                }
            }
        }

        $this->assertNotEmpty($images, "Images should be detected in image.docx");
        $this->assertFalse($doc->mediaBag->isEmpty(), "MediaBag should not be empty");

        $image = $images[0];
        $this->assertNotEmpty($image->target->url);

        $media = $doc->mediaBag->lookup($image->target->url);
        $this->assertNotNull($media, "Image content should be in MediaBag");
    }
}
