## Progress

### Done
- Added `guid TEXT` column to `reports` table; migration + backfill
- `ReportRepository::create()`/`duplicate()` generate UUID and return `['id','guid']`
- `findByGuid()` added; controllers accept ID or GUID
- README.md "External Access" section uses `{guid}`
- Navbar: Readme link no longer highlights Reports
- Center section tabbed (Data Source + Report Designer)
- Data Source tab: two-column Connection/Tables + Parameters layout, full-width SQL query, closable result table
- Table list shows collapsible columns with data types
- Table name click toggles columns; play icon generates SELECT query
- Reset button reverts SQL to last saved version
- Group list restores after report load
- Column header band moved after group header band
- `reprintHeaderOnNewPage` shows field values via `$lastRowData`
- Row number resets per group (optional `resetRowNo` property)
- Popup modals restructured: fixed header (colored, reduced height) + scrollable content + fixed footer
- Page counting redefined: both renderers track content Y, break page when band exceeds usable height, reprint active group headers + column header on new page; `pageBreakBefore` support
- Navbar sticky (always visible)
- External access link shown in report General tab (copy + open buttons)
- Aggregate `formatValue` now respects format strings in both renderers
- Element left/top clamped to ≥0 in drag, drop, property editor, and keyboard nudge
- `snapValue` no longer forces minimum 1; allows 0 for position
- Band resize prevents clipping: element-aware minimum
- Band auto-expands when element top/height exceeds it
- Vertical alignment fixed: HtmlRenderer/Designer canvas use flexbox, PdfRenderer adds `vertical-align`
- Horizontal alignment works with vertical align middle/bottom via `justify-content`
- HTML `<title>` set to report name in rendered output
- **Band/element borders**: fixed `window.borderEditor` orphan instance (checkboxes non-functional); fixed `{}`→`[]`→`[]` PHP json round-trip corruption (borders saved as array named-properties → silently dropped by `JSON.stringify`); added `Array.isArray()` guard in BorderEditor for self-healing
- **Image Display property**: added `imageDisplay` prop (Original/Stretch/Proportional) on image elements; dropdown in ElementEditor, `object-fit` CSS in renders, `fit` option in PdfRenderer
- **Toolbar redesign**: removed `btn-sm` from toolbar buttons, reordered: Save, Preview, Export, Import, Save as Template, Export PDF, Export HTML
- **Zoom control**: dropdown from 25% to 200% (was 50–150%)
- **Image delete guard**: `ImageController::delete()` checks `ReportRepository::findByImageGuid()` — refuses if image is used in any saved report
- **Export embeds images**: `ReportController::export()` base64-encodes local images into `_embeddedImages` in the exported JSON
- **Import auto-adds images**: `ReportController::import()` extracts `_embeddedImages`, saves to `data/images/`, creates library entries, rewrites `imageGuid` refs
- **Hash-based dedup on upload**: `ImageRepository::findByHash()` + SHA256 stored in `images.hash` column; duplicate upload returns existing record
- **Expression evaluator**: new `src/Report/ExpressionEvaluator.php` — parses `[fieldRef]` substitutions, ternary ops, comparators, math, logical ops; wired into label rendering and conditional visibility in both HtmlRenderer and PdfRenderer
- **Conditional visibility**: all elements get `visibilityExpression` property; designers shows input in ElementEditor (replaces old static "condition" field)
- **Max upload size setting**: `/settings` page with `app_settings` table; `max_upload_size` configurable in MB; `ImageController` and designer JS read it; displayed in designer image picker
- **Print button**: shown in standalone/external HTML output (hidden during print via `@media print` + `.no-print`); hidden in preview page (controlled by `no_print` query param)
- **Auto-print script**: `<script>` auto-clicks print button when URL contains `?print`; only included when print button is shown
- **README.md sync**: removed duplicate export section; kept content current
- **Local marked.js**: downloaded `marked@15.0.7` to `js/marked.min.js`, updated `views/reports/readme.php` to use local copy instead of CDN

### In Progress
- (none)

### Blocked
- (none)
