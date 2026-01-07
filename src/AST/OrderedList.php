<?php

namespace Pandoc\AST;

readonly class OrderedList implements Block { public function __construct(public ListAttributes $attr, public array $items) {} }
