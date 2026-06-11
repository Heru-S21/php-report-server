# Embedding Reports Into External Projects

The PHP Reporting Engine exposes two integration paths:

| Approach | Use Case | Latency | Dependency |
|----------|----------|---------|------------|
| **JavaScript (REST API)** | Embed live reports in another web app via iframe or `fetch()` | Network call to running server | Server must be running |
| **PHP (Library mode)** | Render reports server-side from another PHP app | In-process | Composer dependency + SQLite DB |

---

## 1. JavaScript Embedding (REST API)

The server exposes `/api/render/{id}` (saved reports) and `/api/render/preview` (ad-hoc definitions). Both return raw HTML or PDF.

### 1.1 Embed Saved Report via iframe

```html
<iframe src="https://reports.example.com/api/render/42?format=html"
        width="800" height="600" style="border:1px solid #ddd"></iframe>
```

Replace `42` with your report's numeric ID or GUID. Omit `format=html` — it's the default.

**PDF in a new tab:**

```html
<a href="https://reports.example.com/api/render/42?format=pdf" target="_blank">
  Download PDF
</a>
```

### 1.2 Embed Report with URL Parameters

Query parameters prefixed with `param_` are passed to the report's SQL query as named parameters:

```html
<iframe src="https://reports.example.com/api/render/42?format=html&param_start_date=2025-01-01&param_end_date=2025-12-31"
        width="800" height="600"></iframe>
```

Assuming the report's SQL contains `WHERE date BETWEEN :start_date AND :end_date`, the values are substituted at render time.

### 1.3 Fetch Report HTML via `fetch()`

```js
async function loadReport(reportId, params = {}) {
  const qs = new URLSearchParams();
  qs.set('format', 'html');
  for (const [k, v] of Object.entries(params)) {
    if (v !== '' && v != null) qs.set('param_' + k, v);
  }
  const res = await fetch(`/api/render/${reportId}?${qs}`);
  if (!res.ok) throw new Error('Render failed');
  return res.text(); // returns full HTML document
}

// Usage
loadReport(42, { start_date: '2025-01-01', end_date: '2025-12-31' })
  .then(html => document.getElementById('report-container').innerHTML = html);
```

### 1.4 Download PDF via `fetch()`

```js
async function downloadPdf(reportId, filename = 'report.pdf') {
  const res = await fetch(`/api/render/${reportId}?format=pdf`);
  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}
```

### 1.5 Preview Unsaved Definition

If you have a report definition object (unsaved / built dynamically), POST it to `/api/render/preview`:

```js
const definition = {
  version: '1.0',
  page: { paperSize: 'A4', orientation: 'portrait', marginTop: 20, marginBottom: 20, marginLeft: 15, marginRight: 15 },
  query: { sql: 'SELECT * FROM orders WHERE status = :status', parameters: [
    { name: 'status', type: 'string', defaultValue: 'shipped' }
  ]},
  groups: [],
  bands: [
    { type: 'page_header', height: 20, elements: [
      { id: 'e1', type: 'label', text: 'Order Report', top: 4, left: 10, width: 100, height: 12 }
    ]},
    { type: 'detail', height: 16, elements: [
      { id: 'e2', type: 'field', fieldName: 'id', top: 2, left: 10, width: 50, height: 12 }
    ]},
  ],
};

async function previewHtml(def, params = {}) {
  const body = {
    json: JSON.stringify(def),
    format: 'html',
  };
  for (const [k, v] of Object.entries(params)) {
    if (v !== '') body['param_' + k] = v;
  }
  const res = await fetch('/api/render/preview', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  return res.text();
}
```

---

## 2. PHP Embedding (Library Mode)

Add the reporting engine as a dependency and use its renderers directly in-process, avoiding HTTP round-trips.

### 2.1 Install

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/your-org/php-reporting-engine.git"
    }
  ],
  "require": {
    "reporting-engine/php-reporting-engine": "dev-main"
  }
}
```

Then:

```bash
composer install
```

### 2.2 Initialize the Database

The engine uses an **internal SQLite database** to store report definitions and settings. Initialize it early in your bootstrap:

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use ReportingEngine\Core\Database;
use ReportingEngine\Core\Request;
use ReportingEngine\Report\ReportDefinition;
use ReportingEngine\Report\ReportRepository;
use ReportingEngine\Renderer\HtmlRenderer;
use ReportingEngine\Renderer\PdfRenderer;
use ReportingEngine\Query\QueryRunner;
use ReportingEngine\Connection\ConnectionManager;

// Point at the engine's data directory (or your own)
Database::init([
    'sqlite_path' => __DIR__ . '/data/reporting.sqlite',
    'data_path'   => __DIR__ . '/data',
]);
```

