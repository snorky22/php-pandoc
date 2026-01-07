<?php

require_once __DIR__ . '/vendor/autoload.php';

use Pandoc\Reader\MarkdownReader;
use Pandoc\Writer\LatexWriter;

$markdown = file_get_contents(__DIR__ . '/test_input.md');

$reader = new MarkdownReader();
$doc = $reader->read($markdown);

$writer = new LatexWriter();
$latex = $writer->write($doc);

echo "--- LATEX OUTPUT ---\n";
echo $latex . "\n";
echo "--- END ---\n";
