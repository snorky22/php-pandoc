<?php

namespace Pandoc\Writer;

use Pandoc\AST\Attr;
use Pandoc\AST\Block;
use Pandoc\AST\Header;
use Pandoc\AST\Image;
use Pandoc\AST\Pandoc;
use ZipArchive;

/**
 * Writes an EPUB 3 container (with an EPUB 2 compatible toc.ncx).
 *
 * Options:
 *  - splitLevel   (int, default 1)  headings of this level or higher start a new chapter file
 *  - tocDepth     (int, default 3)  deepest heading level listed in the table of contents
 *  - css          (string|null)     stylesheet contents replacing the default one
 *  - coverImage   (string|null)     path of a cover image; overrides the document's \cover{file}
 *  - resourcePath (string[]|string) directories searched for images missing from the MediaBag
 *  - identifier   (string|null)     dc:identifier, defaults to a UUID derived from the metadata
 *  - language     (string|null)     BCP 47 tag, defaults to the 'lang' metadata or en-US
 *  - titlePage    (bool, default true)
 *  - optimizeImages (bool, default false) opt-in: shrink images with Imagick (CPU heavy, ~10 s for 40 MB of PNGs)
 *  - maxImageWidth  (int, default 1600)  wider images are downscaled; 0 keeps the original size
 *  - jpegQuality    (int, default 85)    quality used when an opaque photographic image becomes JPEG
 *  - numberSections (bool, default true) show section numbers read from the 'number' heading attribute
 *  - mathml       (bool, default true)  convert math to MathML when latex2mathml-php is available
 */
class EpubWriter
{
    private const MIME_BY_EXT = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'svg' => 'image/svg+xml', 'webp' => 'image/webp',
    ];

    private const DEFAULT_CSS = <<<'CSS'