The database auto-creates tables and seed data on first access.

### 2.3 Render a Saved Report from DB

```php
// Load a report from the engine's database
$repo = new ReportRepository();
$report = $repo->find(42);              // by numeric ID
// $report = $repo->findByGuid('...');  // or by GUID

if (!$report) {
    throw new RuntimeException('Report not found');
}

// Hydrate the definition object
$definitionData = is_string($report['definition'])
    ? json_decode($report['definition'], true)
    : $report['definition'];
$definitionData['id'] = $report['id'];
$definitionData['connectionId'] = (int)$report['connection_id'];
$definition = ReportDefinition::fromArray($definitionData);

// Fetch data from the report's connection
$sql = $definition->sqlQuery;
if (!empty($sql) && $definition->connectionId > 0) {
    $connManager = new ConnectionManager();
    $driver = $connManager->getDriver($definition->connectionId);
    $runner = new QueryRunner($driver);
    $result = $runner->execute($sql, [], 10000);
    $data = [];
    foreach ($result['rows'] as $i => $row) {
        $assoc = [];
        foreach ($result['columns'] as $j => $col) {
            $assoc[$col['name']] = $row[$j] ?? null;
        }
        $assoc['_rowno'] = $i + 1;
        $data[] = $assoc;
    }
} else {
    $data = [];
}

// Render as HTML
$renderer = new HtmlRenderer();
$html = $renderer->render($definition, $data);

echo $html;
```

### 2.4 Pass Query Parameters

Supply named parameters as the third argument to `$runner->execute()`:

```php
$params = [
    'start_date' => '2025-01-01',
    'end_date'   => '2025-12-31',
];
$result = $runner->execute($sql, $params, 10000);
```

### 2.5 Render Without a Database (Ad-Hoc Definitions)

