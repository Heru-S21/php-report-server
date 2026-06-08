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

### In Progress
- (none)

### Blocked
- (none)
