<?php
namespace Pandoc\AST;
readonly class Underline implements Inline
{
    public function __construct(public array $content) {}
}
