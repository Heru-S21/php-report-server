# PHP Reporting Engine — Complete Design Specification
> **Version:** 1.0  
> **Target:** AI agents building the full application  
> **Stack:** PHP 8.2+, SQLite (internal), HTML/CSS/JS (vanilla), no framework required

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Directory Structure](#2-directory-structure)
3. [Internal Database Schema (SQLite)](#3-internal-database-schema-sqlite)
4. [Database Connection Layer](#4-database-connection-layer)
5. [Core Engine Classes](#5-core-engine-classes)
6. [Report Data Model](#6-report-data-model)
7. [Visual Report Designer UI](#7-visual-report-designer-ui)
8. [Report Sections & Bands](#8-report-sections--bands)
9. [Grouping Engine](#9-grouping-engine)
10. [Aggregate Functions](#10-aggregate-functions)
11. [Border Properties System](#11-border-properties-system)
12. [Visual Query Designer](#12-visual-query-designer)
13. [SQL Query Editor](#13-sql-query-editor)
14. [Report Renderer (HTML/PDF)](#14-report-renderer-htmlpdf)
15. [REST API Endpoints](#15-rest-api-endpoints)
16. [Frontend Architecture](#16-frontend-architecture)
17. [Security Considerations](#17-security-considerations)
18. [Build & Deployment](#18-build--deployment)
19. [Sample Report JSON Schema](#19-sample-report-json-schema)
20. [Implementation Checklist](#20-implementation-checklist)

---

## 1. Project Overview

**PHP Reporting Engine** is a self-contained web application that allows users to:

- Connect to **SQLite, MySQL, Microsoft SQL Server, and PostgreSQL** databases.
- Design reports using a **drag-and-drop visual band/section designer**.
- Write or visually design **SQL queries** as the data source.
- Define **group-by fields** with collapsible group headers/footers.
- Apply **aggregate functions** (SUM, AVG, COUNT, MIN, MAX) per group or globally.
- Style each band with **border properties** (top, bottom, left, right — color, width, style).
- Save all settings, connections, and report definitions in an **internal SQLite database**.
- Preview and export reports as **HTML** or **PDF** (via mPDF or TCPDF).

### Technology Choices

| Layer | Technology |
|---|---|
| Backend language | PHP 8.2+ |
| Internal storage | SQLite 3 via PDO |
| External DB drivers | PDO_SQLITE, PDO_MYSQL, PDO_SQLSRV, PDO_PGSQL |
| Frontend | Vanilla HTML5/CSS3/JavaScript (ES2020) |
| PDF export | mPDF 8.x (composer) |
| Code editor widget | CodeMirror 6 (CDN) |
| Drag-and-drop | SortableJS (CDN) |
| Icons | Phosphor Icons (CDN) |

---

## 2. Directory Structure

```
php-reporting-engine/
├── composer.json
├── composer.lock
├── index.php                         ← Entry point / router
├── .htaccess                         ← mod_rewrite rules
│
├── config/
│   └── app.php                       ← App-level config (paths, debug)
│
├── data/
│   └── reporting.sqlite              ← Internal SQLite DB (auto-created)
│
├── src/
│   ├── Core/
│   │   ├── Router.php
│   │   ├── Request.php
│   │   ├── Response.php
│   │   └── Database.php              ← Internal SQLite PDO singleton
│   │
│   ├── Connection/
│   │   ├── ConnectionManager.php     ← CRUD for saved connections
│   │   ├── DriverInterface.php
│   │   ├── SqliteDriver.php
│   │   ├── MysqlDriver.php
│   │   ├── MssqlDriver.php
│   │   └── PgsqlDriver.php
│   │
│   ├── Report/
│   │   ├── ReportDefinition.php      ← Hydrates JSON → PHP object
│   │   ├── ReportRepository.php      ← CRUD for reports in SQLite
│   │   ├── BandCollection.php
│   │   ├── Band.php
│   │   ├── BandElement.php           ← Label, Field, Aggregate, Image, Line
│   │   ├── GroupDefinition.php
│   │   ├── AggregateDefinition.php
│   │   └── BorderDefinition.php
│   │
│   ├── Query/
│   │   ├── QueryRunner.php           ← Executes SQL on target connection
│   │   ├── QueryParser.php           ← Parses SQL for field extraction
│   │   └── VisualQueryBuilder.php    ← Builds SQL from visual query JSON
│   │
│   ├── Renderer/
│   │   ├── RendererInterface.php
│   │   ├── HtmlRenderer.php
│   │   └── PdfRenderer.php           ← mPDF wrapper
│   │
│   └── Api/
│       ├── ConnectionController.php
│       ├── ReportController.php
│       ├── QueryController.php
│       └── RenderController.php
│
├── public/
│   ├── css/
│   │   ├── app.css
│   │   ├── designer.css
│   │   └── query-designer.css
│   ├── js/
│   │   ├── app.js
│   │   ├── designer/
│   │   │   ├── Designer.js           ← Main designer controller
│   │   │   ├── BandManager.js
│   │   │   ├── ElementEditor.js
│   │   │   ├── GroupEditor.js
│   │   │   ├── AggregateEditor.js
│   │   │   ├── BorderEditor.js
│   │   │   └── DragDrop.js
│   │   └── query-designer/
│   │       ├── QueryDesigner.js
│   │       ├── TableJoinCanvas.js
│   │       └── FieldSelector.js
│   └── img/
│
├── views/
│   ├── layout.php
│   ├── dashboard.php
│   ├── connections/
│   │   ├── index.php
│   │   └── edit.php
│   ├── reports/
│   │   ├── index.php
│   │   ├── designer.php              ← Main designer view
│   │   └── preview.php
│   └── partials/
│       ├── navbar.php
│       └── sidebar.php
│
└── vendor/                           ← Composer dependencies
```

---

## 3. Internal Database Schema (SQLite)

All schema must be created automatically on first run via `Database::migrate()`.

### 3.1 `connections` table

```sql
CREATE TABLE IF NOT EXISTS connections (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL UNIQUE,
    driver      TEXT NOT NULL CHECK(driver IN ('sqlite','mysql','mssql','pgsql')),
    host        TEXT,
    port        INTEGER,
    database    TEXT NOT NULL,
    username    TEXT,
    password    TEXT,          -- AES-256-CBC encrypted with APP_KEY
    options     TEXT,          -- JSON blob for extra PDO options
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### 3.2 `reports` table

```sql
CREATE TABLE IF NOT EXISTS reports (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT NOT NULL,
    description   TEXT,
    connection_id INTEGER NOT NULL REFERENCES connections(id) ON DELETE RESTRICT,
    definition    TEXT NOT NULL,   -- Full JSON (see §19)
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### 3.3 `report_categories` table

```sql
CREATE TABLE IF NOT EXISTS report_categories (
    id    INTEGER PRIMARY KEY AUTOINCREMENT,
    name  TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS report_category_map (
    report_id   INTEGER REFERENCES reports(id) ON DELETE CASCADE,
    category_id INTEGER REFERENCES report_categories(id) ON DELETE CASCADE,
    PRIMARY KEY (report_id, category_id)
);
```

### 3.4 `query_templates` table

```sql
CREATE TABLE IF NOT EXISTS query_templates (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT NOT NULL,
    connection_id INTEGER REFERENCES connections(id) ON DELETE SET NULL,
    sql_text      TEXT NOT NULL,
    visual_json   TEXT,            -- Visual query designer state
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### 3.5 `app_settings` table

```sql
CREATE TABLE IF NOT EXISTS app_settings (
    key   TEXT PRIMARY KEY,
    value TEXT
);
-- Seed rows:
-- INSERT OR IGNORE INTO app_settings VALUES ('app_key', '<random-32-char-hex>');
-- INSERT OR IGNORE INTO app_settings VALUES ('pdf_engine', 'mpdf');
-- INSERT OR IGNORE INTO app_settings VALUES ('date_format', 'Y-m-d');
-- INSERT OR IGNORE INTO app_settings VALUES ('number_format_decimals', '2');
-- INSERT OR IGNORE INTO app_settings VALUES ('number_format_dec_point', '.');
-- INSERT OR IGNORE INTO app_settings VALUES ('number_format_thousands_sep', ',');
```

---

## 4. Database Connection Layer

### 4.1 `DriverInterface.php`

```php
interface DriverInterface
{
    public function connect(): \PDO;
    public function testConnection(): bool;
    public function getTables(): array;          // ['table_name', ...]
    public function getColumns(string $table): array; // [['name','type'], ...]
    public function quoteIdentifier(string $name): string;
    public function getLimitSyntax(int $limit, int $offset): string;
    public function getDriverName(): string;
}
```

### 4.2 DSN patterns per driver

| Driver | DSN Pattern |
|---|---|
| SQLite | `sqlite:/absolute/path/to/file.db` |
| MySQL | `mysql:host={host};port={port};dbname={db};charset=utf8mb4` |
| MS SQL Server | `sqlsrv:Server={host},{port};Database={db}` |
| PostgreSQL | `pgsql:host={host};port={port};dbname={db}` |

### 4.3 `ConnectionManager.php` public methods

```php
class ConnectionManager
{
    public function all(): array;
    public function find(int $id): ?array;
    public function create(array $data): int;          // Returns new ID
    public function update(int $id, array $data): void;
    public function delete(int $id): void;
    public function getDriver(int $id): DriverInterface;
    public function testById(int $id): array;          // ['ok'=>bool, 'message'=>string]
}
```

Passwords must be encrypted at rest using `openssl_encrypt()` with `AES-256-CBC` and the `app_key` from `app_settings`.

---

## 5. Core Engine Classes

### 5.1 `Database.php` (Internal SQLite Singleton)

```php
class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $path = __DIR__ . '/../../data/reporting.sqlite';
            self::$instance = new PDO('sqlite:' . $path);
            self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$instance->exec('PRAGMA foreign_keys = ON;');
            self::migrate(self::$instance);
        }
        return self::$instance;
    }

    private static function migrate(PDO $pdo): void
    {
        // Execute all CREATE TABLE IF NOT EXISTS statements from §3
    }
}
```

### 5.2 `Router.php`

Simple front-controller. Reads `$_SERVER['REQUEST_URI']`, strips base path, matches against a route table. Supports GET, POST, PUT, DELETE. Returns 404 JSON on miss.

Route registration pattern:
```php
$router->get('/api/reports', [ReportController::class, 'index']);
$router->post('/api/reports', [ReportController::class, 'store']);
$router->get('/api/reports/{id}', [ReportController::class, 'show']);
$router->put('/api/reports/{id}', [ReportController::class, 'update']);
$router->delete('/api/reports/{id}', [ReportController::class, 'destroy']);
```

---

## 6. Report Data Model

### 6.1 `ReportDefinition.php`

```php
class ReportDefinition
{
    public int $id;
    public string $name;
    public string $description;
    public int $connectionId;
    public string $sqlQuery;          // Raw SQL (or generated from visual designer)
    public ?string $visualQueryJson;  // Null if query was hand-written
    public PageSettings $pageSettings;
    public BandCollection $bands;     // Ordered collection of bands
    public array $groups;             // GroupDefinition[]
    public array $parameters;         // Runtime parameter definitions

    public static function fromJson(string $json): self;
    public function toJson(): string;
}
```

### 6.2 `PageSettings`

```php
class PageSettings
{
    public string $paperSize   = 'A4';      // A4, Letter, Legal, Custom
    public string $orientation = 'portrait'; // portrait | landscape
    public float  $marginTop   = 20;         // mm
    public float  $marginBottom= 20;
    public float  $marginLeft  = 15;
    public float  $marginRight = 15;
    public int    $width;                    // computed or custom px
    public int    $height;                   // computed or custom px
}
```

---

## 7. Visual Report Designer UI

### 7.1 Layout

The designer view (`views/reports/designer.php`) is a single-page application split into four panels:

```
┌─────────────────────────────────────────────────────────────────┐
│  TOOLBAR: [New] [Save] [Preview] [Export PDF] [Export HTML]     │
│           [Undo] [Redo] | Zoom: [50%][75%][100%][125%]          │
├──────────┬──────────────────────────────────┬───────────────────┤
│          │                                  │                   │
│  PANEL   │      DESIGN CANVAS               │  PROPERTIES       │
│  LEFT    │  (scrollable, px-accurate)       │  PANEL            │
│          │                                  │  (right sidebar)  │
│  Field   │  ┌──────────────────────────┐    │                   │
│  List    │  │ Page Header Band         │    │  Shows props of   │
│  (from   │  ├──────────────────────────┤    │  selected element │
│  query)  │  │ Report Header Band       │    │  or selected band │
│          │  ├──────────────────────────┤    │                   │
│  Element │  │ Group Header [field]     │    │  Tabs:            │
│  Toolbox │  ├──────────────────────────┤    │  - General        │
│          │  │ Detail Band (repeating)  │    │  - Border         │
│  Groups  │  ├──────────────────────────┤    │  - Font           │
│  Panel   │  │ Group Footer [field]     │    │  - Format         │
│          │  ├──────────────────────────┤    │                   │
│          │  │ Report Footer Band       │    │                   │
│          │  ├──────────────────────────┤    │                   │
│          │  │ Page Footer Band         │    │                   │
│          │  └──────────────────────────┘    │                   │
│          │                                  │                   │
└──────────┴──────────────────────────────────┴───────────────────┘
```

### 7.2 Left Panel Sections

**Field List (from query)**
- Shows columns returned by the report's SQL query (fetched via `/api/query/fields`).
- Each field is draggable onto a band.
- Shows column name and inferred data type icon.

**Element Toolbox**
- Draggable elements: `Label`, `Field`, `Aggregate`, `Image`, `Line`, `Rectangle`, `Page Number`, `Date/Time`.

**Groups Panel**
- Lists current group definitions.
- [+ Add Group] button opens Group Editor modal.
- Each group shows: field name, sort direction, [Edit] [Delete].

### 7.3 Design Canvas

- Fixed-width canvas representing the paper width (minus margins), scaled by zoom %.
- Each band is rendered as a colored row with a resize handle at the bottom.
- Elements inside bands are absolutely positioned (top, left, width, height in pt or px).
- Clicking an element selects it (blue outline) and loads its properties into the right panel.
- Double-clicking a Label opens inline text edit.
- Multi-select via Shift+click or drag-select rectangle.
- Arrow keys nudge selected elements by 1px (Shift+Arrow = 10px).
- Delete/Backspace removes selected elements.
- Ctrl+Z / Ctrl+Y for undo/redo (maintain a state stack of up to 50 states).

### 7.4 Band Visual Styling

| Band Type | Header Color | Left Border Color |
|---|---|---|
| Page Header | `#e8f4f8` | `#2196F3` |
| Page Footer | `#e8f4f8` | `#2196F3` |
| Report Header | `#e8f0fe` | `#7C3AED` |
| Report Footer | `#e8f0fe` | `#7C3AED` |
| Group Header | `#fef3c7` | `#F59E0B` |
| Group Footer | `#fef3c7` | `#F59E0B` |
| Detail | `#f0fdf4` | `#22C55E` |

---

## 8. Report Sections & Bands

### 8.1 Band Types (Enum)

```php
enum BandType: string
{
    case PageHeader    = 'page_header';
    case ReportHeader  = 'report_header';
    case GroupHeader   = 'group_header';
    case Detail        = 'detail';
    case GroupFooter   = 'group_footer';
    case ReportFooter  = 'report_footer';
    case PageFooter    = 'page_footer';
}
```

### 8.2 Band Render Order (top → bottom per page)

```
1. Page Header        (every page)
2. Report Header      (first page only, or every page — configurable)
3. [For each group level, outermost first:]
   Group Header N     (when group field value changes)
4. Detail Row         (repeated for every data row)
5. [For each group level, innermost first:]
   Group Footer N     (when group field value changes)
6. Report Footer      (last page only, or every page — configurable)
7. Page Footer        (every page)
```

### 8.3 `Band.php`

```php
class Band
{
    public BandType $type;
    public ?string  $groupField;        // For group_header / group_footer
    public int      $groupLevel;        // 0 = outermost
    public int      $height;            // px / pt
    public bool     $printOnEveryPage  = false;
    public bool     $visible           = true;
    public bool     $keepTogether      = false;  // Prevent page break mid-band
    public string   $backgroundColor   = 'transparent';
    public BorderDefinition $border;
    /** @var BandElement[] */
    public array    $elements          = [];
}
```

### 8.4 `BandElement.php`

```php
class BandElement
{
    public string  $id;          // UUID
    public string  $type;        // label|field|aggregate|image|line|rect|pageno|datetime
    // Position (absolute within band, in pt)
    public float   $top;
    public float   $left;
    public float   $width;
    public float   $height;
    // Content
    public ?string $text;        // Static text for label
    public ?string $fieldName;   // Column name for field / aggregate source
    public ?string $aggregateFunc; // sum|avg|count|min|max
    public ?string $aggregateScope; // group|report
    public ?string $format;      // printf-style or date format string
    public ?string $imageUrl;
    // Style
    public string  $fontFamily   = 'Arial';
    public int     $fontSize     = 10;
    public bool    $bold         = false;
    public bool    $italic       = false;
    public bool    $underline    = false;
    public string  $color        = '#000000';
    public string  $textAlign    = 'left';   // left|center|right
    public string  $verticalAlign= 'middle'; // top|middle|bottom
    public string  $backgroundColor = 'transparent';
    public BorderDefinition $border;
    public bool    $wordWrap     = true;
    public ?string $conditionalExpression = null; // PHP-safe expression
    public ?string $conditionalStyle      = null; // JSON style overrides
}
```

---

## 9. Grouping Engine

### 9.1 `GroupDefinition.php`

```php
class GroupDefinition
{
    public string $id;              // UUID
    public string $fieldName;       // Column to group by
    public int    $level;           // 0 = outermost group
    public string $sortDirection    = 'ASC';  // ASC | DESC
    public bool   $pageBreakBefore  = false;
    public bool   $reprintHeaderOnNewPage = false;
    public bool   $showHeader       = true;
    public bool   $showFooter       = true;
    public bool   $startCollapsed   = false;  // For interactive HTML preview
}
```

### 9.2 Rendering Algorithm (PHP pseudocode)

```php
function renderReport(array $data, ReportDefinition $def): string
{
    $output = '';
    $groups = $def->groups; // sorted by level ascending
    $groupValues = array_fill(0, count($groups), null);
    $aggregates  = initAggregates($def);

    $output .= renderBand('page_header', null, $def);
    $output .= renderBand('report_header', null, $def);

    foreach ($data as $rowIndex => $row) {

        // Detect group breaks (outermost first)
        for ($g = 0; $g < count($groups); $g++) {
            $field = $groups[$g]->fieldName;
            if ($groupValues[$g] !== null && $groupValues[$g] !== $row[$field]) {
                // Close inner groups first
                for ($inner = count($groups) - 1; $inner >= $g; $inner--) {
                    $output .= renderBand('group_footer', $groups[$inner], $def, $aggregates[$inner]);
                    resetAggregates($aggregates[$inner]);
                }
                // Reopen outer group headers
                for ($outer = $g; $outer < count($groups); $outer++) {
                    $groupValues[$outer] = $row[$groups[$outer]->fieldName];
                    $output .= renderBand('group_header', $groups[$outer], $def, $row);
                }
                break;
            }
        }

        // Open groups on first row
        if ($rowIndex === 0) {
            for ($g = 0; $g < count($groups); $g++) {
                $groupValues[$g] = $row[$groups[$g]->fieldName];
                $output .= renderBand('group_header', $groups[$g], $def, $row);
            }
        }

        // Accumulate aggregates
        accumulateAggregates($aggregates, $row);

        $output .= renderBand('detail', null, $def, $row);
    }

    // Close all groups after last row (innermost first)
    for ($g = count($groups) - 1; $g >= 0; $g--) {
        $output .= renderBand('group_footer', $groups[$g], $def, $aggregates[$g]);
    }

    $output .= renderBand('report_footer', null, $def, $aggregates['report']);
    $output .= renderBand('page_footer', null, $def);

    return $output;
}
```

### 9.3 Group UI in Designer

When the user adds a group:
1. A **Group Header** band and a **Group Footer** band are inserted into the canvas automatically.
2. Bands are ordered by group level.
3. Dragging a field element into a Group Footer automatically sets its aggregate context to `group`.
4. The left panel Groups section shows a numbered list; drag handles allow reordering (which re-numbers levels).

---

## 10. Aggregate Functions

### 10.1 `AggregateDefinition.php`

```php
class AggregateDefinition
{
    public string $func;         // sum|avg|count|min|max
    public string $fieldName;    // Source column
    public string $scope;        // group|report
    public int    $groupLevel;   // Which group level (if scope=group)
    public string $format;       // Number format string
    public string $label;        // Display label in designer
}
```

### 10.2 Accumulator (PHP)

```php
class AggregateAccumulator
{
    private array $sums   = [];
    private array $counts = [];
    private array $mins   = [];
    private array $maxs   = [];

    public function accumulate(string $field, mixed $value): void
    {
        $v = (float) $value;
        $this->sums[$field]   = ($this->sums[$field]   ?? 0) + $v;
        $this->counts[$field] = ($this->counts[$field] ?? 0) + 1;
        $this->mins[$field]   = min($this->mins[$field] ?? $v, $v);
        $this->maxs[$field]   = max($this->maxs[$field] ?? $v, $v);
    }

    public function resolve(string $func, string $field): float|int
    {
        return match($func) {
            'sum'   => $this->sums[$field]   ?? 0,
            'count' => $this->counts[$field] ?? 0,
            'avg'   => ($this->counts[$field] ?? 0) > 0
                         ? $this->sums[$field] / $this->counts[$field] : 0,
            'min'   => $this->mins[$field] ?? 0,
            'max'   => $this->maxs[$field] ?? 0,
        };
    }

    public function reset(): void
    {
        $this->sums = $this->counts = $this->mins = $this->maxs = [];
    }
}
```

### 10.3 UI: Adding Aggregates

1. User drags an `Aggregate` element from the toolbox into a Group Footer or Report Footer band.
2. A modal opens: **Field selector** (dropdown of query columns), **Function** (SUM/AVG/COUNT/MIN/MAX), **Scope** (auto-detected from band type), **Format** (e.g. `#,##0.00`).
3. The element displays `{SUM(amount)}` as its design-time label.

---

## 11. Border Properties System

### 11.1 `BorderDefinition.php`

```php
class BorderDefinition
{
    public BorderSide $top;
    public BorderSide $right;
    public BorderSide $bottom;
    public BorderSide $left;

    public static function none(): self { /* all sides disabled */ }
    public static function all(string $color, int $width, string $style): self;

    public function toCssString(): string
    {
        // Returns CSS like: border-top: 1px solid #000;
    }

    public function toHtmlStyle(): string; // Inline style string
}

class BorderSide
{
    public bool   $enabled = false;
    public int    $width   = 1;         // px
    public string $style   = 'solid';   // solid|dashed|dotted|double|none
    public string $color   = '#000000';
}
```

### 11.2 Border Editor UI (Properties Panel Tab)

```
┌─────────────────────────────────────────┐
│ BORDER PROPERTIES                       │
├──────────────┬──────────────────────────┤
│              │  [Preview Box]           │
│  Sides:      │  ┌──────────────┐        │
│  ☐ Top       │  │              │        │
│  ☐ Right     │  │              │        │
│  ☐ Bottom    │  │              │        │
│  ☐ Left      │  └──────────────┘        │
│  ☐ All       │                          │
│              │  Width:  [___1___] px    │
│              │  Style:  [solid  ▼]      │
│              │  Color:  [■ #000000]     │
└──────────────┴──────────────────────────┘
```

- Clicking a side checkbox enables that side.
- "All" is a shortcut to enable all four sides at once.
- The preview box shows the current border in real time.
- Changes apply immediately to the selected element or band.

---

## 12. Visual Query Designer

### 12.1 Overview

The Visual Query Designer is a canvas-based tool accessible via a tab next to the SQL Editor in the report setup screen. It lets users:

1. Browse and add **tables** from the connected database.
2. Define **JOIN** relationships by drawing lines between columns.
3. Select **columns** to include in the result.
4. Add **WHERE** conditions via a condition builder UI.
5. Define **ORDER BY** and **GROUP BY** (for the data query, distinct from report grouping).
6. Generate the equivalent SQL, which is synced back to the SQL Editor.

### 12.2 Visual Query Designer Layout

```
┌──────────────────────────────────────────────────────────────────┐
│ VISUAL QUERY DESIGNER                                            │
├────────────────┬─────────────────────────────────────────────────┤
│ Table Browser  │   JOIN Canvas                                   │
│                │                                                 │
│ [Search...]    │  ┌─────────────┐      ┌─────────────┐           │
│                │  │ orders      │      │ customers   │           │
│ > orders       │  │─────────────│      │─────────────│           │
│ > customers    │  │☑ id         │══════│☑ id         │           │
│ > products     │  │☑ customer_id│      │☑ name       │           │
│ > order_items  │  │☑ total      │      │☑ email      │           │
│                │  │☐ notes      │      └─────────────┘           │
│  [Add Table]   │  └─────────────┘                                │
│                │                                                 │
│                │  [+] Add Table to Canvas                        │
├────────────────┴─────────────────────────────────────────────────┤
│ WHERE CONDITIONS                                                 │
│ [+] [field▼] [operator▼] [value________] [AND▼]                  │
├──────────────────────────────────────────────────────────────────┤
│ ORDER BY: [field▼][ASC▼] [+]   GROUP BY: [field▼] [+]            │
├──────────────────────────────────────────────────────────────────┤
│ Generated SQL:                                                   │
│ SELECT o.id, o.total, c.name FROM orders o                       │
│ INNER JOIN customers c ON o.customer_id = c.id                   │
│ WHERE o.total > 100 ORDER BY o.id ASC                            │
│                             [Copy SQL] [Apply to Editor]         │
└──────────────────────────────────────────────────────────────────┘
```

### 12.3 Table Card (JS Component)

- Rendered as an absolutely-positioned `div` on the canvas.
- Draggable to reposition.
- Has a column list with checkboxes.
- Hovering a column shows a drag handle for creating join lines.
- Join lines are drawn with SVG `<line>` elements on an overlay canvas.

### 12.4 Join Types Supported

- INNER JOIN
- LEFT JOIN
- RIGHT JOIN
- FULL OUTER JOIN
- CROSS JOIN

Clicking a join line opens a popup to change the join type.

### 12.5 `VisualQueryBuilder.php`

```php
class VisualQueryBuilder
{
    public function buildSql(array $visualJson, DriverInterface $driver): string;
    // visualJson structure:
    // {
    //   tables: [{alias, name, columns: [{name, selected, alias}]}],
    //   joins:  [{leftTable, leftCol, rightTable, rightCol, type}],
    //   where:  [{field, operator, value, conjunction}],
    //   orderBy:[{field, direction}],
    //   groupBy:[{field}],
    //   limit:  int|null
    // }
}
```

---

## 13. SQL Query Editor

### 13.1 Features

- **CodeMirror 6** with SQL dialect highlighting.
- **Auto-complete** for table names and column names (fetched from the active connection).
- **Query execution** with result preview (first 50 rows shown in a table below the editor).
- **Parameterized queries**: Parameters use `:paramName` syntax. The engine detects parameters and adds an input form above the preview.
- **Field extraction**: On "Apply Query", the backend parses the result set and populates the Field List in the designer.

### 13.2 Parameter Handling

When a query contains `:paramName`:
```sql
SELECT * FROM orders WHERE status = :status AND total > :min_total
```

The UI renders:
```
Parameters:
  status    [______________]
  min_total [______________]
[Run Preview]
```

### 13.3 `/api/query/execute` Endpoint

```json
POST /api/query/execute
{
  "connection_id": 2,
  "sql": "SELECT * FROM orders WHERE status = :status",
  "params": { "status": "active" },
  "limit": 50
}

Response:
{
  "columns": [{"name":"id","type":"integer"}, {"name":"total","type":"float"}],
  "rows": [[1, 99.5], [2, 150.0]],
  "rowCount": 2,
  "executionMs": 12
}
```

---

## 14. Report Renderer (HTML/PDF)

### 14.1 `HtmlRenderer.php`

Produces a self-contained HTML string with inline CSS.

Key responsibilities:
- Iterate bands in render order (§8.2).
- For each `BandElement`, compute the actual value (static text, row field, aggregate).
- Apply conditional styling if `conditionalExpression` evaluates truthy.
- Render page breaks (`<div style="page-break-before:always">`) between pages.
- For interactive preview: wrap detail rows with `data-group-*` attributes for JS collapsing.

Output structure per band:
```html
<div class="band band-detail" style="height:24pt; background:#f0fdf4; border-bottom:1px solid #ccc;">
  <div class="element" style="position:absolute; top:4pt; left:8pt; width:100pt; font-size:10pt;">
    <!-- element content -->
  </div>
</div>
```

### 14.2 `PdfRenderer.php`

Uses **mPDF 8.x**.

- Sets page size and margins from `PageSettings`.
- Loops bands in order, using `$mpdf->WriteHTML()` per band-page.
- Page Header and Page Footer set via `$mpdf->SetHTMLHeader()` / `$mpdf->SetHTMLFooter()`.
- Handles page numbering via `{PAGENO}` and `{nb}` mPDF variables.

### 14.3 `/api/render/{id}` Endpoint

```
GET /api/render/{id}?format=html&param_status=active
GET /api/render/{id}?format=pdf&param_status=active
```

- `format` = `html` | `pdf`
- Additional query params with prefix `param_` are passed as query parameters.

---

## 15. REST API Endpoints

### Connections

| Method | URL | Description |
|---|---|---|
| GET | `/api/connections` | List all connections |
| POST | `/api/connections` | Create connection |
| GET | `/api/connections/{id}` | Get single connection |
| PUT | `/api/connections/{id}` | Update connection |
| DELETE | `/api/connections/{id}` | Delete connection |
| POST | `/api/connections/{id}/test` | Test connection |
| GET | `/api/connections/{id}/tables` | List tables |
| GET | `/api/connections/{id}/tables/{table}/columns` | List columns |

### Reports

| Method | URL | Description |
|---|---|---|
| GET | `/api/reports` | List all reports |
| POST | `/api/reports` | Create report |
| GET | `/api/reports/{id}` | Get report definition |
| PUT | `/api/reports/{id}` | Save report definition |
| DELETE | `/api/reports/{id}` | Delete report |
| POST | `/api/reports/{id}/duplicate` | Clone report |

### Query

| Method | URL | Description |
|---|---|---|
| POST | `/api/query/execute` | Run SQL, return rows |
| POST | `/api/query/fields` | Extract field metadata from SQL |
| POST | `/api/query/build` | Build SQL from visual query JSON |
| GET | `/api/query/templates` | List query templates |
| POST | `/api/query/templates` | Save query template |

### Render

| Method | URL | Description |
|---|---|---|
| GET | `/api/render/{id}` | Render report (html or pdf) |
| POST | `/api/render/preview` | Render from unsaved definition JSON |

### Settings

| Method | URL | Description |
|---|---|---|
| GET | `/api/settings` | Get all settings |
| PUT | `/api/settings` | Update settings |

### All API responses follow:

```json
{
  "success": true,
  "data": { ... },
  "message": "Optional message",
  "errors": []
}
```

Error responses use appropriate HTTP status codes (400, 404, 422, 500).

---

## 16. Frontend Architecture

### 16.1 Global App State

`app.js` maintains a global singleton:

```javascript
window.ReportingEngine = {
  state: {
    activeReportId: null,
    definition: {},          // Full report definition JSON
    selectedElement: null,   // Currently selected element UUID
    selectedBand: null,      // Currently selected band type
    undoStack: [],           // Max 50 states
    redoStack: [],
    zoom: 1.0,
    isDirty: false,
    queryColumns: [],        // From last successful query run
  },
  dispatch(action, payload) { ... },  // Mutate state + re-render
  on(event, handler) { ... },
  emit(event, data) { ... },
};
```

### 16.2 `Designer.js` — Main Controller

```javascript
class Designer {
  constructor(containerId) { ... }
  init() { ... }                      // Fetch report, render canvas
  renderCanvas() { ... }              // Full re-render of all bands
  renderBand(band) { ... }            // Single band DOM
  renderElement(element, band) { ... }
  selectElement(id) { ... }
  deselectAll() { ... }
  addElement(type, bandType, x, y) { ... }
  removeElement(id) { ... }
  moveElement(id, dx, dy) { ... }
  resizeElement(id, dw, dh) { ... }
  undo() { ... }
  redo() { ... }
  save() { ... }                      // POST/PUT to API
}
```

### 16.3 `BandManager.js`

Handles band height resizing, band visibility toggling, adding/removing group bands when groups are edited.

### 16.4 `ElementEditor.js`

Populates the Properties Panel (right sidebar) when an element is selected. Uses a tab-based UI:
- **General tab**: text/field/format inputs.
- **Style tab**: font family, size, bold/italic/underline, color, background, alignment.
- **Border tab**: renders the `BorderEditor` component (§11.2).
- **Advanced tab**: conditional expression, word wrap.

### 16.5 Drag-and-Drop

- **SortableJS** for reordering groups in the Groups Panel.
- **Native HTML5 drag** for dragging from toolbox/field list → canvas.
- On `drop` over a band, the Designer creates a new element at the drop coordinates.
- On `dragstart` of an existing element, moves it (alt-drag clones it).

### 16.6 Keyboard Shortcuts

| Shortcut | Action |
|---|---|
| Ctrl+S | Save report |
| Ctrl+Z | Undo |
| Ctrl+Y / Ctrl+Shift+Z | Redo |
| Delete / Backspace | Delete selected element |
| Arrow keys | Nudge 1px |
| Shift+Arrow | Nudge 10px |
| Ctrl+D | Duplicate selected element |
| Ctrl+A | Select all elements in current band |
| Escape | Deselect all |
| Ctrl+P | Preview report |

---

## 17. Security Considerations

### 17.1 SQL Injection Prevention

- All user queries are executed via PDO prepared statements for parameters (`:paramName`).
- The admin-entered SQL itself is run as-is against the target database (by design — this is a reporting tool for trusted users). Access to the tool must be protected at the server level (HTTP auth or application-level login).
- Consider adding a **role system** with `read_only` connections that wrap all queries in a `BEGIN TRANSACTION` + `ROLLBACK` to prevent mutations.

### 17.2 Credential Storage

- Connection passwords are encrypted with `AES-256-CBC` before storing in SQLite.
- The `APP_KEY` is auto-generated on first run and stored in `app_settings`.
- **Recommendation**: Set `APP_KEY` via environment variable (`$_ENV['APP_KEY']`) rather than DB for production.

### 17.3 Authentication

The engine does not implement auth itself. Protect via:
- `.htpasswd` HTTP Basic Auth (development/intranet)
- Reverse proxy authentication (nginx/Apache)
- Wrap the entry point in a session check (recommend adding a simple session-based login module)

### 17.4 File Upload Safety

If image elements allow file upload:
- Restrict MIME types to `image/jpeg`, `image/png`, `image/gif`, `image/svg+xml`.
- Rename uploaded files to UUID-based filenames.
- Store outside the web root or serve through a PHP proxy.

---

## 18. Build & Deployment

### 18.1 `composer.json`

```json
{
  "require": {
    "php": ">=8.2",
    "mpdf/mpdf": "^8.2",
    "ext-pdo": "*",
    "ext-pdo_sqlite": "*"
  },
  "autoload": {
    "psr-4": {
      "ReportingEngine\\": "src/"
    }
  }
}
```

### 18.2 `.htaccess`

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]

# Prevent direct access to data/ and src/
RewriteRule ^(data|src|vendor)/ - [F,L]
```

### 18.3 `index.php` — Entry Point

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use ReportingEngine\Core\Router;
use ReportingEngine\Core\Request;
use ReportingEngine\Core\Response;

$request  = Request::fromGlobals();
$router   = new Router();

// Register API routes
require __DIR__ . '/src/routes.php';

// Register view routes
require __DIR__ . '/src/web_routes.php';

$response = $router->dispatch($request);
$response->send();
```

### 18.4 Minimum Server Requirements

- PHP 8.2+ with extensions: `pdo`, `pdo_sqlite`, `pdo_mysql` (optional), `pdo_pgsql` (optional), `mbstring`, `json`, `openssl`, `fileinfo`
- For MS SQL Server: `pdo_sqlsrv` (Microsoft ODBC driver must be installed on the OS)
- Apache 2.4+ with `mod_rewrite` OR Nginx with try_files fallback

### 18.5 First-Run Setup

On the first request, `Database::getInstance()` auto-creates `data/reporting.sqlite` and runs all migrations. No CLI setup required.

---

## 19. Sample Report JSON Schema

This is the structure stored in `reports.definition`:

```json
{
  "version": "1.0",
  "name": "Monthly Sales Report",
  "description": "Sales by customer and product",
  "connectionId": 3,
  "page": {
    "paperSize": "A4",
    "orientation": "portrait",
    "marginTop": 20,
    "marginBottom": 20,
    "marginLeft": 15,
    "marginRight": 15
  },
  "query": {
    "sql": "SELECT c.name, p.product_name, oi.qty, oi.unit_price, (oi.qty * oi.unit_price) AS line_total FROM order_items oi JOIN orders o ON oi.order_id = o.id JOIN customers c ON o.customer_id = c.id JOIN products p ON oi.product_id = p.id ORDER BY c.name, p.product_name",
    "visualJson": null,
    "parameters": []
  },
  "groups": [
    {
      "id": "grp-001",
      "fieldName": "name",
      "level": 0,
      "sortDirection": "ASC",
      "pageBreakBefore": false,
      "reprintHeaderOnNewPage": true,
      "showHeader": true,
      "showFooter": true
    }
  ],
  "bands": [
    {
      "type": "page_header",
      "height": 30,
      "printOnEveryPage": true,
      "backgroundColor": "#f0f4f8",
      "border": {
        "top":    {"enabled": false},
        "right":  {"enabled": false},
        "bottom": {"enabled": true, "width": 2, "style": "solid", "color": "#2196F3"},
        "left":   {"enabled": false}
      },
      "elements": [
        {
          "id": "el-001",
          "type": "label",
          "top": 8, "left": 10, "width": 200, "height": 16,
          "text": "Monthly Sales Report",
          "fontFamily": "Arial",
          "fontSize": 14,
          "bold": true,
          "color": "#1a1a2e",
          "textAlign": "left"
        },
        {
          "id": "el-002",
          "type": "datetime",
          "top": 10, "left": 400, "width": 120, "height": 12,
          "format": "Y-m-d",
          "textAlign": "right",
          "fontSize": 9,
          "color": "#666666"
        },
        {
          "id": "el-003",
          "type": "pageno",
          "top": 10, "left": 530, "width": 60, "height": 12,
          "text": "Page {page} of {pages}",
          "textAlign": "right",
          "fontSize": 9,
          "color": "#666666"
        }
      ]
    },
    {
      "type": "report_header",
      "height": 20,
      "backgroundColor": "transparent",
      "border": { "top": {"enabled":false}, "right": {"enabled":false}, "bottom": {"enabled":true,"width":1,"style":"solid","color":"#cccccc"}, "left": {"enabled":false} },
      "elements": [
        {"id":"el-010","type":"label","top":4,"left":10,"width":150,"height":12,"text":"Customer","bold":true,"fontSize":9,"backgroundColor":"#e8e8e8","border":{"bottom":{"enabled":true,"width":1,"style":"solid","color":"#999"}}},
        {"id":"el-011","type":"label","top":4,"left":170,"width":150,"height":12,"text":"Product","bold":true,"fontSize":9,"backgroundColor":"#e8e8e8"},
        {"id":"el-012","type":"label","top":4,"left":330,"width":60,"height":12,"text":"Qty","bold":true,"fontSize":9,"textAlign":"right","backgroundColor":"#e8e8e8"},
        {"id":"el-013","type":"label","top":4,"left":400,"width":80,"height":12,"text":"Unit Price","bold":true,"fontSize":9,"textAlign":"right","backgroundColor":"#e8e8e8"},
        {"id":"el-014","type":"label","top":4,"left":490,"width":90,"height":12,"text":"Line Total","bold":true,"fontSize":9,"textAlign":"right","backgroundColor":"#e8e8e8"}
      ]
    },
    {
      "type": "group_header",
      "groupField": "name",
      "groupLevel": 0,
      "height": 18,
      "backgroundColor": "#fff8e1",
      "border": {"bottom":{"enabled":true,"width":1,"style":"dashed","color":"#f59e0b"}},
      "elements": [
        {"id":"el-020","type":"field","top":3,"left":10,"width":200,"height":14,"fieldName":"name","bold":true,"fontSize":10,"color":"#92400e"}
      ]
    },
    {
      "type": "detail",
      "height": 16,
      "backgroundColor": "transparent",
      "border": {"bottom":{"enabled":true,"width":1,"style":"solid","color":"#eeeeee"}},
      "elements": [
        {"id":"el-030","type":"field","top":2,"left":170,"width":150,"height":12,"fieldName":"product_name","fontSize":9},
        {"id":"el-031","type":"field","top":2,"left":330,"width":60,"height":12,"fieldName":"qty","fontSize":9,"textAlign":"right","format":"%d"},
        {"id":"el-032","type":"field","top":2,"left":400,"width":80,"height":12,"fieldName":"unit_price","fontSize":9,"textAlign":"right","format":"%,.2f"},
        {"id":"el-033","type":"field","top":2,"left":490,"width":90,"height":12,"fieldName":"line_total","fontSize":9,"textAlign":"right","format":"%,.2f"}
      ]
    },
    {
      "type": "group_footer",
      "groupField": "name",
      "groupLevel": 0,
      "height": 18,
      "backgroundColor": "#fef9c3",
      "border": {"top":{"enabled":true,"width":1,"style":"solid","color":"#f59e0b"},"bottom":{"enabled":2,"width":2,"style":"double","color":"#f59e0b"}},
      "elements": [
        {"id":"el-040","type":"label","top":3,"left":380,"width":100,"height":12,"text":"Customer Total:","bold":true,"fontSize":9,"textAlign":"right"},
        {"id":"el-041","type":"aggregate","top":3,"left":490,"width":90,"height":12,"fieldName":"line_total","aggregateFunc":"sum","aggregateScope":"group","format":"%,.2f","bold":true,"fontSize":9,"textAlign":"right","color":"#92400e"}
      ]
    },
    {
      "type": "report_footer",
      "height": 22,
      "backgroundColor": "#e8f0fe",
      "border": {"top":{"enabled":true,"width":2,"style":"solid","color":"#3f51b5"}},
      "elements": [
        {"id":"el-050","type":"label","top":5,"left":350,"width":130,"height":14,"text":"Grand Total:","bold":true,"fontSize":11,"textAlign":"right"},
        {"id":"el-051","type":"aggregate","top":5,"left":490,"width":90,"height":14,"fieldName":"line_total","aggregateFunc":"sum","aggregateScope":"report","format":"%,.2f","bold":true,"fontSize":11,"textAlign":"right","color":"#1a237e"}
      ]
    },
    {
      "type": "page_footer",
      "height": 16,
      "backgroundColor": "#f0f4f8",
      "border": {"top":{"enabled":true,"width":1,"style":"solid","color":"#2196F3"}},
      "elements": [
        {"id":"el-060","type":"label","top":3,"left":10,"width":300,"height":10,"text":"Confidential — Internal Use Only","fontSize":8,"color":"#999999","italic":true}
      ]
    }
  ]
}
```

---

## 20. Implementation Checklist

Use this checklist to track progress when building the engine.

### Phase 1 — Foundation

- [ ] Create directory structure
- [ ] Set up `composer.json` and install dependencies
- [ ] Implement `Database.php` singleton with auto-migration
- [ ] Create all SQLite tables (§3)
- [ ] Implement `Router.php` and `Request.php`
- [ ] Create `.htaccess` / nginx config
- [ ] Implement `Response.php` with JSON helper

### Phase 2 — Connection Layer

- [ ] Implement `DriverInterface.php`
- [ ] Implement `SqliteDriver.php`
- [ ] Implement `MysqlDriver.php`
- [ ] Implement `MssqlDriver.php`
- [ ] Implement `PgsqlDriver.php`
- [ ] Implement `ConnectionManager.php` with encryption
- [ ] Implement `ConnectionController.php` (all CRUD + test + tables + columns)
- [ ] Build Connections UI (list, create/edit form, test button)

### Phase 3 — Query Layer

- [ ] Implement `QueryRunner.php`
- [ ] Implement `QueryParser.php` (field extraction from result set)
- [ ] Implement `VisualQueryBuilder.php`
- [ ] Implement `QueryController.php`
- [ ] Build SQL Editor UI (CodeMirror, auto-complete, result preview)
- [ ] Build Visual Query Designer UI (canvas, table cards, join lines, where builder)

### Phase 4 — Report Data Model

- [ ] Implement all model classes: `ReportDefinition`, `Band`, `BandElement`, `GroupDefinition`, `AggregateDefinition`, `BorderDefinition`, `PageSettings`
- [ ] Implement `ReportRepository.php`
- [ ] Implement `ReportController.php` (CRUD + duplicate)
- [ ] Validate JSON schema on save

### Phase 5 — Visual Designer UI

- [ ] Build designer layout (toolbar, left panel, canvas, properties panel)
- [ ] Implement `Designer.js` main controller
- [ ] Implement `BandManager.js` (height resize, visibility toggle)
- [ ] Implement `DragDrop.js` (toolbox → canvas, field list → canvas, element move)
- [ ] Implement `ElementEditor.js` (properties panel tabs)
- [ ] Implement `BorderEditor.js` component
- [ ] Implement `GroupEditor.js` modal
- [ ] Implement `AggregateEditor.js` modal
- [ ] Implement undo/redo stack
- [ ] Implement keyboard shortcuts
- [ ] Implement zoom control

### Phase 6 — Rendering Engine

- [ ] Implement `AggregateAccumulator.php`
- [ ] Implement `HtmlRenderer.php` with grouping algorithm
- [ ] Implement `PdfRenderer.php` (mPDF)
- [ ] Implement `RenderController.php`
- [ ] Build preview modal/page
- [ ] Test conditional expressions
- [ ] Test page breaks

### Phase 7 — Polish & Settings

- [ ] Build Dashboard (list reports, quick actions)
- [ ] Build Settings UI (date format, number format, PDF engine)
- [ ] Add export PDF button to preview
- [ ] Add report categories
- [ ] Add query templates CRUD
- [ ] Error handling and user-facing error messages
- [ ] Loading spinners and save indicators

### Phase 8 — Testing

- [ ] Test each DB driver (connection, table listing, query execution)
- [ ] Test report rendering with all band types
- [ ] Test grouping with nested groups (2+ levels)
- [ ] Test all aggregate functions (sum, avg, count, min, max) at both group and report scope
- [ ] Test border rendering in HTML and PDF
- [ ] Test visual query designer SQL generation
- [ ] Test PDF export pagination and page header/footer
- [ ] Test undo/redo in designer
- [ ] Cross-browser test designer (Chrome, Firefox, Safari, Edge)

---

## Appendix A — Color & Typography Tokens

```css
:root {
  --color-primary:      #2563EB;
  --color-primary-dark: #1D4ED8;
  --color-accent:       #F59E0B;
  --color-danger:       #DC2626;
  --color-success:      #16A34A;
  --color-surface:      #FFFFFF;
  --color-surface-2:    #F8FAFC;
  --color-border:       #E2E8F0;
  --color-text:         #0F172A;
  --color-text-muted:   #64748B;

  --band-page:          #EFF6FF;
  --band-report:        #F5F3FF;
  --band-group:         #FFFBEB;
  --band-detail:        #F0FDF4;

  --font-ui:   'Inter', system-ui, sans-serif;
  --font-mono: 'JetBrains Mono', 'Fira Code', monospace;

  --radius-sm: 4px;
  --radius-md: 8px;
  --radius-lg: 12px;

  --shadow-sm: 0 1px 3px rgba(0,0,0,.08);
  --shadow-md: 0 4px 12px rgba(0,0,0,.10);
  --shadow-lg: 0 8px 32px rgba(0,0,0,.14);
}
```

## Appendix B — mPDF Page Number Variables

| Variable | Description |
|---|---|
| `{PAGENO}` | Current page number |
| `{nb}` | Total page count |
| `{DATE Y-m-d}` | Current date |

Use these in `text` fields of `pageno` and `datetime` elements.

## Appendix C — Supported SQL Parameter Operators (WHERE builder)

`=`, `!=`, `<`, `<=`, `>`, `>=`, `LIKE`, `NOT LIKE`, `IN`, `NOT IN`, `IS NULL`, `IS NOT NULL`, `BETWEEN`

---

*End of PHP Reporting Engine Design Specification v1.0*
