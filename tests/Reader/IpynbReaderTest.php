<?php

namespace Pandoc\Tests\Reader;

use PHPUnit\Framework\TestCase;
use Pandoc\Reader\IpynbReader;
use Pandoc\AST\Div;
use Pandoc\AST\Para;
use Pandoc\AST\CodeBlock;

class IpynbReaderTest extends TestCase
{
    private IpynbReader $reader;

    protected function setUp(): void
    {
        $this->reader = new IpynbReader();
    }

    public function testSimpleNotebook(): void
    {
        $path = __DIR__ . '/../../test/ipynb/simple.ipynb';
        if (!file_exists($path)) {
            $this->markTestSkipped("simple.ipynb not found");
        }

        $doc = $this->reader->read(file_get_contents($path));

        // simple.ipynb has markdown and code cells
        $this->assertNotEmpty($doc->blocks);

        $div1 = $doc->blocks[0];
        $this->assertInstanceOf(Div::class, $div1);
        $this->assertContains('markdown', $div1->attr->classes);

        $div2 = $doc->blocks[1];
        $this->assertInstanceOf(Div::class, $div2);
        $this->assertContains('code', $div2->attr->classes);
        $this->assertInstanceOf(CodeBlock::class, $div2->content[0]);
    }

    public function testMetadataExtraction(): void
    {
        $json = '{
            "cells": [
                {
                    "cell_type": "markdown",
                    "metadata": {
                        "tags": ["test"],
                        "name": "mycell"
                    },
                    "source": ["# Hello"]
                }
            ],
            "nbformat": 4,
            "nbformat_minor": 0,
            "metadata": {}
        }';

        $doc = $this->reader->read($json);
        $div = $doc->blocks[0];

        // Tags is an array, my reader only handles scalars for now in Attr attributes
        // But it should at least have 'name' => 'mycell'
        $foundName = false;
        foreach ($div->attr->attributes as $attr) {
            if ($attr[0] === 'name' && $attr[1] === 'mycell') {
                $foundName = true;
            }
        }
        $this->assertTrue($foundName, "Metadata 'name' should be in attributes");
    }
}
