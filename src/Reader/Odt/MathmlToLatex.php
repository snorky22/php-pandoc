<?php

namespace Pandoc\Reader\Odt;

/**
 * Converts presentation MathML (as written by LibreOffice or by latex2mathml-php) to LaTeX that
 * only needs amsmath/amssymb. An <annotation encoding="application/x-tex"> is used as it is.
 */
final class MathmlToLatex
{
    private const GREEK = [
        'α' => '\\alpha', 'β' => '\\beta', 'γ' => '\\gamma', 'δ' => '\\delta', 'ε' => '\\varepsilon', 'ϵ' => '\\epsilon',
        'ϰ' => '\\varkappa', 'ζ' => '\\zeta', 'η' => '\\eta', 'θ' => '\\theta', 'ϑ' => '\\vartheta', 'ι' => '\\iota', 'κ' => '\\kappa',
        'λ' => '\\lambda', 'μ' => '\\mu', 'ν' => '\\nu', 'ξ' => '\\xi', 'π' => '\\pi', 'ϖ' => '\\varpi', 'ρ' => '\\rho',
        'ϱ' => '\\varrho', 'σ' => '\\sigma', 'ς' => '\\varsigma', 'τ' => '\\tau', 'υ' => '\\upsilon', 'φ' => '\\varphi',
        'ϕ' => '\\phi', 'χ' => '\\chi', 'ψ' => '\\psi', 'ω' => '\\omega',
        'Γ' => '\\Gamma', 'Δ' => '\\Delta', 'Θ' => '\\Theta', 'Λ' => '\\Lambda', 'Ξ' => '\\Xi', 'Π' => '\\Pi',
        'Σ' => '\\Sigma', 'Υ' => '\\Upsilon', 'Φ' => '\\Phi', 'Ψ' => '\\Psi', 'Ω' => '\\Omega',
    ];

    private const OPERATORS = [
        '∑' => '\\sum', '∏' => '\\prod', '∐' => '\\coprod', '∫' => '\\int', '∬' => '\\iint', '∭' => '\\iiint', '∮' => '\\oint',
        '⋃' => '\\bigcup', '⋂' => '\\bigcap', '⋁' => '\\bigvee', '⋀' => '\\bigwedge', '⨁' => '\\bigoplus', '⨂' => '\\bigotimes',
        '≤' => '\\le', '≥' => '\\ge', '≠' => '\\ne', '≈' => '\\approx', '≡' => '\\equiv', '∼' => '\\sim', '≃' => '\\simeq',
        '≅' => '\\cong', '∝' => '\\propto', '≪' => '\\ll', '≫' => '\\gg', '≺' => '\\prec', '≻' => '\\succ',
        '→' => '\\to', '←' => '\\leftarrow', '↔' => '\\leftrightarrow', '⇒' => '\\Rightarrow', '⇐' => '\\Leftarrow',
        '⇔' => '\\Leftrightarrow', '↦' => '\\mapsto', '↑' => '\\uparrow', '↓' => '\\downarrow', '⟶' => '\\longrightarrow',
        '⟹' => '\\Longrightarrow', '⟷' => '\\longleftrightarrow',
        '×' => '\\times', '÷' => '\\div', '·' => '\\cdot', '⋅' => '\\cdot', '∙' => '\\cdot', '∘' => '\\circ', '∗' => '\\ast', '⋆' => '\\star', '★' => '\\bigstar',
        '⊕' => '\\oplus', '⊗' => '\\otimes', '⊙' => '\\odot', '±' => '\\pm', '∓' => '\\mp', '†' => '\\dagger', '‡' => '\\ddagger',
        '∈' => '\\in', '∉' => '\\notin', '∋' => '\\ni', '⊂' => '\\subset', '⊃' => '\\supset', '⊆' => '\\subseteq',
        '⊇' => '\\supseteq', '∪' => '\\cup', '∩' => '\\cap', '∖' => '\\setminus', '∅' => '\\emptyset', '∀' => '\\forall',
        '∃' => '\\exists', '¬' => '\\neg', '∧' => '\\wedge', '∨' => '\\vee', '⊢' => '\\vdash', '⊨' => '\\models',
        '∞' => '\\infty', '∂' => '\\partial', '∇' => '\\nabla', '∠' => '\\angle', '△' => '\\triangle', '∣' => '\\mid',
        '∥' => '\\parallel', '⊥' => '\\perp', '⟨' => '\\langle', '⟩' => '\\rangle', '⌈' => '\\lceil', '⌉' => '\\rceil',
        '⌊' => '\\lfloor', '⌋' => '\\rfloor', '‖' => '\\|', '…' => '\\ldots', '⋯' => '\\cdots', '⋮' => '\\vdots',
        '⋱' => '\\ddots', '′' => "'", '″' => "''", '−' => '-', '‐' => '-', '–' => '-', '{' => '\\{', '}' => '\\}',
        '%' => '\\%', '&' => '\\&', '#' => '\\#', '$' => '\\$', '_' => '\\_', '~' => '\\sim', '∶' => ':', '≜' => '\\triangleq',
        '❑' => '{}', '\\' => '\\setminus', '⧵' => '\\setminus', 'ℕ' => '\\mathbb{N}', 'ℤ' => '\\mathbb{Z}', 'ℚ' => '\\mathbb{Q}', 'ℝ' => '\\mathbb{R}', 'ℂ' => '\\mathbb{C}', 'ℙ' => '\\mathbb{P}', 'ℍ' => '\\mathbb{H}', 'ℓ' => '\\ell', 'ℏ' => '\\hbar', 'ℜ' => '\\Re', 'ℑ' => '\\Im', 'ℵ' => '\\aleph', '℘' => '\\wp', '∎' => '\\blacksquare',
        '⊤' => '\\top', '≔' => ':=', '⊲' => '\\vartriangleleft', '⊳' => '\\vartriangleright', '≲' => '\\lesssim', '≳' => '\\gtrsim',
    ];

