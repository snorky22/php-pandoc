# Supported Structures in Pandoc PHP Port

This document details the document structures that are currently detected, parsed, and handled by the native PHP 8.4 port of Pandoc. The implementation follows the modular architecture of the original Haskell source, separating low-level extraction from high-level AST conversion through a unified `ReaderInterface`.

**Supported input formats:** Word (`.docx`), Excel (`.xlsx`), PowerPoint (`.pptx`), HTML, Markdown, Jupyter Notebook (`.ipynb`), BibTeX (`.bib`)  
**Output format:** LaTeX (standalone document or body fragment)

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
*   **Text Color**: Detected via `w:color`. Rendered in LaTeX using the `xcolor` package and `\textcolor[HTML]{RRGGBB}{…}`. Pure black (`000000`) is suppressed — it is the default text color in LaTeX and its presence in DOCX files is usually spurious.
*   **Background Color**: Detected via `w:shd` (shading) and `w:highlight`. Rendered using `\colorbox`.
*   **Run Merging**: Consecutive `w:r` runs that share identical styling (bold, italic, underline, strikeout, vertical alignment, color, background color) are merged into a single AST inline before LaTeX emission. Whitespace-only runs with no explicit color or background are treated as *neutral* and absorbed into the preceding run, inheriting its style — this corrects the common Word artifact of spaces being stored as unstyled runs between styled words.
*   **Span Merging**: After run conversion, adjacent `Span` nodes with identical attributes (e.g., same `\textcolor`) are collapsed into one, and any `Space` inline between two same-attribute spans is absorbed. This eliminates word-by-word `\textcolor{…}{word}` repetition.
*   **Color Wrapping**: A `\textcolor` command wraps the entire styled content as one group (`\textcolor{…}{\textbf{…}}`), not each word individually.

### 3. Hyperlinks
*   **External links**: `w:hyperlink` elements with an `r:id` relationship pointing to an external URL are converted to:
    *   `\url{…}` — when the visible text is identical to the URL (unnamed link).
    *   `\href{url}{text}` — when the visible text differs from the URL.
*   **Internal anchors**: `w:hyperlink` elements carrying a `w:anchor` attribute (cross-references within the same document) are converted to `\hyperref[anchor]{text}`.
*   **Color stripping**: Hyperlink text is stripped of any explicit color or background-color formatting before conversion, since link styling is typically handled by the consuming application rather than hardcoded in the LaTeX source.

### 4. Footnotes and Endnotes
*   **Footnotes**: `w:footnoteReference` markers in runs are detected. The corresponding footnote body is read from `word/footnotes.xml` and inlined as `\footnote{…}` at the reference point.
*   **Endnotes**: `w:endnoteReference` markers are handled identically, with content read from `word/endnotes.xml`. Both are emitted as `\footnote{…}` (no semantic distinction between foot- and endnotes in the LaTeX output).
*   **Footnote content**: Full inline markup (bold, italic, color, hyperlinks, etc.) within footnote bodies is preserved.

### 5. Lists
The converter implements a list state machine to group sequential paragraphs into unified list blocks:
*   **Bullet Lists**: Grouped into `BulletList` nodes.
*   **Ordered Lists**: Grouped into `OrderedList` nodes with associated `ListAttributes`.
*   **Nesting**: Supports basic detection of indentation levels (`w:ilvl`).
*   **Heuristic Detection**: Uses `numId` transitions to identify separate list instances.

### 6. Tables
Comprehensive table support mirroring Pandoc's complex table model:
*   **Structure**: Detects rows (`w:tr`) and cells (`w:tc`).
*   **Headers**: Automatically treats the first row of a table as a header row.
*   **Cell Content**: Supports full block-level parsing within cells (e.g., paragraphs or lists inside tables).
*   **Table Bodies**: Groups rows into `TableBody` structures.

### 7. Styles & Inheritance
*   **Style Map**: Parses `word/styles.xml` to build a map of available styles.
*   **Style Resolution**: Implements a recursive `basedOn` resolver to handle Word's style inheritance hierarchy (e.g., a "Custom Header" based on "Heading 1" will be correctly identified as a Header).
*   **TOC Filtering**: Paragraphs whose style name matches the `toc *` pattern (e.g., `toc 1` through `toc 9`, including custom style IDs like `TM1`) are silently dropped, preventing auto-generated Tables of Contents from cluttering the LaTeX output.

