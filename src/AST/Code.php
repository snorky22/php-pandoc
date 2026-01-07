<?php

namespace Pandoc\AST;

readonly class Code implements Inline { public function __construct(public Attr $attr, public string $text) {} }
