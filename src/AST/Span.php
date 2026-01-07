<?php

namespace Pandoc\AST;

readonly class Span implements Inline { public function __construct(public Attr $attr, public array $content) {} }