### 8. Images and Media
*   **Extraction**: Automatically extracts images from the DOCX ZIP archive, including those in headers and footers.
*   **Mapping**: Uses relationship mapping (`_rels/*.xml.rels`) for each part to correctly link internal relationship IDs to media files, even when IDs collide across parts.
*   **Organization**: Media files are stored in the `MediaBag` using their base filename.
*   **LaTeX Output**: Images are referenced using `\includegraphics{filename}` (no directory prefix) and wrapped in a custom `\pandocbounded` macro that handles scaling to prevent page overflow.

### 9. Currently Simplified or In Progress
*   **Math (MathML/OMML)**: Office Math (OMML) elements inside `w:r` runs are not yet converted; the surrounding text of the paragraph is output but math symbols are omitted.
*   **Quotations**: Paragraphs with `Quote` or `Intense Quote` styles are mapped to `BlockQuote` blocks.
*   **Code Blocks**: Paragraphs with `Source Code` or `Verbatim` styles are converted to `CodeBlock` nodes.

### 10. Robustness Improvements
*   **Error Handling**: The Docx parser gracefully handles missing or malformed optional parts (like `styles.xml` or `numbering.xml`).
*   **XML Parsing**: Uses standardized `DOMXPath` setup with namespace registration and error suppression for resilient parsing of varied Word XML outputs.
*   **Resource Management**: Ensures file handles (like `ZipArchive`) are properly closed even when parsing errors occur.

### 11. Technical Implementation Note
All detected structures are mapped to immutable PHP 8.4 `readonly` classes defined in the `Pandoc\AST` namespace, ensuring that the intermediate representation is strictly compatible with Pandoc's universal document model. The run-merge pipeline is table-driven: `STYLE_PROPS` lists the properties that define run identity, and `NEUTRAL_EXEMPT_PROPS` lists the properties that must be absent for a whitespace run to be treated as neutral. Adding a new formatting property (e.g., `fontSize`) to the merge logic requires only a one-line addition to `STYLE_PROPS`.

## Excel (XLSX) Support

The XLSX reader parses the Open Office XML ZIP archive and converts each worksheet into a pair of AST nodes: a level-2 `Header` (sheet name) and a `Table`. The implementation mirrors the Haskell `Text.Pandoc.Readers.Xlsx` module introduced in Pandoc 3.x.

### 1. Archive Parsing
- Opens `.xlsx` files as ZIP archives using PHP's `ZipArchive`.
- Locates `xl/workbook.xml` and its relationship file (`xl/_rels/workbook.xml.rels`) to discover all worksheets in order.
- Uses `local-name()` XPath queries throughout, making parsing robust against namespace variations across Excel versions and third-party generators.

### 2. Shared Strings
- Parses `xl/sharedStrings.xml` into an indexed array for O(1) lookup.
- Handles both simple `<si><t>text</t></si>` entries and rich-text `<si><r><t>…</t></r></si>` entries (text runs are concatenated).

### 3. Cell Styles (Bold / Italic)
- Parses `xl/styles.xml`: extracts the `<fonts>` array and the `<cellXfs>` index that maps each cell's style index to a font.
- Bold and italic flags are propagated to AST inlines via `Strong` and `Emph` wrappers.

### 4. Cell Values
- **Shared string cells** (`t="s"`): resolved via the shared strings table.
- **Numeric cells**: stored as `float`; integer-valued floats are formatted with a `.0` suffix (e.g. `23.0`) to match Pandoc's Haskell `show` convention.
- **Empty cells**: absent from the sparse cell map; rendered as empty `Plain []` in the table grid.
- **Formula cells**: the cached `<v>` value is used (formula text is ignored).

### 5. Table Construction
- Builds a dense row×column grid from the sparse cell map using the minimum and maximum row/column coordinates.
- The **first row is always treated as the table header** (same heuristic as the Haskell implementation).
- **Trailing all-empty rows are stripped** from the body using a `dropWhileEnd` equivalent.
- Empty interior rows (between non-empty rows) are preserved.

### 6. Multi-Sheet Documents
- Each worksheet produces a `Header 2` block (sheet name) followed by its table.
- Sheets are output in workbook order.
- Sheets with no cells produce only the header (no table).

