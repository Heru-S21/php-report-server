# PHP Reporting Server

A self-contained web-based reporting application with a visual drag-and-drop report designer. Create, preview, and export reports as HTML or PDF.

## Table of Contents

- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Features](#features)
- [Architecture](#architecture)
- [Key Concepts](#key-concepts)
- [Page Settings](#page-settings)
- [Embedded Fonts](#embedded-fonts)
- [Theme Support (Dark Mode)](#theme-support-dark-mode)
- [PDF Engines](#pdf-engines)
- [Encryption](#encryption)
- [Report Categories](#report-categories)
- [Settings Manager](#settings-manager)
- [Routes](#routes)
- [External Access](#external-access)
- [Expression Evaluator](#expression-evaluator)
- [Database Connections](#database-connections)
- [Output Formats](#output-formats)

## Requirements

- PHP 8.2+
- SQLite (internal storage) + any PDO-compatible database for report queries
- Composer

## Quick Start

```bash
composer install
php -S localhost:8080 index.php
```

Open `http://localhost:8080` in your browser.

The database is auto-created at `data/reporting.sqlite` on first run.

For embedding reports in external apps (iframe, fetch, PHP library mode, proxy pattern), see [`EMBED.md`](EMBED.md).

## Features

### 1. Visual Report Designer

Drag-and-drop elements onto band-based report canvas. Add labels, fields, aggregates, images, lines, rectangles, page numbers, row numbers, date/time, **barcodes/QR codes**, and **page count** elements. Position elements absolutely in mm coordinates with snap-to-grid, resize handles, and live canvas preview.

### 2. Multi-Select, Alignment, Distribution & Arrange

- **Ctrl+Click** to select multiple elements — the previously selected element is auto-included
- **Align** toolbar: left, right, top, bottom, horizontal center, vertical center
- **Distribute** toolbar: evenly space elements horizontally or vertically across their span
- **Arrange** toolbar: pack elements sequentially left-to-right with a 1-grid-unit gap
- Toolbar buttons stay disabled until ≥2 elements are selected

### 3. Undo / Redo

Full undo/redo history stack (Ctrl+Z / Ctrl+Shift+Z) with 100-snapshot depth. Deep-clones the entire report definition, bands, and element selection at every mutation. Proper branching — new edits after an undo replace the redo branch.

### 4. Barcode & QR Code Elements

Add barcode or QR code elements to reports using `picqer/php-barcode-generator`. Supported symbologies:

| Symbology | Notes |
|-----------|-------|
| Code 128 | Default; variable length, alphanumeric |
| Code 39 | Variable length, alphanumeric |
| EAN-13 | 13-digit product barcode |
| EAN-8 | 8-digit product barcode |
| UPC-A | 12-digit product barcode |
| UPC-E | 6-digit compressed UPC |
| QR Code | Square matrix, numeric+alphanumeric+byte+kanji |
| PDF417 | Stacked linear, large capacity |
| Data Matrix | 2D matrix, small footprint |
| Codabar | Older format, 16-char max |
| MSI | Modified Plessey, 14-char max |
| Pharmacode | Pharmaceutical, narrow-only bars |

Configure symbology, value expression, and show-text toggle in the element properties panel. Barcodes render as `<img>` tags in both HTML and PDF output.

### 5. Enhanced Parameters

SQL placeholders (`:paramName`) are auto-detected with extended parameter types:

| Type | UI |
|------|----|
| `text` | Text input |
| `number` | Number input |
| `date` | Date picker |
| `boolean` | Checkbox |
| `dropdown` | `<select>` with static options |
| `multi-select` | Checkbox group (values stored as comma-separated string) |

**Cascading** — set `dependsOn` so a parameter's value list is filtered by another parameter (configured in the designer's Parameter Editor).

### 6. Subtotal & Grand Total Wizards

Right-click any **field** element on the canvas and choose **Add Subtotal** or **Add Grand Total**. This automatically:

- Creates a Group Footer (subtotal) or Report Footer (grand total) band
- Creates an aggregate element with `sum([fieldName])` expression
- Positions the element to match the source field's location

### 7. Conditional Visibility

Every element has an **Advanced** tab with a **Conditional Expression** field. This is a boolean expression evaluated per data row:

```
[amount] > 1000
[status] == "overdue"
[total] > 500 && count([items]) > 3
```

- `true` → element renders normally
- `false` → element is hidden (not rendered)
- Empty → element always shows

### 8. Conditional Style

The **Conditional Style** field accepts a JSON object of style overrides applied when the **Conditional Expression** evaluates to `true`:

```json
{"color":"#ff0000","bold":true}
{"backgroundColor":"#fef2f2","bold":true,"color":"#dc2626"}
```

| Key | Type | Description |
|-----|------|-------------|
| `color` | string | Text color (hex) |
| `backgroundColor` | string | Background color |
| `bold` | boolean | Bold text |
| `italic` | boolean | Italic text |
| `fontSize` | number | Font size in points |
| `fontFamily` | string | Font family name |
| `textAlign` | string | `left`, `center`, `right` |
| `verticalAlign` | string | `top`, `middle`, `bottom` |

Only specified keys are overridden; all other styles remain unchanged.

### 9. Word Wrap

Elements have a **Word Wrap** toggle (default: off) in the Advanced tab. When enabled:

- Text auto-wraps within the element width
- The element's height auto-grows on the canvas to fit estimated lines
- Band height expands to accommodate wrapped elements
- Page breaks respect the expanded heights

### 10. Right-Click Context Menu

Right-click any canvas element for:

- **Copy** — copies element to clipboard
- **Cut** — copies and removes element
- **Paste** — pastes clipboard element at offset position
- **Duplicate** — clones element with offset
- **Delete** — removes element
- **Copy Style** — copies style properties (position, size, font, border, etc.)
- **Paste Style** — applies copied style to selected element

### 11. Export & Import

Report designs can be exported as JSON and re-imported. Exported files include:

- Report name, description, and definition
- Connection name (matched on import)
- **Embedded images** — base64-encoded and auto-restored on import
- **Saved templates** — accessible via File → Save as Template
- **Categories** — category assignments are preserved in exports and matched by name on import

Use the **hamburger menu** (≡) in the designer toolbar or the API endpoints:

```
GET  /api/reports/{id}/export
POST /api/reports/import
```

### 12. Image Library

Upload, browse, and manage images from the designer's image picker. Images stored in `data/images/` with UUID filenames. Supports:

- Upload (JPEG, PNG, GIF, WebP; max size configurable)
- Browse with thumbnails
- Delete (refused if the image is used in any saved report)
- Duplicate detection by SHA256 hash
- Embedded in exported designs (base64) and auto-restored on import

### 13. Font Metrics Cache

Element font metrics (height per font+size+style combination) are cached in the report definition JSON to avoid repeated server-side measurement. Cleared automatically when element styles change. Performance optimization for large reports.

### 14. Auth System (Optional)

Stateless authentication using HMAC-signed tokens (no PHP sessions, no DB token storage).

- **Enable/disable** from the Settings page (DB-stored toggle)
- **Login page** at `/login` with username/password
- **Logout** from the navbar user menu
- Bypass routes (always accessible): `/api/render/*`, `/api/images/file/*`, `/api/auth/login`, `/login`, static assets
- If disabled, all pages are public; if enabled, unauthenticated requests redirect to `/login`
- API requests use `Authorization: Bearer <token>` header; 401 on failure with front-end redirect

## Architecture

```
index.php              — Entry point, requires routes, dispatches via Router
config/app.php         — Application config
src/
  Core/                — Router, Request, Response, Database (PDO singleton), Auth, SettingsManager
  Api/                 — REST controllers for CRUD + rendering
  Report/              — Report definition model, bands, elements, groups, page settings
  Renderer/            — HtmlRenderer, PdfRenderer (mPDF)
  Query/               — Query execution (PDO), visual query builder
  Connection/          — Database connection drivers (MySQL, PostgreSQL, SQLite, SQL Server)
views/                 — PHP view templates (dashboard, designer, preview, etc.)
js/designer/           — Client-side report designer (vanilla JS)
css/                   — Stylesheets (light + dark theme support)
```

API responses include `Access-Control-Allow-Origin: *` headers, enabling cross-origin embedding of reports via iframe or fetch. See [`EMBED.md`](EMBED.md) for CORS configuration guidance.

## Key Concepts

### Report Definition
Reports are defined as JSON, stored in SQLite. The definition includes:
- **Page settings** — paper size, orientation, margins
- **Query** — SQL or visual query definition
- **Parameters** — auto-detected `:paramName` placeholders with types, defaults, cascading
- **Bands** — layout sections (page header, report header, column header, detail, group headers/footers, report footer, page footer)
- **Elements** — drag-and-drop components placed in bands
- **Groups** — data grouping with sort, headers, footers

### Bands
| Band | Purpose |
|------|---------|
| Page Header | Repeats at top of every page |
| Report Header | Renders once at the beginning |
| Column Header | Repeats on each page (table column labels) |
| Group Header | Renders when group value changes |
| Detail | Renders for each data row |
| Group Footer | Renders at end of group |
| Report Footer | Renders once at the end |
| Page Footer | Repeats at bottom of every page |

### Elements
Elements are positioned absolutely within bands using mm coordinates:

| Element | Description |
|---------|-------------|
| Label | Static text or dynamic expression (`[field] > 3 ? "high" : "low"`) |
| Field | Data field from query result |
| Aggregate | SUM, AVG, COUNT, MIN, MAX (group or report scope) |
| Image | From image library or external URL |
| Line | Horizontal rule (positioned by top/left/width) |
| Rectangle | Colored rectangle |
| Page # | Current page number |
| Row # | Row number within dataset (supports group reset) |
| Date/Time | Current date/time with format string |
| Barcode / QR | Barcode or QR code via `picqer/php-barcode-generator` |
| Page Count | Total number of pages in document |

All elements support **conditional visibility** and **conditional style** (see Features 7–8).

### Groups
Data can be grouped by one or more fields. Each group has:
- Group Header band (renders when group value changes)
- Group Footer band (renders at end of group)
- **Reprint Header on New Page** option within each group
- **Reset Row Number** option to restart numbering per group

### Autosave
The designer auto-saves your work to `localStorage` on every change. A red dot on the **Save** button indicates unsaved changes. The **Save** button alone writes to the database; preview and export use the in-memory definition (no save required to preview).

## Page Settings

Reports support configurable page dimensions via the Properties panel:

| Setting | Options |
|---------|---------|
| **Paper Size** | A4 (default), Letter, Legal |
| **Orientation** | Portrait, Landscape |
| **Margins** | Top/Bottom (default 10mm), Left/Right (default 15mm) |

Margins can be overridden per-report in the definition JSON, with defaults from `config/app.php` and runtime overrides from the Settings page.

## Embedded Fonts

The engine supports custom TrueType fonts embedded in rendered output. Font files are stored in `data/fonts/` and managed via:

| Font | Variants |
|------|----------|
| **Envy Code R** | Regular, Bold |
| **Inconsolata** | Regular, Bold |
| **Monaspace Neon Frozen** | Regular, Bold |
| **Share Tech Mono** | Regular |

**Font Controller API:**
- `GET /api/fonts` — list cached fonts and their metadata
- `POST /api/fonts` — reload the font cache (scans `data/fonts/` for TTF/OTF/WOFF/WOFF2)
- `GET /api/fonts/file/{filename}` — serve font files with proper MIME types and 1-year cache headers

**How fonts are embedded in output:**
- **HTML renderer** — injects `@font-face` CSS rules pointing to `/api/fonts/file/{filename}`
- **mPDF renderer** — registers fonts via `fontDir` + `fontdata` config, merged with mPDF's built-in fonts
- **Dompdf renderer** — injects `@font-face` CSS with `file://` paths, adds `data/fonts/` to Dompdf's chroot

The **Element Editor** lists uploaded fonts under an "Uploaded Fonts" group in the font selector. Use **File → Reload Font Cache** in the designer hamburger menu to refresh after adding new `.ttf` files to `data/fonts/`.

Font parsing uses `phenx/php-font-lib` to extract family, weight, and style metadata from TTF files.

## Theme Support (Dark Mode)

The application supports light and dark themes with full CSS coverage:

- **Toggle** — Moon/sun icon in the navbar toggles between light and dark
- **Persistence** — Theme choice is saved to `localStorage` and synced to the server via `PUT /api/settings`
- **CSS Implementation** — `[data-theme="dark"]` selectors override colors for all UI components (navbar, tables, buttons, forms, modals, sidebar, canvas, property panels, query builder, field lists, template cards)
- **CSS Custom Properties** — Both themes define `--color-primary`, `--color-surface`, `--color-bg`, `--color-text`, `--color-hover`, `--font-ui`, `--font-mono`, etc.

Configure the default theme via the Settings page or `app_settings` table.

## PDF Engines

The engine supports two PDF rendering engines, selectable in Settings:

### mPDF (Default)
- Full support for page numbers (`{PAGENO}`) and page count (`{nb}`)
- Custom fonts registered via `fontDir` + `fontdata` configuration
- PCRE backtrack limit increased to 20M for large reports
- UTF-8 mode with multi-byte text support

### Dompdf
- Page numbers via CSS `counter(page)` (page count shows "?" — Dompdf does not support `counter(page-count)`)
- `@font-face` CSS rules with `file://` paths for custom fonts
- Self-heals missing Dompdf installation files (generates `installed-fonts.dist.json` and `.afm.json` cache for 14 core PostScript fonts)
- Remote images enabled, HTML5 parser disabled for performance
- 2-minute timeout for large reports

## Encryption

Database connection passwords are stored encrypted using **AES-256-CBC** with an auto-generated encryption key stored in the application settings table. The encryption method is configurable in `config/app.php`.

Encryption/decryption is handled transparently by the Connection Manager — passwords are encrypted on save and decrypted on use, never exposed in plain text in API responses.

## Report Categories

Reports can be organized into categories for easier navigation:

- **Category Management** — Add, rename, and delete categories via the report list UI or API (`/api/categories`)
- **Collapsible Sections** — Reports are grouped under category headings on the dashboard
- **Inline Assignment** — Change a report's category directly from the report list via dropdown
- **Export/Import** — Category assignments are preserved in exported designs and matched by name on import

## Settings Manager

Application-wide settings are managed via the Settings page (`/settings`) and stored in the `app_settings` table. Available settings include:

| Group | Settings |
|-------|----------|
| Date & Number Format | Date format, decimal places, decimal point, thousands separator |
| Image Upload | Max upload size (default 1 MB) |
| PDF Engine | mPDF or Dompdf |
| Appearance | Theme (light/dark), font family |
| Report Default Margins | Top, bottom, left, right |
| Authentication | Enable/disable, username, password |
| Developer | Debug mode |

Settings are accessed via `GET/PUT /api/settings`.

## Routes

### Web pages
| URL | Description |
|-----|-------------|
| `/` | Dashboard with report list |
| `/reports` | Report listing |
| `/reports/new` | Create new report in designer |
| `/reports/designer/{id}` | Edit report in designer |
| `/reports/preview/{id}` | Preview report (with parameter prompt) |
| `/connections` | Database connection manager |
| `/settings` | Application settings (theme, auth toggle) |
| `/login` | Login page (when auth enabled) |

### API endpoints
| Method | URL | Description |
|--------|-----|-------------|
| GET | `/api/reports` | List reports |
| POST | `/api/reports` | Create report |
| GET | `/api/reports/{id}` | Get report definition |
| PUT | `/api/reports/{id}` | Update report |
| DELETE | `/api/reports/{id}` | Delete report |
| GET | `/api/reports/{id}/export` | Export report design (embeds images) |
| POST | `/api/reports/import` | Import report design (restores images) |
| GET | `/api/render/{id}?format=html\|pdf` | Render report |
| POST | `/api/render/preview` | Render unsaved definition |
| POST | `/api/query/execute` | Run SQL query |
| POST | `/api/query/fields` | Extract columns from SQL |
| GET | `/api/images` | List image library |
| POST | `/api/images/upload` | Upload image (hash-dedup) |
| GET | `/api/images/file/{guid}` | Serve image file |
| DELETE | `/api/images/{id}` | Delete image (refused if in use) |
| PUT | `/api/settings` | Update app settings (theme, auth) |
| GET | `/api/auth/status` | Check if auth is enabled (public) |
| POST | `/api/auth/login` | Login (returns HMAC token) |
| POST | `/api/auth/logout` | Logout (no-op on server, clears client token) |

## External Access

Render reports from external systems via URL (use report GUID for secure access):

```
GET /api/render/{guid}?format=html
GET /api/render/{guid}?format=pdf
GET /api/render/{guid}?format=html&param_status=shipped&param_date=2026-01-01
```

The report GUID is shown as a read-only field in the designer's Report properties. Unlike the numeric ID, GUIDs cannot be guessed sequentially. External access routes bypass auth even when enabled.

## Expression Evaluator

Labels support dynamic content via expressions in the designer's properties panel. Syntax:

```
[fieldName] > 3 ? "more than three" : "less or equal three"
[status] == "active" ? "Active" : "Inactive"
"Order #" + [order_id]
[first_name] + " " + [last_name]
```

- Field references: `[fieldName]` — replaced with value from current data row
- String literals: `"double"` or `'single'` quotes
- Concatenation: `+` joins as strings when either operand is a string; otherwise numeric addition
- Comparators: `>`, `<`, `>=`, `<=`, `==`, `!=`
- Ternary: `condition ? value_if_true : value_if_false`
- Math: `-`, `*`, `/`
- Division by zero returns 0 (silent)
- Parentheses for grouping

### Aggregate Functions in Expressions

Inside **group footer** and **report footer** bands, label expressions can use aggregate functions:

```
count([field_name])   sum([field_name])
avg([field_name])     min([field_name])
max([field_name])
```

Examples:

```
"Total orders: " + count([order_id])
"Average: " + avg([line_total])
customer_count > 1 ? customer_count + " customers" : "1 customer"
```

These resolve to the accumulated aggregate value for the current group or report scope (the element's band determines the scope). The same functions are available in **conditional expressions** within footers.

## Database Connections

Supports MySQL, PostgreSQL, SQLite, and SQL Server via PDO. Connections are managed through the Connections UI and stored encrypted.

## Output Formats

- **HTML** — multi-page output with page breaks, repeating page header/footer and column headers, page numbering, Print button (hidden during print)
- **PDF** — rendered via mPDF (default) or Dompdf with the same page layout, fonts, and styling
