<?php

namespace Pandoc\AST;

readonly class Str implements Inline { public function __construct(public string $text) {} }
