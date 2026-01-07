<?php

namespace Pandoc\AST;

readonly class Cell { public function __construct(public Attr $attr, public Alignment $align, public int $rowSpan, public int $colSpan, public array $content) {} }
