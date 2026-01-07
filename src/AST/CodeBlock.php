<?php

namespace Pandoc\AST;

readonly class CodeBlock implements Block { public function __construct(public Attr $attr, public string $text) {} }
