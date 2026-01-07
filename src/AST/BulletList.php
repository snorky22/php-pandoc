<?php

namespace Pandoc\AST;

readonly class BulletList implements Block { public function __construct(public array $items) {} }
