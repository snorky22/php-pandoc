<?php

namespace Pandoc\AST;

readonly class ListAttributes {
    public function __construct(
        public int $start = 1,
        public ListNumberStyle $style = ListNumberStyle::DefaultStyle,
        public ListNumberDelim $delim = ListNumberDelim::DefaultDelim
    ) {}
}
