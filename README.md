# Pandoc PHP

A native PHP 8.4 port of the [Pandoc](https://pandoc.org/) document converter. This library allows you to convert documents between different formats (currently focusing on Word `.docx`, HTML `.html`, and Markdown `.md` to LaTeX) without requiring the system-level Pandoc binary.

## Features

- **Native PHP 8.4 Implementation**: Uses modern PHP features like `readonly` classes, Enums, and property hooks.
- **AST-Centric Architecture**: Mirrors Pandoc's Abstract Syntax Tree (AST) for robust and accurate conversions.
- **Modular Reader System**: Uses a factory pattern and unified `ReaderInterface` for easy expansion to new formats.
- **Deep Docx Parsing**: Extracts paragraphs, headers, tables, lists, images/media, and advanced text styling (bold, italic, underline, strikeout, superscript/subscript, and colors).
- **LaTeX Generation**: Produces clean LaTeX code, available as both standalone documents and body fragments.
- **Media Support**: Automatically extracts images from documents and includes them in the AST's `MediaBag`. The web interface bundles these into a ZIP archive alongside the LaTeX source.
- **Improved Robustness**: Resilient Docx parsing that handles malformed XML, missing styles, and relationship collisions (e.g., images in headers/footers).
- **No External Dependencies**: Works purely in PHP 8.4+, making it easy to deploy in shared hosting or restricted environments.

## Installation

Ensure you have PHP 8.4 or higher.

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

// 1. Read the Docx file into an AST
$doc = $reader->read('document.docx');

// 2. Convert AST to LaTeX string (standalone document)
$latex = $writer->write($doc, standalone: true);

file_put_contents('document.tex', $latex);
```

### Converting Markdown to LaTeX Fragment

```php
use Pandoc\Reader\MarkdownReader;
use Pandoc\Writer\LatexWriter;

$reader = new MarkdownReader();
$writer = new LatexWriter();

$markdown = "# Hello World\nThis is a paragraph.";
$doc = $reader->read($markdown);

// Output just the body (no preamble)
$latexFragment = $writer->write($doc, standalone: false);
```

### Converting HTML to LaTeX

```php
use Pandoc\Reader\HtmlReader;
use Pandoc\Writer\LatexWriter;

$reader = new HtmlReader();
$writer = new LatexWriter();

$html = "<h1>Hello</h1><p>World</p>";
$doc = $reader->read($html);
$latex = $writer->write($doc);
```

### Converting Jupyter Notebooks to LaTeX

```php
use Pandoc\Reader\IpynbReader;
use Pandoc\Writer\LatexWriter;

$reader = new IpynbReader();
$writer = new LatexWriter();

$json = file_get_contents('notebook.ipynb');
$doc = $reader->read($json);
$latex = $writer->write($doc);
```

## Web Interface

The project includes a simple web-based demonstration tool in the `web/` directory.

1. Point your web server to the `php-pandoc/web/` folder.
2. Open `index.html` in your browser.
3. Upload a `.docx`, `.html`, `.ipynb` or `.md` file.
4. Choose the output format (Standalone or Fragment).
5. Download the converted `.tex` file. If the document contains images, you will receive a `.zip` archive containing the LaTeX file and all media files in the same directory.

## Supported Structures

For a detailed list of Word document features handled by this port, see [SUPPORTED_STRUCTURES.md](SUPPORTED_STRUCTURES.md). Highlights include:

- **Headers**: Heading 1-6 and Title mapping.
- **Text Styling**: Bold, Italic, Underline, Strikeout, Superscript, Subscript.
- **Colors**: Text color and background (highlight/shading).
- **Lists**: Bulleted and Ordered lists.
- **Images/Media**: Automatic extraction from Word documents, HTML, and Jupyter Notebooks.
- **Headers & Footers**: Extraction of content from Docx headers and footers.
- **Tables**: Multi-body tables with header row detection.
- **Horizontal Rules**: Detection of underscore sequences as rules.

## Development and Testing

The project uses PHPUnit for testing. To run the test suite:

```bash
./vendor/bin/phpunit
```

Tests cover:
- **AST Integrity**: Ensuring immutability and correct structure.
- **Reader/Writer Modularity**: Testing the `ReaderFactory` and interface consistency.
- **Writer Accuracy**: Verifying LaTeX output and character escaping.
- **Reader Reliability**: Testing against "Golden" Docx samples from the original Pandoc repository.

## Credits

This project is a port of [Pandoc](https://github.com/jgm/pandoc), originally created by John MacFarlane.

## License

This project is licensed under the GPL v2 or later, mirroring the original Pandoc license.
