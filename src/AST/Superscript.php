<?php

namespace Pandoc\AST;

readonly class Superscript implements Inline { public function __construct(public array $content) {} }
