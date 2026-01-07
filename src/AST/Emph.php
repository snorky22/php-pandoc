<?php

namespace Pandoc\AST;

readonly class Emph implements Inline { public function __construct(public array $content) {} }
