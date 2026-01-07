<?php

namespace Pandoc\AST;

readonly class RawInline implements Inline { public function __construct(public string $format, public string $text) {} }
