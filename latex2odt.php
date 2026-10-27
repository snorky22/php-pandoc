<?php

require_once __DIR__ . '/vendor/autoload.php';

use Pandoc\Reader\LatexReader;
use Pandoc\Writer\OdtWriter;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php latex2odt.php input.tex [output.odt] [--cover=image.png] [--no-numbers]\n");
    exit(1);
}

$input = $argv[1];
$output = null;
$options = ['resourcePath' => [dirname($input), '.']];
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--cover=')) {
        $options['coverImage'] = substr($arg, 8);
    } elseif ($arg === '--no-numbers') {
        $options['numberSections'] = false;
    } else {
        $output = $arg;
    }
}
$output ??= preg_replace('/\.[^.]+$/', '', $input) . '.odt';

$doc = (new LatexReader())->read($input);
(new OdtWriter($options))->writeToFile($doc, $output);
echo "Wrote $output\n";
