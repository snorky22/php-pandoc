<?php

namespace Pandoc\Reader\Docx;

use DOMElement;
use DOMText;

/**
 * Converts an OMML (Office Math Markup Language) equation, as found in
 * word/document.xml (m:oMath / m:oMathPara elements), into a LaTeX string.
 */
class OmmlToLatex
{
    private const NS_M = 'http://schemas.openxmlformats.org/officeDocument/2006/math';

    private const FUNC_NAMES = [
        'sin', 'cos', 'tan', 'csc', 'sec', 'cot',
        'sinh', 'cosh', 'tanh', 'coth',
        'arcsin', 'arccos', 'arctan',
        'ln', 'log', 'exp', 'lim', 'max', 'min',
        'det', 'gcd', 'deg', 'dim', 'ker', 'arg', 'inf', 'sup', 'mod',
    ];

    private const NARY_CHAR_MAP = [
        '∫' => '\\int', '∬' => '\\iint', '∭' => '\\iiint', '∮' => '\\oint',
        '∑' => '\\sum', '∏' => '\\prod', '∐' => '\\coprod',
        '⋃' => '\\bigcup', '⋂' => '\\bigcap', '⋁' => '\\bigvee', '⋀' => '\\bigwedge',
        '⨁' => '\\bigoplus', '⨂' => '\\bigotimes', '⨀' => '\\bigodot',
    ];

    private const DELIM_MAP = [
        '(' => '(', ')' => ')', '[' => '[', ']' => ']',
        '{' => '\\{', '}' => '\\}', '|' => '|', '‖' => '\\|',
        '⌊' => '\\lfloor', '⌋' => '\\rfloor', '⌈' => '\\lceil', '⌉' => '\\rceil',
        '⟨' => '\\langle', '⟩' => '\\rangle', '' => '.',
    ];

    private const ACCENT_MAP = [
        "\u{0302}" => '\\hat', "\u{0303}" => '\\tilde', "\u{0304}" => '\\bar',
        "\u{0307}" => '\\dot', "\u{0308}" => '\\ddot', "\u{20D7}" => '\\vec',
        "\u{0306}" => '\\breve', "\u{030C}" => '\\check',
        '^' => '\\hat', '~' => '\\tilde', '¨' => '\\ddot',
    ];

