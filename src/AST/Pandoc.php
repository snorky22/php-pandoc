<?php

namespace Pandoc\AST;

readonly class Pandoc
{
    /**
     * @param Meta $meta
     * @param Block[] $blocks
     * @param MediaBag $mediaBag
     */
    public function __construct(
        public Meta $meta = new Meta(),
        public array $blocks = [],
        public MediaBag $mediaBag = new MediaBag()
    ) {}
}
