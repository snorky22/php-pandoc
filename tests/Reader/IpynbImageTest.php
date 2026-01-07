<?php

namespace Pandoc\Tests\Reader;

use PHPUnit\Framework\TestCase;
use Pandoc\Reader\IpynbReader;
use Pandoc\AST\Div;
use Pandoc\AST\Image;

class IpynbImageTest extends TestCase
{
    public function testIpynbAttachments(): void
    {
        $ipynb = json_encode([
            'cells' => [
                [
                    'cell_type' => 'markdown',
                    'source' => ['![alt](attachment:test.png)'],
                    'attachments' => [
                        'test.png' => [
                            'image/png' => base64_encode('fake png content')
                        ]
                    ]
                ]
            ]
        ]);

        $reader = new IpynbReader();
        $doc = $reader->read($ipynb);

        $this->assertFalse($doc->mediaBag->isEmpty());
        $this->assertNotNull($doc->mediaBag->lookup('test.png'));

        $div = $doc->blocks[0];
        $this->assertInstanceOf(Div::class, $div);
        $para = $div->content[0];
        $image = $para->content[0];
        $this->assertInstanceOf(Image::class, $image);
        $this->assertEquals('test.png', $image->target->url);
    }
}
