<?php

namespace Pandoc\AST;

readonly class Para implements Block { public function __construct(public array $content) {} }
