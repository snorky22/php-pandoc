<?php

require_once __DIR__ . '/vendor/autoload.php';

use Pandoc\Reader\XlsxReader;
use Pandoc\Writer\LatexWriter;

$inputFile = $argv[1] ?? null;
$outputFile = $argv[2] ?? null;

if (!$inputFile || !file_exists($inputFile)) {
    fwrite(STDERR, "Usage: php convert_xlsx.php <input.xlsx> <output.tex>\n");
    exit(1);
}

$reader = new XlsxReader();
$writer = new LatexWriter();

$doc = $reader->read($inputFile);
$latex = $writer->write($doc);

if ($outputFile) {
    file_put_contents($outputFile, $latex);
    echo "Written to $outputFile\n";
} else {
    echo $latex;
}
