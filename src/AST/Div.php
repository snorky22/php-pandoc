<?php

namespace Pandoc\AST;

readonly class Div implements Block { public function __construct(public Attr $attr, public array $content) {} }