    /** Letterlike Symbols block: the script/fraktur capitals that Unicode keeps outside the styled alphabets. */
    private const LETTERLIKE = [
        'ℬ' => '\\mathcal{B}', 'ℰ' => '\\mathcal{E}', 'ℱ' => '\\mathcal{F}', 'ℋ' => '\\mathcal{H}', 'ℐ' => '\\mathcal{I}',
        'ℒ' => '\\mathcal{L}', 'ℳ' => '\\mathcal{M}', 'ℛ' => '\\mathcal{R}', 'ℯ' => '\\mathcal{e}', 'ℊ' => '\\mathcal{g}',
        'ℴ' => '\\mathcal{o}', 'ℭ' => '\\mathfrak{C}', 'ℌ' => '\\mathfrak{H}', 'ℨ' => '\\mathfrak{Z}', 'ℑ' => '\\Im', 'ℜ' => '\\Re',
    ];

    private const BIG_OPERATORS = ['\\sum', '\\prod', '\\coprod', '\\int', '\\iint', '\\iiint', '\\oint', '\\bigcup', '\\bigcap',
        '\\bigvee', '\\bigwedge', '\\bigoplus', '\\bigotimes'];

    private const FUNCTIONS = ['sin', 'cos', 'tan', 'cot', 'sec', 'csc', 'sinh', 'cosh', 'tanh', 'coth', 'arcsin', 'arccos',
        'arctan', 'log', 'ln', 'lg', 'exp', 'lim', 'liminf', 'limsup', 'max', 'min', 'sup', 'inf', 'det', 'dim', 'ker',
        'deg', 'gcd', 'hom', 'arg', 'Pr', 'sgn'];

    private const ACCENTS = [
        '¯' => '\\bar', '‾' => '\\bar', '_' => '\\underline', '^' => '\\hat', 'ˆ' => '\\hat', '~' => '\\tilde', '˜' => '\\tilde',
        '→' => '\\vec', '⃗' => '\\vec', '˙' => '\\dot', '¨' => '\\ddot', '˘' => '\\breve', 'ˇ' => '\\check', '´' => '\\acute',
        '`' => '\\grave', '⏞' => '\\overbrace', '⏟' => '\\underbrace', '←' => '\\overleftarrow',
    ];

    private const VARIANTS = [
        'bold' => '\\mathbf', 'bold-italic' => '\\boldsymbol', 'script' => '\\mathcal', 'bold-script' => '\\mathcal',
        'double-struck' => '\\mathbb', 'fraktur' => '\\mathfrak', 'bold-fraktur' => '\\mathfrak', 'sans-serif' => '\\mathsf',
        'monospace' => '\\mathtt', 'normal' => '\\mathrm',
    ];

