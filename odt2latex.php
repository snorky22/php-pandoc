<?php

require_once __DIR__ . '/vendor/autoload.php';

use Pandoc\Reader\OdtReader;
use Pandoc\Writer\LatexWriter;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php odt2latex.php input.odt [output.tex] [--fragment] [--compact-tables]\n"
        . "Writes the .tex file and the images it uses next to it.\n");
    exit(1);
}

$input = $argv[1];
$output = null;
$standalone = true;
$options = [];
foreach (array_slice($argv, 2) as $arg) {
    if ($arg === '--fragment') {
        $standalone = false;
    } elseif ($arg === '--compact-tables') {
        $options['compactWideTables'] = true;
    } else {
        $output = $arg;
    }
}
$output ??= preg_replace('/\.[^.]+$/', '', $input) . '.tex';

$doc = (new OdtReader())->read($input);
file_put_contents($output, (new LatexWriter($options))->write($doc, $standalone) . "\n");

foreach ($doc->mediaBag->getAll() as $path => $media) {
    $target = dirname($output) . '/' . $path;
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0777, true);
    }
    file_put_contents($target, $media['contents']);
}
echo "Wrote $output\n";
