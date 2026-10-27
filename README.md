# Pandoc PHP

A native PHP 8.4 port of the [Pandoc](https://pandoc.org/) document converter. This library converts documents between formats (Word `.docx`, Excel `.xlsx`, PowerPoint `.pptx`, HTML `.html`, Markdown `.md`, Jupyter `.ipynb`, BibTeX `.bib` → LaTeX) and exports **LaTeX projects to EPUB 3** (see [Converting LaTeX to EPUB](#converting-latex-to-epub)) without requiring the system-level Pandoc binary.

## Features

- **Native PHP 8.4**: Uses `readonly` classes, Enums, and property hooks.
- **AST-Centric Architecture**: Mirrors Pandoc's Abstract Syntax Tree for robust conversions.
- **Modular Reader System**: Factory pattern and `ReaderInterface` for easy format expansion.
- **Deep Docx Parsing**: Paragraphs, headers, tables, lists, images, bold/italic/underline/strikeout, superscript/subscript, text and background colors, hyperlinks (external `\href`/`\url`, internal `\hyperref`), footnotes and endnotes (`\footnote`), math equations (Office Math/OMML → LaTeX, inline `$…$` and display `\[…\]`), automatic run-merging (consecutive runs with identical styling are collapsed into one command), and black-color suppression (spurious `\textcolor[HTML]{000000}` commands are dropped).
- **Excel (XLSX)**: All sheets as booktabs tables, shared strings, bold/italic, embedded images, chart extraction (JSON metadata + CSV data for Chart.js), per-sheet CSV export with locale-aware separators, and a `metadata.json` summary of document locale.
- **PowerPoint (PPTX)**: Each slide becomes a `slide` environment, all slides wrapped in a `slider` environment. Images, embedded videos (`\begin{video}...\end{video}`), and audio (`\begin{audio}...\end{audio}`) extracted to MediaBag.
- **LaTeX Generation**: Standalone documents or body fragments.
- **Automatic ZIP Bundling**: When a document contains images or chart data, output is a `.zip` with the `.tex` and all media files in the same directory. Plain `.tex` otherwise.
- **Full UTF-8**: End-to-end UTF-8, supporting CJK, Cyrillic, Arabic, Thai, and all Latin-extended scripts.
- **LaTeX → EPUB 3**: Reads vanilla LaTeX (`LatexReader`) and writes a valid EPUB 3 with MathML math, numbered sections/figures/tables/theorems with working cross-references, footnotes, bibliography, algorithms, embedded images and an optional cover (see below).
- **Minimal Dependencies**: Pure PHP 8.4+ (`ext-dom`, `ext-zip`) plus [`latex2mathml/latex2mathml-php`](https://packagist.org/packages/latex2mathml/latex2mathml-php) for MathML. `ext-imagick` is optional.

## Installation

Requires PHP 8.4 or higher.

```bash
composer require pandoc-php/pandoc
```

## Basic Usage

### Converting a Word Document to LaTeX

```php
use Pandoc\Reader\DocxReader;
use Pandoc\Writer\LatexWriter;

$reader = new DocxReader();
$writer = new LatexWriter();

$doc   = $reader->read('document.docx');
$latex = $writer->write($doc, standalone: true);

file_put_contents('document.tex', $latex);
```

Word equations (Office Math/OMML, `Insert → Equation`) are converted to LaTeX automatically — no extra setup required. An inline equation becomes a `Math` AST node of type `InlineMath` (`$…$`), and a standalone equation paragraph becomes `DisplayMath` (`\[…\]`):

```php
// document.docx contains the equation: y = ∫₀ᵗ x(s) ds
$latex = $writer->write($reader->read('document.docx'), standalone: false);
// → "\[y=\int_{0}^{t}{x\left(s\right)ds}\]"
```

Superscripts/subscripts, fractions, radicals, n-ary operators (∫, ∑, ∏, ⋃, …), delimiters, functions (`sin`, `log`, `lim`, …), matrices, and common Greek letters/operator symbols are all supported. See [SUPPORTED_STRUCTURES.md](SUPPORTED_STRUCTURES.md#9-math-equations-omml--latex) for the full list and known limitations.

### Converting Markdown to a LaTeX Fragment

```php
use Pandoc\Reader\MarkdownReader;
use Pandoc\Writer\LatexWriter;

$reader   = new MarkdownReader();
$writer   = new LatexWriter();
$markdown = "# Hello World\nThis is a paragraph.";
$doc      = $reader->read($markdown);

// standalone: false → body only, no \documentclass preamble
$fragment = $writer->write($doc, standalone: false);
```

### Converting HTML to LaTeX

```php
use Pandoc\Reader\HtmlReader;
use Pandoc\Writer\LatexWriter;

$reader = new HtmlReader();
$writer = new LatexWriter();

$doc   = $reader->read("<h1>Hello</h1><p>World</p>");
$latex = $writer->write($doc);
```

### Converting an Excel Spreadsheet to LaTeX

```php
use Pandoc\Reader\XlsxReader;
use Pandoc\Writer\LatexWriter;

$reader = new XlsxReader();
$writer = new LatexWriter();

$doc   = $reader->read('spreadsheet.xlsx');
$latex = $writer->write($doc);
```

Each sheet produces a level-2 header followed by a `booktabs` table. If the spreadsheet contains embedded images or charts, use the ZIP output pattern below.

> **Note**: Only `.xlsx` (OOXML) is supported. Legacy `.xls` files must be converted first (e.g. via LibreOffice).

**Chart extraction**: Charts are exported as two companion files added to the MediaBag:

`chart1.json` — Chart.js-ready metadata:
```json
{
  "type": "bar",
  "title": "Sales by Quarter",
  "dataFile": "chart1.csv",
  "options": {
    "indexAxis": "x",
    "scales": {
      "x": { "title": { "display": true, "text": "Quarter" }, "stacked": false },
      "y": { "title": { "display": true, "text": "Revenue" }, "stacked": false }
    }
  },
  "series": [
    { "label": "Product A" },
    { "label": "Product B" }
  ]
}
```

`chart1.csv` — the data (categories + one column per series):
```
Category,Product A,Product B
Q1,120,85
Q2,135,90
Q3,128,95
Q4,145,110
```

A comment marker is inserted in the LaTeX at the chart's position:
```latex
% [pandoc-chart: chart1.json]
```
Your app reads the marker → loads the JSON → finds `dataFile` → loads the CSV → renders with Chart.js.

**Per-sheet CSV export**: Each worksheet is also exported as a standalone CSV file (e.g. `sheet-Sales.csv`) added to the MediaBag. Trailing empty rows and columns are stripped automatically.

**Locale detection**: The reader inspects `docProps/core.xml` for a `<dc:language>` tag and selects separators accordingly:

| Language group | Decimal sep. | Thousands sep. | Column delim. |
|----------------|:---:|:---:|:---:|
| `en`, `ja`, `zh`, `pt-BR`, … | `.` | `,` | `,` |
| `fr`, `de`, `it`, `es`, `nl`, `pl`, `ru`, … | `,` | `.` | `;` |

When no language tag is present the file falls back to `en-US` conventions.

**`metadata.json`**: Always added to the MediaBag alongside the CSVs:

```json
{
    "language": "fr-FR",
    "decimalSeparator": ",",
    "thousandsSeparator": ".",
    "columnDelimiter": ";",
    "quoteCharacter": "\"",
    "sheets": ["Sheet1", "Sheet2"]
}
```

**Utility script**: `export_xlsx_media.php` converts any `.xlsx` file to a ZIP containing its CSVs and `metadata.json`:

```bash
php export_xlsx_media.php spreadsheet.xlsx output.zip
```

### Converting a PowerPoint Presentation to LaTeX

```php
use Pandoc\Reader\PptxReader;
use Pandoc\Writer\LatexWriter;

$reader = new PptxReader();
$writer = new LatexWriter();

$doc   = $reader->read('presentation.pptx');
$latex = $writer->write($doc, standalone: true);
```

Each slide is wrapped in a `slide` environment (with the slide title as argument), and all slides are enclosed in a `slider` environment:

```latex
\begin{slider}

\begin{slide}{Slide Title}
Paragraph content here.
\end{slide}

\begin{slide}{Second Slide}
More content.
\end{slide}

\end{slider}
```

These are custom environments — define them in your LaTeX preamble to control rendering. All images (including slide master/template graphics) are extracted into the MediaBag.

Embedded videos are exported as a `video` environment:

```latex
\begin{video}
\url{media1.mp4}
\type{mp4}
\end{video}
```

Embedded audio is exported as an `audio` environment:

```latex
\begin{audio}
\url{recording.mp3}
\end{audio}
```

All media files (images, video, audio) are included in the ZIP output alongside the `.tex`.

### Converting BibTeX to LaTeX

```php
use Pandoc\Reader\BibtexReader;
use Pandoc\Writer\LatexWriter;

$reader  = new BibtexReader();
$writer  = new LatexWriter();

$content = file_get_contents('references.bib');
$doc     = $reader->read($content);

// standalone: false → bibliography block only, no \documentclass preamble
$fragment = $writer->write($doc, standalone: false);
file_put_contents('references.tex', $fragment);
```

The output is a self-contained `thebibliography` block:

```latex
\begin{thebibliography}{99}

\bibitem{Smith2020}
\emph{A Great Title}, John Smith, Journal of Examples, 2020

\end{thebibliography}
```

- HTTP/HTTPS URLs are automatically wrapped in `\url{…}`.
- The `title`, `booktitle`, `journal`, `series`, and `publisher` fields are italicised with `\emph{…}`.
- The `keywords` and `abstract` fields are silently omitted from the output.
- BibTeX output is always produced as a fragment (`standalone: false`); the web interface enforces this automatically.

### Converting Jupyter Notebooks to LaTeX

```php
use Pandoc\Reader\IpynbReader;
use Pandoc\Writer\LatexWriter;

$reader = new IpynbReader();
$writer = new LatexWriter();

$json  = file_get_contents('notebook.ipynb');
$doc   = $reader->read($json);
$latex = $writer->write($doc);
```

### Converting LaTeX to EPUB

Turns a LaTeX source (an `article`, `report` or `book` using common packages) into an EPUB 3 file with real MathML equations, numbered sections, figures, tables and theorems, working cross-references (`Fig. 3`, `Section A.1`), footnotes, a bibliography and embedded images.

Requirements: PHP 8.4 with `ext-zip` and `ext-dom`. [`latex2mathml-php`](https://packagist.org/packages/latex2mathml/latex2mathml-php) (MathML) is installed automatically by Composer.

#### From PHP

```php
require 'vendor/autoload.php';

use Pandoc\Reader\LatexReader;
use Pandoc\Writer\EpubWriter;

// Pass the path of the .tex file: images, \input files and \cover are looked up next to it.
$doc = (new LatexReader())->read('/projects/paper/paper.tex');

(new EpubWriter())->writeToFile($doc, '/projects/paper/paper.epub');
```

To send the file from a web script instead of saving it:

```php
$epub = (new EpubWriter())->write($doc);          // binary string

header('Content-Type: application/epub+zip');
header('Content-Disposition: attachment; filename="paper.epub"');
header('Content-Length: ' . strlen($epub));
echo $epub;
```

If you give `read()` a string of LaTeX instead of a path, there is no folder to search; pass the image folder to the writer: `new EpubWriter(['resourcePath' => ['/projects/paper']])`.

#### From the command line

```bash
php latex2epub.php paper.tex                       # writes paper.epub next to it
php latex2epub.php paper.tex out.epub --cover=cover.png --split=2 --toc-depth=2
```

The project must be unzipped first (the reader does not open archives), with the images beside the `.tex` file or in a `\graphicspath` folder. The web upload form in `web/` does not offer EPUB yet.

#### Preparing the LaTeX source

*   **Metadata**: `\title{…}`, `\author{A \and B}`, `\date{…}` and a `babel` language (`\usepackage[french]{babel}`) fill the EPUB metadata and title page.
*   **Cover**: add `\cover{file.png}` to the preamble (extension optional, resolved like `\includegraphics`). The image is shown centred at the top of the title page and declared as the EPUB cover. No `\cover`, no cover. If the same source is also compiled with LaTeX, add `\providecommand{\cover}[1]{}` so LaTeX ignores the command.
*   **Bibliography**: BibTeX is **not** run and `\bibliography{…}` is ignored. Put the generated `thebibliography` environment (the contents of the `.bbl` file) in the source; `\cite` commands then become links to the entries.
*   **Images**: PNG, JPEG, GIF, SVG and WebP are embedded unchanged. PDF/EPS figures cannot be used in an EPUB; convert them to PNG or SVG.
*   **Macros**: `\newcommand` / `\def` definitions are expanded; packages are not loaded, and unknown commands are skipped rather than failing.

#### Options

Pass them to the writer: `new EpubWriter(['splitLevel' => 2, 'tocDepth' => 2])`.

| Option | Default | Meaning |
|---|---|---|
| `splitLevel` | `1` | Headings of this level or higher start a new chapter file |
| `tocDepth` | `3` | Deepest heading level in the table of contents |
| `numberSections` | `true` | Show section numbers in headings and the table of contents |
| `titlePage` | `true` | Generate a title page (title, authors, date, cover) |
| `coverImage` | none | Path of a cover image; overrides the document's `\cover` |
| `language` | from `babel`, else `en-US` | BCP 47 language tag |
| `identifier` | derived UUID | `dc:identifier` of the book |
| `css` | built-in | Replacement stylesheet contents |
| `resourcePath` | `['.']` | Folders searched for images not found next to the `.tex` |
| `mathml` | `true` | Convert math to MathML (falls back to the TeX source if a formula cannot be converted) |
| `optimizeImages` | `false` | Opt-in image compression with `ext-imagick` (CPU-heavy: about 12 s for 40 MB of images). Uses `maxImageWidth` (`1600`) and `jpegQuality` (`85`) |

#### What to expect

Measured on an Apple M4 Max with images embedded unchanged: about 1 second for a 3,000-line survey with 20 figures (the EPUB is about the size of the images). Peak memory is 2 to 3 times the total image size, so allow headroom in `memory_limit` for very large projects.

The output has been checked for well-formed XML, working internal links and MathML, but not with a strict validator such as [epubcheck](https://www.w3.org/publications/epubcheck/); run it before distributing a book. See [SUPPORTED_STRUCTURES.md](SUPPORTED_STRUCTURES.md#latex-tex-support-and-epub-3-output) for the full list of supported and unsupported LaTeX constructs.

## Output: Plain `.tex` or `.zip`

When a document contains images, charts, or other media, you need to bundle them alongside the `.tex` file. The `MediaBag` tells you whether there are any attachments:

```php
use Pandoc\Reader\ReaderFactory;
use Pandoc\Writer\LatexWriter;

$reader = ReaderFactory::createForExtension('docx'); // or xlsx, pptx, etc.
$doc    = $reader->read($filePath);
$latex  = (new LatexWriter())->write($doc, standalone: true);

if (!$doc->mediaBag->isEmpty()) {
    // Bundle .tex + all media into a ZIP
    $zip = new ZipArchive();
    $zip->open('output.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('document.tex', $latex);
    foreach ($doc->mediaBag->getAll() as $filename => $media) {
        $zip->addFromString($filename, $media['contents']);
    }
    $zip->close();
    // → distribute output.zip
} else {
    // No media — plain .tex is sufficient
    file_put_contents('document.tex', $latex);
}
```

All media files (images, chart JSON/CSV) are stored at the **root of the ZIP**, so `\includegraphics{image.png}` and chart references resolve correctly when the `.tex` is compiled or processed from the same directory.

## Web Interface

The project includes a web-based demonstration tool in `web/`.

1. Point your web server to the `php-pandoc/web/` folder.
2. Open `index.html` in your browser.
3. Upload a `.docx`, `.xlsx`, `.pptx`, `.html`, `.ipynb`, `.md`, or `.bib` file.
4. Choose Standalone or Fragment output.
5. Download the result — a plain `.tex` if the document has no media, or a `.zip` if it does.

## Supported Structures

See [SUPPORTED_STRUCTURES.md](SUPPORTED_STRUCTURES.md) for a full feature list. Highlights:

- **Word**: Headers (H1–H6, Title), bold/italic/underline/strikeout/color, lists, tables, images, headers & footers, hyperlinks, footnotes/endnotes, math equations (OMML → LaTeX), automatic run-merging.
- **Excel**: Multi-sheet tables, cell formatting, embedded images, Chart.js-ready chart extraction, per-sheet CSV export with locale-aware separators.
- **PowerPoint**: Slide titles, body text, bullet/ordered lists, images, tables, `slide`/`slider` LaTeX environments.
- **HTML**: Full block and inline element support.
- **Jupyter**: Markdown cells, code blocks, output images.
- **BibTeX**: Entries rendered as a `thebibliography` environment with `\bibitem` items; URLs wrapped in `\url{…}`, title/journal/booktitle/series/publisher fields italicised with `\emph{…}`, and `keywords`/`abstract` fields omitted.

## Development and Testing

```bash
./vendor/bin/phpunit
```

## Credits

This project is a port of [Pandoc](https://github.com/jgm/pandoc), originally created by John MacFarlane.

## License

GPL v2 or later, mirroring the original Pandoc license.