    private const SYMBOL_MAP = [
        // lowercase Greek
        'α' => '\\alpha', 'β' => '\\beta', 'γ' => '\\gamma', 'δ' => '\\delta',
        'ε' => '\\epsilon', 'ϵ' => '\\varepsilon', 'ζ' => '\\zeta', 'η' => '\\eta',
        'θ' => '\\theta', 'ϑ' => '\\vartheta', 'ι' => '\\iota', 'κ' => '\\kappa',
        'λ' => '\\lambda', 'μ' => '\\mu', 'ν' => '\\nu', 'ξ' => '\\xi',
        'ο' => 'o', 'π' => '\\pi', 'ϖ' => '\\varpi', 'ρ' => '\\rho', 'ϱ' => '\\varrho',
        'σ' => '\\sigma', 'ς' => '\\varsigma', 'τ' => '\\tau', 'υ' => '\\upsilon',
        'φ' => '\\varphi', 'ϕ' => '\\phi', 'χ' => '\\chi', 'ψ' => '\\psi', 'ω' => '\\omega',
        // uppercase Greek (only the ones with a glyph distinct from Latin)
        'Γ' => '\\Gamma', 'Δ' => '\\Delta', 'Θ' => '\\Theta', 'Λ' => '\\Lambda',
        'Ξ' => '\\Xi', 'Π' => '\\Pi', 'Σ' => '\\Sigma', 'Υ' => '\\Upsilon',
        'Φ' => '\\Phi', 'Ψ' => '\\Psi', 'Ω' => '\\Omega',
        'Α' => 'A', 'Β' => 'B', 'Ε' => 'E', 'Ζ' => 'Z', 'Η' => 'H', 'Ι' => 'I',
        'Κ' => 'K', 'Μ' => 'M', 'Ν' => 'N', 'Ο' => 'O', 'Ρ' => 'P', 'Τ' => 'T', 'Χ' => 'X',
        // operators
        '×' => '\\times', '÷' => '\\div', '±' => '\\pm', '∓' => '\\mp',
        '·' => '\\cdot', '∙' => '\\cdot', '∘' => '\\circ',
        // relations
        '≤' => '\\leq', '≥' => '\\geq', '≠' => '\\neq', '≈' => '\\approx',
        '≡' => '\\equiv', '∝' => '\\propto', '∼' => '\\sim', '≃' => '\\simeq',
        '≪' => '\\ll', '≫' => '\\gg',
        // set theory / logic
        '∈' => '\\in', '∉' => '\\notin', '⊂' => '\\subset', '⊆' => '\\subseteq',
        '⊃' => '\\supset', '⊇' => '\\supseteq', '∪' => '\\cup', '∩' => '\\cap',
        '∅' => '\\emptyset', '∀' => '\\forall', '∃' => '\\exists', '¬' => '\\neg',
        // arrows
        '→' => '\\to', '←' => '\\leftarrow', '↔' => '\\leftrightarrow',
        '⇒' => '\\Rightarrow', '⇐' => '\\Leftarrow', '⇔' => '\\Leftrightarrow',
        '↦' => '\\mapsto',
        // calculus / misc
        '∞' => '\\infty', '∇' => '\\nabla', '∂' => '\\partial',
        '√' => '\\sqrt', '∑' => '\\sum', '∏' => '\\prod', '∫' => '\\int', '∮' => '\\oint',
        '…' => '\\ldots', '⋯' => '\\cdots', '⋮' => '\\vdots', '⋱' => '\\ddots',
        '°' => '^\\circ',
        '′' => "'", '″' => "''", '‴' => "'''",
        // additional relations/operators from U+2200-U+22FF
        '∧' => '\\wedge', '∨' => '\\vee', '≅' => '\\cong', '≐' => '\\doteq',
        '≍' => '\\asymp', '∣' => '\\mid', '∤' => '\\nmid',
        '⊕' => '\\oplus', '⊖' => '\\ominus', '⊗' => '\\otimes',
        '⊘' => '\\oslash', '⊙' => '\\odot',
        '⊥' => '\\perp', '⊢' => '\\vdash', '⊣' => '\\dashv', '⊨' => '\\models',
        '⊲' => '\\vartriangleleft', '⊳' => '\\vartriangleright',
        '⊴' => '\\unlhd', '⊵' => '\\unrhd',
        // Letterlike Symbols (U+2100-U+214F) used as stand-ins for "holes" left
        // unassigned in the Mathematical Alphanumeric Symbols block, plus a few
        // standalone math symbols from the same block
        'ℂ' => '\\mathbb{C}', 'ℍ' => '\\mathbb{H}', 'ℕ' => '\\mathbb{N}',
        'ℙ' => '\\mathbb{P}', 'ℚ' => '\\mathbb{Q}', 'ℝ' => '\\mathbb{R}', 'ℤ' => '\\mathbb{Z}',
        'ℬ' => '\\mathcal{B}', 'ℰ' => '\\mathcal{E}', 'ℱ' => '\\mathcal{F}',
        'ℋ' => '\\mathcal{H}', 'ℐ' => '\\mathcal{I}', 'ℒ' => '\\mathcal{L}',
        'ℳ' => '\\mathcal{M}', 'ℛ' => '\\mathcal{R}',
        // \mathcal only has usable glyphs for uppercase in the standard (non
        // unicode-math) math font, so these lowercase script holes fall back to
        // a plain letter rather than rendering as garbage, same as italic h below
        'ℯ' => 'e', 'ℊ' => 'g', 'ℴ' => 'o',
        'ℭ' => '\\mathfrak{C}', 'ℌ' => '\\mathfrak{H}', 'ℑ' => '\\mathfrak{I}',
        'ℜ' => '\\mathfrak{R}', 'ℨ' => '\\mathfrak{Z}',
        'ℎ' => 'h',
        'ℏ' => '\\hbar', 'ℓ' => '\\ell', '℘' => '\\wp',
        'ℵ' => '\\aleph', 'ℶ' => '\\beth', 'ℷ' => '\\gimel', 'ℸ' => '\\daleth',
        '℧' => '\\mho', "\u{2126}" => '\\Omega',
    ];

