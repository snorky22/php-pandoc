<?php

namespace Pandoc\AST;

readonly class SmallCaps implements Inline { public function __construct(public array $content) {} }
