<?php

namespace Pandoc\AST;

readonly class Image implements Inline { public function __construct(public Attr $attr, public array $content, public Target $target) {} }
