<?php

namespace Pandoc\Writer;

use Pandoc\AST\Block;
use Pandoc\AST\BulletList;
use Pandoc\AST\Emph;
use Pandoc\AST\Header;
use Pandoc\AST\Inline;
use Pandoc\AST\OrderedList;
use Pandoc\AST\Pandoc;
use Pandoc\AST\Para;
use Pandoc\AST\Plain;
use Pandoc\AST\Space;
use Pandoc\AST\Span;
use Pandoc\AST\Str;
use Pandoc\AST\Strikeout;
use Pandoc\AST\Strong;
use Pandoc\AST\Subscript;
use Pandoc\AST\Superscript;
use Pandoc\AST\Underline;
use Pandoc\AST\Link;
use Pandoc\AST\Image;
use Pandoc\AST\Math;
use Pandoc\AST\MathType;
use Pandoc\AST\Note;
use Pandoc\AST\Code;
use Pandoc\AST\CodeBlock;
use Pandoc\AST\BlockQuote;
use Pandoc\AST\Div;
use Pandoc\AST\Alignment;
use Pandoc\AST\Attr;
use Pandoc\AST\Cite;
use Pandoc\AST\DefinitionList;
use Pandoc\AST\Figure;
use Pandoc\AST\LineBreak;
use Pandoc\AST\ListNumberDelim;
use Pandoc\AST\ListNumberStyle;
use Pandoc\AST\Quoted;
use Pandoc\AST\QuoteType;
use Pandoc\AST\RawInline;
use Pandoc\AST\SmallCaps;
use Pandoc\AST\SoftBreak;

/**
 * Writes the AST as LaTeX that needs only standard packages and that {@see \Pandoc\Reader\LatexReader}
 * reads back.
 *
 * Constructs are written only when the AST carries them, so documents from the other readers keep the
 * output they always had: \label is written only for ids that an internal link points to, amsmath,
 * subcaption and babel are loaded only when needed, and the title block only when there is metadata.
 */
class LatexWriter
{
    private const UNICODE = [
        '→' => '\\ensuremath{\\rightarrow}', '←' => '\\ensuremath{\\leftarrow}', '↔' => '\\ensuremath{\\leftrightarrow}',
        '⇒' => '\\ensuremath{\\Rightarrow}', '⇐' => '\\ensuremath{\\Leftarrow}', '⇔' => '\\ensuremath{\\Leftrightarrow}',
        '↑' => '\\ensuremath{\\uparrow}', '↓' => '\\ensuremath{\\downarrow}', '↦' => '\\ensuremath{\\mapsto}',
        '≤' => '\\ensuremath{\\leq}', '≥' => '\\ensuremath{\\geq}', '≠' => '\\ensuremath{\\neq}', '≈' => '\\ensuremath{\\approx}',
        '≡' => '\\ensuremath{\\equiv}', '∼' => '\\ensuremath{\\sim}', '∞' => '\\ensuremath{\\infty}', '±' => '\\ensuremath{\\pm}',
        '∓' => '\\ensuremath{\\mp}', '∈' => '\\ensuremath{\\in}', '∉' => '\\ensuremath{\\notin}', '⊂' => '\\ensuremath{\\subset}',
        '⊆' => '\\ensuremath{\\subseteq}', '∪' => '\\ensuremath{\\cup}', '∩' => '\\ensuremath{\\cap}', '∅' => '\\ensuremath{\\emptyset}',
        '∀' => '\\ensuremath{\\forall}', '∃' => '\\ensuremath{\\exists}', '∂' => '\\ensuremath{\\partial}', '∇' => '\\ensuremath{\\nabla}',
        '∑' => '\\ensuremath{\\sum}', '∏' => '\\ensuremath{\\prod}', '∫' => '\\ensuremath{\\int}', '√' => '\\ensuremath{\\surd}',
        '∗' => '\\ensuremath{\\ast}', '∘' => '\\ensuremath{\\circ}', '⋅' => '\\ensuremath{\\cdot}', '∙' => '\\ensuremath{\\cdot}',
        '⊕' => '\\ensuremath{\\oplus}', '⊗' => '\\ensuremath{\\otimes}', '∧' => '\\ensuremath{\\wedge}', '∨' => '\\ensuremath{\\vee}',
        '¬' => '\\ensuremath{\\neg}', '∠' => '\\ensuremath{\\angle}', '⟨' => '\\ensuremath{\\langle}', '⟩' => '\\ensuremath{\\rangle}',
        '⋆' => '\\ensuremath{\\star}', '❑' => '\\ensuremath{\\square}', '◻' => '\\ensuremath{\\square}', '□' => '\\ensuremath{\\square}', '▢' => '\\ensuremath{\\square}', '■' => '\\ensuremath{\\blacksquare}', '◼' => '\\ensuremath{\\blacksquare}', '∎' => '\\ensuremath{\\blacksquare}', 'ϰ' => '\\ensuremath{\\varkappa}', '―' => '---', '✓' => '\\ensuremath{\\checkmark}', '✗' => '\\ensuremath{\\times}', '▷' => '\\ensuremath{\\triangleright}', '△' => '\\ensuremath{\\triangle}',
        'ℝ' => '\\ensuremath{\\mathbb{R}}', 'ℕ' => '\\ensuremath{\\mathbb{N}}', 'ℤ' => '\\ensuremath{\\mathbb{Z}}', 'ℚ' => '\\ensuremath{\\mathbb{Q}}',
        'ℂ' => '\\ensuremath{\\mathbb{C}}', 'ℓ' => '\\ensuremath{\\ell}', 'ℏ' => '\\ensuremath{\\hbar}',
        'α' => '\\ensuremath{\\alpha}', 'β' => '\\ensuremath{\\beta}', 'γ' => '\\ensuremath{\\gamma}', 'δ' => '\\ensuremath{\\delta}',
        'ε' => '\\ensuremath{\\varepsilon}', 'ζ' => '\\ensuremath{\\zeta}', 'η' => '\\ensuremath{\\eta}', 'θ' => '\\ensuremath{\\theta}',
        'ι' => '\\ensuremath{\\iota}', 'κ' => '\\ensuremath{\\kappa}', 'λ' => '\\ensuremath{\\lambda}', 'μ' => '\\ensuremath{\\mu}',
        'ν' => '\\ensuremath{\\nu}', 'ξ' => '\\ensuremath{\\xi}', 'π' => '\\ensuremath{\\pi}', 'ρ' => '\\ensuremath{\\rho}',
        'σ' => '\\ensuremath{\\sigma}', 'τ' => '\\ensuremath{\\tau}', 'υ' => '\\ensuremath{\\upsilon}', 'φ' => '\\ensuremath{\\varphi}',
        'χ' => '\\ensuremath{\\chi}', 'ψ' => '\\ensuremath{\\psi}', 'ω' => '\\ensuremath{\\omega}',
        'Γ' => '\\ensuremath{\\Gamma}', 'Δ' => '\\ensuremath{\\Delta}', 'Θ' => '\\ensuremath{\\Theta}', 'Λ' => '\\ensuremath{\\Lambda}',
        'Ξ' => '\\ensuremath{\\Xi}', 'Π' => '\\ensuremath{\\Pi}', 'Σ' => '\\ensuremath{\\Sigma}', 'Φ' => '\\ensuremath{\\Phi}',
        'Ψ' => '\\ensuremath{\\Psi}', 'Ω' => '\\ensuremath{\\Omega}',
        "\u{2009}" => '\\,', "\u{202F}" => '\\,', "\u{200B}" => '', "\u{FEFF}" => '',
    ];