    /** Mathematical alphanumeric blocks (U+1D400…): base code point => LaTeX font command. */
    private const STYLED_BLOCKS = [
        0x1D400 => ['\\mathbf', 'A'], 0x1D41A => ['\\mathbf', 'a'], 0x1D434 => [null, 'A'], 0x1D44E => [null, 'a'],
        0x1D468 => ['\\boldsymbol', 'A'], 0x1D482 => ['\\boldsymbol', 'a'], 0x1D49C => ['\\mathcal', 'A'], 0x1D4D0 => ['\\mathcal', 'A'],
        0x1D504 => ['\\mathfrak', 'A'], 0x1D51E => ['\\mathfrak', 'a'], 0x1D538 => ['\\mathbb', 'A'], 0x1D552 => ['\\mathbb', 'a'],
        0x1D5A0 => ['\\mathsf', 'A'], 0x1D5BA => ['\\mathsf', 'a'], 0x1D670 => ['\\mathtt', 'A'], 0x1D68A => ['\\mathtt', 'a'],
    ];

    public static function fromDocument(\DOMDocument $doc): string
    {
        $x = new \DOMXPath($doc);
        foreach ($x->query('//*[local-name()="annotation"][@encoding="application/x-tex"]') as $a) {
            return trim($a->textContent);
        }
        $root = $doc->documentElement;
        return $root instanceof \DOMElement ? self::balanced(trim((new self())->node($root))) : '';
    }

    /**
     * Brace-balance repair. A producer that mis-parses \xrightarrow{\;x\;} leaves a stray "}" operator,
     * which would be written as \} and leave a group open; turn such a brace back into a group delimiter.
     */
    public static function balanced(string $tex): string
    {
        for ($n = 0; $n < 5; $n++) {
            $plain = preg_replace('/\\\\[{}]/', '', preg_replace('/\\\\\\\\/', '', $tex));
            $open = substr_count($plain, '{');
            $close = substr_count($plain, '}');
            if ($open > $close && ($pos = strpos($tex, '\\}')) !== false) {
                $tex = substr($tex, 0, $pos) . '}' . substr($tex, $pos + 2);
            } elseif ($close > $open && ($pos = strrpos($tex, '\\{')) !== false) {
                $tex = substr($tex, 0, $pos) . '{' . substr($tex, $pos + 2);
            } else {
                break;
            }
        }
        return $tex;
    }

    public static function fromString(string $mathml): string
    {
        $doc = new \DOMDocument();
        return @$doc->loadXML($mathml) ? self::fromDocument($doc) : '';
    }

    private function kids(\DOMElement $e): array
    {
        $out = [];
        foreach ($e->childNodes as $c) {
            if ($c instanceof \DOMElement) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /** Concatenates LaTeX pieces, separating a control word from a following letter. */
    private function cat(string ...$parts): string
    {
        $out = '';
        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }
            if ($out !== '' && preg_match('/\\\\[a-zA-Z]+$/', $out) && preg_match('/^[a-zA-Z]/', $p)) {
                $out .= ' ';
            }
            $out .= $p;
        }
        return $out;
    }

    private function children(\DOMElement $e): string
    {
        $kids = $this->mergeLetterRuns($this->kids($e));
        $out = '';
        for ($i = 0; $i < count($kids); $i++) {
            if (is_string($kids[$i])) {
                $out = $this->cat($out, $kids[$i]);
                continue;
            }
            // stretchy fences become \left ... \right
            if (($kind = $this->fenceKind($kids[$i])) === 'prefix' || $kind === 'bar') {
                $j = $this->closingFence($kids, $i, $kind);
                if ($j !== null) {
                    $inner = '';
                    for ($k = $i + 1; $k < $j; $k++) {
                        $inner = $this->cat($inner, is_string($kids[$k]) ? $kids[$k] : $this->node($kids[$k]));
                    }
                    $out = $this->cat($out, '\\left' . $this->delim(trim($kids[$i]->textContent)) . ' ' . trim($inner)
                        . ' \\right' . $this->delim(trim($kids[$j]->textContent)));
                    $i = $j;
                    continue;
                }
            }
            // ( mtable ) becomes a matrix with the right delimiters
            if ($i + 2 < count($kids) && !is_string($kids[$i + 1]) && !is_string($kids[$i + 2]) && $kids[$i + 1]->localName === 'mtable'
                && ($pair = $this->matrixDelimiters($kids[$i], $kids[$i + 2])) !== null) {
                $out = $this->cat($out, $this->table($kids[$i + 1], $pair));
                $i += 2;
                continue;
            }
            $out = $this->cat($out, $this->node($kids[$i]));
        }
        return $out;
    }

