<?php

namespace Pandoc\AST;

readonly class LineBlock implements Block { public function __construct(public array $content) {} }
