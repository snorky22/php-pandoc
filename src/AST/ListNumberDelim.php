<?php

namespace Pandoc\AST;

enum ListNumberDelim {
    case DefaultDelim; case Period; case OneParen; case TwoParens;
}