    /**
     * Consecutive single-letter <mi> with the same upright/bold/... variant become one LaTeX
     * command (\mathrm{PCA} rather than \mathrm{P}\mathrm{C}\mathrm{A}); the run is returned as a string.
     *
     * @param \DOMElement[] $kids
     * @return array<int, \DOMElement|string>
     */
    private function mergeLetterRuns(array $kids): array
    {
        $out = [];
        $run = [];
        $variant = null;
        $flush = function () use (&$out, &$run, &$variant): void {
            if (count($run) >= 2) {
                $out[] = (self::VARIANTS[$variant] ?? '\\mathrm') . '{' . implode('', array_map(fn($e) => trim($e->textContent), $run)) . '}';
            } else {
                foreach ($run as $e) {
                    $out[] = $e;
                }
            }
            $run = [];
            $variant = null;
        };
        foreach ($kids as $k) {
            $v = $k->localName === 'mi' ? $k->getAttribute('mathvariant') : null;
            $letter = $v !== null && $v !== '' && isset(self::VARIANTS[$v]) && preg_match('/^[A-Za-z0-9]$/', trim($k->textContent));
            if ($letter && ($variant === null || $variant === $v)) {
                $variant = $v;
                $run[] = $k;
                continue;
            }
            $flush();
            if ($letter) {
                $variant = $v;
                $run[] = $k;
            } else {
                $out[] = $k;
            }
        }
        $flush();
        return $out;
    }

    /** 'prefix', 'postfix' or 'bar' for a stretchy fence <mo>, null for anything else. */
    private function fenceKind(\DOMElement $e): ?string
    {
        if ($e->localName !== 'mo' || $e->getAttribute('fence') !== 'true' || $e->getAttribute('stretchy') === 'false') {
            return null;
        }
        $t = trim($e->textContent);
        $form = $e->getAttribute('form');
        if ($form === 'prefix' || $form === 'postfix') {
            return $t === '|' || $t === '‖' || $t === '∣' ? $form : $form;
        }
        return match (true) {
            in_array($t, ['(', '[', '{', '⟨', '⌈', '⌊'], true) => 'prefix',
            in_array($t, [')', ']', '}', '⟩', '⌉', '⌋'], true) => 'postfix',
            in_array($t, ['|', '‖', '∣'], true) => 'bar',
            default => null,
        };
    }

    /** Index of the fence that closes the one at $i, honouring nesting. */
    private function closingFence(array $kids, int $i, string $openKind): ?int
    {
        $depth = 0;
        for ($j = $i + 1; $j < count($kids); $j++) {
            $k = is_string($kids[$j]) ? null : $this->fenceKind($kids[$j]);
            if ($openKind === 'bar') {
                if ($k === 'bar' || $k === 'postfix') {
                    return $j;
                }
                continue;
            }
            if ($k === 'prefix') {
                $depth++;
            } elseif ($k === 'postfix' || ($k === 'bar' && $depth === 0 && false)) {
                if ($depth === 0) {
                    return $j;
                }
                $depth--;
            }
        }
        return null;
    }

    private function matrixDelimiters(\DOMElement $open, \DOMElement $close): ?string
    {
        if ($open->localName !== 'mo' || $close->localName !== 'mo') {
            return null;
        }
        return match ([trim($open->textContent), trim($close->textContent)]) {
            ['(', ')'] => 'pmatrix', ['[', ']'] => 'bmatrix', ['{', '}'] => 'Bmatrix', ['|', '|'] => 'vmatrix', ['‖', '‖'] => 'Vmatrix',
            default => null,
        };
    }

    private function group(string $s): string
    {
        return '{' . $s . '}';
    }

    /** Argument of a command: braces unless it is a single token. */
    private function arg(\DOMElement $e): string
    {
        $s = trim($this->node($e));
        return $s === '' ? '{}' : $this->group($s);
    }

