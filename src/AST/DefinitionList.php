<?php

namespace Pandoc\AST;

readonly class DefinitionList implements Block { public function __construct(public array $items) {} }
