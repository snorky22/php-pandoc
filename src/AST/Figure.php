<?php

namespace Pandoc\AST;

readonly class Figure implements Block { public function __construct(public Attr $attr, public Caption $caption, public array $content) {} }
