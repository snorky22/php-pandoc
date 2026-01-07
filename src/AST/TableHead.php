<?php

namespace Pandoc\AST;

readonly class TableHead { public function __construct(public Attr $attr, public array $rows) {} }