body { margin: 5%; text-align: justify; font-size: medium; }
code { font-family: monospace; }
h1, h2, h3, h4, h5, h6 { text-align: left; }
h1 { margin-top: 2em; }
p { margin: 1em 0 0 0; }
pre { white-space: pre-wrap; margin: 1em 0; padding: 0.5em; background: #f4f4f4; }
blockquote { margin: 1em 2em; }
table { border-collapse: collapse; margin: 1em auto; }
th, td { padding: 0.25em 0.75em; border-bottom: 1px solid #ccc; }
th { border-bottom: 2px solid #000; }
caption, figcaption { font-style: italic; margin: 0.5em 0; text-align: center; }
figure { margin: 1em 0; text-align: center; }
img { max-width: 100%; }
span.header-section-number { margin-right: 0.5em; }
span.smallcaps { font-variant: small-caps; }
span.underline { text-decoration: underline; }
span.math.display { display: block; text-align: center; margin: 1em 0; }
section.footnotes { font-size: 0.85em; }
div.algorithm { margin: 1em 0; padding: 0.5em; border-top: 2px solid #000; border-bottom: 2px solid #000; }
div.alg-line { font-family: serif; margin: 0.15em 0; }
div.alg-i1 { margin-left: 1.5em; } div.alg-i2 { margin-left: 3em; } div.alg-i3 { margin-left: 4.5em; }
div.alg-i4 { margin-left: 6em; } div.alg-i5 { margin-left: 7.5em; } div.alg-i6 { margin-left: 9em; }
div.alg-i7 { margin-left: 10.5em; } div.alg-i8 { margin-left: 12em; }
span.alg-num { color: #777; margin-right: 0.3em; }
div.cover { text-align: center; }
div.cover img { display: block; margin: 0 auto; max-height: 98vh; max-width: 100%; }
section.titlepage div.cover { margin: 1em 0 2em 0; }
section.titlepage div.cover img { max-height: 60vh; }
.title { text-align: center; margin-top: 3em; }
.author, .date { text-align: center; }
CSS;

    public function __construct(private array $options = []) {}

    public function writeToFile(Pandoc $doc, string $path): void
    {
        file_put_contents($path, $this->write($doc));
    }

    /** @return string binary contents of the .epub file */
    public function write(Pandoc $doc): string
    {
        $splitLevel = (int) ($this->options['splitLevel'] ?? 1);
        $tocDepth = (int) ($this->options['tocDepth'] ?? 3);
        $numberSections = (bool) ($this->options['numberSections'] ?? true);

        // --- metadata -----------------------------------------------------
        $title = trim($this->metaText($doc->meta->map['title'] ?? ''));
        $authors = $this->metaAuthors($doc->meta->map['author'] ?? []);
        $date = $this->normalizeDate($this->metaText($doc->meta->map['date'] ?? ''));
        $lang = (string) ($this->options['language'] ?? ($this->metaText($doc->meta->map['lang'] ?? '') ?: 'en-US'));
        $identifier = (string) ($this->options['identifier'] ?? $this->uuid($title . '|' . implode(',', $authors) . '|' . $date));
        $docTitle = $title !== '' ? $title : 'Untitled';

        // --- chapters -----------------------------------------------------
        $chapters = $this->splitChapters($this->ensureHeaderIds($doc->blocks), $splitLevel, $docTitle);
        $idMap = [];
        foreach ($chapters as $k => &$chapter) {
            $chapter['file'] = sprintf('ch%03d.xhtml', $k + 1);
            foreach ($this->walk($chapter['blocks']) as $node) {
                if ($node instanceof Attr && $node->identifier !== '') {
                    $idMap[$node->identifier] ??= $chapter['file'];
                }
            }
        }
        unset($chapter);

        // --- media --------------------------------------------------------
        $media = [];
        $imageMap = [];
        foreach ($chapters as $chapter) {
            foreach ($this->walk($chapter['blocks']) as $node) {
                if ($node instanceof Image && !isset($imageMap[$node->target->url])) {
                    $entry = $this->loadImage($node->target->url, $doc, count($media) + 1);
                    if ($entry !== null) {
                        $media[] = $entry;
                        $imageMap[$node->target->url] = '../' . $entry['href'];
                    }
                }
            }
        }
        $cover = $this->loadCover($doc);

        // --- chapter XHTML --------------------------------------------------
        $writer = new XhtmlWriter($idMap, $imageMap, (bool) ($this->options['mathml'] ?? true), $numberSections);
        $toc = [];
        $files = [];
        foreach ($chapters as $k => $chapter) {
            $writer->setCurrentFile($chapter['file']);
            $body = $writer->writeBlocks($chapter['blocks']);
            $notes = $writer->takeNotes();
            $chapters[$k]['mathml'] = $writer->takeMathMlFlag();
            $files['EPUB/text/' . $chapter['file']] = $this->page(
                $chapter['title'] !== '' ? $chapter['title'] : $docTitle,
                $lang,
                '<section epub:type="chapter">' . "\n" . $body . ($notes !== '' ? "\n" . $notes : '') . "\n</section>"
            );
            if ($chapter['blocks'] && !($chapter['blocks'][0] instanceof Header)) {
                $toc[] = ['level' => 1, 'title' => $chapter['title'], 'href' => 'text/' . $chapter['file']];
            }
            foreach ($chapter['blocks'] as $idx => $block) {
                if ($block instanceof Header && $block->level <= $tocDepth) {
                    $number = '';
                    foreach ($block->attr->attributes as $pair) {
                        if (is_array($pair) && $pair[0] === 'number' && $numberSections) {
                            $number = $pair[1] . ' ';
                        }
                    }
                    $toc[] = [
                        'level' => $block->level,
                        'title' => $number . trim(XhtmlWriter::stringify($block->content)),
                        'href' => 'text/' . $chapter['file'] . ($idx === 0 ? '' : '#' . $block->attr->identifier),
                    ];
                }
            }
        }
        if (!$toc) {
            $toc[] = ['level' => 1, 'title' => $chapters[0]['title'] ?: $docTitle, 'href' => 'text/' . $chapters[0]['file']];
        }

        $hasTitlePage = ($this->options['titlePage'] ?? true) && $title !== '';
        // The cover is shown inside the title page when there is one; otherwise on a page of its own.
        $coverOnTitlePage = $hasTitlePage && $cover !== null;
        $coverPage = $cover !== null && !$coverOnTitlePage;
        if ($hasTitlePage) {
            $files['EPUB/text/title_page.xhtml'] = $this->page(
                $docTitle,
                $lang,
                $this->titlePageBody($title, $authors, $date, $coverOnTitlePage ? $cover['href'] : null)
            );
        }
        if ($coverPage) {
            $files['EPUB/text/cover.xhtml'] = $this->page('Cover', $lang, $this->coverImageHtml($cover['href']));
        }

        $files['EPUB/styles/stylesheet1.css'] = (string) ($this->options['css'] ?? self::DEFAULT_CSS);
        $files['EPUB/nav.xhtml'] = $this->navDocument($docTitle, $lang, $toc);
        $files['EPUB/toc.ncx'] = $this->ncxDocument($docTitle, $identifier, $toc);
        $files['EPUB/content.opf'] = $this->opfDocument(
            $docTitle, $authors, $date, $lang, $identifier, $chapters, $media, $cover, $hasTitlePage, $coverPage
        );
        $files['META-INF/container.xml'] = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
  <rootfiles>
    <rootfile full-path="EPUB/content.opf" media-type="application/oebps-package+xml" />
  </rootfiles>
</container>
XML;

        foreach ($media as $m) {
            $files['EPUB/' . $m['href']] = $m['contents'];
        }
        if ($cover !== null) {
            $files['EPUB/' . $cover['href']] = $cover['contents'];
        }

        return $this->zip($files);
    }

    // ------------------------------------------------------------------
    // Structure
    // ------------------------------------------------------------------

    /**
     * @param Block[] $blocks
     * @return Block[]
     */
    private function ensureHeaderIds(array $blocks): array
    {
        $used = [];
        foreach ($this->walk($blocks) as $node) {
            if ($node instanceof Attr && $node->identifier !== '') {
                $used[$node->identifier] = true;
            }
        }
        $out = [];
        foreach ($blocks as $b) {
            if ($b instanceof Header && $b->attr->identifier === '') {
                $base = 'section';
                for ($k = 1; isset($used[$id = $base . '-' . $k]); $k++) {
                }
                $used[$id] = true;
                $b = new Header($b->level, new Attr($id, $b->attr->classes, $b->attr->attributes), $b->content);
            }
            $out[] = $b;
        }
        return $out;
    }

    /**
     * @param Block[] $blocks
     * @return array<int, array{title:string, blocks:Block[], file?:string, mathml?:bool}>
     */
    private function splitChapters(array $blocks, int $splitLevel, string $docTitle): array
    {
        $chapters = [];
        $current = null;
        foreach ($blocks as $block) {
            if ($block instanceof Header && $block->level <= $splitLevel) {
                if ($current !== null) {
                    $chapters[] = $current;
                }
                $current = ['title' => trim(XhtmlWriter::stringify($block->content)), 'blocks' => [$block]];
            } else {
                $current ??= ['title' => $docTitle, 'blocks' => []];
                $current['blocks'][] = $block;
            }
        }
        if ($current !== null) {
            $chapters[] = $current;
        }
        return $chapters ?: [['title' => $docTitle, 'blocks' => []]];
    }

    /** Depth-first walk over arrays and AST objects. */
    private function walk(mixed $node): \Generator
    {
        if (is_array($node)) {
            foreach ($node as $v) {
                yield from $this->walk($v);
            }
        } elseif (is_object($node) && !($node instanceof \UnitEnum)) {
            yield $node;
            foreach (get_object_vars($node) as $v) {
                yield from $this->walk($v);
            }
        }
    }

    // ------------------------------------------------------------------
    // Media
    // ------------------------------------------------------------------

    /** @return array{href:string, mime:string, contents:string, id:string}|null */
    private function loadImage(string $url, Pandoc $doc, int $n): ?array
    {
        $contents = null;
        $mime = null;
        if (($item = $doc->mediaBag->lookup($url)) !== null) {
            $contents = $item['contents'];
            $mime = $item['mime'];
        } elseif (preg_match('#^data:([^;,]+);base64,(.+)$#s', $url, $m)) {
            $contents = base64_decode($m[2], true) ?: null;
            $mime = $m[1];
        } elseif (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            $dirs = (array) ($this->options['resourcePath'] ?? ['.']);
            foreach ($dirs as $dir) {
                $file = str_starts_with($url, '/') ? $url : rtrim($dir, '/') . '/' . $url;
                if (is_file($file)) {
                    $contents = (string) file_get_contents($file);
                    $mime = self::MIME_BY_EXT[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
                    break;
                }
            }
        }
        if ($contents === null || $mime === null) {
            return null;
        }
        [$contents, $mime] = $this->optimizeImage($contents, $mime);
        $ext = array_search($mime, self::MIME_BY_EXT, true) ?: 'png';
        return ['href' => "media/image{$n}.{$ext}", 'mime' => $mime, 'contents' => $contents, 'id' => "image{$n}"];
    }

    /** @return array{0:string, 1:string} contents and mime type, optimized when possible */
    private function optimizeImage(string $contents, string $mime): array
    {
        if (!($this->options['optimizeImages'] ?? false)) {
            return [$contents, $mime];
        }
        $optimizer = new ImageOptimizer((int) ($this->options['maxImageWidth'] ?? 1600), (int) ($this->options['jpegQuality'] ?? 85));
        $result = $optimizer->optimize($contents, $mime);
        return $result !== null ? [$result['contents'], $result['mime']] : [$contents, $mime];
    }

    /**
     * The cover comes from the coverImage option (a file path) or from the 'cover-image' metadata
     * (set by \cover{file} in LaTeX: a MediaBag key, falling back to a file under resourcePath).
     *
     * @return array{href:string, mime:string, contents:string}|null
     */
    private function loadCover(Pandoc $doc): ?array
    {
        $ref = $this->options['coverImage'] ?? ($doc->meta->map['cover-image'] ?? null);
        if (!is_string($ref) || $ref === '') {
            return null;
        }
        $contents = null;
        $mime = null;
        if (!isset($this->options['coverImage']) && ($item = $doc->mediaBag->lookup($ref)) !== null) {
            $contents = $item['contents'];
            $mime = $item['mime'];
        } else {
            $dirs = array_merge([''], (array) ($this->options['resourcePath'] ?? ['.']));
            foreach ($dirs as $dir) {
                $file = str_starts_with($ref, '/') || $dir === '' ? $ref : rtrim($dir, '/') . '/' . $ref;
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (is_file($file) && isset(self::MIME_BY_EXT[$ext])) {
                    $contents = (string) file_get_contents($file);
                    $mime = self::MIME_BY_EXT[$ext];
                    break;
                }
            }
        }
        if ($contents === null || $mime === null) {
            return null;
        }
        [$contents, $mime] = $this->optimizeImage($contents, $mime);
        $ext = array_search($mime, self::MIME_BY_EXT, true) ?: 'png';
        return ['href' => 'media/cover.' . $ext, 'mime' => $mime, 'contents' => $contents];
    }

    // ------------------------------------------------------------------
    // Metadata helpers
    // ------------------------------------------------------------------

    private function metaText(mixed $v): string
    {
        $text = match (true) {
            is_string($v) => $v,
            is_array($v) => XhtmlWriter::stringify(array_filter($v, 'is_object')),
            default => '',
        };
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** @return string[] */
    private function metaAuthors(mixed $v): array
    {
        if (is_string($v)) {
            return $v !== '' ? [$v] : [];
        }
        if (!is_array($v) || !$v) {
            return [];
        }
        $authors = is_array(reset($v)) ? array_map(fn($a) => $this->metaText($a), $v) : [$this->metaText($v)];
        return array_values(array_filter(array_map('trim', $authors), fn($a) => $a !== ''));
    }

    private function normalizeDate(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }
        if (preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $date)) {
            return $date;
        }
        $ts = strtotime($date);
        return $ts !== false ? date('Y-m-d', $ts) : '';
    }

    private function uuid(string $seed): string
    {
        $h = md5($seed);
        return sprintf('urn:uuid:%s-%s-4%s-a%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
    }

    // ------------------------------------------------------------------
    // XML documents
    // ------------------------------------------------------------------

    private function page(string $title, string $lang, string $body): string
    {
        $t = XhtmlWriter::escape($title);
        $l = XhtmlWriter::escape($lang);
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="$l" xml:lang="$l">
<head>
<meta charset="utf-8" />
<title>$t</title>
<link rel="stylesheet" type="text/css" href="../styles/stylesheet1.css" />
</head>
<body>
$body
</body>
</html>
XML;
    }

    private function coverImageHtml(string $href): string
    {
        return '<div class="cover"><img src="../' . XhtmlWriter::escape($href) . '" alt="Cover" /></div>';
    }

    private function titlePageBody(string $title, array $authors, string $date, ?string $coverHref = null): string
    {
        $e = [XhtmlWriter::class, 'escape'];
        $out = $coverHref !== null ? $this->coverImageHtml($coverHref) . "\n" : '';
        $out .= '<h1 class="title">' . $e($title) . '</h1>';
        foreach ($authors as $a) {
            $out .= "\n<p class=\"author\">" . $e($a) . '</p>';
        }
        if ($date !== '') {
            $out .= "\n<p class=\"date\">" . $e($date) . '</p>';
        }
        return '<section class="titlepage" epub:type="titlepage">' . "\n" . $out . "\n</section>";
    }

    /** @param array<int, array{level:int, title:string, href:string}> $toc */
    private function navList(array $toc, int &$i, int $level): string
    {
        $out = "<ol>\n";
        while ($i < count($toc)) {
            $e = $toc[$i];
            if ($e['level'] < $level) {
                break;
            }
            $i++;
            $out .= '<li><a href="' . XhtmlWriter::escape($e['href']) . '">' . XhtmlWriter::escape($e['title']) . '</a>';
            if ($i < count($toc) && $toc[$i]['level'] > $e['level']) {
                $out .= "\n" . $this->navList($toc, $i, $toc[$i]['level']);
            }
            $out .= "</li>\n";
        }
        return $out . '</ol>';
    }

    private function navDocument(string $title, string $lang, array $toc): string
    {
        $i = 0;
        $list = $this->navList($toc, $i, min(array_column($toc, 'level')));
        $l = XhtmlWriter::escape($lang);
        $t = XhtmlWriter::escape($title);
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="$l" xml:lang="$l">
<head>
<meta charset="utf-8" />
<title>$t</title>
<link rel="stylesheet" type="text/css" href="styles/stylesheet1.css" />
</head>
<body>
<nav epub:type="toc" id="toc">
<h1 id="toc-title">Table of Contents</h1>
$list
</nav>
</body>
</html>
XML;
    }

    private function ncxPoints(array $toc, int &$i, int $level, int &$order): string
    {
        $out = '';
        while ($i < count($toc)) {
            $e = $toc[$i];
            if ($e['level'] < $level) {
                break;
            }
            $i++;
            $order++;
            $out .= "<navPoint id=\"navPoint-{$order}\"><navLabel><text>" . XhtmlWriter::escape($e['title']) . '</text></navLabel>'
                . '<content src="' . XhtmlWriter::escape($e['href']) . '" />';
            if ($i < count($toc) && $toc[$i]['level'] > $e['level']) {
                $out .= "\n" . $this->ncxPoints($toc, $i, $toc[$i]['level'], $order);
            }
            $out .= "</navPoint>\n";
        }
        return $out;
    }

    private function ncxDocument(string $title, string $identifier, array $toc): string
    {
        $i = 0;
        $order = 0;
        $points = $this->ncxPoints($toc, $i, min(array_column($toc, 'level')), $order);
        $depth = max(array_column($toc, 'level')) - min(array_column($toc, 'level')) + 1;
        $t = XhtmlWriter::escape($title);
        $id = XhtmlWriter::escape($identifier);
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1">
<head>
<meta name="dtb:uid" content="$id" />
<meta name="dtb:depth" content="$depth" />
<meta name="dtb:totalPageCount" content="0" />
<meta name="dtb:maxPageNumber" content="0" />
</head>
<docTitle><text>$t</text></docTitle>
<navMap>
$points</navMap>
</ncx>
XML;
    }

    private function opfDocument(
        string $title,
        array $authors,
        string $date,
        string $lang,
        string $identifier,
        array $chapters,
        array $media,
        ?array $cover,
        bool $hasTitlePage,
        bool $coverPage
    ): string {
        $e = [XhtmlWriter::class, 'escape'];
        $meta = '<dc:identifier id="epub-id-1">' . $e($identifier) . "</dc:identifier>\n"
            . '<dc:title>' . $e($title) . "</dc:title>\n";
        foreach ($authors as $k => $a) {
            $meta .= '<dc:creator id="creator' . ($k + 1) . '">' . $e($a) . "</dc:creator>\n";
        }
        if ($date !== '') {
            $meta .= '<dc:date>' . $e($date) . "</dc:date>\n";
        }
        $meta .= '<dc:language>' . $e($lang) . "</dc:language>\n"
            . '<meta property="dcterms:modified">' . gmdate('Y-m-d\TH:i:s\Z') . "</meta>\n";
        if ($cover !== null) {
            $meta .= "<meta name=\"cover\" content=\"cover-image\" />\n";
        }

        $manifest = "<item id=\"ncx\" href=\"toc.ncx\" media-type=\"application/x-dtbncx+xml\" />\n"
            . "<item id=\"nav\" href=\"nav.xhtml\" media-type=\"application/xhtml+xml\" properties=\"nav\" />\n"
            . "<item id=\"stylesheet1\" href=\"styles/stylesheet1.css\" media-type=\"text/css\" />\n";
        $spine = '';
        if ($cover !== null) {
            $manifest .= "<item id=\"cover-image\" href=\"{$e($cover['href'])}\" media-type=\"{$e($cover['mime'])}\" properties=\"cover-image\" />\n";
            if ($coverPage) {
                $manifest .= "<item id=\"cover\" href=\"text/cover.xhtml\" media-type=\"application/xhtml+xml\" />\n";
                $spine .= "<itemref idref=\"cover\" linear=\"yes\" />\n";
            }
        }
        if ($hasTitlePage) {
            $manifest .= "<item id=\"title_page\" href=\"text/title_page.xhtml\" media-type=\"application/xhtml+xml\" />\n";
            $spine .= "<itemref idref=\"title_page\" />\n";
        }
        foreach ($chapters as $k => $c) {
            $id = sprintf('ch%03d', $k + 1);
            $props = !empty($c['mathml']) ? ' properties="mathml"' : '';
            $manifest .= "<item id=\"$id\" href=\"text/{$c['file']}\" media-type=\"application/xhtml+xml\"$props />\n";
            $spine .= "<itemref idref=\"$id\" />\n";
        }
        $spine .= "<itemref idref=\"nav\" linear=\"no\" />\n";
        foreach ($media as $m) {
            $manifest .= "<item id=\"{$m['id']}\" href=\"{$e($m['href'])}\" media-type=\"{$e($m['mime'])}\" />\n";
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<package version="3.0" xmlns="http://www.idpf.org/2007/opf" unique-identifier="epub-id-1">
<metadata xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:opf="http://www.idpf.org/2007/opf">
$meta</metadata>
<manifest>
$manifest</manifest>
<spine toc="ncx">
$spine</spine>
</package>
XML;
    }

    /** @param array<string, string> $files path => contents */
    private function zip(array $files): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'epub');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create EPUB archive');
        }
        // The mimetype entry must come first and be stored uncompressed.
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
        foreach ($files as $path => $contents) {
            $zip->addFromString($path, $contents);
        }
        $zip->close();
        $data = (string) file_get_contents($tmp);
        unlink($tmp);
        return $data;
    }
}
