# PHP Reporting Engine

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
- **Label** — static text
- **Field** — data field from query result
- **Aggregate** — SUM, AVG, COUNT, MIN, MAX (group or report scope)
- **Image** — external image URL
- **Line** — horizontal rule
- **Rectangle** — colored rectangle (placeholder)
- **Page #** — current page number (`{PAGENO}`)
- **Row #** — row number within dataset
- **Date/Time** — current date/time with format string

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
| GET | `/api/render/{id}?format=html\|pdf` | Render report |
| POST | `/api/render/preview` | Render unsaved definition |
| POST | `/api/query/execute` | Run SQL query |
| POST | `/api/query/fields` | Extract columns from SQL |

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

## Export

- **HTML** — multi-page output with page breaks based on paper dimensions, page headers/footers repeat on every page, column headers repeat on every page, proper page numbering
- **PDF** — rendered via mPDF with the same page layout

## Database Connections

Supports MySQL, PostgreSQL, SQLite, and SQL Server via PDO. Connections are managed through the Connections UI and stored encrypted.
