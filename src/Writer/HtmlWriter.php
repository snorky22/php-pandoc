<?php

namespace Pandoc\Writer;

use Pandoc\AST\Pandoc;
use Pandoc\AST\Block;
use Pandoc\AST\Inline;
use Pandoc\AST\Str;
use Pandoc\AST\Space;
use Pandoc\AST\Emph;
use Pandoc\AST\Strong;
use Pandoc\AST\Para;
use Pandoc\AST\Plain;
use Pandoc\AST\Header;
use Pandoc\AST\BulletList;
use Pandoc\AST\OrderedList;
use Pandoc\AST\CodeBlock;
use Pandoc\AST\RawBlock;
use Pandoc\AST\BlockQuote;
use Pandoc\AST\HorizontalRule;
use Pandoc\AST\Code;
use Pandoc\AST\Link;
use Pandoc\AST\Image;
use Pandoc\AST\Span;
use Pandoc\AST\Note;
use Pandoc\AST\DefinitionList;

class HtmlWriter
{
    public function write(Pandoc $doc): string
    {
        $result = '';
        foreach ($doc->blocks as $block) {
            $result .= $this->writeBlock($block) . "\n";
        }
        return trim($result);
    }

    protected function writeBlock(Block $block): string
    {
        return match (true) {
            $block instanceof Plain => $this->writeInlines($block->content),
            $block instanceof Para => '<p>' . $this->writeInlines($block->content) . '</p>',
            $block instanceof Header => "<h{$block->level}>" . $this->writeInlines($block->content) . "</h{$block->level}>",
            $block instanceof BulletList => "<ul>\n" . implode("\n", array_map(fn($item) => "  <li>" . $this->writeBlocks($item) . "</li>", $block->items)) . "\n</ul>",
            $block instanceof BlockQuote => "<blockquote>\n" . $this->writeBlocks($block->content) . "\n</blockquote>",
            $block instanceof CodeBlock => "<pre><code>" . htmlspecialchars($block->text) . "</code></pre>",
            $block instanceof RawBlock => $block->format === 'html' ? $block->text : '',
            $block instanceof HorizontalRule => "<hr />",
            $block instanceof DefinitionList => "<dl>\n" . implode("\n", array_map(function($item) {
                [$term, $defs] = $item;
                $html = "<dt>" . $this->writeInlines($term) . "</dt>\n";
                foreach ($defs as $def) {
                    $html .= "<dd>\n" . (is_array($def) ? $this->writeBlocks($def) : $this->writeBlock($def)) . "\n</dd>";
                }
                return $html;
            }, $block->items)) . "\n</dl>",
            default => '<!-- Unsupported Block: ' . get_class($block) . ' -->'
        };
    }

    /**
     * @param Block[] $blocks
     */
    protected function writeBlocks(array $blocks): string
    {
        return implode("\n", array_map([$this, 'writeBlock'], $blocks));
    }

    /**
     * @param Inline[] $inlines
     */
    protected function writeInlines(array $inlines): string
    {
        $result = '';
        foreach ($inlines as $inline) {
            $result .= $this->writeInline($inline);
        }
        return $result;
    }

    protected function writeInline(Inline $inline): string
    {
        return match (true) {
            $inline instanceof Str => htmlspecialchars($inline->text),
            $inline instanceof Space => ' ',
            $inline instanceof Emph => '<em>' . $this->writeInlines($inline->content) . '</em>',
            $inline instanceof Strong => '<strong>' . $this->writeInlines($inline->content) . '</strong>',
            $inline instanceof Code => '<code>' . htmlspecialchars($inline->text) . '</code>',
            $inline instanceof Image => '<img src="' . htmlspecialchars($inline->target->url) . '"' .
                ($inline->target->title ? ' title="' . htmlspecialchars($inline->target->title) . '"' : '') .
                ' alt="' . htmlspecialchars($this->stringify($inline->content)) . '" />',
            $inline instanceof Link => '<a href="' . htmlspecialchars($inline->target->url) . '"' .
                ($inline->target->title ? ' title="' . htmlspecialchars($inline->target->title) . '"' : '') .
                '>' . $this->writeInlines($inline->content) . '</a>',
            default => '<!-- Unsupported Inline: ' . get_class($inline) . ' -->'
        };
    }

    protected function stringify(array $inlines): string
    {
        $result = '';
        foreach ($inlines as $inline) {
            $result .= match (true) {
                $inline instanceof Str => $inline->text,
                $inline instanceof Space => ' ',
                property_exists($inline, 'content') && is_array($inline->content) => $this->stringify($inline->content),
                default => ''
            };
        }
        return $result;
    }
}
