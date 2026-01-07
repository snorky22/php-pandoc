<?php

namespace Pandoc\Reader;

use Pandoc\AST\Pandoc;

interface ReaderInterface
{
    /**
     * Reads a source string or file path and returns a Pandoc AST.
     *
     * @param string $source The source content or file path.
     * @return Pandoc
     */
    public function read(string $source): Pandoc;
}
