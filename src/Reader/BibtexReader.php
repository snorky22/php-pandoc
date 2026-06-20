<?php

namespace Pandoc\Reader;

use Pandoc\AST\Meta;
use Pandoc\AST\Pandoc;
use Pandoc\AST\RawBlock;

class BibtexReader implements ReaderInterface
{
    /**
     * Reads a BibTeX string and returns a Pandoc AST.
     * Each BibTeX entry is rendered as a \bibitem inside a thebibliography environment.
     */
    public function read(string $content): Pandoc
    {
        $entries = $this->parseBibtex($content);

        if (empty($entries)) {
            return new Pandoc(new Meta(), []);
        }

        $lines = [];
        $lines[] = '\begin{thebibliography}{99}';

        // Fields whose values should be italicised
        $italicFields = ['title', 'booktitle', 'journal', 'series', 'publisher'];

        foreach ($entries as $entry) {
            $key = $entry['key'];
            $label = $key;

            $parts = [];
            foreach ($entry['fields'] as $field => $value) {
                if (in_array($field, $italicFields)) {
                    $parts[] = '\\emph{' . $value . '}';
                } else {
                    $parts[] = $value;
                }
            }
            $citeKey = implode(', ', $parts);

            $lines[] = '';
            $lines[] = '\bibitem{' . $key . '}';
            $lines[] = $citeKey;
        }

        $lines[] = '';
        $lines[] = '\end{thebibliography}';

        $blocks = [new RawBlock('latex', implode("\n", $lines))];

        return new Pandoc(new Meta(), $blocks);
    }

    /**
     * Parses BibTeX content into an array of entries.
     * Each entry has: type, key, fields (assoc array).
     */
    protected function parseBibtex(string $content): array
    {
        $entries = [];

        // Remove comments (lines starting with %)
        $content = preg_replace('/^%.*$/m', '', $content);

        // Match each @type{key, ...} entry
        // We use a manual brace-counting approach to handle nested braces
        $pos = 0;
        $len = strlen($content);

        while ($pos < $len) {
            // Find next '@'
            $atPos = strpos($content, '@', $pos);
            if ($atPos === false) {
                break;
            }

            // Match entry type
            if (!preg_match('/\G@([a-zA-Z]+)\s*\{/i', $content, $m, 0, $atPos)) {
                $pos = $atPos + 1;
                continue;
            }

            $type = strtolower($m[1]);

            // Skip @string, @preamble, @comment
            if (in_array($type, ['string', 'preamble', 'comment'])) {
                $pos = $atPos + strlen($m[0]);
                continue;
            }

            // Find the matching closing brace
            $braceStart = $atPos + strlen($m[0]) - 1; // position of '{'
            $depth = 1;
            $i = $braceStart + 1;
            while ($i < $len && $depth > 0) {
                if ($content[$i] === '{') $depth++;
                elseif ($content[$i] === '}') $depth--;
                $i++;
            }

            $inner = substr($content, $braceStart + 1, $i - $braceStart - 2);
            $pos = $i;

            // Extract citation key (first token before comma)
            if (!preg_match('/^\s*([^,\s]+)\s*,?/s', $inner, $km)) {
                continue;
            }
            $key = trim($km[1]);
            $rest = substr($inner, strlen($km[0]));

            $fields = $this->parseFields($rest);

            $entries[] = [
                'type'   => $type,
                'key'    => $key,
                'fields' => $fields,
            ];
        }

        return $entries;
    }

    /**
     * Parses the fields portion of a BibTeX entry body.
     * Returns an associative array of field => value.
     */
    protected function parseFields(string $body): array
    {
        $fields = [];
        $len = strlen($body);
        $pos = 0;

        while ($pos < $len) {
            // Skip whitespace and commas
            while ($pos < $len && (ctype_space($body[$pos]) || $body[$pos] === ',')) {
                $pos++;
            }
            if ($pos >= $len) break;

            // Match field name
            if (!preg_match('/\G([a-zA-Z][a-zA-Z0-9_\-]*)\s*=\s*/i', $body, $m, 0, $pos)) {
                // Skip unrecognised content
                $pos++;
                continue;
            }
            $fieldName = strtolower($m[1]);
            $pos += strlen($m[0]);

            if ($pos >= $len) break;

            // Read field value: {…}, "…", or bare number/macro
            $value = $this->readFieldValue($body, $pos);

            $fields[$fieldName] = $this->cleanValue($value);
        }

        return $fields;
    }

    /**
     * Reads a single field value starting at $pos (modified by reference).
     * Handles {…}, "…", and bare tokens (numbers / macros).
     * Supports # concatenation.
     */
    protected function readFieldValue(string $body, int &$pos): string
    {
        $len = strlen($body);
        $parts = [];

        while ($pos < $len) {
            // Skip whitespace
            while ($pos < $len && ctype_space($body[$pos])) $pos++;
            if ($pos >= $len) break;

            $ch = $body[$pos];

            if ($ch === '{') {
                // Brace-delimited value
                $depth = 1;
                $pos++;
                $start = $pos;
                while ($pos < $len && $depth > 0) {
                    if ($body[$pos] === '{') $depth++;
                    elseif ($body[$pos] === '}') $depth--;
                    $pos++;
                }
                $parts[] = substr($body, $start, $pos - $start - 1);
            } elseif ($ch === '"') {
                // Quote-delimited value
                $pos++;
                $start = $pos;
                $depth = 0;
                while ($pos < $len) {
                    if ($body[$pos] === '{') $depth++;
                    elseif ($body[$pos] === '}') $depth--;
                    elseif ($body[$pos] === '"' && $depth === 0) break;
                    $pos++;
                }
                $parts[] = substr($body, $start, $pos - $start);
                if ($pos < $len) $pos++; // skip closing "
            } else {
                // Bare token (number or macro name)
                $start = $pos;
                while ($pos < $len && $body[$pos] !== ',' && $body[$pos] !== '#' && !ctype_space($body[$pos]) && $body[$pos] !== '}') {
                    $pos++;
                }
                $parts[] = substr($body, $start, $pos - $start);
            }

            // Skip whitespace
            while ($pos < $len && ctype_space($body[$pos])) $pos++;

            // Concatenation operator
            if ($pos < $len && $body[$pos] === '#') {
                $pos++;
                continue;
            }

            break;
        }

        return implode('', $parts);
    }

    /**
     * Cleans a BibTeX field value: strips extra braces, normalises whitespace.
     */
    protected function cleanValue(string $value): string
    {
        // Remove surrounding braces used for case protection: {Word} -> Word
        $value = preg_replace('/\{([^{}]*)\}/', '$1', $value);
        // Normalise whitespace
        $value = preg_replace('/\s+/', ' ', trim($value));
        return $value;
    }

}