    private function node(\DOMElement $e): string
    {
        return match ($e->localName) {
            'math', 'mrow', 'mstyle', 'mpadded', 'merror', 'mrowspan' => $this->children($e),
            'semantics' => ($k = $this->kids($e)) ? $this->node($k[0]) : '',
            'annotation', 'annotation-xml' => '',
            'mi' => $this->identifier($e),
            'mn' => $this->escapeText(trim($e->textContent)),
            'mo' => $this->operator($e),
            'mtext' => $this->text((string) preg_replace('/^\x{200B}+|\x{200B}+$/u', '', $e->textContent), $e),
            'ms' => '\\text{' . $this->escapeText($e->textContent) . '}',
            'mspace' => $this->space($e),
            'msub' => $this->script($e, '_', null),
            'msup' => $this->script($e, '^', null),
            'msubsup' => $this->script($e, '_', '^'),
            'mfrac' => $this->fraction($e),
            'msqrt' => '\\sqrt' . $this->group($this->children($e)),
            'mroot' => $this->root($e),
            'mover' => $this->over($e),
            'munder' => $this->under($e),
            'munderover' => $this->underOver($e),
            'mtable' => $this->table($e, 'matrix'),
            'mfenced' => $this->fenced($e),
            'menclose' => '\\boxed' . $this->group($this->children($e)),
            'mphantom' => '\\phantom' . $this->group($this->children($e)),
            'mmultiscripts' => $this->children($e),
            default => $this->children($e),
        };
    }

    private function escapeText(string $s): string
    {
        return strtr($s, ['\\' => '\\textbackslash{}', '%' => '\\%', '&' => '\\&', '#' => '\\#', '_' => '\\_', '{' => '\\{', '}' => '\\}', '$' => '\\$']);
    }

    private function text(string $s, \DOMElement $e): string
    {
        if ($s === '') {
            return '';
        }
        if (trim($s) === '') {
            return '\\;';
        }
        $t = trim($s);
        if (mb_strlen($t) === 1 && !preg_match('/\p{L}|\p{N}/u', $t)) {
            return self::OPERATORS[$t] ?? $t; // a lone symbol is not text
        }
        return '\\text{' . $this->escapeText($s) . '}';
    }

    private function space(\DOMElement $e): string
    {
        $w = $e->getAttribute('width');
        $named = [
            'veryverythinmathspace' => '', 'verythinmathspace' => '', 'thinmathspace' => '\\,', 'mediummathspace' => '\\:',
            'thickmathspace' => '\\;', 'verythickmathspace' => '\\quad', 'veryverythickmathspace' => '\\qquad',
            'negativeverythinmathspace' => '\\!', 'negativethinmathspace' => '\\!', 'negativemediummathspace' => '\\!',
            'negativethickmathspace' => '\\!',
        ];
        if (isset($named[$w])) {
            return $named[$w];
        }
        if (!preg_match('/^(-?[0-9.]+)\s*(em|ex|pt|px|mu)?$/', $w, $m)) {
            return '\\ ';
        }
        $em = (float) $m[1] / (($m[2] ?? 'em') === 'pt' ? 10 : 1);
        return match (true) {
            $em < 0 => '\\!',
            $em <= 0.01 => '',
            $em < 0.2 => '\\,',
            $em < 0.25 => '\\:',
            $em < 0.5 => '\\;',
            $em < 1.5 => '\\quad',
            default => '\\qquad',
        };
    }

    private function identifier(\DOMElement $e): string
    {
        $t = trim($e->textContent);
        if ($t === '') {
            return '';
        }
        $variant = $e->getAttribute('mathvariant');
        if (mb_strlen($t) === 1) {
            $styled = $this->styledChar($t);
            if ($styled !== null) {
                return $styled;
            }
            $g = self::GREEK[$t] ?? null;
            if ($g !== null) {
                return isset(self::VARIANTS[$variant]) && $variant !== 'normal' ? self::VARIANTS[$variant] . $this->group($g) : $g;
            }
            if (isset(self::OPERATORS[$t])) {
                return self::OPERATORS[$t];
            }
            $ch = $this->escapeText($t);
            return match ($variant) {
                'bold', 'bold-italic', 'script', 'bold-script', 'double-struck', 'fraktur', 'bold-fraktur', 'sans-serif', 'monospace' => self::VARIANTS[$variant] . $this->group($ch),
                'normal' => preg_match('/^[A-Za-z]$/', $t) ? '\\mathrm' . $this->group($ch) : $ch,
                default => $ch,
            };
        }
        if (in_array($t, self::FUNCTIONS, true) && ($variant === '' || $variant === 'normal')) {
            return in_array($t, ['sgn'], true) ? '\\operatorname' . $this->group($t) : '\\' . $t;
        }
        // a multi-letter identifier: upright text, or a styled run
        $styledRun = $this->styledRun($t);
        if ($styledRun !== null) {
            return $styledRun;
        }
        $prefix = self::VARIANTS[$variant] ?? ($variant === '' ? '\\mathit' : '\\mathrm');
        if ($variant === '' && !preg_match('/^[A-Za-z]+$/', $t)) {
            $prefix = '\\mathrm';
        }
        return $prefix . $this->group($this->escapeText($t));
    }

