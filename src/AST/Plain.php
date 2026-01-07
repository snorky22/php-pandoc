<?php

namespace Pandoc\AST;

readonly class Plain implements Block { public function __construct(public array $content) {} }