If your data comes from your own application (not from the engine's DB connections), build the definition manually and pass your own data:

```php
$definition = ReportDefinition::fromArray([
    'page' => [
        'paperSize'    => 'A4',
        'orientation'  => 'portrait',
        'marginTop'    => 20,
        'marginBottom' => 20,
        'marginLeft'   => 15,
        'marginRight'  => 15,
    ],
    'bands' => [
        [
            'type'   => 'page_header',
            'height' => 20,
            'elements' => [
                [
                    'id'   => 'h1',
                    'type' => 'label',
                    'text' => 'Invoice Report',
                    'top'  => 4, 'left' => 10, 'width' => 100, 'height' => 12,
                    'bold' => true, 'fontSize' => 14,
                ],
            ],
        ],
        [
            'type'   => 'detail',
            'height' => 16,
            'elements' => [
                [
                    'id'        => 'd1',
                    'type'      => 'field',
                    'fieldName' => 'invoice_number',
                    'top'  => 2, 'left' => 10, 'width' => 80, 'height' => 12,
                ],
                [
                    'id'        => 'd2',
                    'type'      => 'field',
                    'fieldName' => 'total',
                    'top'  => 2, 'left' => 100, 'width' => 60, 'height' => 12,
                    'format'    => '%.2f',
                ],
            ],
        ],
        [
            'type'   => 'page_footer',
            'height' => 16,
            'printOnEveryPage' => true,
            'elements' => [
                [
                    'id'   => 'f1',
                    'type' => 'pageno',
                    'text' => 'Page {page} of {pages}',
                    'top'  => 3, 'left' => 10, 'width' => 100, 'height' => 10,
                ],
            ],
        ],
    ],
]);

// Your data (can come from any source — MySQL, API, CSV, etc.)
$data = [
    ['invoice_number' => 'INV-001', 'total' => 150.00, '_rowno' => 1],
    ['invoice_number' => 'INV-002', 'total' => 275.50, '_rowno' => 2],
    ['invoice_number' => 'INV-003', 'total' => 89.99,  '_rowno' => 3],
];

$renderer = new HtmlRenderer();
$html = $renderer->render($definition, $data);
```

### 2.6 Render PDF

```php
$renderer = new PdfRenderer();
$pdf = $renderer->render($definition, $data);

// Stream to browser
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="report.pdf"');
echo $pdf;

// Save to file
file_put_contents('/tmp/report.pdf', $pdf);
```

### 2.7 Embed in a PHP Framework (Symfony/Laravel)

**Symfony controller:**

```php
use ReportingEngine\Renderer\HtmlRenderer;
use ReportingEngine\Report\ReportDefinition;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends AbstractController
{
    #[Route('/report/preview', name: 'report_preview')]
    public function preview(): Response
    {
        $data = $this->getDataFromYourDatabase();
        $definition = ReportDefinition::fromArray($this->getDefinitionArray());

        $renderer = new HtmlRenderer();
        $html = $renderer->render($definition, $data);

        return new Response($html);
    }
}
```

**Laravel controller:**

```php
use ReportingEngine\Renderer\PdfRenderer;
use ReportingEngine\Report\ReportDefinition;

class ReportController extends Controller
{
    public function download()
    {
        $data = YourModel::get()->toArray();
        // Add _rowno
        $data = array_map(fn($row, $i) => $row + ['_rowno' => $i + 1], $data, array_keys($data));

        $definition = ReportDefinition::fromJson(file_get_contents(resource_path('reports/my-report.json')));

        $renderer = new PdfRenderer();
        $pdf = $renderer->render($definition, $data);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="report.pdf"',
        ]);
    }
}
```

---

## 3. CORS Configuration (JS Embedding Only)

If the reporting engine runs on a different origin from your app, the server must include CORS headers. Currently, `Router.php` returns `Access-Control-Allow-Origin: *` on API responses. Add explicit origin restrictions in `src/Core/Router.php` if needed:

```php
// In dispatch(), before sending the response:
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://your-app.com', 'https://admin.your-app.com'];
if (in_array($origin, $allowed)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}
```

---

## 4. Authentication

The engine has **built-in auth** (disabled by default). Enable it via **Settings → Authentication** or set `'enabled' => true` in `config/app.php`.

### How auth affects external access

| Endpoint | Auth Required? | Reason |
|----------|---------------|--------|
| `/api/render/{id}` | No | Embedded iframes and fetch() access |
| `/api/render/preview` | No | Ad-hoc preview from external apps |
| `/api/images/file/{guid}` | No | Images embedded in rendered reports |
| `/api/reports` (CRUD) | Yes | Admin API — requires login |
| All other `/api/*` routes | Yes | Requires login |
| View pages (UI) | Yes | Redirects to `/login` |

### Embedded Reports (iframes / fetch)

The render and image endpoints are always public (auth bypassed), so embedded reports work regardless of auth status. No token or header is needed for these URLs.

### PHP Library Mode

Library mode never goes through HTTP, so auth does not apply:

```php
$renderer = new HtmlRenderer();
$html = $renderer->render($definition, $data);
```

### Adding Your Own Auth Guard

If you need stricter access on render endpoints, protect them at the reverse-proxy layer (nginx/Apache) or add a custom middleware in `Router.php`.

**Note:** When auth is enabled, the UI login page is at `/login`. External embedders do not need to authenticate to view rendered reports.

---

## 5. PHP Proxy Pattern (Embed Without Exposing the Engine)

If your external app runs on a different server from the reporting engine and you don't want to expose the engine directly to end users (or you need to add your own auth/logic around each report fetch), the recommended approach is a **server-side proxy**. Your app makes an HTTP request to the engine's render endpoint and writes the response downstream — the engine stays behind your firewall and the browser never talks to it directly.

### Architecture

```
Browser / Client App
        │
        │  GET /my-app/reports/42
        ▼
┌──────────────────┐
│  Your App        │  ← your auth, session, permissions here
│  (Proxy Route)   │
└──────┬───────────┘
       │  cURL / file_get_contents / Guzzle
       │  GET http://engine:8080/api/render/42?format=html
       ▼
┌──────────────────┐
│  Engine Server   │  ← behind firewall, not internet-facing
│  (php -S 8080)   │
└──────────────────┘
       │
       ▼   Returns raw HTML or PDF
┌──────────────────┐
│  Your App        │
│  streams response│
│  to client       │
└──────────────────┘
```

### Why use a proxy

| Concern | Direct iframe | PHP proxy |
|---------|--------------|-----------|
| Engine visibility | Exposed to browser | Hidden behind firewall |
| Your app's auth | Can't add auth easily | Your normal middleware applies |
| Query parameters | Must trust user-supplied params | You control/validate params |
| Error handling | Browser shows engine's raw error | You catch + format errors |
| Caching | None | You add cache layer |
| Mix data sources | Engine data only | Merge engine report + your own data |

### Basic proxy controller (request-report → relay)

This is the simplest pattern: your app receives a request, fetches the rendered report from the engine, and relays the response with the correct content type.

```php
<?php
// Example: Symfony controller
// src/Controller/ReportProxyController.php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ReportProxyController
{
    private string $engineUrl = 'http://127.0.0.1:8080';  // engine internal address

    #[Route('/reports/{id}', name: 'report_embed')]
    public function embed(int $id, Request $request): Response
    {
        // 1. YOUR AUTH — standard framework guard
        // $this->denyAccessUnlessGranted('ROLE_USER');

        // 2. Build engine URL (passthrough format + query params)
        $format = $request->query->get('format', 'html');
        $url = "{$this->engineUrl}/api/render/{$id}?format=" . urlencode($format);

        foreach ($request->query as $key => $value) {
            if (str_starts_with($key, 'param_')) {
                $url .= '&' . urlencode($key) . '=' . urlencode($value);
            }
        }

        // 3. Fetch from engine
        $html = @file_get_contents($url);

        if ($html === false) {
            return new Response('Report unavailable', 502);
        }

        // 4. Relay with correct content type
        $contentType = $format === 'pdf'
            ? 'application/pdf'
            : 'text/html; charset=utf-8';

        return new Response($html, 200, [
            'Content-Type' => $contentType,
        ]);
    }
}
```

```php
<?php
// Example: Laravel controller
// app/Http/Controllers/ReportProxyController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;  // Laravel's HTTP client

class ReportProxyController extends Controller
{
    protected string $engineUrl = 'http://127.0.0.1:8080';

    public function show(int $id, Request $request)
    {
        // 1. YOUR AUTH
        // $this->authorize('view-report');

        // 2. Build URL
        $format = $request->query('format', 'html');
        $url = "{$this->engineUrl}/api/render/{$id}?format=" . urlencode($format);

        foreach ($request->query() as $key => $value) {
            if (str_starts_with($key, 'param_')) {
                $url .= '&' . urlencode($key) . '=' . urlencode($value);
            }
        }

        // 3. Fetch via Guzzle (Laravel's Http facade)
        try {
            $response = Http::timeout(30)->get($url);
        } catch (\Exception $e) {
            abort(502, 'Report engine unreachable');
        }

        if ($response->failed()) {
            abort(502, 'Report render failed');
        }

        // 4. Return with content type
        $contentType = $format === 'pdf'
            ? 'application/pdf'
            : 'text/html; charset=utf-8';

        return response($response->body(), 200)
            ->header('Content-Type', $contentType);
    }
}
```

### Proxy with caching

Add a cache layer so repeated views of the same report don't hit the engine every time.

```php
// Symfony — using Symfony Cache
use Symfony\Contracts\Cache\CacheInterface;

#[Route('/reports/{id}', name: 'report_embed')]
public function embed(int $id, Request $request, CacheInterface $cache): Response
{
    $format = $request->query->get('format', 'html');
    $cacheKey = "report_{$id}_{$format}";

    $html = $cache->get($cacheKey, function () use ($id, $format) {
        $url = "http://127.0.0.1:8080/api/render/{$id}?format={$format}";
        $result = @file_get_contents($url);
        if ($result === false) throw new \RuntimeException('Engine error');
        return $result;
    }, 300); // 5-minute TTL

    return new Response($html, 200, [
        'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'text/html; charset=utf-8',
    ]);
}
```

```php
// Laravel — using Cache facade
use Illuminate\Support\Facades\Cache;

public function show(int $id, Request $request)
{
    $format = $request->query('format', 'html');
    $cacheKey = "report_{$id}_{$format}";

    $html = Cache::remember($cacheKey, 300, function () use ($id, $format) {
        $url = "http://127.0.0.1:8080/api/render/{$id}?format={$format}";
        return Http::timeout(30)->get($url)->body();
    });

    return response($html, 200)
        ->header('Content-Type', $format === 'pdf' ? 'application/pdf' : 'text/html; charset=utf-8');
}
```

### Proxy ad-hoc preview (POST relay)

If you need to preview an unsaved definition from your app, relay the POST to the engine's preview endpoint:

```php
// Plain PHP example
function proxyPreview(string $definitionJson, string $format = 'html'): string
{
    $ch = curl_init('http://127.0.0.1:8080/api/render/preview');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'json' => $definitionJson,
            'format' => $format,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new \RuntimeException('Preview failed: HTTP ' . $httpCode);
    }
    return $result;
}
```

### Security notes

- **Keep the engine on an internal network** — bind it to `127.0.0.1` or a private subnet so only your proxy app can reach it. Never expose the engine port to the public internet.
- **Validate forwarded query parameters** — only forward `param_*` keys; strip anything else.
- **Timeout** — set a reasonable HTTP timeout (30 s) so a slow render doesn't hang your app.
- **Error handling** — check HTTP status codes from the engine and return a user-friendly error (502 Bad Gateway) instead of propagating the raw engine error.
- **Rate limiting** — add rate limiting on your proxy route if needed; the engine has none built in.
- **Engine internal auth** — if you want an extra layer between your proxy and the engine, enable the engine's built-in auth and pass the token as a `Bearer` header in your proxy's fetch call:

```php
$opts = [
    'http' => [
        'header' => "Authorization: Bearer " . getenv('ENGINE_API_TOKEN'),
    ],
];
$context = stream_context_create($opts);
$html = file_get_contents($engineUrl, false, $context);
```

---

## 6. Report Definition Structure Reference

The definition JSON for ad-hoc rendering has this shape:

```json
{
  "version": "1.0",
  "page": {
    "paperSize": "A4",
    "orientation": "portrait",
    "marginTop": 20,
    "marginBottom": 20,
    "marginLeft": 15,
    "marginRight": 15
  },
  "query": {
    "sql": "SELECT * FROM orders",
    "parameters": [
      { "name": "status", "type": "string", "defaultValue": "" }
    ]
  },
  "groups": [
    { "id": "g1", "fieldName": "customer_id", "level": 0, "sortDirection": "ASC",
      "showHeader": true, "showFooter": true, "reprintHeaderOnNewPage": true }
  ],
  "bands": [
    {
      "type": "detail",
      "height": 16,
      "backgroundColor": "transparent",
      "printOnEveryPage": false,
      "elements": [
        {
          "id": "e1",
          "type": "field",
          "fieldName": "customer_name",
          "top": 2,
          "left": 10,
          "width": 100,
          "height": 12,
          "fontFamily": "Arial",
          "fontSize": 10,
          "bold": false,
          "italic": false,
          "underline": false,
          "color": "#000000",
          "textAlign": "left",
          "verticalAlign": "top",
          "backgroundColor": "transparent",
          "border": {},
          "inheritStyle": true,
          "format": ""
        }
      ],
      "border": {}
    }
  ]
}
```

**Band types:** `page_header`, `report_header`, `column_header`, `group_header`, `detail`, `group_footer`, `report_footer`, `page_footer`

**Element types:** `label`, `field`, `aggregate`, `image`, `line`, `rect`, `pageno`, `rowno`, `datetime`

**Aggregate functions** (for `aggregate` elements): `sum`, `count`, `avg`, `min`, `max`

**Aggregate scopes:** `group` (per-group subtotal), `report` (grand total)

---

## 7. Summary

| Goal | Best Approach |
|------|--------------|
| Embed a live HTML report in another web app | `<iframe>` pointing at `/api/render/{id}?format=html` |
| Fetch report HTML programmatically from browser | `fetch('/api/render/{id}')` |
| Let users download PDF from browser | `window.open('/api/render/{id}?format=pdf')` |
| Keep engine private, embed via your own app | **PHP proxy** — your app fetches engine internally and relays response |
| Render a report server-side in PHP | Install as Composer dep, call `HtmlRenderer::render()` |
| Render with your own data (no engine DB) | Build `ReportDefinition` manually, pass `$data` directly |
| Protect public endpoints | Reverse-proxy auth or shared secret middleware |
