<?php

namespace Pandoc\AST;

readonly class Attr
{
    /**
     * @param string $identifier
     * @param string[] $classes
     * @param array<string, string> $attributes
     */
    public function __construct(
        public string $identifier = '',
        public array $classes = [],
        public array $attributes = []
    ) {}
}
