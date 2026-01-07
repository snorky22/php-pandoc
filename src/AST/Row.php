<?php

namespace Pandoc\AST;

readonly class Row { public function __construct(public Attr $attr, public array $cells) {} }
