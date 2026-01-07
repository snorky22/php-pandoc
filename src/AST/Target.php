<?php

namespace Pandoc\AST;

readonly class Target
{
    public function __construct(
        public string $url,
        public string $title = ''
    ) {}
}
