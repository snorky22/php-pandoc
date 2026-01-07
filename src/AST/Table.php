<?php

namespace Pandoc\AST;

readonly class Table implements Block {
    public function __construct(
        public Attr $attr,
        public Caption $caption,
        public array $colSpecs,
        public TableHead $head,
        public array $bodies,
        public TableFoot $foot
    ) {}
}
