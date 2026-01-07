<?php

namespace Pandoc\AST;

readonly class Caption {
    public function __construct(
        public ?array $short = null,
        public array $content = []
    ) {}
}
