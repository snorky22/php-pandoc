<?php

namespace Pandoc\AST;

readonly class Strong implements Inline { public function __construct(public array $content) {} }
