<?php

namespace Pandoc\AST;

readonly class Cite implements Inline { public function __construct(public array $citations, public array $content) {} }
