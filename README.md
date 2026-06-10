# PHP Reporting Server

A self-contained web-based reporting application with a visual drag-and-drop report designer. Create, preview, and export reports as HTML or PDF.

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

## Architecture

```
index.php              — Entry point, routes all requests
config/app.php         — Application config
src/
  Core/                — Router, Request, Response, Database
  Api/                 — REST controllers for CRUD + rendering
  Report/              — Report definition model, bands, elements, groups, page settings
  Renderer/            — HtmlRenderer, PdfRenderer (mPDF)
  Query/               — Query execution (PDO), visual query builder
  Connection/          — Database connection drivers (MySQL, PostgreSQL, SQLite, SQL Server)
views/                 — PHP view templates (dashboard, designer, preview, etc.)
js/designer/           — Client-side report designer (vanilla JS)
css/                   — Stylesheets
```

## Key Concepts

### Report Definition
Reports are defined as JSON, stored in SQLite. The definition includes:
- **Page settings** — paper size, orientation, margins
- **Query** — SQL or visual query definition
- **Bands** — layout sections (page header, report header, column header, detail, group headers/footers, report footer, page footer)
- **Elements** — drag-and-drop components placed in bands (label, field, aggregate, image, line, rectangle, page number, row number, date/time)
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
Elements are positioned absolutely within bands using mm coordinates. Types:
- **Label** — static text or dynamic expression (e.g. `[field] > 3 ? "high" : "low"`)
- **Field** — data field from query result
- **Aggregate** — SUM, AVG, COUNT, MIN, MAX (group or report scope)
- **Image** — from image library or external URL
- **Line** — horizontal rule
- **Rectangle** — colored rectangle (placeholder)
- **Page #** — current page number (`{PAGENO}`)
- **Row #** — row number within dataset
- **Date/Time** — current date/time with format string

All elements support **conditional visibility** — set an expression in the properties panel to show/hide elements based on field values.

### Image Library
Upload, browse, and manage images from the designer's image picker modal. Images are stored in `data/images/` with UUID filenames. The library supports:
- Upload (JPEG, PNG, GIF, WebP; max size configurable)
- Browse with thumbnails
- Delete (refused if the image is used in any saved report)
- Duplicate detection by SHA256 hash — uploading the same file returns the existing image
- Embedded in exported report designs (base64) and auto-restored on import

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

## External Access

Render reports from external systems via URL (use report GUID for secure access):

```
GET /api/render/{guid}?format=html
GET /api/render/{guid}?format=pdf
GET /api/render/{guid}?format=html&param_status=shipped&param_date=2026-01-01
```

The report GUID is shown as a read-only field in the designer's Report properties. Unlike the numeric ID, GUIDs cannot be guessed sequentially.

## Parameters

Use `:paramName` placeholders in SQL queries. Parameters are auto-detected in the designer and can be edited with name, type (text/number/date/boolean), and default value. When previewing or exporting, the user is prompted for parameter values. Values are passed as `param_<name>` query parameters to the render endpoint.

## Export & Import

Report designs can be exported as JSON and re-imported later. Exported files include:
- Report name, description, and definition
- Connection name (matched on import)
- **Embedded images** — local images from the image library are base64-encoded into the export and auto-restored on import

Use the **Export Design** and **Import Design** buttons in the designer toolbar, or the API endpoints:

```
GET  /api/reports/{id}/export
POST /api/reports/import
```

Render output formats:
- **HTML** — multi-page output with page breaks based on paper dimensions, page headers/footers repeat on every page, column headers repeat on every page, proper page numbering. A Print button is included in the rendered view (hidden during print).
- **PDF** — rendered via mPDF with the same page layout

## Expression Evaluator

Labels support dynamic content via expressions in the designer's properties panel. Syntax:

```
[fieldName] > 3 ? "more than three" : "less or equal three"
[status] == "active" ? "Active" : "Inactive"
[score] >= 90 ? "A" : [score] >= 80 ? "B" : "C"
"Order #" + [order_id]
[first_name] + " " + [last_name]
```

- Field references: `[fieldName]` — replaced with the value from the current data row
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
count([field_name])
sum([field_name])
avg([field_name])
min([field_name])
max([field_name])
```

Examples:

```
"Total orders: " + count([order_id])
"Average: " + avg([line_total])
customer_count > 1 ? customer_count + " customers" : "1 customer"
```

These resolve to the accumulated aggregate value for the current group or report scope (the element's band determines the scope). The `[field_name]` inside the parentheses refers to the data field name, not its value.

The same aggregate functions are also available in **conditional expressions** within footers.

### Advanced Tab: Conditional Expression

Every element has an **Advanced** tab in the properties panel with a **Conditional Expression** field. This is a boolean expression evaluated for each data row:

```
[amount] > 1000
[status] == "overdue"
[total] > 500 && count([items]) > 3
```

- If the expression evaluates to `true`, the element is rendered normally
- If `false`, the element is **hidden** (not rendered at all)
- Uses the same expression syntax as labels (field references, comparisons, ternary, etc.)
- Leave the field empty for the element to always show

### Advanced Tab: Conditional Style

The **Conditional Style** field accepts a JSON object of style overrides applied when the **Conditional Expression** evaluates to `true`:

```
{"color":"#ff0000","bold":true}
{"color":"#16a34a","fontSize":14,"italic":true}
{"backgroundColor":"#fef2f2","bold":true,"color":"#dc2626"}
```

Supported style keys:

| Key | Type | Description |
|-----|------|-------------|
| `color` | string | Text color (hex, e.g. `#ff0000`) |
| `backgroundColor` | string | Background color |
| `bold` | boolean | Bold text |
| `italic` | boolean | Italic text |
| `fontSize` | number | Font size in points |
| `fontFamily` | string | Font family name |
| `textAlign` | string | `left`, `center`, or `right` |
| `verticalAlign` | string | `top`, `middle`, or `bottom` |

Only the keys specified in the JSON are overridden; all other element styles remain unchanged.

## Database Connections

Supports MySQL, PostgreSQL, SQLite, and SQL Server via PDO. Connections are managed through the Connections UI and stored encrypted.