    private const BABEL = [
        'fr' => 'french', 'de' => 'ngerman', 'es' => 'spanish', 'it' => 'italian', 'pt' => 'portuguese', 'nl' => 'dutch',
        'sv' => 'swedish', 'da' => 'danish', 'nb' => 'norsk', 'fi' => 'finnish', 'pl' => 'polish', 'cs' => 'czech',
        'ru' => 'russian', 'ca' => 'catalan', 'en-GB' => 'british',
    ];

    /** The Unicode table plus the superscript and subscript characters (written as \textsuperscript / \textsubscript). */
    private static function unicodeMap(): array
    {
        static $map = null;
        if ($map === null) {
            $map = self::UNICODE;
            $super = ['⁰' => '0', 'ⁱ' => 'i', '⁴' => '4', '⁵' => '5', '⁶' => '6', '⁷' => '7', '⁸' => '8', '⁹' => '9', '⁺' => '+', '⁻' => '-',
                '⁼' => '=', '⁽' => '(', '⁾' => ')', 'ⁿ' => 'n'];
            $sub = ['₀' => '0', '₁' => '1', '₂' => '2', '₃' => '3', '₄' => '4', '₅' => '5', '₆' => '6', '₇' => '7', '₈' => '8', '₉' => '9',
                '₊' => '+', '₋' => '-', '₌' => '=', '₍' => '(', '₎' => ')', 'ₐ' => 'a', 'ₑ' => 'e', 'ₒ' => 'o', 'ₓ' => 'x'];
            foreach ($super as $u => $c) {
                $map[$u] = '\\textsuperscript{' . $c . '}';
            }
            foreach ($sub as $u => $c) {
                $map[$u] = '\\textsubscript{' . $c . '}';
            }
        }
        return $map;
    }

    /** @var array<string, bool> ids that an internal link points to: only these get a \label */
    private array $referenced = [];
    /** @var array<string, string> id => section|figure|table|equation|other, for the ids of the document */
    private array $kinds = [];
    private bool $hasMath = false;
    private bool $hasSubfigures = false;
    private int $listDepth = 0;
    private int $inCell = 0;
    private bool $inAppendix = false;

    /**
     * Options:
     *  - compactWideTables (bool, default false) tables of 7 or more columns get \small and a narrower
     *    \tabcolsep, to fit the page
     */
    public function __construct(private array $options = []) {}

    public function write(Pandoc $doc, bool $standalone = true): string
    {
        $this->scan($doc);
        $body = '';
        foreach ($doc->blocks as $block) {
            $body .= $this->writeBlock($block) . "\n\n";
        }
        $body = trim($body);

        return $standalone ? $this->standalone($body, $doc) : $body;
    }

