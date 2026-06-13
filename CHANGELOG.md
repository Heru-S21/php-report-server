# Changelog

All notable changes to this project are documented here.

## [Unreleased]

### Major
- README updated with all features documented
- Fix distribute icons for Phosphor v1.4.2 compat, add Arrange Horizontal button
- Ctrl+Click multi-select includes previously selected element
- Canvas click handler scoped to `.designer-center` (theme toggle no longer deselects)

### Minor
- Multi-select DOM class sync (`syncMultiSelect`)
- Escape key handler calls `selectReport()` instead of undefined `deselectAll()`
- Multi-select outline color changed from blue to green

## [1.0.0] — 2026-06-13

### Major

#### Initial Project Foundation
- Core framework: Router, Request, Response, Database (PDO singleton)
- Connection layer: DriverInterface + MySQL/PostgreSQL/SQLite/MSSQL drivers with AES-256 encryption
- Report data model: ReportDefinition, Band, BandElement, GroupDefinition, AggregateDefinition, BorderDefinition, PageSettings, ReportRepository
- Renderer: HtmlRenderer + PdfRenderer (mPDF)
- API controllers: Connection, Report, Query, Render with full CRUD
- Frontend: App state management, Designer, BandManager, ElementEditor, BorderEditor, GroupEditor, AggregateEditor, DragDrop, QueryDesigner
- Views: Layout, dashboard, designer, preview, connections, settings UI with CSS
- Database schema: reports, connections, app_settings tables with WAL mode, foreign keys

#### GUID-Based Report Identifiers
- Reports get both numeric ID and GUID for secure external access
- External render URLs use GUID instead of guessable sequential IDs
- Read-only GUID display in report properties

#### Image Library
- Upload (JPEG, PNG, GIF, WebP) with configurable max size
- SHA256 hash-based duplicate detection — re-uploading same file returns existing image
- Browse with thumbnails, delete with usage check (refused if used in saved report)
- Base64 embed on export, auto-restore on import
- Image display modes: Original, Stretch, Proportional

#### Expression Evaluator
- Field references: `[fieldName]` replaced with current data row value
- Ternary: `condition ? "yes" : "no"`
- Comparators: `>`, `<`, `>=`, `<=`, `==`, `!=`
- Math: `+`, `-`, `*`, `/` (division by zero returns 0 silently)
- String concatenation via `+` when either operand is a string
- Aggregate functions in footer expressions: `count()`, `sum()`, `avg()`, `min()`, `max()`
- Conditional style JSON: color, backgroundColor, bold, italic, fontSize, fontFamily, textAlign, verticalAlign

#### Autosave & Dirty Indicator
- Every edit auto-saves to `localStorage`
- Red dot on Save button when unsaved changes exist
- Dirty flag persisted across page reloads
- Preview uses POST with in-memory definition (no save required)
- Opening designer checks `localStorage` draft first (preferred over DB)

#### Stateless Auth System
- HMAC-signed tokens, no PHP sessions, no DB token storage
- Login page at `/login` with username/password
- Toggle on/off from Settings page (DB-stored `auth_enabled`)
- Bypass routes: `/api/render/*`, `/api/images/file/*`, `/api/auth/login`, `/login`, static assets
- API uses `Authorization: Bearer <token>` header with 401 redirect

#### Word Wrap Auto-Grow
- Word wrap toggle (default: `false` in both BandElement and UI)
- `estimateTextHeight()` calculates line count from average char width
- `calculateEffectiveBandHeight()` aggregates lowest element bottom per band
- Canvas `fixWordWrapHeights()` post-processing on preview
- Page breaks respect expanded heights

#### Right-Click Context Menu
- Copy, Cut, Paste, Duplicate, Delete
- Copy Style, Paste Style (position, size, font, border)
- `window.clipboard` stores separate `element` and `style` entries

#### Page Count Element
- `pagecount` type with `{{PAGECOUNT}}` placeholder in HtmlRenderer
- `{nb}` placeholder in PdfRenderer
- Replaced during page assembly loop (`count($pages)`)

#### Feature 1: Font Metrics Cache
- `ReportDefinition.fontMetrics` cached in definition JSON
- `measureFontMetrics()` returns cache when available
- Cleared on any element mutation (add, remove, paste, style change)

#### Feature 4: Visibility Expressions
- `BandElement::$visibleExpression` (string) evaluated per row
- Skip-render check in both HtmlRenderer and PdfRenderer
- Fail-open on syntax error (treated as visible)
- UI input in ElementEditor Advanced tab

#### Feature 7: Subtotal / Grand Total Wizards
- Right-click field element → Add Subtotal or Add Grand Total
- Auto-creates Group Footer (subtotal) or Report Footer (grand total) band
- Creates aggregate element with `sum([fieldName])`
- Matches source field's position

#### Feature 3: Alignment & Distribution Tools
- Multi-select with Ctrl+Click
- `alignElements()`: left, right, top, bottom, horizontal center, vertical center
- `distributeElements()`: horizontal, vertical (even spacing across span)
- Toolbar buttons disabled until ≥2 elements selected
- CSS dashed outline for multi-selected elements

#### Feature 2: Undo/Redo History Stack
- Single `history` array + index with proper branching
- Deep-clone snapshots of `{def, bands, selectedElement}`
- 100-entry cap, 20+ mutation points instrumented
- Ctrl+Z undo, Ctrl+Shift+Z redo

