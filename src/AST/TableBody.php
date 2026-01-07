<?php

namespace Pandoc\AST;

readonly class TableBody { public function __construct(public Attr $attr, public int $rowHeadColumns, public array $head, public array $rows) {} }
