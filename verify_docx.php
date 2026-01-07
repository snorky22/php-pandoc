<?php

require_once __DIR__ . '/vendor/autoload.php';

use Pandoc\Reader\DocxReader;
use Pandoc\Writer\LatexWriter;

$testFiles = [
    'test/docx/unicode.docx',
    'test/docx/tables.docx'
];

$reader = new DocxReader();
$writer = new LatexWriter();

foreach ($testFiles as $file) {
    $fullPath = __DIR__ . '/' . $file;
    if (!file_exists($fullPath)) {
        echo "File not found: $fullPath\n";
        continue;
    }

    echo "Converting $file...\n";
    try {
        $doc = $reader->read($fullPath);
        $latex = $writer->write($doc);
        echo "Successfully converted $file. Preview:\n";
        echo substr($latex, 0, 500) . "...\n";
        echo "-----------------------------------\n";
    } catch (\Exception $e) {
        echo "Failed to convert $file: " . $e->getMessage() . "\n";
    }
}
