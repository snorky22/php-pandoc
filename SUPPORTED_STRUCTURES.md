# Supported Structures in Pandoc PHP Port

This document details the document structures that are currently detected, parsed, and handled by the native PHP 8.4 port of Pandoc. The implementation follows the modular architecture of the original Haskell source, separating low-level extraction from high-level AST conversion through a unified `ReaderInterface`.

## Microsoft Word (Docx) Support
### 1. Document Structure & Sections
*   **Paragraphs**: Standard text paragraphs are detected and converted to `Para` or `Plain` AST nodes.
*   **Headers**: 
    *   Supports `Heading 1` through `Heading 6` styles.
    *   Detects headers by style name (case-insensitive) and style ID.
    *   Supports the `Title` style, mapping it to a Level 1 Header.
*   **Recursive Body Parsing**: Handles document bodies, table cells, and nested structures recursively.
*   **Headers & Footers**: Content from all header and footer parts in the DOCX is extracted and appended to the main document body.

### 2. Text Styling (Inlines)
The reader detects direct formatting and character styles applied to text runs (`w:r`):
*   **Bold**: Detected via `w:b`.
*   **Italic**: Detected via `w:i`.
*   **Underline**: Detected via `w:u`.
*   **Strikeout**: Supports both single (`w:strike`) and double strikeout (`w:dstrike`).
*   **Superscript**: Detected via `w:vertAlign[@w:val='superscript']`.
*   **Subscript**: Detected via `w:vertAlign[@w:val='subscript']`.
*   **Text Color**: Detected via `w:color`. Rendered in LaTeX using the `xcolor` package and `\textcolor`.
*   **Background Color**: Detected via `w:shd` (shading) and `w:highlight`. Rendered in LaTeX using the `xcolor` package and `\colorbox`.
*   **Automatic Space Handling**: Correctly identifies and preserves spaces between formatting changes.

### 3. Lists
The converter implements a list state machine to group sequential paragraphs into unified list blocks:
*   **Bullet Lists**: Grouped into `BulletList` nodes.
*   **Ordered Lists**: Grouped into `OrderedList` nodes with associated `ListAttributes`.
*   **Nesting**: Supports basic detection of indentation levels (`w:ilvl`).
*   **Heuristic Detection**: Uses `numId` transitions to identify separate list instances.

### 4. Tables
Comprehensive table support mirroring Pandoc's complex table model:
*   **Structure**: Detects rows (`w:tr`) and cells (`w:tc`).
*   **Headers**: Automatically treats the first row of a table as a header row.
*   **Cell Content**: Supports full block-level parsing within cells (e.g., paragraphs or lists inside tables).
*   **Table Bodies**: Groups rows into `TableBody` structures.

### 5. Styles & Inheritance
*   **Style Map**: Parses `word/styles.xml` to build a map of available styles.
*   **Style Resolution**: Implements a recursive `basedOn` resolver to handle Word's style inheritance hierarchy (e.g., a "Custom Header" based on "Heading 1" will be correctly identified as a Header).

### 6. Images and Media
*   **Extraction**: Automatically extracts images from the DOCX ZIP archive, including those in headers and footers.
*   **Mapping**: Uses relationship mapping (`_rels/*.xml.rels`) for each part to correctly link internal relationship IDs to media files, even when IDs collide across parts.
*   **Organization**: Media files are stored in the `MediaBag` using their base filename.
*   **LaTeX Output**: Images are referenced using `\includegraphics{filename}` (no directory prefix) and wrapped in a custom `\pandocbounded` macro that handles scaling to prevent page overflow.

### 7. Currently Simplified or In Progress
*   **Math (MathML/OMML)**: Detection of `m:oMath` blocks is defined in the low-level parser; conversion to `Math` AST nodes is being aligned with `texmath` logic.
*   **Quotations**: Paragraphs with `Quote` or `Intense Quote` styles are mapped to `BlockQuote` blocks.
*   **Code Blocks**: Paragraphs with `Source Code` or `Verbatim` styles are converted to `CodeBlock` nodes.

### 8. Robustness Improvements
*   **Error Handling**: The Docx parser gracefully handles missing or malformed optional parts (like `styles.xml` or `numbering.xml`).
*   **XML Parsing**: Uses standardized `DOMXPath` setup with namespace registration and error suppression for resilient parsing of varied Word XML outputs.
*   **Resource Management**: Ensures file handles (like `ZipArchive`) are properly closed even when parsing errors occur.

### 9. Technical Implementation Note
All detected structures are mapped to immutable PHP 8.4 `readonly` classes defined in the `Pandoc\AST` namespace, ensuring that the intermediate representation is strictly compatible with Pandoc's universal document model.

## HTML Support
The HTML reader uses `DOMDocument` to parse HTML and maps elements to the Pandoc AST:
*   **Headers**: `<h1>` through `<h6>`.
*   **Blocks**: `<p>`, `<blockquote>`, `<pre>`, `<hr>`, `<div>`.
*   **Lists**: `<ul>`, `<ol>`, `<li>`.
*   **Tables**: `<table>`, `<thead>`, `<tbody>`, `<tfoot>`, `<tr>`, `<th>`, `<td>`. Heuristic detection of header rows if `<thead>` is missing.
*   **Inlines**: `<b>`/`<strong>`, `<i>`/`<em>`, `<u>`, `<s>`/`<strike>`/`<del>`, `<sup>`, `<sub>`, `<a>`, `<img>`, `<code>`/`<kbd>`/`<samp>`/`<var>`, `<span>`, `<br>`.
*   **Attributes**: Preservation of `id` and `class` attributes in `Attr` objects.

## Jupyter Notebook (Ipynb) Support
The Jupyter reader parses the notebook JSON and maps cells to the Pandoc AST, wrapping each in a `Div` with appropriate classes:
*   **Markdown Cells**: Parsed using the `MarkdownReader` and wrapped in a `Div` with the `markdown` class.
*   **Code Cells**: Converted to `CodeBlock` nodes and wrapped in a `Div` with the `code` class.
*   **Raw Cells**: Converted to `RawBlock` nodes.
*   **Images & Media**: 
    - **Attachments**: Extracts and bundles images attached to markdown cells.
    - **Output Images**: Automatically detects and extracts image outputs (plots, diagrams) from code cells, inserting them as `Image` nodes in the AST.
*   **Metadata**: Basic cell metadata is extracted and stored in the `Div`'s `Attr` object.
*   **Source Handling**: Correctly handles both string and array-of-strings source formats.