    /** Starting codepoint of each style's A-Z,a-z run in the Mathematical Alphanumeric Symbols block; each run spans 52 codepoints. */
    private const LATIN_ALPHANUM_STYLES = [
        0x1D400 => 'bold', 0x1D434 => 'italic', 0x1D468 => 'bolditalic',
        0x1D49C => 'script', 0x1D4D0 => 'boldscript', 0x1D504 => 'fraktur',
        0x1D538 => 'doublestruck', 0x1D56C => 'boldfraktur', 0x1D5A0 => 'sansserif',
        0x1D5D4 => 'sansserifbold', 0x1D608 => 'sansserifitalic',
        0x1D63C => 'sansserifbolditalic', 0x1D670 => 'monospace',
    ];

    /** Starting codepoint of each style's 0-9 run in the same block; each run spans 10 codepoints. */
    private const DIGIT_ALPHANUM_STYLES = [
        0x1D7CE => 'bold', 0x1D7D8 => 'doublestruck', 0x1D7E2 => 'sansserif',
        0x1D7EC => 'sansserifbold', 0x1D7F6 => 'monospace',
    ];

    private const ALPHANUM_STYLE_WRAPPERS = [
        'bold' => '\\mathbf{%s}', 'italic' => '%s',
        'bolditalic' => '\\boldsymbol{%s}', 'script' => '\\mathcal{%s}',
        'boldscript' => '\\boldsymbol{\\mathcal{%s}}', 'fraktur' => '\\mathfrak{%s}',
        'boldfraktur' => '\\boldsymbol{\\mathfrak{%s}}', 'doublestruck' => '\\mathbb{%s}',
        'sansserif' => '\\mathsf{%s}', 'sansserifbold' => '\\boldsymbol{\\mathsf{%s}}',
        'sansserifitalic' => '\\mathsf{\\mathit{%s}}',
        'sansserifbolditalic' => '\\boldsymbol{\\mathsf{\\mathit{%s}}}',
        'monospace' => '\\mathtt{%s}',
    ];

    private const ESCAPE_MAP = [
        '\\' => '\\textbackslash{}',
        '{' => '\\{',
        '}' => '\\}',
        '$' => '\\$',
        '&' => '\\&',
        '#' => '\\#',
        '%' => '\\%',
        '_' => '\\_',
        '^' => '\\textasciicircum{}',
        '~' => '\\textasciitilde{}',
    ];

    /**
     * @param DOMElement $node an m:oMath or m:oMathPara element
     */
    public function convert(DOMElement $node): string
    {
        if ($node->localName === 'oMathPara') {
            $maths = $this->allChildren($node, 'oMath');
            if (count($maths) > 1) {
                $lines = array_map(fn(DOMElement $m) => $this->convertContainer($m), $maths);
                return '\\begin{aligned}' . implode(' \\\\ ', $lines) . '\\end{aligned}';
            }
            if (count($maths) === 1) {
                return $this->convertContainer($maths[0]);
            }
            return '';
        }
        return $this->convertContainer($node);
    }

    private function convertNode(DOMElement $node): string
    {
        return match ($node->localName) {
            'oMath', 'e', 'num', 'den', 'deg', 'lim', 'sub', 'sup', 'box', 'fName' => $this->convertContainer($node),
            'r' => $this->convertRun($node),
            't' => $this->escapeMathText($node->textContent),
            'brk' => ' \\\\ ',
            'sSup' => $this->convertSSup($node),
            'sSub' => $this->convertSSub($node),
            'sSubSup' => $this->convertSSubSup($node),
            'sPre' => $this->convertSPre($node),
            'f' => $this->convertFraction($node),
            'rad' => $this->convertRadical($node),
            'nary' => $this->convertNary($node),
            'd' => $this->convertDelimiter($node),
            'func' => $this->convertFunc($node),
            'limLow' => $this->convertLimLow($node),
            'limUpp' => $this->convertLimUpp($node),
            'm' => $this->convertMatrix($node),
            'eqArr' => $this->convertEqArr($node),
            'bar' => $this->convertBar($node),
            'groupChr' => $this->convertGroupChr($node),
            'acc' => $this->convertAcc($node),
            default => $this->convertContainer($node),
        };
    }

