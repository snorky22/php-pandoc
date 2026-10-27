<?php

require_once __DIR__ . '/vendor/autoload.php';

use Pandoc\Reader\LatexReader;
use Pandoc\Writer\EpubWriter;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php latex2epub.php input.tex [output.epub] [--cover=image.png] [--split=N] [--toc-depth=N] [--max-image-width=N] [--optimize-images]\n");
    exit(1);
}

$input = $argv[1];
$output = null;
$options = ['resourcePath' => [dirname($input), '.']];
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--cover=')) {
        $options['coverImage'] = substr($arg, 8);
    } elseif (str_starts_with($arg, '--split=')) {
        $options['splitLevel'] = (int) substr($arg, 8);
    } elseif (str_starts_with($arg, '--toc-depth=')) {
        $options['tocDepth'] = (int) substr($arg, 12);
    } elseif (str_starts_with($arg, '--max-image-width=')) {
        $options['maxImageWidth'] = (int) substr($arg, 18);
    } elseif ($arg === '--optimize-images') {
        $options['optimizeImages'] = true;
    } else {
        $output = $arg;
    }
}
$output ??= preg_replace('/\.[^.]+$/', '', $input) . '.epub';

$doc = (new LatexReader())->read($input);
(new EpubWriter($options))->writeToFile($doc, $output);
echo "Wrote $output\n";