    /** Collects the link targets and the features that need packages. */
    private function scan(Pandoc $doc): void
    {
        $this->referenced = [];
        $this->kinds = [];
        $this->hasMath = false;
        $this->hasSubfigures = false;
        $this->inAppendix = false;
        $walk = function ($n) use (&$walk): void {
            if (is_array($n)) {
                foreach ($n as $v) {
                    $walk($v);
                }
                return;
            }
            if (!is_object($n) || $n instanceof \UnitEnum) {
                return;
            }
            if ($n instanceof Header && $n->attr->identifier !== '') {
                $this->kinds[$n->attr->identifier] = 'section';
            } elseif ($n instanceof Figure && $n->attr->identifier !== '' && $n->caption->content) {
                $this->kinds[$n->attr->identifier] = 'figure';
            } elseif ($n instanceof \Pandoc\AST\Table && $n->attr->identifier !== '' && $n->caption->content) {
                $this->kinds[$n->attr->identifier] = 'table';
            } elseif ($n instanceof Para && $this->hasDisplayMath($n->content)) {
                foreach ($n->content as $c) {
                    if ($c instanceof Span && $c->attr->identifier !== '') {
                        $this->kinds[$c->attr->identifier] = 'equation';
                    }
                }
            } elseif (($n instanceof Div || $n instanceof Span) && $n->attr->identifier !== '') {
                $this->kinds[$n->attr->identifier] ??= 'other';
            }
            if ($n instanceof Link && str_starts_with($n->target->url, '#')) {
                $this->referenced[substr($n->target->url, 1)] = true;
            } elseif ($n instanceof Math) {
                $this->hasMath = true;
            } elseif ($n instanceof Figure && in_array('subfigure', $n->attr->classes, true)) {
                $this->hasSubfigures = true;
            }
            foreach (get_object_vars($n) as $v) {
                $walk($v);
            }
        };
        $walk($doc->blocks);
    }

    private function hasDisplayMath(array $inlines): bool
    {
        foreach ($inlines as $i) {
            if (($i instanceof Math && $i->type === MathType::DisplayMath)
                || ($i instanceof Span && $this->hasDisplayMath($i->content))) {
                return true;
            }
        }
        return false;
    }

    private function label(string $id): string
    {
        return $id !== '' && isset($this->referenced[$id]) ? '\\label{' . $this->labelName($id) . '}' : '';
    }

    private function labelName(string $id): string
    {
        return preg_replace('/[^\p{L}\p{N}:._\-\/+]/u', '-', $id) ?? $id;
    }

    protected function standalone(string $body, ?Pandoc $doc = null): string
    {
        $meta = $doc?->meta->map ?? [];
        $packages = '';
        if ($this->hasMath) {
            $packages .= "\\usepackage{amsmath}\n\\usepackage{amssymb}\n";
        }
        if ($this->hasSubfigures) {
            $packages .= "\\usepackage{subcaption}\n";
        }
        $lang = is_string($meta['lang'] ?? null) ? $meta['lang'] : '';
        $babel = self::BABEL[$lang] ?? self::BABEL[strtolower(explode('-', $lang)[0])] ?? null;
        if ($babel !== null) {
            $packages .= "\\usepackage[$babel]{babel}\n";
        }

        $front = '';
        $title = $meta['title'] ?? [];
        if ($title) {
            $authors = array_map(fn($a) => $this->writeInlines($a), array_filter($meta['author'] ?? [], 'is_array'));
            $front .= "\\title{" . $this->writeInlines($title) . "}\n"
                . "\\author{" . implode(" \\and ", $authors) . "}\n"
                . "\\date{" . $this->writeInlines($meta['date'] ?? []) . "}\n";
        }
        $cover = $meta['cover-image'] ?? null;
        if (is_string($cover) && $cover !== '') {
            $front .= "\\providecommand{\\cover}[1]{}\n\\cover{" . $cover . "}\n";
        }
        $maketitle = $title ? "\\maketitle\n\n" : '';

        $preamble = <<<'EOF'
\documentclass{article}
\usepackage[utf8]{inputenc}
\usepackage[T1]{fontenc}
\usepackage{hyperref}
\usepackage{soul}
\usepackage{xcolor}
\usepackage{graphicx}
\usepackage{booktabs}

EOF;
        $preamble .= $packages . <<<'EOF'

\providecommand{\tightlist}{%
  \setlength{\itemsep}{0pt}\setlength{\parskip}{0pt}}
\makeatother

EOF;
        return $preamble . "\n" . $front . "\\begin{document}\n\n" . $maketitle . $body . "\n\n\\end{document}";
    }

    protected function writeBlock(Block $block): string
    {
        return match (true) {
            $block instanceof Para => $this->writeParagraph($block),
            $block instanceof Plain => $this->writeInlines($block->content),
            $block instanceof Header => $this->writeHeader($block),
            $block instanceof BulletList => $this->writeBulletList($block),
            $block instanceof OrderedList => $this->writeOrderedList($block),
            $block instanceof DefinitionList => $this->writeDefinitionList($block),
            $block instanceof \Pandoc\AST\Table => $this->writeTable($block),
            $block instanceof Figure => $this->writeFigure($block),
            $block instanceof \Pandoc\AST\HorizontalRule => '\begin{center}\rule{0.5\linewidth}{0.5pt}\end{center}',
            $block instanceof BlockQuote => $this->writeBlockQuote($block),
            $block instanceof CodeBlock => $this->writeCodeBlock($block),
            $block instanceof Div => $this->writeDiv($block),
            $block instanceof \Pandoc\AST\RawBlock => $block->format === 'latex' ? $block->text : '',
            default => '% Unsupported Block: ' . get_class($block)
        };
    }