#### Feature 8: Barcode / QR Code Element Type
- `picqer/php-barcode-generator ^3.2` installed
- Supported symbologies: Code128, Code39, EAN-13, EAN-8, UPC-A, UPC-E, QR Code, PDF417, Data Matrix, Codabar, MSI, Pharmacode
- `BarcodeRenderer` outputs PNG data URI and SVG
- Rendered as `<img>` tag in both HTML and PDF output
- Editor UI: symbology selector, value expression, show-text toggle

#### Feature 5: Enhanced Parameter UI
- Parameter types: `text`, `number`, `date`, `boolean`, `dropdown`, `multi-select`
- Static options textarea (one per line: `value,Label`)
- `dependsOn` cascading (parent param filters child options)
- Preview renders `<select>` for dropdown, checkbox group for multi-select
- Multi-select values stored as comma-separated string

#### Hamburger Menu
- Export, Import, Save as Template, Export PDF, Export HTML moved to hamburger dropdown
- Styled with `btn btn-primary` matching Save button
- Dark mode hover CSS variable (`--color-hover`) added to both themes

### Minor

#### Designer & Canvas
- Grid overlay (dots via radial-gradient), snap-to-grid, configurable grid size
- Zoom controls with DPI-coordinated coordinate math for element move/resize
- Band auto-expand when element top/height exceeds it
- Element left/top clamped to ≥0 on drag, drop, and save
- Element width/height minimum of 1
- Corner resize handles (6px) with proper positioning and visibility
- Uniform default element size: 50×10mm for all types
- Report-level default style with element inheritance
- Object tree panel with event delegation selection
- Right panel converted to vertical tabs (Properties, Style, Groups)
- Left panel converted to vertical tabs with table browser
- Collapsible sidebar with localStorage persistence
- Query builder: connection selector, SQL editor, field extraction, table browser, collapsible table columns
- Table name click generates SELECT query, separate icon for EXPLAIN
- Reset button to revert query to last saved version

#### Preview & Rendering
- Multi-page HTML export with page breaks based on paper dimensions
- Page setup UI: paper size, orientation, margins
- Print button shown on external access (`/api/render/{id}`), hidden in preview (`no_print` param)
- Text-overflow ellipsis via child `<span>` for flex compatibility
- HTML title set to report name
- Vertical alignment: flexbox for HtmlRenderer, `vertical-align` for PdfRenderer
- Horizontal alignment: `justify-content` in flex mode for middle/bottom vertical align
- Aggregate number formatting respects format option in both renderers
- Column header band auto-added to loaded reports that lack it
- Skip rendering bands with no children
- Row number element (`rowno`) with group-reset option
- Fixed reprinted group headers showing field values (uses `$lastRowData`)
- Column header band renders after Group Header on page breaks
- Page counting: calculated from paper height + all report sections per page

#### Navigation & UI
- Navbar converted from sidebar: brand icon as toggle, tabs for navigation
- Navbar sticky (always visible on scroll)
- Active tab indicator on left border
- Vertical stacked tab labels with `writing-mode: sideways-lr`
- Modals: fixed header/footer with colored header, scrollable body
- External access link shown in report properties (General tab)
- Font list split into Standard Fonts and System Fonts sections
- Default background set to transparent for all bands and elements
- Transparent background button next to color picker
- Page header/footer default to light red (`#fee2e2`), other bands distinguished by color
- Auto-focus Name field in New Report modal
- Dashboard report count and status table
- Hide connection password when editing

#### API & Backend
- POST `/api/render/preview` for rendering unsaved definitions
- GET/POST/PUT/DELETE for reports, connections, images
- Settings CRUD at `/api/settings`
- Import preserves images (base64 → file), connection matched by name
- Settings: report name auto-generation, image max size, first-day-of-week
- Bootgrid-compatible settings with light/dark theme stored in DB

### Fixes

- Border JSON round-trip corruption: PHP `json_decode` with `true` produces objects `{}`, re-encoding named-property objects yields `[]` — `Array.isArray()` guard in BorderEditor
- AggregateAccumulator passed to ExpressionEvaluator in footer bands for aggregate function resolution
- Field elements in footer bands render via AggregateAccumulator last-value tracking
- GUID missing from state after loading `localStorage` draft
- Preview opens in current tab (not new tab) to preserve designer state
- Text alignment rendering: `text-align` added to inner `<span>`
- Properties tab scrolling: `.properties-tabs` outside `.panel-section`, `.properties-scroll` wrapper
- Preview showed unsaved edits in new tab without losing designer state
- Element resize: missing `startX`/`startY` in mousedown handler
- Arrow keys moving element when editing property inputs
- Dotted table names broken by PHP built-in server path info stripping
- Page break calculation in HtmlRenderer
- Border rendering in PdfRenderer
- MSSQL schema detection
- SnapValue allowed ≥0, not ≥1 (let elements sit at 0)
- Band resize prevented below element bounds
- Group list not restored after report load
- Navbar active state tracking
- `UNIQUE` constraint on `guid` column — SQLite limitation for ALTER TABLE
- Center section restructured into tabs with redesigned Data Source layout
- `SELECT` icon: `ph-play`, always visible
- Fire event on first group only when `reprintHeaderOnNewPage`
- Font metrics `estimateTextHeight` avgCharWidth increased from 0.5 to 1.2
- Word wrap default `false` for all elements (not `null` → falsey)
- Print button hidden in preview, shown on external access
- Auth redirect loop when enabled via Settings page
- Auth bypass list includes `/api/images/file/`
- Label expression string concatenation: `+` joins as strings when either operand is a string