    /** A character from the Mathematical Alphanumeric block as LaTeX, or null. */
    private function styledChar(string $c): ?string
    {
        if (isset(self::LETTERLIKE[$c])) {
            return self::LETTERLIKE[$c];
        }
        $cp = mb_ord($c);
        if ($cp < 0x1D400 || $cp > 0x1D7FF) {
            return null;
        }
        foreach (array_reverse(self::STYLED_BLOCKS, true) as $base => [$cmd, $first]) {
            if ($cp >= $base && $cp < $base + 52) {
                $i = $cp - $base;
                $letter = $i < 26 ? chr(ord($first === 'A' ? 'A' : 'a') + $i) : chr(ord('a') + $i - 26);
                if ($i >= 26 && $first === 'A') {
                    $letter = chr(ord('a') + $i - 26);
                }
                return $cmd === null ? $letter : $cmd . '{' . $letter . '}';
            }
        }
        return null;
    }

    private function styledRun(string $t): ?string
    {
        $chars = mb_str_split($t);
        $out = '';
        foreach ($chars as $c) {
            $s = $this->styledChar($c);
            if ($s === null) {
                return null;
            }
            $out = $this->cat($out, $s);
        }
        return $out;
    }

    private function operator(\DOMElement $e): string
    {
        $t = trim($e->textContent);
        if ($t === '') {
            return '';
        }
        if (mb_strlen($t) > 1 && preg_match('/^[A-Za-z]+$/', $t)) {
            return in_array($t, self::FUNCTIONS, true) && $t !== 'sgn' ? '\\' . $t : '\\operatorname' . $this->group($t);
        }
        if ($t === '<') {
            return '<';
        }
        if ($t === '>') {
            return '>';
        }
        if (isset(self::OPERATORS[$t])) {
            return self::OPERATORS[$t];
        }
        if (isset(self::GREEK[$t])) {
            return self::GREEK[$t];
        }
        return mb_strlen($t) === 1 ? $t : implode('', array_map(fn($c) => self::OPERATORS[$c] ?? $c, mb_str_split($t)));
    }

    private function script(\DOMElement $e, string $first, ?string $second): string
    {
        $k = $this->kids($e);
        if (count($k) < 2) {
            return $this->children($e);
        }
        $base = $this->node($k[0]);
        if ($this->isScripted($k[0])) {
            $base = $this->group($base); // {x_t}_0, not x_t_0
        }
        $out = $this->cat($base === '' ? '{}' : $base, $first, $this->arg($k[1]));
        if ($second !== null && isset($k[2])) {
            $out .= $second . $this->arg($k[2]);
        }
        return $out;
    }

    /** True when $e is, or only wraps, a sub/superscript element. */
    private function isScripted(\DOMElement $e): bool
    {
        while ($e->localName === 'mrow' && count($this->kids($e)) === 1) {
            $e = $this->kids($e)[0];
        }
        return in_array($e->localName, ['msub', 'msup', 'msubsup'], true);
    }

    private function fraction(\DOMElement $e): string
    {
        $k = $this->kids($e);
        if (count($k) < 2) {
            return $this->children($e);
        }
        if ($e->getAttribute('linethickness') === '0') {
            return '\\binom' . $this->arg($k[0]) . $this->arg($k[1]);
        }
        return '\\frac' . $this->arg($k[0]) . $this->arg($k[1]);
    }

