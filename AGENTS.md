# PHP Reporting Engine — Agent Guide

## Quick start
```bash
composer install
php -S localhost:8080 index.php
# Open http://localhost:8080
```

SQLite DB auto-creates at `data/reporting.sqlite` on first request (WAL mode, `foreign_keys=ON`).

## No tests / no CI
No test framework, no test directory, no CI. No linter, formatter, or typechecker config. Validate manually — run `php -l` on edited files.

## Stack
- **PHP 8.2+** with PSR-4 autoload: `ReportingEngine\` → `src/`
- **No PHP framework** — custom Router, Request, Response, Database (PDO singleton)
- **Vanilla JS** — no framework, no bundler (JS lives in `js/`)
- **mPDF** for PDF output
- **Phpactor** language server configured in `opencode.json` (PHPStan enabled, Psalm disabled)

## Reference files
- `design.md` — full design spec (may not match current implementation exactly)
- `ANCHORED_SUMMARY.md` — recent change log; read on first session

## Architecture
```
index.php                — Entry point, requires routes, dispatches via Router
src/routes.php           — API routes (JSON controllers)
src/web_routes.php       — View routes (render PHP templates)
config/app.php           — App config (debug, paths, encryption, defaults)
```

### Key source layout
| Directory | Purpose |
|-----------|---------|
| `src/Api/` | REST controllers (CRUD + render, query, connections) |
| `src/Core/` | Router, Request, Response, Database singleton |
| `src/Report/` | Report model (Definition, Band, Element, Group, Aggregate, Border, Repository) |
| `src/Renderer/` | HtmlRenderer + PdfRenderer (mPDF) |
| `src/Query/` | QueryRunner, QueryParser, VisualQueryBuilder |
| `src/Connection/` | DriverInterface + MySQL/PostgreSQL/SQLite/MSSQL drivers |

### Routes accept ID or GUID
Controllers use `resolveReport()` or inline `is_numeric()` — passes both numeric IDs and GUIDs to `findByGuid()`.

### Report definition
Full report JSON stored in `reports.definition` column. Bands, elements, groups, page settings, query all in one JSON blob.

### Designer layout
Center section has two tabs: **Data Source** (connection/query) and **Report Designer** (band canvas).

## Code conventions
- PHP namespace: `ReportingEngine\` → `src/`
- API responses: `Response::json($data, $status, $message)` / `Response::error($message, $status)` — always returns `{success, data, message, errors}`
- Controllers: method signature `(Request $request): Response`, return Response objects
- Views: PHP templates in `views/`, rendered via `Response::view('layout', ['content' => 'reports/designer', ...])`

## PHP built-in server quirk
The dev server drops dots from path info (e.g. `table.name` becomes `tablename`). Avoid relying on dots in URL segments, or work around the built-in server's path handling (`$_SERVER['PATH_INFO']` stripping).

## Important gotchas
- `config/app.php` has `'debug' => false` by default — switch to `true` during development for error visibility
- `data/reporting.sqlite`, `data/reporting.sqlite-wal`, `data/reporting.sqlite-shm` are gitignored
- No `.env` loading — config is plain PHP
- `composer.lock` is gitignored (listed in `.gitignore`)
- `vendor/`, `data/`, `src/` blocked from direct HTTP access via `.htaccess`
- **Border JSON round-trip**: PHP `json_decode` with `true` produces objects `{}`, but re-encoding named-property objects can yield `[]` arrays. BorderEditor has `Array.isArray()` guards. If borders fail to serialize, this is the likely cause.
- **Element positions**: left/top clamped to ≥0 everywhere. Band resize is element-aware to prevent clipping. Band auto-expands when element top/height exceeds it.
- **Group rendering**: Column Header band renders after Group Header band on page breaks. `reprintHeaderOnNewPage` relies on `$lastRowData` for field values.
- **Row numbers**: support `resetRowNo` property to reset numbering within each group.