    /** A paragraph; one that holds only a labelled display formula becomes an equation environment. */
    protected function writeParagraph(Para $para): string
    {
        $labels = '';
        $math = null;
        foreach ($para->content as $i) {
            if ($i instanceof Span && !$i->content) {
                $labels .= $this->label($i->attr->identifier);
            } elseif ($i instanceof Span && count($i->content) === 1 && $i->content[0] instanceof Math && $i->content[0]->type === MathType::DisplayMath && $math === null) {
                $math = $i->content[0];
                $labels .= $this->label($i->attr->identifier);
            } elseif ($i instanceof Math && $i->type === MathType::DisplayMath && $math === null) {
                $math = $i;
            } elseif (!($i instanceof Space || $i instanceof SoftBreak)) {
                return $this->writeInlines($para->content);
            }
        }
        if ($math !== null && $labels !== '') {
            // amsmath allows one \label per equation; the others follow the environment (they then name the same number)
            preg_match_all('/\\\\label\{[^}]*\}/', $labels, $all);
            $first = $all[0][0] ?? '';
            $rest = implode('', array_slice($all[0], 1));
            // the number shown by the equation environment replaces the "\qquad (n)" tag written into the formula
            $tag = '';
            $text = preg_replace_callback('/\s*\\\\qquad\s*\(\s*(\d+)\s*\)\s*$/', function ($m) use (&$tag) {
                $tag = '\\tag{' . $m[1] . '}'; // \ref then gives the number the document shows
                return '';
            }, $math->text);
            return "\\begin{equation}" . $first . $tag . "\n" . $text . "\n\\end{equation}" . ($rest !== '' ? "\n" . $rest : '');
        }
        return $this->writeInlines($para->content);
    }

    protected function writeTable(\Pandoc\AST\Table $table): string
    {
        $head = $table->head->rows;
        $body = [];
        foreach ($table->bodies as $b) {
            array_push($body, ...$b->rows);
        }
        array_push($body, ...$table->foot->rows);

        $cols = 0;
        if (!empty($head)) {
            $cols = count($head[0]->cells);
        } elseif (!empty($body)) {
            $cols = count($body[0]->cells);
        }
        foreach (array_merge($head, $body) as $row) {
            $n = 0;
            foreach ($row->cells as $cell) {
                $n += max(1, $cell->colSpan);
            }
            $cols = max($cols, $n);
        }

        $letters = [];
        for ($c = 0; $c < $cols; $c++) {
            $letters[$c] = $this->alignLetter($table->colSpecs[$c][0] ?? Alignment::AlignDefault);
        }
        $colSpec = implode('', $letters);

        $latex = "\\begin{table}[ht]\n\\centering\n";
        if ($cols >= 7 && ($this->options['compactWideTables'] ?? false)) {
            $latex .= "\\small\n\\setlength{\\tabcolsep}{3pt}\n";
        }
        $caption = $this->captionText($table->caption->content);
        if ($caption !== '') {
            $latex .= "\\caption{" . $caption . "}" . $this->label($table->attr->identifier) . "\n";
        }
        $latex .= "\\begin{tabular}{@{}" . $colSpec . "@{}}\n";

        if (!empty($head)) {
            $latex .= "\\toprule\n";
            foreach ($head as $row) {
                $latex .= $this->writeRow($row, $letters) . " \\\\\n";
            }
            $latex .= "\\midrule\n";
        }
        foreach ($body as $row) {
            $latex .= $this->writeRow($row, $letters) . " \\\\\n";
        }

        $latex .= "\\bottomrule\n";
        $latex .= "\\end{tabular}\n\\end{table}";
        return $latex;
    }

    private function alignLetter(Alignment $a): string
    {
        return match ($a) {
            Alignment::AlignCenter => 'c',
            Alignment::AlignRight => 'r',
            default => 'l',
        };
    }

    /** @param string[] $letters column alignments */
    protected function writeRow(\Pandoc\AST\Row $row, array $letters = []): string
    {
        $out = [];
        $col = 0;
        foreach ($row->cells as $cell) {
            $span = max(1, $cell->colSpan);
            $content = $this->cellText($cell->content);
            $mine = $this->alignLetter($cell->align);
            $column = $letters[$col] ?? 'l';
            if ($span > 1) {
                $content = "\\multicolumn{" . $span . "}{" . ($cell->align === Alignment::AlignDefault ? $column : $mine) . "}{" . $content . "}";
            } elseif ($cell->align !== Alignment::AlignDefault && $mine !== $column) {
                $content = "\\multicolumn{1}{" . $mine . "}{" . $content . "}";
            }
            $out[] = $content;
            $col += $span;
        }
        return implode(' & ', $out);
    }

    /** Cell content on one line when it is only text; other blocks as they are. */
    private function cellText(array $blocks): string
    {
        $this->inCell++;
        try {
            foreach ($blocks as $b) {
                if (!($b instanceof Plain || $b instanceof Para)) {
                    return $this->writeBlocks($blocks);
                }
            }
            return implode(' ', array_map(fn($b) => $this->writeInlines($b->content), $blocks));
        } finally {
            $this->inCell--;
        }
    }