### 7. Unicode
- All text is preserved as-is in UTF-8 (no re-encoding), supporting CJK, Cyrillic, Thai, Arabic, and all Latin-extended scripts.

### 8. Embedded Images
- Embedded images (inserted via Insert → Picture) are detected by traversing sheet drawing relationships (`xl/worksheets/_rels/`, `xl/drawings/_rels/`).
- Image files are extracted into the `MediaBag` and inserted as `Image` AST nodes at the chart's position in the document.

### 9. Chart Extraction
Charts are exported as two companion files added to the `MediaBag`.

**`chartN.json`** — Chart.js-ready metadata:
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

| Field | Description |
|-------|-------------|
| `type` | Chart.js type: `bar`, `line`, `pie`, `doughnut`, `scatter`, `area`, `radar`, `bubble` |
| `title` | Chart title (empty string if none) |
| `dataFile` | Filename of the companion CSV — always `chartN.csv` |
| `options.indexAxis` | `"x"` for vertical bar/column charts, `"y"` for horizontal bar charts |
| `options.scales.x/y.stacked` | `true` if the chart uses stacked series |
| `options.scales.x/y.title.text` | Axis label (empty string if none) |
| `series[].label` | Series name as it appears in the chart legend |

**`chartN.csv`** — data table (categories as first column, one column per series):
```
Category,Product A,Product B
Q1,120,85
Q2,135,90
Q3,128,95
Q4,145,110
```

The `dataFile` field in the JSON always points to the companion CSV, so your app only needs to find the JSON to locate all assets.

A comment marker is emitted in the LaTeX at the chart's position:
```latex
% [pandoc-chart: chart1.json]
```

Data is read from the OOXML cache embedded in the chart XML — no cell-range resolution required.

### 10. Per-Sheet CSV Export and Locale Detection

Each worksheet is exported as a locale-aware CSV file and added to the `MediaBag` alongside any images and charts.

**File naming**: `sheet-{SafeName}.csv` — the sheet name is sanitised (non-alphanumeric characters replaced with `_`).

**Locale detection**: The reader parses `docProps/core.xml` for a `<dc:language>` tag (Dublin Core). The primary language subtag drives separator selection:

| Language group | Decimal | Thousands | Column delimiter |
|----------------|:-------:|:---------:|:----------------:|
| `en`, `ja`, `zh`, `pt-BR`, `es-MX`, … | `.` | `,` | `,` |
| `fr`, `de`, `it`, `es`, `nl`, `pl`, `ru`, `sv`, … | `,` | `.` | `;` |

If no language tag is present, `en-US` conventions are used as the default.

**Trailing-blank trimming**: Trailing all-empty rows and trailing all-empty columns are stripped from the CSV output before writing.

**`metadata.json`**: A single JSON file is always included in the `MediaBag` summarising the document locale and sheet list:

```json
{
    "language": "fr-FR",
    "decimalSeparator": ",",
    "thousandsSeparator": ".",
    "columnDelimiter": ";",
    "quoteCharacter": "\"",
    "sheets": ["Feuille1", "Feuille2"]
}
```

Chart CSVs also use the detected locale separators for consistency.

### 11. Not Yet Supported
- Cell background/foreground colors.
- Merged cells (span > 1 row or column).
- Inline string cells (`t="inlineStr"`).
- Number format codes (dates, currencies, percentages are rendered as raw floats).

## PowerPoint (PPTX) Support

The PPTX reader parses the Office Open XML ZIP archive and converts each slide into a `slide` LaTeX environment. All slides are enclosed in a `slider` environment.

### 1. Slide Structure
- Each slide in `ppt/slides/slideN.xml` is processed in presentation order (from `ppt/presentation.xml`).
- The **title placeholder** (`p:ph type="title"` or `type="ctrTitle"`) becomes the argument to `\begin{slide}{title}`.
- If no title placeholder is found, a fallback `Slide N` label is used.
- Output structure:
```latex
\begin{slider}

\begin{slide}{Slide Title}
  content...
\end{slide}

\end{slider}
```
These are custom environments — define them in your LaTeX preamble to control rendering.

### 2. Text Content
- **Text shapes** (`p:sp`): body text paragraphs are extracted from `p:txBody/a:p`.
- **Text runs** (`a:r`): bold (`b="1"`) and italic (`i="1"`) run properties are mapped to `\textbf{}` and `\emph{}`.
- **Bullet lists**: paragraphs with `a:buChar` or `a:buClr` markers → `BulletList` AST nodes.
- **Ordered lists**: paragraphs with `a:buAutoNum` → `OrderedList` AST nodes.
- **Nesting**: bullet level from `a:pPr/@lvl` is preserved.

