<?php

namespace Pandoc\AST;

readonly class Strikeout implements Inline { public function __construct(public array $content) {} }
