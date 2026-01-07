<?php

namespace Pandoc\AST;

readonly class Quoted implements Inline { public function __construct(public QuoteType $type, public array $content) {} }
