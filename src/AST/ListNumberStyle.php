<?php

namespace Pandoc\AST;

enum ListNumberStyle {
    case DefaultStyle; case Example; case Decimal; case LowerRoman; case UpperRoman; case LowerAlpha; case UpperAlpha;
}
