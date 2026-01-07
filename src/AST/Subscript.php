<?php

namespace Pandoc\AST;

readonly class Subscript implements Inline { public function __construct(public array $content) {} }
