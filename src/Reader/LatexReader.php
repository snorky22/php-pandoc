<?php

namespace Pandoc\Reader;

use Pandoc\AST\Alignment;
use Pandoc\AST\Attr;
use Pandoc\AST\Block;
use Pandoc\AST\BlockQuote;
use Pandoc\AST\BulletList;
use Pandoc\AST\Caption;
use Pandoc\AST\Cell;
use Pandoc\AST\Code;
use Pandoc\AST\CodeBlock;
use Pandoc\AST\DefinitionList;
use Pandoc\AST\Div;
use Pandoc\AST\Emph;
use Pandoc\AST\Figure;
use Pandoc\AST\Header;
use Pandoc\AST\Image;
use Pandoc\AST\Inline;
use Pandoc\AST\LineBreak;
use Pandoc\AST\Link;
use Pandoc\AST\ListAttributes;
use Pandoc\AST\ListNumberDelim;
use Pandoc\AST\ListNumberStyle;
use Pandoc\AST\Math;
use Pandoc\AST\MathType;
use Pandoc\AST\Meta;
use Pandoc\AST\Note;
use Pandoc\AST\OrderedList;
use Pandoc\AST\Pandoc;
use Pandoc\AST\Para;
use Pandoc\AST\Plain;
use Pandoc\AST\Quoted;
use Pandoc\AST\QuoteType;
use Pandoc\AST\Row;
use Pandoc\AST\SmallCaps;
use Pandoc\AST\SoftBreak;
use Pandoc\AST\Space;
use Pandoc\AST\Span;
use Pandoc\AST\Str;
use Pandoc\AST\Strikeout;
use Pandoc\AST\Strong;
use Pandoc\AST\Subscript;
use Pandoc\AST\Superscript;
use Pandoc\AST\Table;
use Pandoc\AST\TableBody;
use Pandoc\AST\TableFoot;
use Pandoc\AST\TableHead;
use Pandoc\AST\Target;
use Pandoc\AST\Underline;

/**
 * Reader for "vanilla" LaTeX (article/report/book without exotic packages).
 *
 * This is not a TeX engine: it parses the common document structure and text
 * markup directly, expands simple \newcommand/\def macros, and ignores what it
 * does not understand. Math is kept as raw TeX.
 *
 * Meta values: title/date are Inline[], author is Inline[][], lang is a string,
 * cover-image is the image URL as registered in the MediaBag (set by \cover{file}).
 *
 * \cover{file} is a non-standard command; authors who also compile with LaTeX can
 * add \providecommand{\cover}[1]{} to their preamble.
 */
class LatexReader implements ReaderInterface
{
    use HasMediaBag;

    private const SECTION_COMMANDS = ['part', 'chapter', 'section', 'subsection', 'subsubsection', 'paragraph', 'subparagraph'];

    /** Commands skipped at block level, with their number of mandatory arguments. */
    private const IGNORED_BLOCK_COMMANDS = [
        'maketitle' => 0, 'tableofcontents' => 0, 'listoffigures' => 0, 'listoftables' => 0,
        'newpage' => 0, 'clearpage' => 0, 'cleardoublepage' => 0, 'pagebreak' => 0, 'appendix' => 0,
        'frontmatter' => 0, 'mainmatter' => 0, 'backmatter' => 0, 'par' => 0, 'noindent' => 0,
        'centering' => 0, 'raggedright' => 0, 'raggedleft' => 0, 'bigskip' => 0, 'medskip' => 0,
        'smallskip' => 0, 'vfill' => 0, 'hfill' => 0, 'vspace' => 1, 'pagestyle' => 1,
        'thispagestyle' => 1, 'setcounter' => 2, 'addtocounter' => 2, 'setlength' => 2,
        'bibliographystyle' => 1, 'bibliography' => 1, 'label' => 1, 'printbibliography' => 0,
        'rowcolor' => 1, 'cellcolor' => 1, 'color' => 1, 'hypersetup' => 1, 'newgeometry' => 1, 'pagenumbering' => 1, 'index' => 1, 'printindex' => 0,
    ];

    private const SYMBOLS = [
        'LaTeX' => 'LaTeX', 'TeX' => 'TeX', 'ldots' => '…', 'dots' => '…', 'textellipsis' => '…',
        'S' => '§', 'P' => '¶', 'dag' => '†', 'ddag' => '‡', 'copyright' => '©', 'textcopyright' => '©',
        'textregistered' => '®', 'texttrademark' => '™', 'pounds' => '£', 'euro' => '€', 'ss' => 'ß',
        'ae' => 'æ', 'AE' => 'Æ', 'oe' => 'œ', 'OE' => 'Œ', 'o' => 'ø', 'O' => 'Ø', 'aa' => 'å', 'AA' => 'Å',
        'l' => 'ł', 'L' => 'Ł', 'i' => 'ı', 'j' => 'ȷ', 'textbullet' => '•', 'textendash' => '–',
        'textemdash' => '—', 'textquoteleft' => '‘', 'textquoteright' => '’', 'textquotedblleft' => '“',
        'textquotedblright' => '”', 'guillemotleft' => '«', 'guillemotright' => '»', 'og' => '«',
        'fg' => '»', 'textbackslash' => '\\', 'textasciitilde' => '~', 'textasciicircum' => '^',
        'textbar' => '|', 'textless' => '<', 'textgreater' => '>', 'textdegree' => '°', 'textunderscore' => '_',
        'textbraceleft' => '{', 'textbraceright' => '}', 'textdollar' => '$', 'textpercent' => '%',
        'textampersand' => '&', 'textnumero' => '№', 'textperthousand' => '‰', 'textmu' => 'µ',
        'quad' => ' ', 'qquad' => ' ', 'enspace' => ' ',
    ];

    private const TRANSPARENT_COMMANDS = [
        'textrm', 'textsf', 'textnormal', 'textmd', 'textup', 'mbox', 'hbox', 'text', 'textlf',
        'texorpdfstring', 'mathrm_text', 'nolinkurl', 'foreignlanguage', 'acr', 'gls',
    ];

    /** Inline commands with arguments that are dropped entirely. */
    private const IGNORED_INLINE_COMMANDS = [
        'vspace' => 1, 'hspace' => 1, 'index' => 1, 'thanks' => 1, 'footnotemark' => 0, 'setlength' => 2,
        'setcounter' => 2, 'pagestyle' => 1, 'thispagestyle' => 1, 'phantom' => 1, 'vphantom' => 1,
        'hphantom' => 1, 'noindent' => 0, 'par' => 0, 'centering' => 0, 'raggedright' => 0, 'raggedleft' => 0,
        'hfill' => 0, 'vfill' => 0, 'bigskip' => 0, 'medskip' => 0, 'smallskip' => 0, 'newpage' => 0,
        'clearpage' => 0, 'relax' => 0, 'protect' => 0, 'indent' => 0, 'footnotetext' => 1, 'item' => 0,
        'hline' => 0, 'maketitle' => 0, 'tableofcontents' => 0, 'bibliographystyle' => 1, 'bibliography' => 1,
        'cellcolor' => 1, 'rowcolor' => 1, 'columncolor' => 1, 'arrayrulecolor' => 1,
        'color' => 1, 'hypersetup' => 1, 'pagecolor' => 1,
        'pagebreak' => 0, 'linebreak' => 0, 'nopagebreak' => 0, 'nobreak' => 0, 'allowbreak' => 0,
    ];

    private const ACCENTS = [
        "'" => "\u{0301}", '`' => "\u{0300}", '^' => "\u{0302}", '"' => "\u{0308}", '~' => "\u{0303}",
        '=' => "\u{0304}", '.' => "\u{0307}", 'c' => "\u{0327}", 'v' => "\u{030C}", 'u' => "\u{0306}",
        'H' => "\u{030B}", 'k' => "\u{0328}", 'r' => "\u{030A}", 'd' => "\u{0323}", 'b' => "\u{0331}",
    ];

    private const LANGUAGES = [
        'english' => 'en', 'american' => 'en-US', 'usenglish' => 'en-US', 'british' => 'en-GB',
        'ukenglish' => 'en-GB', 'french' => 'fr', 'francais' => 'fr', 'german' => 'de', 'ngerman' => 'de',
        'spanish' => 'es', 'italian' => 'it', 'portuguese' => 'pt', 'dutch' => 'nl', 'russian' => 'ru',
        'swedish' => 'sv', 'danish' => 'da', 'norwegian' => 'nb', 'norsk' => 'nb', 'finnish' => 'fi',
        'polish' => 'pl', 'czech' => 'cs', 'greek' => 'el', 'latin' => 'la', 'catalan' => 'ca',
    ];

    private const DECLARATIONS = 'bf|bfseries|it|itshape|em|sl|slshape|tt|ttfamily|sc|scshape|rm|rmfamily|sf|sffamily|'
        . 'normalfont|tiny|scriptsize|footnotesize|small|normalsize|large|Large|LARGE|huge|Huge|centering|raggedright|raggedleft';

