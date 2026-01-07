<?php

require_once __DIR__ . '/vendor/autoload.php';

use Pandoc\AST\Pandoc;
use Pandoc\AST\Para;
use Pandoc\AST\Str;
use Pandoc\AST\Space;
use Pandoc\AST\Emph;
use Pandoc\AST\Strong;
use Pandoc\AST\Header;
use Pandoc\AST\Attr;
use Pandoc\Writer\HtmlWriter;

// Simulate a parsed document
$doc = new Pandoc(
    meta: [],
    blocks: [
        new Header(
            level: 1,
            attr: new Attr(),
            content: [new Str("Hello Pandoc PHP")]
        ),
        new Para([
            new Str("This"),
            new Space(),
            new Str("is"),
            new Space(),
            new Emph([new Str("emphasized")]),
            new Space(),
            new Str("text"),
            new Space(),
            new Str("and"),
            new Space(),
            new Strong([new Str("strong")]),
            new Str(".")
        ])
    ]
);

$writer = new HtmlWriter();
echo $writer->write($doc) . "\n";