### 3. Images, Video, and Audio
- **Slide images** (`p:pic`): extracted via per-slide relationship files (`ppt/slides/_rels/slideN.xml.rels`) and added to the `MediaBag` as `Image` AST nodes.
- **Template/master images**: images in slide masters (`ppt/slideMasters/`) and slide layouts (`ppt/slideLayouts/`) — typically logos or background graphics — are also collected from `ppt/media/` and added to the `MediaBag` so they travel with the ZIP output. They are not inserted into the LaTeX body since their position is layout-defined.
- **Embedded video** (`p:pic` with `a:videoFile` in `nvPr`): detected via the per-slide relationship of type `video`. The file is added to the `MediaBag` and a `video` environment is emitted at the shape's position:
```latex
\begin{video}
\url{media1.mp4}
\type{mp4}
\end{video}
```
Supported formats: `mp4`, `mov`, `webm`, `avi`, `wmv`.
- **Embedded audio** (`p:pic` with `a:audioFile` in `nvPr`): detected via the per-slide relationship of type `audio`. The file is added to the `MediaBag` and an `audio` environment is emitted:
```latex
\begin{audio}
\url{recording.mp3}
\end{audio}
```
Supported formats: `mp3`, `wav`, `ogg`, `aac`, `m4a`, `flac`.

### 4. Tables
- Tables embedded in graphic frames (`p:graphicFrame/a:graphic/a:graphicData/a:tbl`) are converted to Pandoc `Table` AST nodes.
- First row is treated as the header row.
- Cell text is extracted from all `a:t` runs within each cell.

### 5. SmartArt / Charts (Fallback)
- SmartArt diagrams and charts in graphic frames are not rendered.
- All text nodes within the frame are extracted and output as `Para` blocks (text fallback).

### 6. Not Yet Supported
- Slide notes.
- Animations and transitions.
- SmartArt layout fidelity (text is extracted; visual structure is lost).
- Per-slide background colors.

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

## BibTeX (`.bib`) Support

The BibTeX reader parses `.bib` files and converts all entries into a single `\begin{thebibliography}{99}…\end{thebibliography}` block, emitted as a `RawBlock('latex', …)` in the Pandoc AST. The output is always produced as a fragment (`standalone: false`).

### 1. Entry Parsing
- Entries of the form `@type{key, field = value, …}` are detected using a brace-counting algorithm that correctly handles arbitrarily nested braces.
- The entry type (e.g. `article`, `book`, `inproceedings`) is parsed but not emitted — only the cite key and fields are used.
- `@string`, `@preamble`, and `@comment` entries are silently skipped.
- Comment lines beginning with `%` are stripped before parsing.

### 2. Field Value Parsing
- **Brace-delimited values** (`{…}`): brace depth is tracked to support nested braces.
- **Quote-delimited values** (`"…"`): inner braces are tracked to avoid premature termination.
- **Bare tokens**: numbers and macro names are read as-is.
- **Concatenation** (`#`): multiple value parts joined with `#` are concatenated into a single string.
- **Value cleaning**: surrounding case-protection braces (e.g. `{Word}`) are stripped and whitespace is normalised.

### 3. LaTeX Output
Each entry produces a `\bibitem{cite_key}` followed by all field values joined with `, `:

```latex
\begin{thebibliography}{99}

\bibitem{Smith2020}
\emph{A Great Title}, John Smith, \emph{Journal of Examples}, \doi{10.1000/xyz123}, 2020

\end{thebibliography}
```

### 4. Special Field Rendering
- **Italic fields**: `title`, `booktitle`, `journal`, `series`, and `publisher` values are wrapped in `\emph{…}`.
- **URL detection**: values containing `http://` or `https://` URLs have those URLs wrapped in `\url{…}`.
- **DOI detection**: tokens beginning with `10.` (standard DOI prefix) are wrapped in `\doi{…}`.

### 5. Not Yet Supported
- `@string` macro expansion (macro names are emitted as-is).
- Cross-references (`crossref` field).
- Author name formatting (first/last name reordering, "et al." truncation).