    /** Caption text from the blocks of a Caption. */
    private function captionText(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $b) {
            if ($b instanceof Plain || $b instanceof Para) {
                $parts[] = $this->writeInlines($b->content);
            }
        }
        return trim(implode(' ', $parts));
    }

    protected function writeFigure(Figure $fig): string
    {
        $caption = $this->captionText($fig->caption->content);
        $label = $this->label($fig->attr->identifier);
        $subs = array_values(array_filter($fig->content, fn($b) => $b instanceof Figure));
        $latex = "\\begin{figure}[ht]\n\\centering\n";
        if ($subs && count($subs) === count($fig->content)) {
            $width = max(0.1, min(0.95, 0.95 / count($subs)));
            $parts = [];
            foreach ($subs as $sub) {
                $w = $this->relativeWidth($sub->attr) ?? $width;
                $part = "\\begin{subfigure}{" . $this->fraction($w) . "}\n\\centering\n";
                foreach ($sub->content as $b) {
                    if ($b instanceof Plain || $b instanceof Para) {
                        $part .= $this->writeInlinesForFigure($b->content, true) . "\n";
                    }
                }
                $subCaption = $this->captionText($sub->caption->content);
                if ($subCaption !== '') {
                    $part .= "\\caption{" . $subCaption . "}" . $this->label($sub->attr->identifier) . "\n";
                } elseif ($this->label($sub->attr->identifier) !== '') {
                    $part .= $this->label($sub->attr->identifier) . "\n";
                }
                $parts[] = $part . "\\end{subfigure}";
            }
            $latex .= implode("\n\\hfill\n", $parts) . "\n";
        } else {
            foreach ($fig->content as $b) {
                if ($b instanceof Plain || $b instanceof Para) {
                    $latex .= $this->writeInlinesForFigure($b->content, false) . "\n";
                } elseif ($b instanceof Block) {
                    $latex .= $this->writeBlock($b) . "\n";
                }
            }
        }
        if ($caption !== '') {
            $latex .= "\\caption{" . $caption . "}\n";
        }
        if ($label !== '') {
            $latex .= $label . "\n";
        }
        return $latex . "\\end{figure}";
    }

    /** Inlines of a figure panel; inside a subfigure the picture fills the panel. */
    private function writeInlinesForFigure(array $inlines, bool $fill): string
    {
        if (!$fill) {
            return $this->writeInlines($inlines);
        }
        $out = [];
        foreach ($inlines as $i) {
            if ($i instanceof Image) {
                $out[] = "\\includegraphics[width=\\textwidth]{" . $i->target->url . "}";
            } elseif (!($i instanceof Space || $i instanceof SoftBreak)) {
                $out[] = $this->writeInline($i);
            }
        }
        return implode("\n", $out);
    }

    /** 0.3 for "30%" or "0.3\textwidth"; null when the attribute is missing or absolute. */
    private function relativeWidth(Attr $attr): ?float
    {
        foreach ($attr->attributes as $pair) {
            $v = is_array($pair) ? ($pair[0] === 'width' ? $pair[1] : null) : null;
            if ($v !== null && preg_match('/^([0-9.]+)%$/', $v, $m)) {
                return min(1.0, (float) $m[1] / 100);
            }
        }
        return null;
    }

    private function fraction(float $w): string
    {
        return abs($w - 1.0) < 0.001 ? '\textwidth' : rtrim(rtrim(number_format($w, 3, '.', ''), '0'), '.') . '\textwidth';
    }

    protected function writeDiv(Div $div): string
    {
        $classes = $div->attr->classes;
        if (in_array('abstract', $classes, true)) {
            return "\\begin{abstract}\n" . $this->writeBlocks($div->content) . "\n\\end{abstract}";
        }
        if (in_array('references', $classes, true) || $div->attr->identifier === 'refs') {
            $bib = $this->writeBibliography($div);
            if ($bib !== null) {
                return $bib;
            }
        }
        if (in_array('algorithmic', $classes, true)) {
            $lines = [];
            foreach ($div->content as $line) {
                $indent = 0;
                if ($line instanceof Div) {
                    foreach ($line->attr->classes as $c) {
                        if (preg_match('/^alg-i(\d)$/', $c, $m)) {
                            $indent = (int) $m[1];
                        }
                    }
                    $lines[] = '\hspace*{' . number_format(1.5 * $indent, 1, '.', '') . 'em}' . $this->writeBlocks($line->content) . '\\\\';
                }
            }
            return "\\begin{quote}\n\\noindent " . implode("\n", $lines) . "\n\\end{quote}";
        }
        $label = $this->label($div->attr->identifier);
        $inner = $this->writeBlocks($div->content);
        return $label !== '' ? $label . "\n" . $inner : $inner;
    }

    /** Entries Div(ref-key)[Para("[n] text")] as a thebibliography environment, or null. */
    private function writeBibliography(Div $div): ?string
    {
        $items = [];
        foreach ($div->content as $entry) {
            if (!($entry instanceof Div) || !str_starts_with($entry->attr->identifier, 'ref-')) {
                return null;
            }
            $key = substr($entry->attr->identifier, 4);
            $text = '';
            foreach ($entry->content as $b) {
                if ($b instanceof Para || $b instanceof Plain) {
                    $inl = $b->content;
                    if (isset($inl[0]) && $inl[0] instanceof Str && preg_match('/^\[\d+\]$/', $inl[0]->text)) {
                        array_shift($inl);
                        if (isset($inl[0]) && $inl[0] instanceof Space) {
                            array_shift($inl);
                        }
                    }
                    $text .= ($text !== '' ? ' ' : '') . $this->writeInlines($inl);
                }
            }
            $items[] = '\bibitem{' . $key . '} ' . $text;
        }
        return $items ? "\\begin{thebibliography}{99}\n" . implode("\n", $items) . "\n\\end{thebibliography}" : null;
    }

    protected function writeHeader(Header $h): string
    {
        $cmd = match ($h->level) {
            1 => 'section',
            2 => 'subsection',
            3 => 'subsubsection',
            4 => 'paragraph',
            default => 'subparagraph'
        };
        $star = in_array('unnumbered', $h->attr->classes, true) ? '*' : '';
        // a heading whose original number was a letter starts the appendices
        $appendix = '';
        if (!$this->inAppendix && $star === '') {
            foreach ($h->attr->attributes as $pair) {
                if (is_array($pair) && $pair[0] === 'number' && preg_match('/^[A-Z]/', $pair[1])) {
                    $this->inAppendix = true;
                    $appendix = "\\appendix\n";
                }
            }
        }
        return $appendix . "\\{$cmd}{$star}{" . $this->writeInlines($h->content) . "}" . $this->label($h->attr->identifier);
    }

    protected function writeBulletList(BulletList $list): string
    {
        $this->listDepth++;
        $items = array_map(fn($item) => "\\item " . $this->writeBlocks($item), $list->items);
        $this->listDepth--;
        return "\\begin{itemize}\n\\tightlist\n" . implode("\n", $items) . "\n\\end{itemize}";
    }

    protected function writeOrderedList(OrderedList $list): string
    {
        $depth = min($this->listDepth, 3);
        $counter = ['enumi', 'enumii', 'enumiii', 'enumiv'][$depth];
        // LaTeX default numbering by depth: 1. (a) i. A.
        $defaults = [
            [ListNumberStyle::Decimal, ListNumberDelim::Period],
            [ListNumberStyle::LowerAlpha, ListNumberDelim::TwoParens],
            [ListNumberStyle::LowerRoman, ListNumberDelim::Period],
            [ListNumberStyle::UpperAlpha, ListNumberDelim::Period],
        ];
        $style = $list->attr->style === ListNumberStyle::DefaultStyle ? $defaults[$depth][0] : $list->attr->style;
        $delim = $list->attr->delim === ListNumberDelim::DefaultDelim ? $defaults[$depth][1] : $list->attr->delim;
        $custom = $style !== $defaults[$depth][0] || $delim !== $defaults[$depth][1];

        $pre = '';
        $post = '';
        if ($custom) {
            $number = match ($style) {
                ListNumberStyle::LowerAlpha => '\alph',
                ListNumberStyle::UpperAlpha => '\Alph',
                ListNumberStyle::LowerRoman => '\roman',
                ListNumberStyle::UpperRoman => '\Roman',
                default => '\arabic',
            } . '{' . $counter . '}';
            [$open, $close] = match ($delim) {
                ListNumberDelim::OneParen => ['', ')'],
                ListNumberDelim::TwoParens => ['(', ')'],
                default => ['', '.'],
            };
            $pre = '\renewcommand{\label' . $counter . '}{' . $open . $number . $close . "}\n";
            [$dStyle, $dDelim] = $defaults[$depth];
            $defNumber = match ($dStyle) {
                ListNumberStyle::LowerAlpha => '\alph',
                ListNumberStyle::LowerRoman => '\roman',
                ListNumberStyle::UpperAlpha => '\Alph',
                default => '\arabic',
            } . '{' . $counter . '}';
            $post = "\n" . '\renewcommand{\label' . $counter . '}{' . ($dDelim === ListNumberDelim::TwoParens ? '(' : '') . $defNumber
                . ($dDelim === ListNumberDelim::TwoParens ? ')' : '.') . '}';
        }
        $setStart = $list->attr->start !== 1 ? '\setcounter{' . $counter . '}{' . ($list->attr->start - 1) . "}\n" : '';

        $this->listDepth++;
        $items = array_map(fn($item) => "\\item " . $this->writeBlocks($item), $list->items);
        $this->listDepth--;
        return $pre . "\\begin{enumerate}\n" . $setStart . "\\tightlist\n" . implode("\n", $items) . "\n\\end{enumerate}" . $post;
    }

    protected function writeDefinitionList(DefinitionList $list): string
    {
        $items = [];
        foreach ($list->items as [$term, $defs]) {
            $text = [];
            foreach ($defs as $def) {
                $text[] = $this->writeBlocks(is_array($def) ? $def : [$def]);
            }
            $items[] = '\item[' . $this->writeInlines($term) . '] ' . implode("\n\n", $text);
        }
        return "\\begin{description}\n" . implode("\n", $items) . "\n\\end{description}";
    }

    protected function writeBlockQuote(BlockQuote $bq): string
    {
        return "\\begin{quote}\n" . $this->writeBlocks($bq->content) . "\n\\end{quote}";
    }

    protected function writeCodeBlock(CodeBlock $cb): string
    {
        $lang = $cb->attr->classes[0] ?? '';
        $opt = $lang !== '' ? '[' . strtoupper($lang) . ']' : '';
        return "\\begin{verbatim}$opt\n" . $cb->text . "\n\\end{verbatim}";
    }

    protected function writeBlocks(array $blocks): string
    {
        return implode("\n\n", array_map([$this, 'writeBlock'], $blocks));
    }

    protected function writeInlines(array $inlines): string
    {
        $out = '';
        for ($i = 0; $i < count($inlines); $i++) {
            if (($cite = $this->citationAt($inlines, $i)) !== null) {
                $out .= $cite[0];
                $i += $cite[1] - 1;
                continue;
            }
            $out .= $this->writeInline($inlines[$i]);
        }
        return $out;
    }

    /**
     * "[" link ";" link "]" where the links are citations (#ref-key) becomes \cite{key1,key2}.
     *
     * @return array{0:string, 1:int}|null LaTeX and the number of inlines consumed
     */
    private function citationAt(array $in, int $i): ?array
    {
        $open = $in[$i];
        if (!($open instanceof Str) || !str_ends_with($open->text, '[') || !isset($in[$i + 1])) {
            return null;
        }
        $keys = [];
        $j = $i + 1;
        while (isset($in[$j]) && $in[$j] instanceof Link && in_array('citation', $in[$j]->attr->classes, true)
            && str_starts_with($in[$j]->target->url, '#ref-')) {
            $keys[] = substr($in[$j]->target->url, 5);
            $j++;
            // separator between two citations: "; " or ", "
            if (isset($in[$j], $in[$j + 1]) && $in[$j] instanceof Str && in_array($in[$j]->text, [';', ','], true)) {
                $j += isset($in[$j + 1]) && $in[$j + 1] instanceof Space ? 2 : 1;
            }
        }
        if (!$keys || !isset($in[$j]) || !($in[$j] instanceof Str) || !str_starts_with($in[$j]->text, ']')) {
            return null;
        }
        $prefix = substr($open->text, 0, -1);
        $tail = substr($in[$j]->text, 1);
        return [$this->escapeLatex($prefix) . '\cite{' . implode(',', $keys) . '}' . $this->escapeLatex($tail), $j - $i + 1];
    }

    protected function writeInline(Inline $i): string
    {
        return match (true) {
            $i instanceof Str => $this->escapeLatex($i->text),
            $i instanceof Space => ' ',
            $i instanceof Emph => '\\emph{' . $this->writeInlines($i->content) . '}',
            $i instanceof Strong => '\\textbf{' . $this->writeInlines($i->content) . '}',
            $i instanceof Underline => ($this->isSimple($i->content) ? '\\ul{' : '\\underline{') . $this->writeInlines($i->content) . '}',
            $i instanceof Strikeout => $this->isSimple($i->content)
                ? '\\st{' . $this->writeInlines($i->content) . '}'
                : $this->writeInlines($i->content), // soul cannot strike links or formulas, and there is no kernel command
            $i instanceof Superscript => '\\textsuperscript{' . $this->writeInlines($i->content) . '}',
            $i instanceof Subscript => '\\textsubscript{' . $this->writeInlines($i->content) . '}',
            $i instanceof Link => $this->writeLink($i),
            $i instanceof Note => '\\footnote{' . $this->noteText($i->content) . '}',
            $i instanceof Image => $this->writeImage($i),
            $i instanceof Code => '\\texttt{' . $this->escapeLatex($i->text) . '}',
            $i instanceof Span => $this->writeSpan($i),
            $i instanceof Math => $this->writeMath($i),
            $i instanceof SmallCaps => '\\textsc{' . $this->writeInlines($i->content) . '}',
            $i instanceof Quoted => $i->type === QuoteType::SingleQuote
                ? '`' . $this->writeInlines($i->content) . "'"
                : '``' . $this->writeInlines($i->content) . "''",
            $i instanceof LineBreak => '\\\\',
            $i instanceof SoftBreak => ' ',
            $i instanceof Cite => $this->writeInlines($i->content),
            $i instanceof RawInline => $i->format === 'latex' ? $i->text : '',
            default => ''
        };
    }

    /** True when the inlines are text that soul's \ul and \st can carry (no links, formulas, notes, pictures). */
    private function isSimple(array $inlines): bool
    {
        foreach ($inlines as $i) {
            if ($i instanceof Str || $i instanceof Space || $i instanceof SoftBreak) {
                continue;
            }
            if (($i instanceof Emph || $i instanceof Strong) && $this->isSimple($i->content)) {
                continue;
            }
            return false;
        }
        return true;
    }

    /** Footnote body: inlines, or blocks (paragraphs separated by a blank line). */
    private function noteText(array $content): string
    {
        if ($content && $content[0] instanceof Block) {
            return trim($this->writeBlocks($content));
        }
        return $this->writeInlines($content);
    }

    protected function writeMath(Math $m): string
    {
        // display math is not allowed inside a table cell
        return $m->type === MathType::DisplayMath && $this->inCell === 0
            ? '\\[' . $m->text . '\\]'
            : '$' . $m->text . '$';
    }

    protected function writeSpan(Span $span): string
    {
        $anchor = $this->label($span->attr->identifier);
        if ($anchor !== '' && !$span->content) {
            return $anchor;
        }
        foreach ($span->attr->attributes as $attr) {
            if ($attr[0] === 'color') {
                $color = $attr[1];
                $content = $this->writeInlines($span->content);
                if (str_starts_with($color, '#')) {
                    $hex = substr($color, 1);
                    if (strlen($hex) === 3) {
                        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
                    }
                    if (strlen($hex) === 6) {
                        return "\\textcolor[HTML]{" . strtoupper($hex) . "}{" . $content . "}";
                    }
                }
                return "\\textcolor{" . $color . "}{" . $content . "}";
            }
            if ($attr[0] === 'background-color') {
                $color = $attr[1];
                $content = $this->writeInlines($span->content);
                if (str_starts_with($color, '#')) {
                    $hex = substr($color, 1);
                    if (strlen($hex) === 3) {
                        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
                    }
                    if (strlen($hex) === 6) {
                        return "\\colorbox[HTML]{" . strtoupper($hex) . "}{" . $content . "}";
                    }
                }
                return "\\colorbox{" . $color . "}{" . $content . "}";
            }
        }
        return $anchor . $this->writeInlines($span->content);
    }

    protected function writeColoredInlines(string $color, ?string $model, array $inlines): string
    {
        return implode('', array_map(fn($i) => $this->writeColoredInline($color, $model, $i), $inlines));
    }

    protected function writeColoredLink(string $color, ?string $model, Link $link): string
    {
        $url = $link->target->url;
        $coloredContent = $this->writeColoredInlines($color, $model, $link->content);
        if (in_array('internal', $link->attr->classes)) {
            return "\\hyperref[" . ltrim($url, '#') . "]{" . $coloredContent . "}";
        }
        if (in_array('url', $link->attr->classes)) {
            return "\\url{" . $url . "}";
        }
        return "\\href{" . $url . "}{" . $coloredContent . "}";
    }

    protected function writeColoredInline(string $color, ?string $model, Inline $i): string
    {
        $cmd = $model ? "\\textcolor[{$model}]{{$color}}" : "\\textcolor{{$color}}";
        return match (true) {
            $i instanceof Str        => $cmd . '{' . $this->escapeLatex($i->text) . '}',
            $i instanceof Space      => ' ',
            $i instanceof Emph       => '\\emph{'             . $this->writeColoredInlines($color, $model, $i->content) . '}',
            $i instanceof Strong     => '\\textbf{'           . $this->writeColoredInlines($color, $model, $i->content) . '}',
            $i instanceof Underline  => '\\ul{'               . $this->writeColoredInlines($color, $model, $i->content) . '}',
            $i instanceof Strikeout  => '\\st{'               . $this->writeColoredInlines($color, $model, $i->content) . '}',
            $i instanceof Superscript => '\\textsuperscript{' . $this->writeColoredInlines($color, $model, $i->content) . '}',
            $i instanceof Subscript   => '\\textsubscript{'   . $this->writeColoredInlines($color, $model, $i->content) . '}',
            $i instanceof Code       => '\\texttt{' . $cmd . '{' . $this->escapeLatex($i->text) . '}}',
            $i instanceof Link       => $this->writeColoredLink($color, $model, $i),
            default                  => $cmd . '{' . $this->writeInline($i) . '}',
        };
    }

    protected function writeLink(Link $link): string
    {
        $url = $link->target->url;
        $text = $this->writeInlines($link->content);

        if (str_starts_with($url, '#') || in_array('internal', $link->attr->classes)) {
            if (!isset($this->kinds[ltrim($url, '#')])) {
                return $text; // nothing in the document carries that id (a broken link in the source): keep the text only
            }
            $anchor = $this->labelName(ltrim($url, '#'));
            $numbered = in_array($this->kinds[ltrim($url, '#')] ?? 'other', ['section', 'figure', 'table', 'equation'], true);
            foreach ($link->attr->attributes as $pair) {
                if (is_array($pair) && $pair[0] === 'reference-type' && $numbered) {
                    return "\\ref{" . $anchor . "}";
                }
            }
            return "\\hyperref[{$anchor}]{" . $text . "}";
        }
        $url = strtr($url, ['%' => '\\%', '#' => '\\#']);
        if (in_array('url', $link->attr->classes) || in_array('uri', $link->attr->classes)) {
            return "\\url{" . $url . "}";
        }
        return "\\href{" . $url . "}{" . $text . "}";
    }

    protected function writeImage(Image $img): string
    {
        $url = $img->target->url;
        if (preg_match('/\.(emf|wmf|svm|svg|svgz|gif|bmp|webp|tiff?)$/i', $url)) {
            // pdflatex reads PNG, JPEG and PDF only: leave a visible mark instead of a compile error
            return '\\fbox{[image: ' . $this->escapeLatex(basename($url)) . ']}';
        }
        $options = [];
        foreach ($img->attr->attributes as $pair) {
            if (is_array($pair) && in_array($pair[0], ['width', 'height'], true) && ($v = $this->length($pair[1], $pair[0])) !== null) {
                $options[] = $pair[0] . '=' . $v;
            }
        }
        // Do not escapeLatex for the URL in \includegraphics as it can break paths (e.g. underscores)
        return "\\includegraphics" . ($options ? '[' . implode(',', $options) . ']' : '') . "{" . $url . "}";
    }

    /** "50%" becomes 0.5\textwidth (\textheight for heights); absolute lengths are kept. */
    private function length(string $v, string $which): ?string
    {
        if (preg_match('/^([0-9.]+)%$/', trim($v), $m)) {
            $f = min(1.0, (float) $m[1] / 100);
            $unit = $which === 'width' ? '\\textwidth' : '\\textheight';
            return abs($f - 1.0) < 0.001 ? $unit : rtrim(rtrim(number_format($f, 3, '.', ''), '0'), '.') . $unit;
        }
        return preg_match('/^[0-9.]+(cm|mm|in|pt|em|ex|bp)$/', trim($v)) ? trim($v) : null;
    }

    protected function escapeLatex(string $text): string
    {
        $map = [
            '\\' => '\\textbackslash{}',
            '{' => '\\{',
            '}' => '\\}',
            '$' => '\\$',
            '&' => '\\&',
            '#' => '\\#',
            '^' => '\\textasciicircum{}',
            '_' => '\\_',
            '~' => '\\textasciitilde{}',
            '%' => '\\%',
            '«' => '<<',
            '»' => '>>',
            ' ' => '~', // Non-breaking space
        ];
        $escaped = strtr(strtr($text, $map), self::unicodeMap());

        // Detect sequences of underscores used as a line (e.g. 2 or more)
        // and replace them with a more solid representation using soul's \ul
        // if soul is available. Since it's in our preamble, we can use it.
        // Actually, Pandoc often just uses \rule.
        // Let's try to find a sequence of escaped underscores.
        if (str_contains($escaped, '\_\_')) {
            $escaped = preg_replace_callback('/(\\\\_){2,}/', function($matches) {
                $count = strlen($matches[0]) / 2; // Each escaped underscore is 2 chars
                // Use a rule that approximates the width of n underscores
                // An underscore is roughly 0.5em wide in many fonts.
                return '\rule{' . ($count * 0.5) . 'em}{0.4pt}';
            }, $escaped);
        }

        return $escaped;
    }
}