    /** Concatenate the LaTeX of every element child that is not a *Pr properties element. */
    private function convertContainer(DOMElement $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement || str_ends_with($child->localName, 'Pr')) {
                continue;
            }
            $out .= $this->convertNode($child);
        }
        return $out;
    }

    private function convertRun(DOMElement $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 't') {
                $out .= $this->escapeMathText($child->textContent);
            }
        }
        return $out;
    }

    private function convertSSup(DOMElement $node): string
    {
        $base = $this->childLatex($node, 'e');
        $sup = $this->childLatex($node, 'sup');
        return '{' . $base . '}^{' . $sup . '}';
    }

    private function convertSSub(DOMElement $node): string
    {
        $base = $this->childLatex($node, 'e');
        $sub = $this->childLatex($node, 'sub');
        return '{' . $base . '}_{' . $sub . '}';
    }

    private function convertSSubSup(DOMElement $node): string
    {
        $base = $this->childLatex($node, 'e');
        $sub = $this->childLatex($node, 'sub');
        $sup = $this->childLatex($node, 'sup');
        return '{' . $base . '}_{' . $sub . '}^{' . $sup . '}';
    }

    /** Pre-script (subscript/superscript to the left of the base), e.g. isotope notation. */
    private function convertSPre(DOMElement $node): string
    {
        $base = $this->childLatex($node, 'e');
        $sub = $this->childLatex($node, 'sub');
        $sup = $this->childLatex($node, 'sup');
        return '{}_{' . $sub . '}^{' . $sup . '}' . $base;
    }

    private function convertFraction(DOMElement $node): string
    {
        $fPr = $this->firstChild($node, 'fPr');
        $type = $fPr ? $this->attrVal($this->firstChild($fPr, 'type')) : '';
        $num = $this->childLatex($node, 'num');
        $den = $this->childLatex($node, 'den');
        if ($type === 'lin') {
            return $num . '/' . $den;
        }
        return '\\frac{' . $num . '}{' . $den . '}';
    }

    private function convertRadical(DOMElement $node): string
    {
        $deg = trim($this->childLatex($node, 'deg'));
        $e = $this->childLatex($node, 'e');
        return $deg !== '' ? '\\sqrt[' . $deg . ']{' . $e . '}' : '\\sqrt{' . $e . '}';
    }

    private function convertNary(DOMElement $node): string
    {
        $naryPr = $this->firstChild($node, 'naryPr');
        $chr = '∫';
        $subHide = false;
        $supHide = false;
        if ($naryPr) {
            $chrVal = $this->attrVal($this->firstChild($naryPr, 'chr'));
            if ($chrVal !== '') {
                $chr = $chrVal;
            }
            $subHide = $this->boolAttr($this->firstChild($naryPr, 'subHide'));
            $supHide = $this->boolAttr($this->firstChild($naryPr, 'supHide'));
        }
        $cmd = self::NARY_CHAR_MAP[$chr] ?? '\\int';

        $out = $cmd;
        $sub = $this->firstChild($node, 'sub');
        $sup = $this->firstChild($node, 'sup');
        if (!$subHide && $sub) {
            $out .= '_{' . $this->convertNode($sub) . '}';
        }
        if (!$supHide && $sup) {
            $out .= '^{' . $this->convertNode($sup) . '}';
        }
        return $out . '{' . $this->childLatex($node, 'e') . '}';
    }

    private function convertDelimiter(DOMElement $node): string
    {
        $dPr = $this->firstChild($node, 'dPr');
        $beg = '(';
        $end = ')';
        $sep = ',';
        if ($dPr) {
            $begEl = $this->firstChild($dPr, 'begChr');
            if ($begEl) {
                $beg = $this->attrVal($begEl);
            }
            $endEl = $this->firstChild($dPr, 'endChr');
            if ($endEl) {
                $end = $this->attrVal($endEl);
            }
            $sepEl = $this->firstChild($dPr, 'sepChr');
            if ($sepEl) {
                $sep = $this->attrVal($sepEl);
            }
        }

        $parts = array_map(fn(DOMElement $e) => $this->convertNode($e), $this->allChildren($node, 'e'));
        $inner = implode($sep . ' ', $parts);

        return '\\left' . $this->delimChar($beg) . $inner . '\\right' . $this->delimChar($end);
    }

    private function convertFunc(DOMElement $node): string
    {
        $fName = $this->firstChild($node, 'fName');
        $e = $this->childLatex($node, 'e');
        $name = $fName ? $this->convertFuncName($fName) : '';
        return $name . '\\left(' . $e . '\\right)';
    }

    private function convertFuncName(DOMElement $fName): string
    {
        $hasStructure = $fName->getElementsByTagNameNS(self::NS_M, 'sSub')->length > 0
            || $fName->getElementsByTagNameNS(self::NS_M, 'sSup')->length > 0
            || $fName->getElementsByTagNameNS(self::NS_M, 'sSubSup')->length > 0;

        if (!$hasStructure) {
            $plain = trim($this->plainText($fName));
            $lower = strtolower($plain);
            if (in_array($lower, self::FUNC_NAMES, true)) {
                return '\\' . $lower . ' ';
            }
            if ($plain !== '') {
                return '\\operatorname{' . $this->escapeMathText($plain) . '}';
            }
        }

        return $this->convertContainer($fName);
    }

    private function convertLimLow(DOMElement $node): string
    {
        $e = $this->firstChild($node, 'e');
        $lim = $this->childLatex($node, 'lim');
        return $this->convertLimBase($e) . '_{' . $lim . '}';
    }

    private function convertLimUpp(DOMElement $node): string
    {
        $e = $this->firstChild($node, 'e');
        $lim = $this->childLatex($node, 'lim');
        return $this->convertLimBase($e) . '^{' . $lim . '}';
    }

    private function convertLimBase(?DOMElement $e): string
    {
        if (!$e) {
            return '';
        }
        $plain = strtolower(trim($this->plainText($e)));
        if (in_array($plain, self::FUNC_NAMES, true)) {
            return '\\' . $plain . ' ';
        }
        return $this->convertNode($e);
    }

    private function convertMatrix(DOMElement $node): string
    {
        $rows = array_map(function (DOMElement $row) {
            $cells = array_map(fn(DOMElement $c) => $this->convertNode($c), $this->allChildren($row, 'e'));
            return implode(' & ', $cells);
        }, $this->allChildren($node, 'mr'));

        return '\\begin{matrix}' . implode(' \\\\ ', $rows) . '\\end{matrix}';
    }

    private function convertEqArr(DOMElement $node): string
    {
        $lines = array_map(fn(DOMElement $e) => $this->convertNode($e), $this->allChildren($node, 'e'));
        return '\\begin{aligned}' . implode(' \\\\ ', $lines) . '\\end{aligned}';
    }

    private function convertBar(DOMElement $node): string
    {
        $barPr = $this->firstChild($node, 'barPr');
        $pos = $barPr ? $this->attrVal($this->firstChild($barPr, 'pos')) : '';
        $e = $this->childLatex($node, 'e');
        return $pos === 'bot' ? '\\underline{' . $e . '}' : '\\overline{' . $e . '}';
    }

    private function convertGroupChr(DOMElement $node): string
    {
        $pr = $this->firstChild($node, 'groupChrPr');
        $chr = '⏟';
        $pos = 'bot';
        if ($pr) {
            $chrVal = $this->attrVal($this->firstChild($pr, 'chr'));
            if ($chrVal !== '') {
                $chr = $chrVal;
            }
            $posVal = $this->attrVal($this->firstChild($pr, 'pos'));
            if ($posVal !== '') {
                $pos = $posVal;
            }
        }
        $e = $this->childLatex($node, 'e');

        $cmd = match ($chr) {
            '→' => '\\overrightarrow',
            '←' => '\\overleftarrow',
            '↔' => '\\overleftrightarrow',
            default => $pos === 'top' ? '\\overbrace' : '\\underbrace',
        };
        return $cmd . '{' . $e . '}';
    }

    private function convertAcc(DOMElement $node): string
    {
        $pr = $this->firstChild($node, 'accPr');
        $chr = "\u{0302}";
        if ($pr) {
            $chrVal = $this->attrVal($this->firstChild($pr, 'chr'));
            if ($chrVal !== '') {
                $chr = $chrVal;
            }
        }
        $e = $this->childLatex($node, 'e');
        $cmd = self::ACCENT_MAP[$chr] ?? '\\hat';
        return $cmd . '{' . $e . '}';
    }

    // --- helpers -----------------------------------------------------

    private function firstChild(DOMElement $node, string $localName): ?DOMElement
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                return $child;
            }
        }
        return null;
    }

    /** @return DOMElement[] */
    private function allChildren(DOMElement $node, string $localName): array
    {
        $result = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                $result[] = $child;
            }
        }
        return $result;
    }

    private function childLatex(DOMElement $node, string $localName): string
    {
        $child = $this->firstChild($node, $localName);
        return $child ? $this->convertNode($child) : '';
    }

    private function attrVal(?DOMElement $el): string
    {
        return $el ? $el->getAttributeNS(self::NS_M, 'val') : '';
    }

    private function boolAttr(?DOMElement $el): bool
    {
        if (!$el) {
            return false;
        }
        $v = strtolower($el->getAttributeNS(self::NS_M, 'val'));
        return $v === '' || $v === '1' || $v === 'true' || $v === 'on';
    }

    private function delimChar(string $ch): string
    {
        return self::DELIM_MAP[$ch] ?? $ch;
    }

    /** Extract the literal text content of a node, descending into markup but ignoring *Pr elements. */
    private function plainText(DOMElement $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $out .= $child->textContent;
            } elseif ($child instanceof DOMElement && !str_ends_with($child->localName, 'Pr')) {
                $out .= $child->localName === 't' ? $child->textContent : $this->plainText($child);
            }
        }
        return $out;
    }

    private function escapeMathText(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $out = '';
        foreach ($chars as $ch) {
            $mapped = self::SYMBOL_MAP[$ch] ?? $this->mathAlphanumericToLatex($ch);
            if ($mapped !== null) {
                $out .= $mapped . ' ';
            } elseif (isset(self::ESCAPE_MAP[$ch])) {
                $out .= self::ESCAPE_MAP[$ch];
            } else {
                $out .= $ch;
            }
        }
        return $out;
    }

    /** Convert a single Mathematical Alphanumeric Symbols (U+1D400-U+1D7FF) character to LaTeX, or null if out of range. */
    private function mathAlphanumericToLatex(string $ch): ?string
    {
        $cp = mb_ord($ch);
        if ($cp === false) {
            return null;
        }
        if ($cp === 0x1D6A4) {
            return '\\imath';
        }
        if ($cp === 0x1D6A5) {
            return '\\jmath';
        }
        foreach (self::LATIN_ALPHANUM_STYLES as $start => $style) {
            if ($cp >= $start && $cp < $start + 52) {
                $offset = $cp - $start;
                $letter = $offset < 26 ? chr(0x41 + $offset) : chr(0x61 + $offset - 26);
                return sprintf(self::ALPHANUM_STYLE_WRAPPERS[$style], $letter);
            }
        }
        foreach (self::DIGIT_ALPHANUM_STYLES as $start => $style) {
            if ($cp >= $start && $cp < $start + 10) {
                return sprintf(self::ALPHANUM_STYLE_WRAPPERS[$style], (string) ($cp - $start));
            }
        }
        return null;
    }
}
