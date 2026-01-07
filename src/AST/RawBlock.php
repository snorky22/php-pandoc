<?php

namespace Pandoc\AST;

readonly class RawBlock implements Block { public function __construct(public string $format, public string $text) {} }
