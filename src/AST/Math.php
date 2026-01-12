<?php

namespace Pandoc\AST;

readonly class Math implements Inline, Block { public function __construct(public MathType $type, public string $text) {} }