    private function root(\DOMElement $e): string
    {
        $k = $this->kids($e);
        return count($k) < 2 ? '\\sqrt' . $this->group($this->children($e)) : '\\sqrt[' . trim($this->node($k[1])) . ']' . $this->arg($k[0]);
    }

    private function accentOf(\DOMElement $acc): ?string
    {
        if ($acc->localName !== 'mo' && $acc->localName !== 'mi') {
            return null;
        }
        return self::ACCENTS[trim($acc->textContent)] ?? null;
    }

    private function over(\DOMElement $e): string
    {
        $k = $this->kids($e);
        if (count($k) < 2) {
            return $this->children($e);
        }
        $cmd = $this->accentOf($k[1]);
        if ($cmd !== null && $cmd !== '\\underline' && $cmd !== '\\underbrace') {
            return $cmd . $this->arg($k[0]);
        }
        $base = $this->node($k[0]);
        if (in_array($base, ['\\lim', '\\max', '\\min', '\\sup', '\\inf'], true) || in_array($base, self::BIG_OPERATORS, true)) {
            return $this->cat($base, '^', $this->arg($k[1]));
        }
        return '\\overset' . $this->arg($k[1]) . $this->arg($k[0]);
    }

    private function under(\DOMElement $e): string
    {
        $k = $this->kids($e);
        if (count($k) < 2) {
            return $this->children($e);
        }
        $cmd = $this->accentOf($k[1]);
        if ($cmd === '\\underline' || $cmd === '\\underbrace') {
            return $cmd . $this->arg($k[0]);
        }
        $base = $this->node($k[0]);
        if (in_array($base, ['\\lim', '\\max', '\\min', '\\sup', '\\inf', '\\liminf', '\\limsup', '\\det', '\\gcd', '\\Pr'], true)
            || in_array($base, self::BIG_OPERATORS, true)) {
            return $this->cat($base, '_', $this->arg($k[1]));
        }
        return '\\underset' . $this->arg($k[1]) . $this->arg($k[0]);
    }

    private function underOver(\DOMElement $e): string
    {
        $k = $this->kids($e);
        if (count($k) < 3) {
            return $this->children($e);
        }
        $base = $this->node($k[0]);
        if (in_array($base, self::BIG_OPERATORS, true) || in_array($base, ['\\lim', '\\max', '\\min'], true)) {
            return $this->cat($base, '_', $this->arg($k[1]), '^', $this->arg($k[2]));
        }
        return '\\overset' . $this->arg($k[2]) . '{\\underset' . $this->arg($k[1]) . $this->arg($k[0]) . '}';
    }

    private function table(\DOMElement $e, string $env): string
    {
        $rows = [];
        foreach ($this->kids($e) as $tr) {
            $cells = [];
            foreach ($this->kids($tr) as $td) {
                $cells[] = trim($this->children($td));
            }
            $rows[] = implode(' & ', $cells);
        }
        // an equation array: cells aligned right / left alternately, or a display-style table
        $aligned = false;
        foreach ($e->getElementsByTagName('mtd') as $td) {
            if ($td->getAttribute('columnalign') === 'right') {
                $aligned = true;
                break;
            }
        }
        if ($env === 'matrix' && ($aligned || $e->getAttribute('displaystyle') === 'true')) {
            $env = 'aligned';
        }
        return '\\begin{' . $env . '} ' . implode(' \\\\ ', $rows) . ' \\end{' . $env . '}';
    }

    private function fenced(\DOMElement $e): string
    {
        $open = $e->hasAttribute('open') ? $e->getAttribute('open') : '(';
        $close = $e->hasAttribute('close') ? $e->getAttribute('close') : ')';
        $sep = $e->hasAttribute('separators') ? $e->getAttribute('separators') : ',';
        $parts = array_map(fn($k) => trim($this->node($k)), $this->kids($e));
        return '\\left' . $this->delim($open) . implode($sep === '' ? ' ' : $sep[0], $parts) . '\\right' . $this->delim($close);
    }

    private function delim(string $d): string
    {
        return match ($d) {
            '' => '.', '{' => '\\{', '}' => '\\}', '‖' => '\\|', '⟨' => '\\langle', '⟩' => '\\rangle', '⌈' => '\\lceil', '⌉' => '\\rceil',
            '⌊' => '\\lfloor', '⌋' => '\\rfloor', default => $d,
        };
    }
}
