<?php

namespace Pandoc\AST;

readonly class Link implements Inline { public function __construct(public Attr $attr, public array $content, public Target $target) {} }
