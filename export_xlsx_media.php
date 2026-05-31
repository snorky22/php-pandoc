<?php

require_once __DIR__ . '/vendor/autoload.php';

use Pandoc\Reader\XlsxReader;

$inputFile = $argv[1] ?? null;
if (!$inputFile || !file_exists($inputFile)) {
    fwrite(STDERR, "Usage: php export_xlsx_media.php <input.xlsx> [output.zip]\n");
    exit(1);
}

$outputFile = $argv[2] ?? preg_replace('/\.xlsx$/i', '_media.zip', $inputFile);

$reader = new XlsxReader();
$doc = $reader->read($inputFile);
$media = $doc->mediaBag->getAll();

if (empty($media)) {
    fwrite(STDERR, "No media bag entries found.\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($outputFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Could not create zip: $outputFile\n");
    exit(1);
}

foreach ($media as $name => $item) {
    $zip->addFromString($name, $item['contents']);
    echo "  + $name\n";
}

$zip->close();
echo "Written: $outputFile\n";
