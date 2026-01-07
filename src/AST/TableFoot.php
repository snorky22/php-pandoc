<?php

namespace Pandoc\AST;

readonly class TableFoot { public function __construct(public Attr $attr, public array $rows) {} }