    private const MIME_TYPES = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'svg' => 'image/svg+xml', 'webp' => 'image/webp',
    ];

    private string $baseDir = '';
    /** @var string[] */
    private array $graphicsPath = [''];
    /** @var array<string, array{0:int, 1:?string, 2:string}> */
    private array $macros = [];
    private array $meta = [];
    /** @var array<string, bool> */
    private array $usedIds = [];
    /** @var array<string, int> bibliography key => number */
    private array $bibKeys = [];
    private bool $hasChapter = false;
    private int $listDepth = 0;
    /** @var array<string, array{0:string, 1:string}> label => [kind, number] */
    private array $labels = [];
    /** @var string[] numbers of \part/\chapter/\section... in source order ('' when unnumbered) */
    private array $sectionNumbers = [];
    private int $sectionIdx = 0;
    /** @var array<string, int[]> */
    private array $floatNumbers = ['figure' => [], 'table' => [], 'algorithm' => []];
    private array $floatIdx = ['figure' => 0, 'table' => 0, 'algorithm' => 0];
    /** @var array<string, array{title:string, counter:string, within:?string, numbered:bool}> from \newtheorem */
    private array $theorems = [];
    /** @var string[] numbers of numbered theorem-like environments in source order */
    private array $thmNumbers = [];
    private int $thmIdx = 0;
    private int $subIdx = 0;
    private ?string $coverPath = null;

    public function __construct()
    {
        $this->initMediaBag();
    }

    /**
     * @param string $source LaTeX source, or the path of a .tex file
     */
    public function read(string $source): Pandoc
    {
        $this->initMediaBag();
        $this->macros = [];
        $this->meta = [];
        $this->usedIds = [];
        $this->bibKeys = [];
        $this->graphicsPath = [''];
        $this->baseDir = '';
        $this->listDepth = 0;
        $this->labels = [];
        $this->sectionNumbers = [];
        $this->sectionIdx = 0;
        $this->floatNumbers = ['figure' => [], 'table' => [], 'algorithm' => []];
        $this->floatIdx = ['figure' => 0, 'table' => 0, 'algorithm' => 0];
        $this->theorems = [];
        $this->thmNumbers = [];
        $this->thmIdx = 0;
        $this->subIdx = 0;
        $this->coverPath = null;

        if (!str_contains($source, "\n") && strlen($source) < 4096 && is_file($source)) {
            $this->baseDir = dirname($source);
            $source = (string) file_get_contents($source);
        }
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $source = $this->resolveInputs($source, 0);
        $source = $this->stripComments($source);
        $source = $this->extractMacros($source);
        $source = $this->expandMacros($source);

        [$preamble, $body] = $this->splitDocument($source);
        $this->readPreamble($preamble);
        $this->hasChapter = (bool) preg_match('/\\\\chapter\*?\s*[\[{]/', $body);
        if (preg_match_all('/\\\\bibitem\s*(?:\[[^\]]*\])?\s*\{([^}]*)\}/', $body, $m)) {
            foreach ($m[1] as $k => $key) {
                $this->bibKeys[trim($key)] = $k + 1;
            }
        }

        $this->numberDocument($body);
        $blocks = $this->parseBlocks($body);
        if ($this->coverPath !== null && $this->coverPath !== '') {
            $this->meta['cover-image'] = $this->registerImage($this->coverPath);
        }

        return new Pandoc(new Meta($this->meta), $blocks, $this->mediaBag);
    }

    // ------------------------------------------------------------------
    // Preprocessing
    // ------------------------------------------------------------------

    private function resolveInputs(string $s, int $depth): string
    {
        if ($depth > 5 || $this->baseDir === '') {
            return $s;
        }
        return preg_replace_callback('/\\\\(?:input|include)\s*\{([^}]+)\}/', function (array $m) use ($depth): string {
            foreach ([$m[1], $m[1] . '.tex'] as $candidate) {
                $path = $this->baseDir . '/' . $candidate;
                if (is_file($path)) {
                    $content = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($path));
                    return $this->resolveInputs($content, $depth + 1);
                }
            }
            return '';
        }, $s) ?? $s;
    }

    private function stripComments(string $s): string
    {
        $verb = 'verbatim\*?|lstlisting|minted';
        $parts = preg_split('/(\\\\begin\{(?:' . $verb . ')\}.*?\\\\end\{(?:' . $verb . ')\})/s', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $k => $part) {
            if ($k % 2 === 0) {
                $parts[$k] = preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)%[^\n]*(?:\n[ \t]*)?/', '$1', $part);
            }
        }
        return implode('', $parts);
    }

    private function extractMacros(string $s): string
    {
        $patterns = [
            '/\\\\(?:re|provide)?newcommand\*?\s*\{?\\\\([a-zA-Z@]+)\}?|\\\\DeclareRobustCommand\*?\s*\{?\\\\([a-zA-Z@]+)\}?/',
            '/\\\\def\s*\\\\([a-zA-Z@]+)((?:#\d)*)\s*(?=\{)/',
        ];
        foreach ($patterns as $pi => $re) {
            $pos = 0;
            while (preg_match($re, $s, $m, PREG_OFFSET_CAPTURE, $pos)) {
                $start = $m[0][1];
                $name = $m[1][0] !== '' ? $m[1][0] : ($m[2][0] ?? '');
                $j = $start + strlen($m[0][0]);
                $nargs = 0;
                $default = null;
                if ($pi === 0) {
                    $j = $this->skipWs($s, $j);
                    if (($opt = $this->readOptional($s, $j)) !== null) {
                        $nargs = (int) $opt[0];
                        $j = $this->skipWs($s, $opt[1]);
                        if (($opt = $this->readOptional($s, $j)) !== null) {
                            $default = $opt[0];
                            $j = $opt[1];
                        }
                    }
                } else {
                    $nargs = substr_count($m[2][0], '#');
                }
                $j = $this->skipWs($s, $j);
                if ($j >= strlen($s) || $s[$j] !== '{') {
                    $pos = $j;
                    continue;
                }
                [$body, $end] = $this->readBraced($s, $j);
                if (!in_array($name, ['title', 'author', 'date', 'cover'], true)) {
                    $this->macros[$name] = [$nargs, $default, $body];
                }
                $s = substr($s, 0, $start) . substr($s, $end);
                $pos = $start;
            }
        }
        return $s;
    }

    private function expandMacros(string $s): string
    {
        if (!$this->macros) {
            return $s;
        }
        $re = '/\\\\(' . implode('|', array_map(fn($n) => preg_quote($n, '/'), array_keys($this->macros))) . ')(?![a-zA-Z@])/';
        for ($pass = 0; $pass < 10; $pass++) {
            $changed = false;
            $out = '';
            $pos = 0;
            while (preg_match($re, $s, $m, PREG_OFFSET_CAPTURE, $pos)) {
                $start = $m[0][1];
                [$nargs, $default, $body] = $this->macros[$m[1][0]];
                $j = $start + strlen($m[0][0]);
                $args = [];
                for ($k = 1; $k <= $nargs; $k++) {
                    if ($k === 1 && $default !== null) {
                        $opt = $this->readOptional($s, $j);
                        $args[$k] = $opt !== null ? $opt[0] : $default;
                        $j = $opt !== null ? $opt[1] : $j;
                        continue;
                    }
                    $args[$k] = $this->readArg($s, $j) ?? '';
                }
                $rep = preg_replace_callback('/#(\d)/', fn($mm) => $args[(int) $mm[1]] ?? '', $body);
                $out .= substr($s, $pos, $start - $pos) . $rep;
                $pos = $j;
                $changed = true;
            }
            $s = $out . substr($s, $pos);
            if (!$changed) {
                break;
            }
        }
        return $s;
    }

    /** @return array{0:string, 1:string} preamble and body */
    private function splitDocument(string $s): array
    {
        if (preg_match('/\\\\begin\{document\}(.*)\\\\end\{document\}/s', $s, $m, PREG_OFFSET_CAPTURE)) {
            return [substr($s, 0, $m[0][1]), $m[1][0]];
        }
        if (preg_match('/\\\\begin\{document\}(.*)$/s', $s, $m, PREG_OFFSET_CAPTURE)) {
            return [substr($s, 0, $m[0][1]), $m[1][0]];
        }
        return ['', $s];
    }

    private function readPreamble(string $preamble): void
    {
        $this->readMetaCommands($preamble);

        if (preg_match_all('/\\\\usepackage\s*\[([^\]]*)\]\s*\{[^}]*(?:babel|polyglossia)[^}]*\}/', $preamble, $m)) {
            foreach ($m[1] as $opts) {
                foreach (array_reverse(array_map('trim', explode(',', $opts))) as $o) {
                    if (isset(self::LANGUAGES[strtolower($o)])) {
                        $this->meta['lang'] = self::LANGUAGES[strtolower($o)];
                        break 2;
                    }
                }
            }
        }
        if (preg_match('/\\\\set(?:main|default)language\s*(?:\[[^\]]*\])?\s*\{([^}]+)\}/', $preamble, $m)
            && isset(self::LANGUAGES[strtolower(trim($m[1]))])) {
            $this->meta['lang'] = self::LANGUAGES[strtolower(trim($m[1]))];
        }
        if (preg_match_all('/\\\\newtheorem(\*?)\s*\{([^}]*)\}\s*(?:\[([^\]]*)\])?\s*\{([^}]*)\}\s*(?:\[([^\]]*)\])?/', $preamble, $tm, PREG_SET_ORDER)) {
            foreach ($tm as $t) {
                $this->theorems[trim($t[2])] = [
                    'title' => trim($t[4]),
                    'counter' => ($t[3] ?? '') !== '' ? trim($t[3]) : trim($t[2]),
                    'within' => ($t[5] ?? '') !== '' ? trim($t[5]) : null,
                    'numbered' => $t[1] !== '*',
                ];
            }
        }
        if (preg_match('/\\\\graphicspath\s*\{((?:\s*\{[^}]*\})+)\s*\}/', $preamble, $m)
            && preg_match_all('/\{([^}]*)\}/', $m[1], $paths)) {
            $this->graphicsPath = array_merge([''], $paths[1]);
        }
    }

    private function readMetaCommands(string $s): void
    {
        $pos = 0;
        while (preg_match('/\\\\(title|author|date|cover)\s*(?:\[[^\]]*\])?\s*(?=\{)/', $s, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $j = $m[0][1] + strlen($m[0][0]);
            [$content, $end] = $this->readBraced($s, $j);
            $this->setMeta($m[1][0], $content);
            $pos = $end;
        }
    }

    private function setMeta(string $key, string $content): void
    {
        if ($key === 'cover') {
            $this->coverPath = $this->cleanUrl($content);
            return;
        }
        if ($key === 'author') {
            $authors = [];
            foreach (preg_split('/\\\\and(?![a-zA-Z])/', $content) as $a) {
                $inl = $this->parseInlines($a);
                if ($inl) {
                    $authors[] = $inl;
                }
            }
            $this->meta['author'] = $authors;
            return;
        }
        $this->meta[$key] = $this->parseInlines($content);
    }

    // ------------------------------------------------------------------
    // Low-level scanning helpers
    // ------------------------------------------------------------------

    private function skipWs(string $s, int $i): int
    {
        $n = strlen($s);
        while ($i < $n && ctype_space($s[$i])) {
            $i++;
        }
        return $i;
    }

    /** @return array{0:string, 1:int} content and index after the closing brace; $s[$i] must be '{' */
    private function readBraced(string $s, int $i): array
    {
        $n = strlen($s);
        $depth = 0;
        $start = $i + 1;
        for ($j = $i; $j < $n; $j++) {
            $c = $s[$j];
            if ($c === '\\') {
                $j++;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return [substr($s, $start, $j - $start), $j + 1];
                }
            }
        }
        return [substr($s, $start), $n];
    }

    /** @return array{0:string, 1:int}|null */
    private function readOptional(string $s, int $i): ?array
    {
        $n = strlen($s);
        if ($i >= $n || $s[$i] !== '[') {
            return null;
        }
        $depth = 0;
        for ($j = $i + 1; $j < $n; $j++) {
            $c = $s[$j];
            if ($c === '\\') {
                $j++;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
            } elseif ($c === ']' && $depth <= 0) {
                return [substr($s, $i + 1, $j - $i - 1), $j + 1];
            }
        }
        return null;
    }

    /** Reads a mandatory argument: a braced group, a control sequence, or a single character. */
    private function readArg(string $s, int &$i): ?string
    {
        $i = $this->skipWs($s, $i);
        if ($i >= strlen($s)) {
            return null;
        }
        if ($s[$i] === '{') {
            [$content, $i] = $this->readBraced($s, $i);
            return $content;
        }
        if ($s[$i] === '\\' && preg_match('/\G\\\\(?:[a-zA-Z]+|.)/s', $s, $m, 0, $i)) {
            $i += strlen($m[0]);
            return $m[0];
        }
        return $s[$i++];
    }

    private function skipOptionalAndArgs(string $s, int &$i, int $nargs): void
    {
        while (($opt = $this->readOptional($s, $i)) !== null) {
            $i = $opt[1];
        }
        for ($k = 0; $k < $nargs; $k++) {
            $this->readArg($s, $i);
        }
    }

    /** Splits at top-level occurrences of '&' or '\\' (row break), ignoring braces and nested environments. */
    private function splitTop(string $s, string $delim): array
    {
        $parts = [];
        $buf = '';
        $depth = 0;
        $env = 0;
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if ($c === '\\' && $i + 1 < $n) {
                $nx = $s[$i + 1];
                if ($nx === '\\') {
                    if ($delim === '\\\\' && $depth === 0 && $env === 0) {
                        $parts[] = $buf;
                        $buf = '';
                        $i += 2;
                        if ($i < $n && $s[$i] === '*') {
                            $i++;
                        }
                        if (($opt = $this->readOptional($s, $i)) !== null) {
                            $i = $opt[1];
                        }
                        $i--;
                        continue;
                    }
                    $buf .= '\\\\';
                    $i++;
                    continue;
                }
                if (ctype_alpha($nx) && preg_match('/\G\\\\(begin|end)\{/', $s, $m, 0, $i)) {
                    $env += $m[1] === 'begin' ? 1 : -1;
                }
                $buf .= $c . $nx;
                $i++;
                continue;
            }
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
            } elseif ($c === '&' && $delim === '&' && $depth === 0 && $env === 0) {
                $parts[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        $parts[] = $buf;
        return $parts;
    }

    // ------------------------------------------------------------------
    // Numbering (sections, floats, equations) so that \ref can show real numbers
    // ------------------------------------------------------------------

    private function numberDocument(string $body): void
    {
        $re = '/\\\\(appendix|part|chapter|section|subsection|subsubsection|paragraph|subparagraph|caption)(\*?)(?![a-zA-Z])'
            . '|\\\\begin\{([^}]*)\}|\\\\end\{([^}]*)\}|\\\\label\s*\{([^}]*)\}'
            . '|\\\\(nonumber|notag)(?![a-zA-Z])|\\\\\\\\/';
        if (!preg_match_all($re, $body, $ms, PREG_SET_ORDER)) {
            return;
        }
        $levels = $this->hasChapter
            ? ['chapter' => 0, 'section' => 1, 'subsection' => 2, 'subsubsection' => 3]
            : ['section' => 0, 'subsection' => 1, 'subsubsection' => 2];
        $counters = [0, 0, 0, 0];
        $appendix = false;
        $count = ['figure' => 0, 'table' => 0, 'algorithm' => 0];
        $eq = 0;
        $secNumber = '';
        $secKind = 'section';
        $floatKind = null;
        $floatNum = null;
        $pendingLabels = [];
        $sub = 0;
        $inSub = false;
        $eqEnv = null;
        $eqNum = null;
        $eqEnvs = ['equation', 'align', 'alignat', 'flalign', 'gather', 'multline', 'eqnarray'];
        $thmCounts = [];
        $thmEnv = null;
        $thmCur = null;
        $withinLevel = function (string $key) use ($levels): ?int {
            $w = $this->theorems[$key]['within'] ?? null;
            return $w !== null ? ($levels[$w] ?? null) : null;
        };
        $format = function (int $level) use (&$counters, &$appendix): string {
            $parts = array_slice($counters, 0, $level + 1);
            if ($appendix) {
                $parts[0] = chr(64 + max(1, min(26, $parts[0])));
            }
            return implode('.', $parts);
        };

        foreach ($ms as $m) {
            $cmd = $m[1] ?? '';
            if ($cmd !== '') {
                if ($cmd === 'appendix') {
                    $appendix = true;
                    $counters = [0, 0, 0, 0];
                } elseif ($cmd === 'caption') {
                    if ($floatKind !== null && $floatNum === null && !$inSub) {
                        $floatNum = ++$count[$floatKind];
                        $this->floatNumbers[$floatKind][] = $floatNum;
                        foreach ($pendingLabels as [$key, $suffix]) {
                            $this->labels[$key] = [$floatKind, $floatNum . $suffix];
                        }
                        $pendingLabels = [];
                    }
                } elseif (!isset($levels[$cmd]) || ($m[2] ?? '') === '*') {
                    $this->sectionNumbers[] = '';
                    if ($cmd !== 'part') {
                        $secNumber = '';
                    }
                } else {
                    $level = $levels[$cmd];
                    $counters[$level]++;
                    for ($k = $level + 1; $k < 4; $k++) {
                        $counters[$k] = 0;
                    }
                    foreach (array_keys($thmCounts) as $key) {
                        $w = $withinLevel($key);
                        if ($w !== null && $w >= $level) {
                            unset($thmCounts[$key]);
                        }
                    }
                    $secNumber = $format($level);
                    $secKind = $appendix && $level === 0 ? 'appendix' : 'section';
                    $this->sectionNumbers[] = $secNumber;
                }
                continue;
            }
            $begin = $m[3] ?? '';
            $end = $m[4] ?? '';
            if ($begin !== '') {
                $name = rtrim($begin, '*');
                if (in_array($name, ['figure', 'sidewaysfigure', 'wrapfigure'], true)) {
                    [$floatKind, $floatNum, $pendingLabels, $sub] = ['figure', null, [], 0];
                } elseif (in_array($name, ['table', 'sidewaystable'], true)) {
                    [$floatKind, $floatNum, $pendingLabels, $sub] = ['table', null, [], 0];
                } elseif (in_array($name, ['subfigure', 'subtable'], true)) {
                    $sub++;
                    $inSub = true;
                } elseif ($name === 'algorithm') {
                    [$floatKind, $floatNum, $pendingLabels] = ['algorithm', null, []];
                } elseif (isset($this->theorems[$name])) {
                    $def = $this->theorems[$name];
                    if ($def['numbered'] && !str_ends_with($begin, '*')) {
                        $key = $def['counter'];
                        $thmCounts[$key] = ($thmCounts[$key] ?? 0) + 1;
                        $w = $withinLevel($key);
                        $number = ($w !== null ? $format($w) . '.' : '') . $thmCounts[$key];
                        $this->thmNumbers[] = $number;
                        $thmCur = ['thm:' . $def['title'], $number];
                    }
                    $thmEnv = $name;
                } elseif (in_array($name, $eqEnvs, true) && !str_ends_with($begin, '*')) {
                    $eqEnv = $name;
                    $eqNum = ++$eq;
                }
            } elseif ($end !== '') {
                $name = rtrim($end, '*');
                if (in_array($name, ['figure', 'sidewaysfigure', 'wrapfigure', 'table', 'sidewaystable', 'algorithm'], true)) {
                    $floatKind = null;
                    $floatNum = null;
                    $pendingLabels = [];
                } elseif (in_array($name, ['subfigure', 'subtable'], true)) {
                    $inSub = false;
                } elseif ($name === $eqEnv) {
                    $eqEnv = null;
                } elseif ($name === $thmEnv) {
                    $thmEnv = null;
                    $thmCur = null;
                }
            } elseif (($m[5] ?? '') !== '') {
                $key = trim($m[5]);
                $suffix = $inSub ? chr(96 + min(26, $sub)) : '';
                if ($floatKind !== null && $floatNum !== null) {
                    $this->labels[$key] = [$floatKind, $floatNum . $suffix];
                } elseif ($floatKind !== null) {
                    $pendingLabels[] = [$key, $suffix];
                } elseif ($eqEnv !== null) {
                    if ($eqNum !== null) {
                        $this->labels[$key] = ['equation', (string) $eqNum];
                    }
                } elseif ($thmCur !== null) {
                    $this->labels[$key] = $thmCur;
                } elseif ($secNumber !== '') {
                    $this->labels[$key] = [$secKind, $secNumber];
                }
            } elseif (($m[6] ?? '') !== '') {
                if ($eqEnv !== null && $eqNum !== null) {
                    $eq--;
                    $eqNum = null;
                }
            } elseif ($eqEnv !== null && $eqEnv !== 'equation' && $eqEnv !== 'multline') {
                $eqNum = ++$eq;
            }
        }
    }

    private function refText(string $name, string $label): string
    {
        [$kind, $num] = $this->labels[$label] ?? [null, null];
        if ($num === null) {
            return "[$label]";
        }
        $withKind = in_array($name, ['autoref', 'cref', 'Cref', 'vref'], true);
        if ($name === 'eqref') {
            return "($num)";
        }
        if ($kind === 'equation' && $withKind) {
            return "Equation ($num)";
        }
        if (!$withKind) {
            return $num;
        }
        return (str_starts_with($kind, 'thm:') ? substr($kind, 4) : ucfirst($kind)) . ' ' . $num;
    }

    // ------------------------------------------------------------------
    // Block parsing
    // ------------------------------------------------------------------

    /** @return Block[] */
    private function parseBlocks(string $s): array
    {
        $blocks = [];
        $n = strlen($s);
        $i = 0;
        while (true) {
            $i = $this->skipWs($s, $i);
            if ($i >= $n) {
                break;
            }
            if (substr_compare($s, '\\begin{', $i, 7) === 0) {
                $i = $this->parseEnvironment($s, $i, $blocks);
                continue;
            }
            if (substr_compare($s, '\\end{', $i, 5) === 0) {
                $i = strpos($s, '}', $i) !== false ? strpos($s, '}', $i) + 1 : $n;
                continue;
            }
            if (preg_match('/\G\\\\(' . implode('|', self::SECTION_COMMANDS) . ')(\*?)(?![a-zA-Z])/', $s, $m, 0, $i)) {
                $i += strlen($m[0]);
                $blocks[] = $this->parseHeader($m[1], $m[2] === '*', $s, $i);
                continue;
            }
            if (preg_match('/\G\\\\([a-zA-Z]+)\*?/', $s, $m, 0, $i)) {
                $name = $m[1];
                if (in_array($name, ['title', 'author', 'date', 'cover'], true)) {
                    $i += strlen($m[0]);
                    $this->skipOptionalAndArgs($s, $i, 0);
                    $content = $this->readArg($s, $i) ?? '';
                    $this->setMeta($name, $content);
                    continue;
                }
                if (isset(self::IGNORED_BLOCK_COMMANDS[$name])) {
                    $i += strlen($m[0]);
                    $this->skipOptionalAndArgs($s, $i, self::IGNORED_BLOCK_COMMANDS[$name]);
                    continue;
                }
                if (in_array($name, ['hrule', 'hrulefill'], true)) {
                    $i += strlen($m[0]);
                    $blocks[] = new \Pandoc\AST\HorizontalRule();
                    continue;
                }
            }
            $end = $this->scanParagraph($s, $i);
            if ($end <= $i) {
                $end = $i + 1;
            }
            $inlines = $this->parseInlines(substr($s, $i, $end - $i));
            if ($inlines) {
                $blocks[] = new Para($inlines);
            }
            $i = $end;
        }
        return $blocks;
    }

    /** Returns the offset where the paragraph starting at $i ends. */
    private function scanParagraph(string $s, int $i): int
    {
        $n = strlen($s);
        $depth = 0;
        $stop = array_merge(self::SECTION_COMMANDS, ['begin', 'end', 'maketitle', 'tableofcontents']);
        while ($i < $n) {
            $c = $s[$i];
            if ($c === "\n" && preg_match('/\G\n[ \t]*\n/', $s, $m, 0, $i)) {
                return $i;
            }
            if ($c === '\\') {
                if ($i + 1 >= $n) {
                    return $n;
                }
                $nx = $s[$i + 1];
                if (ctype_alpha($nx)) {
                    preg_match('/\G\\\\([a-zA-Z]+)/', $s, $m, 0, $i);
                    if ($depth === 0 && in_array($m[1], $stop, true)) {
                        return $i;
                    }
                    $i += strlen($m[0]);
                    if ($m[1] === 'verb' && $i < $n) {
                        $k = $i + ($s[$i] === '*' ? 1 : 0);
                        $delim = $s[$k] ?? '';
                        $close = $delim !== '' ? strpos($s, $delim, $k + 1) : false;
                        $i = $close !== false ? $close + 1 : $n;
                    }
                    continue;
                }
                if ($nx === '[') {
                    $close = strpos($s, '\\]', $i + 2);
                    $i = $close !== false ? $close + 2 : $n;
                    continue;
                }
                $i += 2;
                continue;
            }
            if ($c === '$') {
                if (preg_match('/\G\$\$.*?\$\$/s', $s, $m, 0, $i) || preg_match('/\G\$(?:[^$\\\\]|\\\\.)*\$/s', $s, $m, 0, $i)) {
                    $i += strlen($m[0]);
                    continue;
                }
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth = max(0, $depth - 1);
            }
            $i++;
        }
        return $n;
    }

    private function parseHeader(string $cmd, bool $starred, string $s, int &$i): Header
    {
        if (($opt = $this->readOptional($this->skipWsStr($s, $i), 0)) !== null) {
            $i = $this->skipWs($s, $i) + $opt[1];
        }
        $title = $this->readArg($s, $i) ?? '';
        $label = null;
        if (preg_match('/\\\\label\s*\{([^}]*)\}/', $title, $m)) {
            $label = trim($m[1]);
            $title = str_replace($m[0], '', $title);
        }
        $j = $this->skipWs($s, $i);
        if (preg_match('/\G\\\\label\s*\{([^}]*)\}/', $s, $m, 0, $j)) {
            $label ??= trim($m[1]);
            $i = $j + strlen($m[0]);
        }
        $inlines = $this->parseInlines($title);

        $levels = $this->hasChapter
            ? ['part' => 1, 'chapter' => 1, 'section' => 2, 'subsection' => 3, 'subsubsection' => 4, 'paragraph' => 5, 'subparagraph' => 6]
            : ['part' => 1, 'section' => 1, 'subsection' => 2, 'subsubsection' => 3, 'paragraph' => 4, 'subparagraph' => 5, 'chapter' => 1];
        $id = $label !== null && $label !== '' ? $label : $this->autoId($inlines);
        $this->usedIds[$id] = true;

        $number = $this->sectionNumbers[$this->sectionIdx++] ?? '';

        return new Header(
            min(6, $levels[$cmd]),
            new Attr($id, $starred ? ['unnumbered'] : [], $number !== '' ? [['number', $number]] : []),
            $inlines
        );
    }

    private function skipWsStr(string $s, int $i): string
    {
        return substr($s, $this->skipWs($s, $i));
    }

    private function autoId(array $inlines): string
    {
        $text = mb_strtolower($this->stringify($inlines));
        $text = preg_replace('/[^\p{L}\p{N}_\-.\s]/u', '', $text);
        $text = preg_replace('/^[^\p{L}]+/u', '', $text);
        $text = trim(preg_replace('/\s+/u', '-', trim($text)), '-');
        $base = $text !== '' ? $text : 'section';
        $id = $base;
        for ($k = 1; isset($this->usedIds[$id]); $k++) {
            $id = $base . '-' . $k;
        }
        return $id;
    }

    /** Parses a \begin{env}...\end{env} starting at $i, appends resulting blocks, and returns the next offset. */
    private function parseEnvironment(string $s, int $i, array &$blocks): int
    {
        preg_match('/\G\\\\begin\{([^}]*)\}/', $s, $m, 0, $i);
        $env = $m[1];
        $start = $i + strlen($m[0]);
        [$bodyEnd, $after] = $this->findEnd($s, $env, $start);
        $body = substr($s, $start, $bodyEnd - $start);
        foreach ($this->environment($env, $body) as $b) {
            $blocks[] = $b;
        }
        return $after;
    }

    /** @return array{0:int, 1:int} offset of \end{env} and offset just after it */
    private function findEnd(string $s, string $env, int $from): array
    {
        $q = preg_quote($env, '/');
        $depth = 1;
        $pos = $from;
        while (preg_match('/\\\\(begin|end)\{' . $q . '\}/', $s, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $depth += $m[1][0] === 'begin' ? 1 : -1;
            $pos = $m[0][1] + strlen($m[0][0]);
            if ($depth === 0) {
                return [$m[0][1], $pos];
            }
        }
        return [strlen($s), strlen($s)];
    }

    /** @return Block[] */
    private function environment(string $env, string $body): array
    {
        $name = rtrim($env, '*');
        switch ($name) {
            case 'itemize':
                $this->listDepth++;
                $items = $this->parseItems($body);
                $this->listDepth--;
                return [new BulletList($items)];
            case 'enumerate':
                return [$this->parseEnumerate($body)];
            case 'description':
                return [$this->parseDescription($body)];
            case 'quote':
            case 'quotation':
            case 'verse':
                return [new BlockQuote($this->parseBlocks($body))];
            case 'center':
            case 'flushleft':
            case 'flushright':
            case 'sloppypar':
            case 'spacing':
            case 'singlespace':
            case 'doublespace':
            case 'small':
            case 'footnotesize':
            case 'large':
                return $this->parseBlocks($body);
            case 'minipage':
                $j = 0;
                $this->skipOptionalAndArgs($body, $j, 1);
                return $this->parseBlocks(substr($body, $j));
            case 'multicols':
                $j = 0;
                $this->readArg($body, $j);
                return $this->parseBlocks(substr($body, $j));
            case 'verbatim':
                return [new CodeBlock(new Attr(), preg_replace('/^\n|\n$/', '', $body))];
            case 'lstlisting':
                return [$this->parseListing($body)];
            case 'minted':
                return [$this->parseMinted($body)];
            case 'equation':
            case 'displaymath':
            case 'math':
            case 'eqnarray':
            case 'align':
            case 'alignat':
            case 'flalign':
            case 'gather':
            case 'multline':
            case 'split':
                return [$this->parseMathEnvironment($name, $body)];
            case 'table':
            case 'sidewaystable':
            case 'figure':
            case 'sidewaysfigure':
            case 'wrapfigure':
                return $this->parseFloat($name, $body);
            case 'tabular':
            case 'tabularx':
            case 'tabular*':
            case 'longtable':
            case 'supertabular':
                return [$this->parseTable($name, $body, null, null)];
            case 'subfigure':
            case 'subtable':
                return [$this->parseSubfigure($body)];
            case 'algorithm':
                return [$this->parseAlgorithmFloat($body)];
            case 'algorithmic':
                return [$this->parseAlgorithmic($body)];
            case 'thebibliography':
                return [$this->parseBibliography($body)];
            default:
                return [$this->parseTheoremLike($name, str_ends_with($env, '*'), $body)];
        }
    }

    /** Theorem-like and unknown environments: a Div with a run-in heading ("Theorem 2.1 (Name).") when known. */
    private function parseTheoremLike(string $name, bool $starred, string $body): Div
    {
        $def = $this->theorems[$name] ?? null;
        $title = $def['title'] ?? ($name === 'proof' ? 'Proof' : null);
        $number = null;
        if ($def !== null && $def['numbered'] && !$starred) {
            $number = $this->thmNumbers[$this->thmIdx++] ?? null;
        }
        $optName = null;
        if ($def !== null && ($opt = $this->readOptional($body, $this->skipWs($body, 0))) !== null) {
            $optName = $opt[0];
            $body = substr($body, $opt[1]);
        }
        $label = '';
        if (preg_match('/^\s*\\\\label\s*\{([^}]*)\}/', $body, $m)) {
            $label = trim($m[1]);
            $body = substr($body, strlen($m[0]));
        }
        $blocks = $this->parseBlocks($body);
        if ($title !== null) {
            $head = [new Str($title . ($number !== null ? ' ' . $number : ''))];
            if ($optName !== null) {
                array_push($head, new Space(), new Str('('), ...$this->parseInlines($optName), ...[new Str(')')]);
            }
            $head[] = new Str('.');
            $lead = [new Strong($head), new Space()];
            if ($blocks && $blocks[0] instanceof Para) {
                $blocks[0] = new Para(array_merge($lead, $blocks[0]->content));
            } else {
                array_unshift($blocks, new Para($lead));
            }
        }
        return new Div(new Attr($label, [$name], []), $blocks);
    }

    private function parseAlgorithmFloat(string $body): Div
    {
        if (($opt = $this->readOptional($body, $this->skipWs($body, 0))) !== null) {
            $body = substr($body, $opt[1]);
        }
        $caption = $this->extractCaption($body);
        $label = $this->extractLabel($body) ?? '';
        $blocks = $this->parseBlocks($body);
        if ($caption !== null && trim($caption) !== '') {
            $number = $this->floatNumbers['algorithm'][$this->floatIdx['algorithm']++] ?? null;
            $head = array_merge(
                [new Str('Algorithm' . ($number !== null ? ' ' . $number : '') . ':'), new Space()],
                $this->parseInlines($caption)
            );
            array_unshift($blocks, new Para([new Strong($head)]));
        }
        return new Div(new Attr($label, ['algorithm'], []), $blocks);
    }

    /** Renders algorithmic / algpseudocode bodies as indented lines (Div.alg-line.alg-iN). */
    private function parseAlgorithmic(string $body): Div
    {
        $numbered = false;
        if (($opt = $this->readOptional($body, $this->skipWs($body, 0))) !== null) {
            $numbered = trim($opt[0]) !== '';
            $body = substr($body, $opt[1]);
        }
        $cmds = 'STATE|State|REQUIRE|Require|ENSURE|Ensure|RETURN|Return|PRINT|Print|IF|If|ELSIF|ElsIf|ELSE|Else|ENDIF|EndIf'
            . '|FOR|For|FORALL|ForAll|ENDFOR|EndFor|WHILE|While|ENDWHILE|EndWhile|REPEAT|Repeat|UNTIL|Until|LOOP|Loop|ENDLOOP|EndLoop'
            . '|FUNCTION|Function|PROCEDURE|Procedure|ENDFUNCTION|EndFunction|ENDPROCEDURE|EndProcedure|COMMENT|Comment|CALL|Call';
        $re = '/\\\\(' . $cmds . ')(?![a-zA-Z])/';
        $argCount = ['IF' => 1, 'ELSIF' => 1, 'FOR' => 1, 'FORALL' => 1, 'WHILE' => 1, 'UNTIL' => 1, 'COMMENT' => 1,
            'FUNCTION' => 2, 'PROCEDURE' => 2];
        $keywords = [
            'REQUIRE' => ['Require:', ''], 'ENSURE' => ['Ensure:', ''], 'RETURN' => ['return', ''], 'PRINT' => ['print', ''],
            'IF' => ['if', 'then'], 'ELSIF' => ['else if', 'then'], 'ELSE' => ['else', ''], 'ENDIF' => ['end if', ''],
            'FOR' => ['for', 'do'], 'FORALL' => ['for all', 'do'], 'ENDFOR' => ['end for', ''],
            'WHILE' => ['while', 'do'], 'ENDWHILE' => ['end while', ''], 'REPEAT' => ['repeat', ''], 'UNTIL' => ['until', ''],
            'LOOP' => ['loop', ''], 'ENDLOOP' => ['end loop', ''], 'FUNCTION' => ['function', ''], 'PROCEDURE' => ['procedure', ''],
            'ENDFUNCTION' => ['end function', ''], 'ENDPROCEDURE' => ['end procedure', ''], 'CALL' => ['', ''],
        ];

        $lines = [];
        $depth = 0;
        $n = strlen($body);
        $pos = 0;
        while ($pos < $n && preg_match($re, $body, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $cmd = strtoupper($m[1][0]);
            $j = $m[0][1] + strlen($m[0][0]);
            $args = [];
            for ($a = 0; $a < ($argCount[$cmd] ?? 0); $a++) {
                $k = $this->skipWs($body, $j);
                if (($body[$k] ?? '') === '{') {
                    [$arg, $j] = $this->readBraced($body, $k);
                    $args[] = $arg;
                }
            }
            $text = '';
            if (!isset($argCount[$cmd])) {
                $next = preg_match($re, $body, $nm, PREG_OFFSET_CAPTURE, $j) ? $nm[0][1] : $n;
                $text = substr($body, $j, $next - $j);
                $j = $next;
            }
            $pos = $j;

            if ($cmd === 'COMMENT') {
                if ($lines) {
                    $last = array_key_last($lines);
                    $lines[$last]['inlines'][] = new Space();
                    $lines[$last]['inlines'][] = new Str('▷');
                    $lines[$last]['inlines'][] = new Space();
                    array_push($lines[$last]['inlines'], ...$this->parseInlines($args[0] ?? ''));
                }
                continue;
            }
            if (in_array($cmd, ['ENDIF', 'ENDFOR', 'ENDWHILE', 'ENDLOOP', 'ENDFUNCTION', 'ENDPROCEDURE', 'UNTIL', 'ELSE', 'ELSIF'], true)) {
                $depth = max(0, $depth - 1);
            }
            $inlines = [];
            if ($cmd === 'STATE') {
                if (trim($text) === '') {
                    continue;
                }
                $inlines = $this->parseInlines($text);
            } else {
                [$kw, $tail] = $keywords[$cmd] ?? ['', ''];
                if ($kw !== '') {
                    $inlines[] = new Strong([new Str($kw)]);
                }
                $argText = $args ? ($cmd === 'FUNCTION' || $cmd === 'PROCEDURE'
                    ? trim($args[0]) . '(' . trim($args[1] ?? '') . ')'
                    : $args[0]) : $text;
                $arg = $this->parseInlines($argText);
                if ($arg) {
                    if ($inlines) {
                        $inlines[] = new Space();
                    }
                    array_push($inlines, ...$arg);
                }
                if ($tail !== '') {
                    $inlines[] = new Space();
                    $inlines[] = new Strong([new Str($tail)]);
                }
            }
            if ($inlines) {
                $lines[] = ['depth' => $depth, 'inlines' => $inlines];
            }
            if (in_array($cmd, ['IF', 'ELSIF', 'ELSE', 'FOR', 'FORALL', 'WHILE', 'REPEAT', 'LOOP', 'FUNCTION', 'PROCEDURE'], true)) {
                $depth++;
            }
        }

        $blocks = [];
        foreach ($lines as $k => $line) {
            $inlines = $line['inlines'];
            if ($numbered) {
                array_unshift($inlines, new Span(new Attr('', ['alg-num'], []), [new Str(($k + 1) . ':')]), new Space());
            }
            $blocks[] = new Div(new Attr('', ['alg-line', 'alg-i' . min($line['depth'], 8)], []), [new Plain($inlines)]);
        }
        return new Div(new Attr('', ['algorithmic'], []), $blocks);
    }

    private function tightenBlocks(array $blocks): array
    {
        return array_map(fn(Block $b) => $b instanceof Para ? new Plain($b->content) : $b, $blocks);
    }

    /** @return string[] raw text of each \item (text before the first \item is dropped) */
    private function splitItems(string $body, string $command = 'item'): array
    {
        preg_match_all('/\\\\(begin|end)\{[^}]*\}|\\\\' . $command . '(?![a-zA-Z])/', $body, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $depth = 0;
        $cuts = [];
        foreach ($ms as $m) {
            $tok = $m[0][0];
            if (str_starts_with($tok, '\\begin')) {
                $depth++;
            } elseif (str_starts_with($tok, '\\end')) {
                $depth--;
            } elseif ($depth === 0) {
                $cuts[] = [$m[0][1], $m[0][1] + strlen($tok)];
            }
        }
        $parts = [];
        foreach ($cuts as $k => [$from, $to]) {
            $next = $cuts[$k + 1][0] ?? strlen($body);
            $parts[] = substr($body, $to, $next - $to);
        }
        return $parts;
    }

    /** @return Block[][] */
    private function parseItems(string $body): array
    {
        $tight = !preg_match('/\n[ \t]*\n/', $body);
        $items = [];
        foreach ($this->splitItems($body) as $part) {
            $j = $this->skipWs($part, 0);
            if (($opt = $this->readOptional($part, $j)) !== null) {
                $part = substr($part, $opt[1]);
            }
            $blocks = $this->parseBlocks($part);
            $items[] = $tight ? $this->tightenBlocks($blocks) : $blocks;
        }
        return $items;
    }

    private function parseEnumerate(string $body): OrderedList
    {
        $defaults = [
            [ListNumberStyle::Decimal, ListNumberDelim::Period],
            [ListNumberStyle::LowerAlpha, ListNumberDelim::TwoParens],
            [ListNumberStyle::LowerRoman, ListNumberDelim::Period],
            [ListNumberStyle::UpperAlpha, ListNumberDelim::Period],
        ];
        [$style, $delim] = $defaults[min($this->listDepth, 3)];
        $j = $this->skipWs($body, 0);
        if (($opt = $this->readOptional($body, $j)) !== null) {
            $body = substr($body, $opt[1]);
            $label = preg_replace('/^label\s*=\s*/', '', trim($opt[0]));
            $kind = preg_match('/\\\\(arabic|alph|Alph|roman|Roman)/', $opt[0], $km) ? $km[1] : null;
            $kind ??= preg_match('/[aAiI1]/', $label, $km) ? $km[0] : null;
            $style = match ($kind) {
                'a', 'alph' => ListNumberStyle::LowerAlpha,
                'A', 'Alph' => ListNumberStyle::UpperAlpha,
                'i', 'roman' => ListNumberStyle::LowerRoman,
                'I', 'Roman' => ListNumberStyle::UpperRoman,
                '1', 'arabic' => ListNumberStyle::Decimal,
                default => $style,
            };
            if (str_contains($opt[0], '(')) {
                $delim = ListNumberDelim::TwoParens;
            } elseif (str_contains($opt[0], ')')) {
                $delim = ListNumberDelim::OneParen;
            } elseif (str_contains($opt[0], '.')) {
                $delim = ListNumberDelim::Period;
            }
        }
        $this->listDepth++;
        $items = $this->parseItems($body);
        $this->listDepth--;
        return new OrderedList(new ListAttributes(1, $style, $delim), $items);
    }

    private function parseDescription(string $body): DefinitionList
    {
        $tight = !preg_match('/\n[ \t]*\n/', $body);
        $items = [];
        $this->listDepth++;
        foreach ($this->splitItems($body) as $part) {
            $term = [];
            $j = $this->skipWs($part, 0);
            if (($opt = $this->readOptional($part, $j)) !== null) {
                $term = $this->parseInlines($opt[0]);
                $part = substr($part, $opt[1]);
            }
            $blocks = $this->parseBlocks($part);
            $items[] = [$term, [$tight ? $this->tightenBlocks($blocks) : $blocks]];
        }
        $this->listDepth--;
        return new DefinitionList($items);
    }

    private function parseListing(string $body): CodeBlock
    {
        $classes = [];
        $j = 0;
        if (($opt = $this->readOptional($body, 0)) !== null) {
            $j = $opt[1];
            if (preg_match('/language\s*=\s*\{?([A-Za-z0-9+#_-]+)/', $opt[0], $m)) {
                $classes[] = strtolower($m[1]);
            }
        }
        return new CodeBlock(new Attr('', $classes, []), preg_replace('/^\n|\n$/', '', substr($body, $j)));
    }

    private function parseMinted(string $body): CodeBlock
    {
        $j = 0;
        while (($opt = $this->readOptional($body, $j)) !== null) {
            $j = $opt[1];
        }
        $lang = $this->readArg($body, $j) ?? '';
        return new CodeBlock(new Attr('', $lang !== '' ? [$lang] : [], []), preg_replace('/^\n|\n$/', '', substr($body, $j)));
    }

    private function parseMathEnvironment(string $name, string $body): Para
    {
        $labels = [];
        $body = preg_replace_callback('/\\\\label\s*\{([^}]*)\}/', function (array $m) use (&$labels): string {
            $labels[] = trim($m[1]);
            return '';
        }, $body);
        $label = array_shift($labels);
        $text = trim($body);
        if ($name === 'alignat') {
            $j = 0;
            $this->readArg($text, $j);
            $text = trim(substr($text, $j));
        }
        $text = match ($name) {
            'align', 'alignat', 'flalign', 'eqnarray' => "\\begin{aligned}\n$text\n\\end{aligned}",
            'gather', 'multline' => "\\begin{gathered}\n$text\n\\end{gathered}",
            'split' => "\\begin{split}\n$text\n\\end{split}",
            default => $text,
        };
        if ($label !== null && $name === 'equation' && isset($this->labels[$label])) {
            $text .= ' \\qquad (' . $this->labels[$label][1] . ')';
        }
        $math = new Math($name === 'math' ? MathType::InlineMath : MathType::DisplayMath, $text);
        $inlines = array_map(fn(string $l) => new Span(new Attr($l), []), $labels);
        $inlines[] = $label !== null ? new Span(new Attr($label), [$math]) : $math;
        return new Para($inlines);
    }

    /** Removes and returns the first \caption that is not inside a subfigure/subtable/minipage. */
    private function extractCaption(string &$body): ?string
    {
        $re = '/\\\\(begin|end)\{(?:subfigure|subtable|minipage)\*?\}|\\\\caption\*?\s*(?:\[[^\]]*\])?\s*(?=\{)/';
        if (!preg_match_all($re, $body, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $depth = 0;
        foreach ($ms as $m) {
            $tok = $m[0][0];
            if (str_starts_with($tok, '\\begin')) {
                $depth++;
            } elseif (str_starts_with($tok, '\\end')) {
                $depth--;
            } elseif ($depth === 0) {
                $brace = $m[0][1] + strlen($tok);
                [$caption, $end] = $this->readBraced($body, $brace);
                $body = substr($body, 0, $m[0][1]) . substr($body, $end);
                return $caption;
            }
        }
        return null;
    }

    /** Removes and returns the first \label that is not inside a subfigure/subtable/minipage. */
    private function extractLabel(string &$body): ?string
    {
        if (!preg_match_all('/\\\\(begin|end)\{(?:subfigure|subtable|minipage)\*?\}|\\\\label\s*\{([^}]*)\}/', $body, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $depth = 0;
        foreach ($ms as $m) {
            $tok = $m[0][0];
            if (str_starts_with($tok, '\\begin')) {
                $depth++;
            } elseif (str_starts_with($tok, '\\end')) {
                $depth--;
            } elseif ($depth === 0) {
                $body = substr($body, 0, $m[0][1]) . substr($body, $m[0][1] + strlen($tok));
                return trim($m[2][0]);
            }
        }
        return null;
    }

    private function parseSubfigure(string $body): Figure
    {
        $j = $this->skipWs($body, 0);
        while (($opt = $this->readOptional($body, $j)) !== null) {
            $j = $this->skipWs($body, $opt[1]);
        }
        $attrs = [];
        if (($body[$j] ?? '') === '{') {
            [$declared, $j] = $this->readBraced($body, $j);
            if (trim($declared) !== '') {
                $attrs[] = ['width', $this->relativeLength($declared)];
            }
        }
        $body = substr($body, $j);
        $caption = $this->extractCaption($body);
        $label = $this->extractLabel($body);
        $letter = chr(96 + min(26, ++$this->subIdx));
        $blocks = $this->tightenBlocks($this->parseBlocks($body));
        $captionBlocks = [];
        if ($caption !== null && trim($caption) !== '') {
            $captionBlocks = [new Plain(array_merge([new Str("($letter)"), new Space()], $this->parseInlines($caption)))];
        }
        return new Figure(new Attr($label ?? '', ['subfigure'], $attrs), new Caption(null, $captionBlocks), $blocks);
    }

    /** @return Block[] */
    private function parseFloat(string $name, string $body): array
    {
        $isFigure = str_contains($name, 'figure');
        if (($opt = $this->readOptional($body, $this->skipWs($body, 0))) !== null) {
            $body = substr($body, $opt[1]);
        }
        if ($name === 'wrapfigure') {
            $j = 0;
            $this->skipOptionalAndArgs($body, $j, 2);
            $body = substr($body, $j);
        }
        $caption = $this->extractCaption($body);
        $label = $this->extractLabel($body);
        $captionBlocks = [];
        if ($caption !== null && trim($caption) !== '') {
            $inlines = $this->parseInlines($caption);
            $kind = $isFigure ? 'figure' : 'table';
            $number = $this->floatNumbers[$kind][$this->floatIdx[$kind]++] ?? null;
            if ($number !== null) {
                array_unshift($inlines, new Str(($isFigure ? 'Figure ' : 'Table ') . $number . ':'), new Space());
            }
            $captionBlocks = [new Plain($inlines)];
        }

        if ($isFigure) {
            $this->subIdx = 0;
            $blocks = $this->tightenBlocks($this->parseBlocks($body));
            return [new Figure(new Attr($label ?? ''), new Caption(null, $captionBlocks), $blocks)];
        }

        $captionObj = new Caption(null, $captionBlocks);
        $out = [];
        $found = false;
        foreach ($this->parseBlocks($body) as $b) {
            if (!$found && $b instanceof Table) {
                $out[] = new Table(new Attr($label ?? ''), $captionObj, $b->colSpecs, $b->head, $b->bodies, $b->foot);
                $found = true;
            } else {
                $out[] = $b;
            }
        }
        return $out;
    }

    /** @return Alignment[] */
    private function parseColSpec(string $spec): array
    {
        $aligns = [];
        $n = strlen($spec);
        for ($i = 0; $i < $n; $i++) {
            $c = $spec[$i];
            switch ($c) {
                case 'l':
                    $aligns[] = Alignment::AlignLeft;
                    break;
                case 'c':
                    $aligns[] = Alignment::AlignCenter;
                    break;
                case 'r':
                    $aligns[] = Alignment::AlignRight;
                    break;
                case 'p':
                case 'm':
                case 'b':
                case 'X':
                case 'w':
                case 'W':
                    $aligns[] = Alignment::AlignDefault;
                    if ($c !== 'X') {
                        $j = $i + 1;
                        $this->readArg($spec, $j);
                        if (in_array($c, ['w', 'W'], true)) {
                            $this->readArg($spec, $j);
                        }
                        $i = $j - 1;
                    }
                    break;
                case '@':
                case '!':
                case '>':
                case '<':
                    $j = $i + 1;
                    $this->readArg($spec, $j);
                    $i = $j - 1;
                    break;
                case '*':
                    $j = $i + 1;
                    $count = (int) ($this->readArg($spec, $j) ?? 0);
                    $inner = $this->readArg($spec, $j) ?? '';
                    for ($k = 0; $k < $count; $k++) {
                        array_push($aligns, ...$this->parseColSpec($inner));
                    }
                    $i = $j - 1;
                    break;
            }
        }
        return $aligns;
    }

    private function parseTable(string $env, string $body, ?string $caption, ?string $label): Table
    {
        $caption ??= $this->extractCaption($body);
        $label ??= $this->extractLabel($body);
        $j = 0;
        if (in_array($env, ['tabularx', 'tabular*'], true)) {
            $this->readArg($body, $j);
        }
        if (($opt = $this->readOptional($body, $this->skipWs($body, $j))) !== null) {
            $j = $opt[1];
        }
        $spec = $this->readArg($body, $j) ?? '';
        $aligns = $this->parseColSpec($spec);
        $rest = substr($body, $j);

        $ruleRe = '/^\s*(?:\\\\(?:hline|toprule|midrule|bottomrule|endhead|endfirsthead|endfoot|endlastfoot|cline\s*\{[^}]*\}|cmidrule\s*(?:\([^)]*\))?\s*\{[^}]*\}|noalign\s*\{[^}]*\})\s*)+/';
        $rows = [];
        $marks = [];
        foreach ($this->splitTop($rest, '\\\\') as $k => $raw) {
            $mark = [];
            while (preg_match($ruleRe, $raw, $m)) {
                preg_match_all('/\\\\([a-z]+)/', $m[0], $names);
                array_push($mark, ...$names[1]);
                $raw = substr($raw, strlen($m[0]));
            }
            if (preg_match('/^(.*?)((?:\s*\\\\(?:hline|bottomrule|endhead|endfirsthead|endfoot|endlastfoot))+)\s*$/s', $raw, $m)) {
                $raw = $m[1];
            }
            if (trim($raw) === '') {
                continue;
            }
            $rows[] = $raw;
            $marks[] = $mark;
        }

        // Header detection from rules
        $headCount = 0;
        $drop = [];
        foreach ($marks as $idx => $mark) {
            if ($idx > 0 && array_intersect($mark, ['midrule', 'endhead', 'endfirsthead'])) {
                $headCount = $idx;
                if (in_array('endfirsthead', $mark, true)) {
                    for ($q = $idx; $q < count($marks); $q++) {
                        if ($q > $idx && in_array('endhead', $marks[$q], true)) {
                            $drop = range($idx, $q - 1);
                            break;
                        }
                    }
                }
                break;
            }
        }
        if ($headCount === 0 && count($rows) > 1 && array_intersect($marks[0] ?? [], ['hline', 'toprule'])) {
            foreach ($marks as $idx => $mark) {
                if ($idx > 0 && in_array('hline', $mark, true)) {
                    $headCount = $idx;
                    break;
                }
            }
        }

        $colCount = count($aligns);
        $built = [];
        foreach ($rows as $idx => $raw) {
            if (in_array($idx, $drop, true)) {
                continue;
            }
            $cells = [];
            $col = 0;
            foreach ($this->splitTop($raw, '&') as $cellText) {
                $cellText = trim($cellText);
                $span = 1;
                $align = $aligns[$col] ?? Alignment::AlignDefault;
                if (preg_match('/^\\\\multicolumn\s*\{\s*(\d+)\s*\}\s*\{([^}]*)\}\s*(?=\{)/', $cellText, $m)) {
                    $span = max(1, (int) $m[1]);
                    $a = $this->parseColSpec($m[2]);
                    $align = $a[0] ?? $align;
                    [$cellText] = $this->readBraced($cellText, strlen($m[0]));
                } elseif (preg_match('/^\\\\multirow\s*\{[^}]*\}\s*(?:\[[^\]]*\])?\s*\{[^}]*\}\s*(?:\[[^\]]*\])?\s*(?=\{)/', $cellText, $m)) {
                    [$cellText] = $this->readBraced($cellText, strlen($m[0]));
                }
                $blocks = trim($cellText) === '' ? [] : $this->tightenBlocks($this->parseBlocks($cellText));
                $cells[] = new Cell(new Attr(), $align, 1, $span, $blocks);
                $col += $span;
            }
            $colCount = max($colCount, $col);
            $built[$idx] = new Row(new Attr(), $cells);
        }
        $headRows = [];
        $bodyRows = [];
        foreach ($built as $idx => $row) {
            if ($idx < $headCount) {
                $headRows[] = $row;
            } else {
                $bodyRows[] = $row;
            }
        }
        while (count($aligns) < $colCount) {
            $aligns[] = Alignment::AlignDefault;
        }
        $colSpecs = array_map(fn(Alignment $a) => [$a, null], $aligns);
        $captionBlocks = $caption !== null && trim($caption) !== '' ? [new Plain($this->parseInlines($caption))] : [];

        return new Table(
            new Attr($label ?? ''),
            new Caption(null, $captionBlocks),
            $colSpecs,
            new TableHead(new Attr(), $headRows),
            [new TableBody(new Attr(), 0, [], $bodyRows)],
            new TableFoot(new Attr(), [])
        );
    }

    private function parseBibliography(string $body): Div
    {
        $j = $this->skipWs($body, 0);
        if (($body[$j] ?? '') === '{') {
            $this->readArg($body, $j);
        } else {
            $j = 0;
        }
        $entries = [];
        foreach ($this->splitItems(substr($body, $j), 'bibitem') as $part) {
            $p = $this->skipWs($part, 0);
            if (($opt = $this->readOptional($part, $p)) !== null) {
                $p = $opt[1];
            }
            $key = trim($this->readArg($part, $p) ?? '');
            $number = $this->bibKeys[$key] ?? (count($entries) + 1);
            $inlines = $this->parseInlines(substr($part, $p));
            array_unshift($inlines, new Str('[' . $number . ']'), new Space());
            $entries[] = new Div(new Attr('ref-' . $key, ['reference']), [new Para($inlines)]);
        }
        return new Div(new Attr('refs', ['references']), $entries);
    }

    // ------------------------------------------------------------------
    // Inline parsing
    // ------------------------------------------------------------------

    /** @return Inline[] */
    private function parseInlines(string $s, bool $trim = true): array
    {
        $out = [];
        $buf = '';
        $i = 0;
        $n = strlen($s);
        $flush = function () use (&$out, &$buf): void {
            if ($buf !== '') {
                $out[] = new Str($buf);
                $buf = '';
            }
        };

        while ($i < $n) {
            $c = $s[$i];

            if (ctype_space($c)) {
                $flush();
                $nl = false;
                while ($i < $n && ctype_space($s[$i])) {
                    $nl = $nl || $s[$i] === "\n";
                    $i++;
                }
                $out[] = $nl ? new SoftBreak() : new Space();
                continue;
            }

            if ($c === '\\') {
                if ($i + 1 >= $n) {
                    break;
                }
                $nx = $s[$i + 1];
                if (ctype_alpha($nx)) {
                    preg_match('/\G\\\\([a-zA-Z]+)\*?/', $s, $m, 0, $i);
                    $i += strlen($m[0]);
                    $flush();
                    array_push($out, ...$this->inlineCommand($m[1], $s, $i));
                    continue;
                }
                $i += 2;
                switch ($nx) {
                    case '\\':
                        $flush();
                        $out[] = new LineBreak();
                        if ($i < $n && $s[$i] === '*') {
                            $i++;
                        }
                        if (($opt = $this->readOptional($s, $i)) !== null) {
                            $i = $opt[1];
                        }
                        break;
                    case ' ':
                    case "\n":
                        $flush();
                        $out[] = new Space();
                        break;
                    case ',':
                    case ';':
                    case ':':
                        $buf .= "\u{2009}";
                        break;
                    case '(':
                    case '[':
                        $close = strpos($s, $nx === '(' ? '\\)' : '\\]', $i);
                        $math = substr($s, $i, ($close === false ? $n : $close) - $i);
                        $i = $close === false ? $n : $close + 2;
                        $flush();
                        $out[] = new Math($nx === '(' ? MathType::InlineMath : MathType::DisplayMath, trim($math));
                        break;
                    case "'":
                    case '`':
                    case '"':
                    case '^':
                    case '~':
                    case '=':
                    case '.':
                        $base = $this->readArg($s, $i) ?? '';
                        $buf .= $base === '' ? $nx : $this->accent($nx, $base);
                        break;
                    case '-':
                    case '/':
                    case '@':
                    case '!':
                    case '>':
                    case '<':
                        break;
                    default:
                        $buf .= $nx;
                }
                continue;
            }

            switch ($c) {
                case '$':
                    if (preg_match('/\G\$\$(.*?)\$\$/s', $s, $m, 0, $i)) {
                        $flush();
                        $out[] = new Math(MathType::DisplayMath, trim($m[1]));
                        $i += strlen($m[0]);
                    } elseif (preg_match('/\G\$((?:[^$\\\\]|\\\\.)*)\$/s', $s, $m, 0, $i)) {
                        $flush();
                        $out[] = new Math(MathType::InlineMath, trim($m[1]));
                        $i += strlen($m[0]);
                    } else {
                        $buf .= '$';
                        $i++;
                    }
                    break;
                case '{':
                    [$content, $i] = $this->readBraced($s, $i);
                    $flush();
                    array_push($out, ...$this->groupInlines($content));
                    break;
                case '}':
                case '%':
                    $i++;
                    break;
                case '~':
                    $buf .= "\u{00A0}";
                    $i++;
                    break;
                case '-':
                    $len = 1;
                    while ($i + $len < $n && $s[$i + $len] === '-') {
                        $len++;
                    }
                    $buf .= match ($len) {
                        1 => '-',
                        2 => '–',
                        3 => '—',
                        default => str_repeat('-', $len),
                    };
                    $i += $len;
                    break;
                case '`':
                    $double = ($s[$i + 1] ?? '') === '`';
                    $buf .= $double ? '“' : '‘';
                    $i += $double ? 2 : 1;
                    break;
                case "'":
                    $double = ($s[$i + 1] ?? '') === "'";
                    $buf .= $double ? '”' : '’';
                    $i += $double ? 2 : 1;
                    break;
                default:
                    $buf .= $c;
                    $i++;
            }
        }
        $flush();

        if ($trim) {
            while ($out && ($out[0] instanceof Space || $out[0] instanceof SoftBreak)) {
                array_shift($out);
            }
            while ($out && (end($out) instanceof Space || end($out) instanceof SoftBreak)) {
                array_pop($out);
            }
        }
        return $out;
    }

    /** @return Inline[] */
    private function groupInlines(string $content): array
    {
        if (preg_match('/^\s*\\\\(' . self::DECLARATIONS . ')(?![a-zA-Z])\s*/', $content, $m)) {
            $inner = $this->groupInlines(substr($content, strlen($m[0])));
            return match ($m[1]) {
                'bf', 'bfseries' => [new Strong($inner)],
                'it', 'itshape', 'em', 'sl', 'slshape' => [new Emph($inner)],
                'tt', 'ttfamily' => [new Code(new Attr(), $this->stringify($inner))],
                'sc', 'scshape' => [new SmallCaps($inner)],
                default => $inner,
            };
        }
        return $this->parseInlines($content, false);
    }

    private function accent(string $mark, string $base): string
    {
        $base = match ($base) {
            '\\i' => 'i',
            '\\j' => 'j',
            default => $base,
        };
        $first = mb_substr($base, 0, 1);
        $rest = mb_substr($base, 1);
        $composed = $first . (self::ACCENTS[$mark] ?? '');
        if (class_exists(\Normalizer::class)) {
            $composed = \Normalizer::normalize($composed, \Normalizer::FORM_C) ?: $composed;
        }
        return $composed . $rest;
    }

    private function inlinesArg(string $s, int &$i): array
    {
        return $this->parseInlines($this->readArg($s, $i) ?? '', false);
    }

    private function cleanUrl(string $url): string
    {
        return trim(preg_replace('/\\\\([#%_&~$^{}])/', '$1', $url));
    }

    /** Exact key first, then a case-insensitive match (BibTeX ignores the case of keys). */
    private function resolveBibKey(string $key): ?string
    {
        if (isset($this->bibKeys[$key])) {
            return $key;
        }
        foreach ($this->bibKeys as $known => $_) {
            if (strcasecmp($known, $key) === 0) {
                return (string) $known;
            }
        }
        return null;
    }

    private function citeLink(string $keys): array
    {
        $parts = [];
        foreach (array_filter(array_map('trim', explode(',', $keys))) as $k) {
            $key = $this->resolveBibKey($k);
            $parts[] = $key !== null
                ? new Link(new Attr('', ['citation'], []), [new Str((string) $this->bibKeys[$key])], new Target('#ref-' . $key))
                : new Str($k);
        }
        $out = [new Str('[')];
        foreach ($parts as $k => $p) {
            if ($k > 0) {
                array_push($out, new Str(';'), new Space());
            }
            $out[] = $p;
        }
        $out[] = new Str(']');
        return $out;
    }

    /**
     * Handles a control word at $s[$i] (already past the name).
     *
     * @return Inline[]
     */
    private function inlineCommand(string $name, string $s, int &$i): array
    {
        if (isset(self::SYMBOLS[$name])) {
            if (substr($s, $i, 2) === '{}') {
                $i += 2;
            }
            return [new Str(self::SYMBOLS[$name])];
        }
        if ($name === 'today') {
            return [new Str(date('F j, Y'))];
        }
        if (in_array($name, self::TRANSPARENT_COMMANDS, true)) {
            return $this->inlinesArg($s, $i);
        }
        if (isset(self::IGNORED_INLINE_COMMANDS[$name])) {
            $this->skipOptionalAndArgs($s, $i, self::IGNORED_INLINE_COMMANDS[$name]);
            return [];
        }
        if (preg_match('/^(?:auto|paren|text|foot|super|smart|)cite[a-z]*$/i', $name)) {
            while (($opt = $this->readOptional($s, $this->skipWs($s, $i))) !== null) {
                $i = $opt[1];
            }
            return $this->citeLink($this->readArg($s, $i) ?? '');
        }

        switch ($name) {
            case 'emph':
            case 'textit':
            case 'textsl':
                return [new Emph($this->inlinesArg($s, $i))];
            case 'textbf':
                return [new Strong($this->inlinesArg($s, $i))];
            case 'textsc':
                return [new SmallCaps($this->inlinesArg($s, $i))];
            case 'underline':
            case 'ul':
            case 'uline':
                return [new Underline($this->inlinesArg($s, $i))];
            case 'sout':
            case 'st':
            case 'xout':
                return [new Strikeout($this->inlinesArg($s, $i))];
            case 'textsuperscript':
                return [new Superscript($this->inlinesArg($s, $i))];
            case 'textsubscript':
                return [new Subscript($this->inlinesArg($s, $i))];
            case 'texttt':
                return [new Code(new Attr(), $this->stringify($this->inlinesArg($s, $i)))];
            case 'enquote':
            case 'textquote':
                return [new Quoted(QuoteType::DoubleQuote, $this->inlinesArg($s, $i))];
            case 'ensuremath':
                return [new Math(MathType::InlineMath, trim($this->readArg($s, $i) ?? ''))];
            case 'footnote':
                $text = $this->readArg($s, $i) ?? '';
                return [new Note($this->parseBlocks($text))];
            case 'verb':
            case 'lstinline':
                if ($name === 'lstinline' && ($opt = $this->readOptional($s, $i)) !== null) {
                    $i = $opt[1];
                }
                $k = $i + (($s[$i] ?? '') === '*' ? 1 : 0);
                $delim = $s[$k] ?? '';
                if ($delim === '{') {
                    [$text, $i] = $this->readBraced($s, $k);
                } else {
                    $close = $delim !== '' ? strpos($s, $delim, $k + 1) : false;
                    $text = $close !== false ? substr($s, $k + 1, $close - $k - 1) : '';
                    $i = $close !== false ? $close + 1 : strlen($s);
                }
                return [new Code(new Attr(), $text)];
            case 'href':
                $url = $this->cleanUrl($this->readArg($s, $i) ?? '');
                $text = $this->inlinesArg($s, $i);
                return [new Link(new Attr(), $text ?: [new Str($url)], new Target($url))];
            case 'url':
                $url = $this->cleanUrl($this->readArg($s, $i) ?? '');
                return [new Link(new Attr('', ['uri'], []), [new Str($url)], new Target($url))];
            case 'ref':
            case 'autoref':
            case 'cref':
            case 'Cref':
            case 'pageref':
            case 'eqref':
            case 'nameref':
            case 'vref':
                $label = trim($this->readArg($s, $i) ?? '');
                if ($name === 'pageref') {
                    return [];
                }
                return [new Link(
                    new Attr('', [], [['reference-type', $name], ['reference', $label]]),
                    [new Str($this->refText($name, $label))],
                    new Target('#' . $label)
                )];
            case 'hyperref':
                $label = '';
                if (($opt = $this->readOptional($s, $i)) !== null) {
                    $label = trim($opt[0]);
                    $i = $opt[1];
                }
                return [new Link(new Attr(), $this->inlinesArg($s, $i), new Target('#' . $label))];
            case 'hypertarget':
                $id = trim($this->readArg($s, $i) ?? '');
                return [new Span(new Attr($id), $this->inlinesArg($s, $i))];
            case 'hyperlink':
                $id = trim($this->readArg($s, $i) ?? '');
                return [new Link(new Attr(), $this->inlinesArg($s, $i), new Target('#' . $id))];
            case 'label':
                return [new Span(new Attr(trim($this->readArg($s, $i) ?? '')), [])];
            case 'textcolor':
            case 'colorbox':
                $model = null;
                if (($opt = $this->readOptional($s, $i)) !== null) {
                    $model = strtolower(trim($opt[0]));
                    $i = $opt[1];
                }
                $color = trim($this->readArg($s, $i) ?? '');
                if ($model === 'html' && preg_match('/^[0-9a-fA-F]{6}$/', $color)) {
                    $color = '#' . $color;
                }
                $key = $name === 'textcolor' ? 'color' : 'background-color';
                return [new Span(new Attr('', [], [[$key, $color]]), $this->inlinesArg($s, $i))];
            case 'includegraphics':
                return [$this->parseImage($s, $i)];
            case 'cover':
                $this->skipOptionalAndArgs($s, $i, 0);
                $this->setMeta('cover', $this->readArg($s, $i) ?? '');
                return [];
            case 'newline':
                return [new LineBreak()];
            case 'c':
            case 'v':
            case 'u':
            case 'H':
            case 'k':
            case 'r':
            case 'd':
            case 'b':
                $base = $this->readArg($s, $i) ?? '';
                return [new Str($this->accent($name, $base))];
            case 'begin':
            case 'end':
                $this->readArg($s, $i);
                return [];
            case 'bf':
            case 'bfseries':
            case 'it':
            case 'itshape':
            case 'em':
            case 'sl':
            case 'tt':
            case 'ttfamily':
            case 'sc':
            case 'scshape':
            case 'rm':
            case 'sf':
            case 'normalfont':
                return [];
            default:
                return [];
        }
    }

    /** "0.3\textwidth" becomes "30%"; other lengths (5cm, 120pt) are returned unchanged. */
    private function relativeLength(string $v): string
    {
        $v = trim($v);
        return preg_match('/^([0-9.]*)\s*\\\\(?:textwidth|linewidth|columnwidth|hsize)$/', $v, $m)
            ? round(($m[1] === '' ? 1.0 : (float) $m[1]) * 100, 2) . '%'
            : $v;
    }

    private function parseImage(string $s, int &$i): Inline
    {
        $attrs = [];
        if (($opt = $this->readOptional($s, $i)) !== null) {
            $i = $opt[1];
            foreach (explode(',', $opt[0]) as $kv) {
                if (!str_contains($kv, '=')) {
                    continue;
                }
                [$k, $v] = array_map('trim', explode('=', $kv, 2));
                if (in_array($k, ['width', 'height'], true)) {
                    $attrs[] = [$k, $this->relativeLength($v)];
                }
            }
        }
        $path = trim($this->readArg($s, $i) ?? '');
        $url = $this->registerImage($path);
        return new Image(new Attr('', [], $attrs), [], new Target($url));
    }

    /** Loads a local image into the MediaBag if it can be found; returns the (possibly extended) URL. */
    private function registerImage(string $path): string
    {
        if ($path === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
            return $path;
        }
        $exts = array_merge([''], array_map(fn($e) => '.' . $e, array_keys(self::MIME_TYPES)));
        foreach ($this->graphicsPath as $dir) {
            foreach ($exts as $ext) {
                $rel = $dir . $path . $ext;
                $candidates = str_starts_with($rel, '/') ? [$rel] : [($this->baseDir !== '' ? $this->baseDir . '/' : '') . $rel];
                foreach ($candidates as $file) {
                    $e = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (isset(self::MIME_TYPES[$e]) && is_file($file)) {
                        $url = $path . $ext;
                        $this->mediaBag->insert($url, self::MIME_TYPES[$e], (string) file_get_contents($file));
                        return $url;
                    }
                }
            }
        }
        return $path;
    }

    private function stringify(array $inlines): string
    {
        $out = '';
        foreach ($inlines as $inl) {
            $out .= match (true) {
                $inl instanceof Str => $inl->text,
                $inl instanceof Space, $inl instanceof SoftBreak, $inl instanceof LineBreak => ' ',
                $inl instanceof Code => $inl->text,
                $inl instanceof Math => $inl->text,
                $inl instanceof Note => '',
                property_exists($inl, 'content') && is_array($inl->content) => $this->stringify($inl->content),
                default => '',
            };
        }
        return $out;
    }
}
