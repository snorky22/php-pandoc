<?php

namespace Pandoc\AST;

readonly class BlockQuote implements Block { public function __construct(public array $content) {} }
