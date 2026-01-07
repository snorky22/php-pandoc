<?php

namespace Pandoc\AST;

readonly class Note implements Inline { public function __construct(public array $content) {} }
