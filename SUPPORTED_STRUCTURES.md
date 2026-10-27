# Supported Structures in Pandoc PHP Port

This document details the document structures that are currently detected, parsed, and handled by the native PHP 8.4 port of Pandoc. The implementation follows the modular architecture of the original Haskell source, separating low-level extraction from high-level AST conversion through a unified `ReaderInterface`.

**Supported input formats:** Word (`.docx`), Excel (`.xlsx`), PowerPoint (`.pptx`), HTML, Markdown, Jupyter Notebook (`.ipynb`), BibTeX (`.bib`), LaTeX (`.tex`), OpenDocument text (`.odt`)  
**Output formats:** LaTeX (standalone document or body fragment), EPUB 3 and ODT (from the AST of any reader, in practice LaTeX)

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

### 9. Math Equations (OMML → LaTeX)
Office Math (OMML) equations — `m:oMath` (inline) and `m:oMathPara` (display) — are converted to native `Math` AST nodes (`InlineMath` / `DisplayMath`) by a dedicated `Pandoc\Reader\Docx\OmmlToLatex` converter, and rendered by the LaTeX writer as `$…$` / `\[…\]` (and by the HTML writer as MathJax-ready `\(…\)` / `\[…\]`).
*   **Detection**: `m:oMath` appearing directly inside a paragraph is treated as inline math; `m:oMathPara` (a paragraph containing only an equation) is treated as display math.
*   **Structural elements supported**: runs and text (`m:r`/`m:t`), superscript/subscript/both (`m:sSup`/`m:sSub`/`m:sSubSup`), pre-scripts (`m:sPre`), fractions (`m:f`, including the "linear" `a/b` style), radicals with an optional degree (`m:rad`), n-ary operators with limits (`m:nary` — integral, sum, product, union, intersection, etc.), delimiters with custom opening/closing/separator characters (`m:d`), functions (`m:func`/`m:fName`), limit expressions (`m:limLow`/`m:limUpp`), matrices (`m:m`), equation arrays (`m:eqArr`), over/underlines (`m:bar`), over/underbraces and arrows (`m:groupChr`), and accents (`m:acc`).
*   **Function names**: recognized names (`sin`, `cos`, `ln`, `log`, `lim`, `max`, `min`, `gcd`, …) are emitted as their LaTeX macro (`\sin`, `\log`, …); unrecognized names fall back to `\operatorname{…}`.
*   **Symbol mapping**: a Unicode → LaTeX table covers Greek letters, arithmetic and relational operators, set theory and logic symbols, arrows, calculus symbols (`∞`, `∇`, `∂`, `…`), and primes, so equations typed with Word's Unicode math autocorrect render as proper LaTeX commands rather than raw Unicode.
*   **Escaping**: literal characters that are special in LaTeX (`\`, `{`, `}`, `$`, `&`, `#`, `%`, `_`, `^`, `~`) are escaped when they appear as plain text inside an equation.
*   **Known limitation**: OMML sometimes omits explicit spaces around literal connective words (e.g. Word can store "a=0 or b=0" with no space characters around `or`); the converter faithfully transcribes the source text, so such words may appear glued to adjacent tokens (`a=0orb=0`) in the LaTeX output.

### 10. Quotations and Code Blocks
*   **Quotations**: Paragraphs with `Quote` or `Intense Quote` styles are mapped to `BlockQuote` blocks.
*   **Code Blocks**: Paragraphs with `Source Code` or `Verbatim` styles are converted to `CodeBlock` nodes.

### 11. Robustness Improvements
*   **Error Handling**: The Docx parser gracefully handles missing or malformed optional parts (like `styles.xml` or `numbering.xml`).
*   **XML Parsing**: Uses standardized `DOMXPath` setup with namespace registration and error suppression for resilient parsing of varied Word XML outputs.
*   **Resource Management**: Ensures file handles (like `ZipArchive`) are properly closed even when parsing errors occur.

### 12. Technical Implementation Note
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
\emph{A Great Title}, John Smith, \emph{Journal of Examples}, 2020

\end{thebibliography}
```

### 4. Special Field Rendering
- **Italic fields**: `title`, `booktitle`, `journal`, `series`, and `publisher` values are wrapped in `\emph{…}`.
- **URL detection**: values containing `http://` or `https://` URLs have those URLs wrapped in `\url{…}`.

### 5. Not Yet Supported
- `@string` macro expansion (macro names are emitted as-is).
- Cross-references (`crossref` field).
- Author name formatting (first/last name reordering, "et al." truncation).

## LaTeX (`.tex`) Support and EPUB 3 Output

`LatexReader` reads *vanilla* LaTeX directly (it is not a TeX engine); `EpubWriter` (with `XhtmlWriter`) turns the AST into an EPUB 3 package. Unknown commands are ignored and unknown environments become a `Div` whose class is the environment name, so a document never fails to convert.

### 1. Source Handling
*   **Input**: a source string or a file path. `\input` / `\include` are inlined (relative to the file).
*   **Comments** (`%`) are stripped, except inside `verbatim`, `lstlisting` and `minted`.
*   **Macros**: `\newcommand`, `\renewcommand`, `\providecommand`, `\DeclareRobustCommand` and `\def` (with `#1`…`#9` arguments and one optional default argument) are expanded textually.
*   **Preamble**: `\title`, `\author` (split on `\and`), `\date`, `\cover`, the `babel` / `polyglossia` language (stored as `lang`) and `\graphicspath`. `\thanks` content is dropped.

### 2. Structure
*   **Sectioning**: `\part`, `\chapter`, `\section` … `\subparagraph`, starred forms (unnumbered). With `\chapter` the levels shift down by one. Heading ids come from `\label`, otherwise from the title.
*   **Numbering**: sections (including `\appendix` lettering, `A`, `A.1`), figures, tables, algorithms, equations and theorems are numbered like LaTeX. Headings carry a `number` attribute and captions are prefixed (`Figure 3:`, `Table 1:`, `Algorithm 2:`).
*   **Cross-references**: `\ref`, `\eqref`, `\autoref`, `\cref`, `\Cref`, `\vref`, `\nameref`, `\hyperref`, `\hyperlink` / `\hypertarget` become internal links (`Fig. 3`, `Section A.1`, `(4)`, `Theorem 2.1`); sub-figure labels give `3a`. Unknown labels are shown as `[label]`.
*   **Environments**: `quote` / `quotation` / `verse`, `center` / `flushleft` / `flushright` / `minipage` / `multicols` (transparent), `abstract`, `proof`, any `\newtheorem` environment (run-in heading such as `Lemma 1.2 (Name).`, shared counters and `[section]` resetting), `thebibliography`.

### 3. Text (Inlines)
*   **Styling**: `\emph`, `\textit`, `\textbf`, `\textsc`, `\texttt`, `\underline`, `\sout`, `\textsuperscript`, `\textsubscript`, group declarations such as `{\bfseries …}` / `{\em …}`, `\enquote`, `\textcolor` and `\colorbox` (rendered through `color` / `background-color` span attributes).
*   **Typography**: `--`, `---`, TeX quote ligatures (opening and closing single/double quotes), `~`, `\,`, `\ `, accents (`\'e`, `\"o`, `\c{c}`, `\v{s}`, …), `\ss`, `\ae`, `\ldots`, `\LaTeX`, `\copyright`, and escaped specials.
*   **Links**: `\href`, `\url`.
*   **Code**: `\verb`, `\lstinline`; blocks via `verbatim`, `lstlisting` (language option) and `minted`.
*   **Footnotes**: `\footnote` (collected at the end of each EPUB chapter, with back-links).
*   **Citations**: `\cite` and variants (`\citep`, `\citet`, `\autocite`, …) link to the matching `\bibitem` as `[n]`; keys without a bibliography entry show as `[key]`.

### 4. Lists, Tables, Floats
*   **Lists**: `itemize`, `enumerate` (numbering style and delimiter from the optional label, default style per nesting depth), `description`; tight lists are detected.
*   **Tables**: `tabular`, `tabularx`, `tabular*`, `longtable`; column alignment (`l c r`, `p{}`, `@{}`, `*{n}{…}`), `\multicolumn` (colspan), `\multirow` (content only), booktabs and `\hline` header-row detection, `\endhead` / `\endfirsthead`, rule and colour commands (`\cmidrule`, `\cellcolor`, …) ignored.
*   **Floats**: `figure`, `table`, `wrapfigure`, `sidewaysfigure` / `sidewaystable`, with `\caption`, `\label` and `subfigure` / `subtable` (letters `(a)`, `(b)`).
*   **Algorithms**: `algorithm` with `algorithmic` / `algpseudocode` bodies (`\STATE`, `\IF` / `\ELSIF` / `\ELSE`, `\FOR` / `\FORALL`, `\WHILE`, `\REPEAT` / `\UNTIL`, `\REQUIRE`, `\ENSURE`, `\RETURN`, `\COMMENT`, `\FUNCTION` …) rendered as numbered, indented lines with bold keywords.
*   **Images**: `\includegraphics` with `width` / `height` (including fractions of `\textwidth`); files are searched through `\graphicspath` with or without extension and loaded into the `MediaBag` (PNG, JPEG, GIF, SVG, WebP).

### 5. Math
*   Inline (`$…$`, `\(…\)`) and display (`$$…$$`, `\[…\]`) math, and the environments `equation`, `align`, `alignat`, `flalign`, `gather`, `multline`, `eqnarray`, `split`, `displaymath`, `math`. Every `\label` becomes an anchor; single-line `equation` shows its number.
*   The AST keeps the raw TeX. `XhtmlWriter` converts it to MathML with `latex2mathml/latex2mathml-php` (`\arg` is mapped to `\operatorname{arg}`); a formula that fails to convert falls back to `<span class="math">` with the TeX source.

### 6. EPUB 3 Package
*   **Layout**: `META-INF/container.xml`, `EPUB/content.opf`, `nav.xhtml` (nested table of contents), `toc.ncx` (EPUB 2 readers), one XHTML file per chapter, `styles/stylesheet1.css`, `media/`. `mimetype` is stored first and uncompressed.
*   **Chapters** are split at heading level `splitLevel` (default 1); the table of contents lists levels down to `tocDepth` (default 3); front matter before the first heading gets its own entry. `numberSections` (default on) shows section numbers.
*   **Metadata**: title, authors, date (normalised to ISO 8601), language, a deterministic `urn:uuid` identifier (overridable) and `dcterms:modified`. Pages containing MathML get `properties="mathml"`.
*   **Title page**: title, authors and date, with the cover image centred above them when `\cover{file}` (or the `coverImage` option) is set. Without a title the cover gets a page of its own. No `\cover`, no cover. The image is also declared as the EPUB cover image.
*   **Links and resources**: `#id` links are rewritten to `chapter.xhtml#id` across files; images are embedded unchanged (missing or remote images fall back to their alt text). Optional Imagick compression (`optimizeImages`, off by default) downsizes to `maxImageWidth` and re-encodes opaque photographic images as JPEG.

### 7. Not Yet Supported
*   Author affiliations from `\thanks`; equation numbers inside multi-line environments (`align`, `eqnarray`) are not displayed (references still resolve); `\cellcolor` / `\rowcolor` shading; side-by-side layout of subfigures (they are stacked); `\multirow` row spans; `\pageref`; custom `\newenvironment` definitions; conditionals, counters and other programmable TeX; PDF/EPS figures (not valid EPUB images); `\cite` styles other than numeric.

## OpenDocument Text (`.odt`) Output

`OdtWriter` writes the AST produced by `LatexReader` as an ODF 1.3 text document (pure PHP: a zip of `content.xml`, `styles.xml`, `meta.xml`, `META-INF/manifest.xml`, `Pictures/` and one `Object N/` per formula). The `mimetype` entry is first and stored uncompressed.

### 1. Text and Structure
*   **Headings**: `text:h` with `text:outline-level` 1–6 (styles *Heading 1…6*); the section number from the reader is written as text.
*   **Paragraphs and quotes**: *Text body*, *Quotations*, *Abstract*; a paragraph holding only a display formula is centred; a paragraph holding only pictures is centred.
*   **Inline**: emphasis, strong, underline, strikeout, small caps, super/subscript, inline code, colours and background colours of spans, quotation marks, line breaks.
*   **Links**: external links, internal links to bookmarks (cross-references, citations, bibliography entries), bookmarks for every label.
*   **Footnotes**: real `text:note` footnotes, numbered consecutively.
*   **Lists**: bulleted and numbered lists with format (`1`, `a`, `A`, `i`, `I`), prefix/suffix (`1.`, `1)`, `(1)`) and start value, nested up to six levels; definition lists as term and indented definition paragraphs.
*   **Code**: preformatted paragraphs in a monospace style, keeping runs of spaces, tabs and line breaks.
*   **Metadata**: title, authors, date, language; the language is also set as the document default.

### 2. Tables, Figures, Algorithms
*   **Tables**: column and row spans, header rows (repeated on page breaks), alignment per cell, booktabs-style rules, captions above the table. Column widths use auto layout: at least the longest word of the column, the rest of the 16 cm text width shared in proportion to the amount of text.
*   **Figures**: images embedded in `Pictures/` with width/height from the `\includegraphics` options (percentages relative to the text width, capped at the page), caption below with the figure kept together with its caption. PNG, JPEG, GIF, SVG and WebP.
*   **Sub-figures** are laid out side by side in borderless table rows (each cell as wide as its panel; a new row starts when the next panel does not fit). The panel width comes from the `subfigure` argument, else from the picture's width.
*   **Algorithms**: caption plus one indented paragraph per pseudocode line, with bold keywords.
*   **Cover**: the `\cover` image is centred above the title block.

### 3. Math
*   Every formula becomes an embedded MathML object (`draw:object` → `Object N/content.xml`), editable in LibreOffice. The frame carries **no** size, so LibreOffice sizes the formula itself (a declared size would stretch or shrink it).
*   The MathML is adjusted for LibreOffice (`MathMl::forOffice`): runs of upright letters and multi-letter operator names are merged (otherwise rendered letter-spaced); plain bars `|x|`, `|g|_\infty` are paired into fences; a column of an aligned equation that starts with a relation gets an empty left operand, and a cell holding only a relation or nothing is replaced by text or an empty group; a lone operator used as a script (`J^{*}`, `\mathbb{R}_{+}`) and brackets that do not pair up (`[a,b)`) become literal text.
*   With `mathml => false`, or when a formula cannot be converted, the TeX source is written as monospace text.

### 4. Verified and Not Yet Supported
*   Verified by opening the files in LibreOffice 26.8 (headless rendering in the test-suite, plus visual inspection of the four sample papers). Not verified in Microsoft Word, Apple Pages or Google Docs.
*   About 0.6% of the distinct formulas of the sample papers still show LibreOffice's red `¿` marker (constructs such as `\bigl\lfloor … \bigr\rceil`, `\lVert … \rVert` combined with other fences, some nested `\boldsymbol` sums, `\tilde\alpha_{\mathrm{…}}`).
*   Not supported: automatic outline numbering (numbers are text), a generated table of contents or list of figures/tables, page headers, `\multirow` row spans, side-by-side layout of figures other than sub-figures, `\cellcolor` shading, author affiliations from `\thanks`, page size or margin options.



## OpenDocument Text (`.odt`) Input

`OdtReader` reads ODF 1.x text documents written by LibreOffice, Apache OpenOffice or this library's `OdtWriter` (`content.xml`, `styles.xml`, `meta.xml`, `Pictures/`, `Object N/` for formulas), and `LatexWriter` writes the result as `pdflatex`-ready LaTeX.

### 1. Text and Structure
*   **Styles**: character formatting is resolved through the style inheritance chain, so only what differs from the paragraph's own style becomes `Strong`/`Emph`/`Underline`/`Strikeout`/`Superscript`/`Subscript`/`SmallCaps`/`Code`/coloured span (a Heading style that is bold does not wrap every heading in `Strong`). Direct formatting of a whole paragraph (an automatic paragraph style) is applied; adjacent runs with the same formatting are merged.
*   **Whitespace**: runs of spaces, tabs and line feeds collapse as ODF specifies; `text:s` and `text:tab` become spaces; preformatted paragraphs keep their spaces, tabs and line breaks.
*   **Headings**: `text:h` with `text:outline-level` (and paragraphs in a "Heading N" style); empty headings are dropped; headings that sit inside shapes or text boxes (Word "text shapes" converted by LibreOffice) are recovered. Manual numbers are detected and removed as described in the README.
*   **Front matter**: `Title`, `Author` and `Date` paragraphs before the body become metadata; `meta.xml` supplies title, creator and language when there is no such paragraph. The file's own creation/modification dates are not used as the document date. A leading picture described as "Cover" becomes the cover image.
*   **Skipped**: tables of contents and other generated indexes, tracked changes, comments, soft page breaks, page numbers and other fields (their text is kept when it is meaningful, e.g. caption numbers).

### 2. Lists, Quotes, Code
*   **Lists**: bullet and numbered lists with the format of each level (`1`, `a`, `A`, `i`, `I`), prefix and suffix (`1.`, `1)`, `(1)`) and start value, nested; list styles are looked up per level.
*   **Quotes and code**: "Quotations" paragraphs become block quotes; consecutive "Preformatted Text" paragraphs one code block; "Definition Term"/"Definition" paragraphs a definition list; "Abstract" paragraphs an abstract; a horizontal-line paragraph a rule.

### 3. Tables, Figures, Cross-references
*   **Tables**: header rows, rows, column and row spans (`covered-table-cell` is skipped), repeated empty cells, cell alignment taken from the first paragraph; a caption paragraph before or after the table is attached to it.
*   **Figures**: pictures are read from `Pictures/` into the `MediaBag` with their width (as a percentage of the text width when it fits); a paragraph starting with a label such as "Figure 3:", "Table 2:", "Illustration 1:" (English, French, German, Spanish) is a caption, whose label is removed because LaTeX numbers it; a figure's bookmark may sit on the picture or on the caption. A row of pictures in a borderless table, with optional "(a)" captions, becomes sub-figures; consecutive rows are merged into one figure.
*   **Links**: external links; links to bookmarks (a link whose text is a number becomes a reference, a link to `ref-…` a citation); LibreOffice's `bookmark-ref`/`reference-ref`/`sequence-ref` fields; bookmarks become ids. Bibliography entries (`ref-key` bookmarks) become a `thebibliography`.
*   **Footnotes and endnotes**: `text:note` with its paragraphs.
*   **Lists of headings**: Word numbers headings through a list, so LibreOffice writes `text:h` inside `text:list-item`; such a list is dissolved into its headings and paragraphs (nested ones too).
*   **Broken links**: a link to a bookmark that does not exist keeps its text and is written without `\\ref` or `\\hyperref`.

### 4. Math
*   Each formula object is read from its `Object N/content.xml`: an `application/x-tex` annotation is used as it is, otherwise the MathML is converted to LaTeX (`MathmlToLatex`): tokens, scripts, fractions, roots, accents and over/under-scripts, big operators with limits, matrices (with the surrounding parentheses becoming `pmatrix`, `bmatrix`, …), equation arrays (`aligned`), `\left … \right` from stretchy fences, function names, Greek letters and the Letterlike/Mathematical Alphanumeric blocks (`\mathbb`, `\mathcal`, `\mathfrak`, …), named and numeric spaces. A brace-balance repair handles producers that leave a stray `}`.
*   On the formulas of four test papers (1,596 distinct formulas) none failed to convert and 98.5% kept the same content after LaTeX → MathML → LaTeX → MathML.

### 5. LaTeX Writer Additions
*   `\label` only for linked ids (headings, captioned figures and tables, equations, paragraphs); `\ref` for links to numbered things, `\hyperref` otherwise (so a reference to a theorem keeps its own text), `\cite{a,b}` for grouped citation links; labelled display formulas as `equation` with `\tag` so that the number shown in the document is kept; `\appendix` before the first lettered heading.
*   Figures (`figure`, `subfigure` with `subcaption`), tables (caption, `l`/`c`/`r` columns, `\multicolumn`, booktabs rules), `description` lists, `thebibliography`, `abstract`, footnotes, title block (`\title`/`\author`/`\date`/`\maketitle`), language through `babel`, `\cover{…}`.
*   Characters that `pdflatex` rejects in text are mapped (see the README), including Unicode superscripts and subscripts (`Fe²⁺`, `H₂O`) as `\\textsuperscript`/`\\textsubscript` and the end-of-proof boxes; LibreOffice's empty-argument placeholder `❑` in a formula becomes `{}`; pictures in formats `pdflatex` cannot read become a visible `[image: name]` box.

### 6. Verified and Not Yet Supported
*   Verified by compiling the generated LaTeX with `pdflatex` (TeX Live 2026): the four sample papers (up to 113 pages) and eight files that LibreOffice wrote from real Word documents (a 6 MB review with chemistry notation and pictures, maths notes with Word equations, three converters' versions of one paper) give no error and no undefined reference; and by reading the LaTeX back: for the sample papers the headings, tables, figures, images, formulas, notes and links match those of the original source, and 97–100% of the words are identical.
*   Not supported: theorem-like environments and algorithm structure (their text is kept), `\multirow`, per-row equation numbers, EMF/WMF/SVG/GIF pictures (a visible `[image: name]` box is written), comments and tracked changes, shapes other than text boxes, page layout, and styles as custom LaTeX classes.

