<?php

namespace Pandoc\AST;

readonly class Header implements Block { public function __construct(public int $level, public Attr $attr, public array $content) {} }
