<?php

namespace Pandoc\Reader;

use Pandoc\AST\Meta;
use Pandoc\AST\Pandoc;
use Pandoc\AST\Para;
use Pandoc\AST\Str;
use Pandoc\AST\Space;
use Pandoc\AST\Emph;
use Pandoc\AST\Strong;
use Pandoc\AST\Header;
use Pandoc\AST\Attr;
use Pandoc\AST\MediaBag;
use Pandoc\AST\Image;
use Pandoc\AST\Target;

class MarkdownReader implements ReaderInterface
{
    use HasMediaBag;

    public function __construct()
    {
        $this->initMediaBag();
    }

    /**
     * A very simplistic Markdown reader for demonstration.
     * Supports: Headers (#), Paragraphs, Bold (**), Italic (*), Images (![])
     */
    public function read(string $content): Pandoc
    {
        $this->initMediaBag();
        $lines = explode("\n", str_replace("\r\n", "\n", $content));
        $blocks = [];
        $currentPara = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                if (!empty($currentPara)) {
                    $blocks[] = new Para($this->parseInlines(implode(" ", $currentPara)));
                    $currentPara = [];
                }
                continue;
            }

            // Header
            if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $matches)) {
                if (!empty($currentPara)) {
                    $blocks[] = new Para($this->parseInlines(implode(" ", $currentPara)));
                    $currentPara = [];
                }
                $level = strlen($matches[1]);
                $blocks[] = new Header($level, new Attr(), $this->parseInlines($matches[2]));
                continue;
            }

            // Bullet list item
            if (preg_match('/^[-*+]\s+(.+)$/', $trimmed, $matches)) {
                if (!empty($currentPara)) {
                    $blocks[] = new Para($this->parseInlines(implode(" ", $currentPara)));
                    $currentPara = [];
                }
                // For simplicity, we just treat bullet items as paragraphs for now
                // but let's actually make it look like a list item if we wanted to be more accurate.
                // However, our simple reader doesn't have state for lists yet.
                // Let's at least keep it as a separate block.
                $blocks[] = new Para($this->parseInlines($matches[1]));
                continue;
            }

            $currentPara[] = $line;
        }

        if (!empty($currentPara)) {
            $blocks[] = new Para($this->parseInlines(implode(" ", $currentPara)));
        }

        return new Pandoc(new Meta(), $blocks, $this->mediaBag);
    }

    /**
     * @return \Pandoc\AST\Inline[]
     */
    protected function parseInlines(string $text): array
    {
        // Simplistic inline parsing: Image, Strong, Emph, then Str/Space
        $inlines = [];

        // Handle Images ! [alt] (url)
        if (preg_match_all('/!\[([^\]]*)\]\(([^)]+)\)/', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            $lastOffset = 0;
            foreach ($matches as $match) {
                $fullMatch = $match[0][0];
                $offset = $match[0][1];
                $alt = $match[1][0];
                $url = $match[2][0];

                // Parse text before image
                if ($offset > $lastOffset) {
                    $before = substr($text, $lastOffset, $offset - $lastOffset);
                    $inlines = array_merge($inlines, $this->parseBasicInlines($before));
                }

                // Handle data-uri in markdown
                if (str_starts_with($url, 'data:')) {
                    $data = $this->parseDataUri($url);
                    if ($data) {
                        $this->mediaBag->insert($data['filename'], $data['mime'], $data['contents']);
                        $url = $data['filename'];
                    }
                }

                $inlines[] = new Image(new Attr(), $this->textToInlines($alt), new Target($url));
                $lastOffset = $offset + strlen($fullMatch);
            }
            if ($lastOffset < strlen($text)) {
                $inlines = array_merge($inlines, $this->parseBasicInlines(substr($text, $lastOffset)));
            }
            return $inlines;
        }

        return $this->parseBasicInlines($text);
    }

    protected function parseBasicInlines(string $text): array
    {
        $inlines = [];
        // This is a very naive regex-based parser
        $tokens = preg_split('/(\*\*|__|\*|_)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        $stack = [];
        $currentContent = [];

        foreach ($tokens as $token) {
            if ($token === '**' || $token === '__') {
                if (!empty($stack) && end($stack) === 'strong') {
                    array_pop($stack);
                    $content = $this->textToInlines(implode('', $currentContent));
                    $inlines[] = new Strong($content);
                    $currentContent = [];
                } else {
                    $inlines = array_merge($inlines, $this->textToInlines(implode('', $currentContent)));
                    $currentContent = [];
                    $stack[] = 'strong';
                }
            } elseif ($token === '*' || $token === '_') {
                if (!empty($stack) && end($stack) === 'emph') {
                    array_pop($stack);
                    $content = $this->textToInlines(implode('', $currentContent));
                    $inlines[] = new Emph($content);
                    $currentContent = [];
                } else {
                    $inlines = array_merge($inlines, $this->textToInlines(implode('', $currentContent)));
                    $currentContent = [];
                    $stack[] = 'emph';
                }
            } else {
                $currentContent[] = $token;
            }
        }

        $inlines = array_merge($inlines, $this->textToInlines(implode('', $currentContent)));

        return $inlines;
    }

    /**
     * @return \Pandoc\AST\Inline[]
     */
    protected function textToInlines(string $text): array
    {
        if ($text === '') return [];

        $inlines = [];
        $parts = preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $part) {
            if ($part === '') continue;
            if (ctype_space($part)) {
                $inlines[] = new Space();
            } else {
                $inlines[] = new Str($part);
            }
        }
        return $inlines;
    }
}
