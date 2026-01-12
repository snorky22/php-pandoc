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
use Pandoc\AST\Code;
use Pandoc\AST\CodeBlock;
use Pandoc\AST\BlockQuote;
use Pandoc\AST\Div;

class LatexWriter
{
    public function write(Pandoc $doc, bool $standalone = true): string
    {
        $body = '';
        foreach ($doc->blocks as $block) {
            $body .= $this->writeBlock($block) . "\n\n";
        }
        $body = trim($body);

        return $standalone ? $this->standalone($body) : $body;
    }

    protected function standalone(string $body): string
    {
        $preamble = <<<'EOF'
\documentclass{article}
\usepackage[utf8]{inputenc}
\usepackage[T1]{fontenc}
\usepackage{hyperref}
\usepackage{soul}
\usepackage{xcolor}
\usepackage{graphicx}

\providecommand{\tightlist}{%
  \setlength{\itemsep}{0pt}\setlength{\parskip}{0pt}}
\makeatother

\begin{document}
EOF;
        return $preamble . "\n\n" . $body . "\n\n\\end{document}";
    }

    protected function writeBlock(Block $block): string
    {
        return match (true) {
            $block instanceof Para => $this->writeInlines($block->content),
            $block instanceof Plain => $this->writeInlines($block->content),
            $block instanceof Header => $this->writeHeader($block),
            $block instanceof BulletList => $this->writeBulletList($block),
            $block instanceof OrderedList => $this->writeOrderedList($block),
            $block instanceof \Pandoc\AST\Table => $this->writeTable($block),
            $block instanceof \Pandoc\AST\HorizontalRule => '\begin{center}\rule{0.5\linewidth}{0.5pt}\end{center}',
            $block instanceof BlockQuote => $this->writeBlockQuote($block),
            $block instanceof CodeBlock => $this->writeCodeBlock($block),
            $block instanceof Div => $this->writeBlocks($block->content),
            default => '% Unsupported Block: ' . get_class($block)
        };
    }

    protected function writeTable(\Pandoc\AST\Table $table): string
    {
        $latex = "\\begin{longtable}[]{@{}";
        $cols = 0;
        if (!empty($table->head->rows)) {
            $cols = count($table->head->rows[0]->cells);
        } elseif (!empty($table->bodies)) {
            $cols = count($table->bodies[0]->rows[0]->cells);
        }

        for ($i = 0; $i < $cols; $i++) { $latex .= "l"; }
        $latex .= "@{}}\n";

        if (!empty($table->head->rows)) {
            $latex .= "\\toprule\\noalign{}\n";
            foreach ($table->head->rows as $row) {
                $latex .= $this->writeRow($row) . " \\\\\n";
            }
            $latex .= "\\midrule\\noalign{}\n\\endhead\n";
        }

        foreach ($table->bodies as $body) {
            foreach ($body->rows as $row) {
                $latex .= $this->writeRow($row) . " \\\\\n";
            }
        }

        $latex .= "\\bottomrule\\noalign{}\n";
        $latex .= "\\end{longtable}";
        return $latex;
    }

    protected function writeRow(\Pandoc\AST\Row $row): string
    {
        return implode(' & ', array_map(fn($cell) => $this->writeBlocks($cell->content), $row->cells));
    }

    protected function writeHeader(Header $h): string
    {
        $cmd = match ($h->level) {
            1 => 'section',
            2 => 'subsection',
            3 => 'subsubsection',
            default => 'section'
        };
        return "\\{$cmd}{" . $this->writeInlines($h->content) . "}";
    }

    protected function writeBulletList(BulletList $list): string
    {
        $items = array_map(fn($item) => "\\item " . $this->writeBlocks($item), $list->items);
        return "\\begin{itemize}\n\\tightlist\n" . implode("\n", $items) . "\n\\end{itemize}";
    }

    protected function writeOrderedList(OrderedList $list): string
    {
        $items = array_map(fn($item) => "\\item " . $this->writeBlocks($item), $list->items);
        return "\\begin{enumerate}\n\\tightlist\n" . implode("\n", $items) . "\n\\end{enumerate}";
    }

    protected function writeBlockQuote(BlockQuote $bq): string
    {
        return "\\begin{quote}\n" . $this->writeBlocks($bq->content) . "\n\\end{quote}";
    }

    protected function writeCodeBlock(CodeBlock $cb): string
    {
        return "\\begin{verbatim}\n" . $cb->text . "\n\\end{verbatim}";
    }

    protected function writeBlocks(array $blocks): string
    {
        return implode("\n\n", array_map([$this, 'writeBlock'], $blocks));
    }

    protected function writeInlines(array $inlines): string
    {
        return implode('', array_map([$this, 'writeInline'], $inlines));
    }

    protected function writeInline(Inline $i): string
    {
        return match (true) {
            $i instanceof Str => $this->escapeLatex($i->text),
            $i instanceof Space => ' ',
            $i instanceof Emph => '\\emph{' . $this->writeInlines($i->content) . '}',
            $i instanceof Strong => '\\textbf{' . $this->writeInlines($i->content) . '}',
            $i instanceof Underline => '\\ul{' . $this->writeInlines($i->content) . '}',
            $i instanceof Strikeout => '\\st{' . $this->writeInlines($i->content) . '}',
            $i instanceof Superscript => '\\textsuperscript{' . $this->writeInlines($i->content) . '}',
            $i instanceof Subscript => '\\textsubscript{' . $this->writeInlines($i->content) . '}',
            $i instanceof Link => $this->writeLink($i),
            $i instanceof Image => $this->writeImage($i),
            $i instanceof Code => '\\texttt{' . $this->escapeLatex($i->text) . '}',
            $i instanceof Span => $this->writeSpan($i),
            default => ''
        };
    }

    protected function writeSpan(Span $span): string
    {
        $content = $this->writeInlines($span->content);
        foreach ($span->attr->attributes as $attr) {
            if ($attr[0] === 'color') {
                $color = $attr[1];
                if (str_starts_with($color, '#')) {
                    $hex = substr($color, 1);
                    // Handle 3-digit hex
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
                if (str_starts_with($color, '#')) {
                    $hex = substr($color, 1);
                    if (strlen($hex) === 3) {
                        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
                    }
                    if (strlen($hex) === 6) {
                        return "\\colorbox[HTML]{" . strtoupper($hex) . "}{" . $content . "}";
                    }
                }
                // For named colors like 'yellow' from highlight
                return "\\colorbox{" . $color . "}{" . $content . "}";
            }
        }
        return $content;
    }

    protected function writeLink(Link $link): string
    {
        $url = $link->target->url;
        return "\\href{" . $this->escapeLatex($url) . "}{" . $this->writeInlines($link->content) . "}";
    }

    protected function writeImage(Image $img): string
    {
        $url = $img->target->url;
        // Do not escapeLatex for the URL in \includegraphics as it can break paths (e.g. underscores)
        return "\\pandocbounded{\\includegraphics{{$url}}}";
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
        $escaped = strtr($text, $map);

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
