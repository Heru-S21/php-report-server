# PHP Reporting Engine — Implementation Plan 001

## Overview
8 features ordered by estimated effort. Each section contains background context, implementation steps, files to modify, test strategy, and rollback notes.

---

## 1. Server-side Font Metrics Cache

### Problem
Font metrics are measured on every preview request (client-side canvas) and sent via POST body. They are lost on page refresh, unavailable on the GET `/api/render/{id}` path, and unused when reports are exported directly from the server.

### Solution
Store font metrics inside the report definition JSON after first measurement. Re-measure only when the definition changes (new/removed word-wrapped elements, font/size/style changes).

### Implementation Steps

**Client-side — Designer.js**
1. In `measureFontMetrics()`, after computing results, store them in the definition object:
   ```javascript
   if (Object.keys(results).length > 0) {
       definition.fontMetrics = results;
   }
   ```
2. In `autosave()`, the definition (including `fontMetrics`) is already serialized to localStorage — no extra save step needed.
3. In `restoreFromStorage()`, when a draft is loaded, check if `definition.fontMetrics` exists. If so, set an internal flag so the next preview skips `measureFontMetrics()`.
4. In the `constructor` (or wherever elements are modified), clear `definition.fontMetrics` whenever an element's `wordWrap`, `fontFamily`, `fontSize`, `bold`, or `italic` is changed:
   ```javascript
   // Inside updateField or after addElement/duplicateElement/etc.
   this.clearFontMetrics();
   ```
5. Add a `clearFontMetrics()` method that deletes `definition.fontMetrics` if it exists.

**Client-side — preview.php**
6. In `loadPreview()`, check `this.definition.fontMetrics` before calling `measureFontMetrics()`:
   ```javascript
   let fontMetrics = this.definition.fontMetrics || {};
   if (Object.keys(fontMetrics).length === 0) {
       fontMetrics = this.measureFontMetrics(this.definition);
       if (Object.keys(fontMetrics).length > 0) {
           this.definition.fontMetrics = fontMetrics;
       }
   }
   ```

**Server-side — RenderController.php**
7. In `RenderController::render()` (GET path), extract `fontMetrics` from the loaded definition if present:
   ```php
   $body = $request->body;
   if (isset($definition->fontMetrics) && is_array($definition->fontMetrics)) {
       $body['_fontMetrics'] = $definition->fontMetrics;
   }
   ```
   Pass `$body` to `$renderer->render()` (currently passes empty `$params`).

**Files to modify:**
- `js/designer/Designer.js` — add `clearFontMetrics()`, modify `measureFontMetrics` callers
- `views/reports/preview.php` — check cached font metrics before measuring
- `src/Api/RenderController.php` — extract font metrics from definition for GET path

### Edge cases
- Word wrap disabled on all elements → `definition.fontMetrics` should be removed or set to `{}`
- Existing reports without `fontMetrics` in their definition → measure on first preview after upgrade
- Bold/italic change on a word-wrapped element → font metrics are stale and must be cleared

### Test Strategy
1. Create a report with word-wrapped elements → preview once (metrics measured and cached) → preview again (check that `measureFontMetrics` is skipped via console log or network tab)
2. Export PDF/HTML from designer → verify no `_fontMetrics` in request body (cached in definition)
3. Change font size on a word-wrapped element → verify metrics are cleared on next save/preview
4. Change word wrap from on to off → verify metrics are cleared

### Rollback
Revert all changes to the 4 files listed above.

---

## 2. Undo/Redo History Stack

### Problem
The current undo/redo (`Ctrl+Z`/`Ctrl+Shift+Z`) has limited coverage. Only some operations push to `window.ReportingEngine.history`. Element move, resize, property edits, add/delete/duplicate/paste, and band reorder/height changes may not be tracked.

### Solution
Replace the existing simple history with a shallow-copy snapshot stack that captures the full bands array (source of truth) on every mutation. Snapshots store `{ bands: [...], selectedElement: string|null }`.

### Implementation Steps

**Core — capture mechanism**
1. In `Designer.js`, add a `captureHistory()` method:
   ```javascript
   captureHistory() {
       const snapshot = {
           bands: JSON.parse(JSON.stringify(this.bands)),
           selectedElement: window.ReportingEngine.state.selectedElement || null,
       };
       window.ReportingEngine.history.push(snapshot);
       // Prune future if we branched
       if (window.ReportingEngine.historyIndex < window.ReportingEngine.history.length - 1) {
           window.ReportingEngine.history = window.ReportingEngine.history.slice(0, window.ReportingEngine.historyIndex + 1);
       }
       window.ReportingEngine.historyIndex = window.ReportingEngine.history.length - 1;
   }
   ```

2. In the existing `window.ReportingEngine` state, add:
   ```javascript
   history: [],
   historyIndex: -1,
   ```

3. Replace the direct `this.bands = ...` / `this.bands.push(...)` / `band.elements.push(...)` mutations throughout `Designer.js` with a wrapper:
   - `pushHistory()` → calls `captureHistory()` then performs mutation.
   - For bulk operations (load definition, restore from storage), skip capture by passing a flag or calling a separate method.

**Undo/Redo methods**
4. Add `undo()` and `redo()` methods:
   ```javascript
   undo() {
       if (window.ReportingEngine.historyIndex <= 0) return;
       window.ReportingEngine.historyIndex--;
       this.restoreSnapshot(window.ReportingEngine.history[window.ReportingEngine.historyIndex]);
   }
   redo() {
       if (window.ReportingEngine.historyIndex >= window.ReportingEngine.history.length - 1) return;
       window.ReportingEngine.historyIndex++;
       this.restoreSnapshot(window.ReportingEngine.history[window.ReportingEngine.historyIndex]);
   }
   restoreSnapshot(snapshot) {
       this.bands = JSON.parse(JSON.stringify(snapshot.bands));
       // Restore selection
       if (snapshot.selectedElement) {
           this.selectElementById(snapshot.selectedElement);
       }
       this.renderCanvas();
   }
   ```

**Mutation points to instrument**
5. Search all locations in `Designer.js` that mutate `band.elements` or `bands`:
   - `addElement()` — push to elements array
   - `deleteElement()` — splice from elements
   - `moveElement()` — update top/left
   - `resizeElement()` — update width/height
   - `duplicateElement()` — push cloned element
   - `pasteElement()` — push
   - `pasteStyle()` — update properties
   - `copyStyle()` — no mutation, skip
   - `updateBandOrder()` — reorder bands array
   - `resizeBand()` — update band.height
   - `addBand()` / `removeBand()` — push/splice bands
   - `loadReport()` — full replace (skip capture)
   - `restoreFromStorage()` — full replace (skip capture)

   For each mutation point, call `captureHistory()` immediately before the mutation.

6. In `ElementEditor.js`, `updateField()` and `updateBandField()` call `captureHistory()` before modifying element/band properties.

7. In `BandManager.js`, if it exists, instrument its band add/remove/reorder methods.

**Keyboard bindings**
8. In the Designer constructor or `init()`:
   ```javascript
   document.addEventListener('keydown', (e) => {
       if ((e.ctrlKey || e.metaKey) && e.key === 'z') {
           e.preventDefault();
           if (e.shiftKey) this.redo();
           else this.undo();
       }
       if ((e.ctrlKey || e.metaKey) && e.key === 'y') {
           e.preventDefault();
           this.redo();
       }
   });
   ```

**Undo stack size limit**
9. Prune the history array when it exceeds a limit (e.g., 100 entries):
   ```javascript
   if (window.ReportingEngine.history.length > 100) {
       window.ReportingEngine.history.shift();
       window.ReportingEngine.historyIndex--;
   }
   ```

**UI indicator**
10. Add a subtle visual indicator (e.g., gray out undo/redo toolbar buttons when unavailable) or a tooltip showing available steps.

### Files to modify
- `js/designer/Designer.js` — core undo/redo, snapshot, instrument mutations
- `js/designer/ElementEditor.js` — capture before property changes
- `js/designer/BandManager.js` — capture before band mutations
- `js/app.js` — initialize `history: [], historyIndex: -1` in state

### Edge cases
- Load definition → history is cleared (start fresh)
- Undo past the first snapshot → block (index 0 is initial state)
- Redo past latest → block
- Mutation between undo and redo → discard redo future (branched history)
- Very large definitions → deep clone may be expensive; consider structured clone or reference-based approach with selector tracking

### Test Strategy
1. Add element → Ctrl+Z → element removed → Ctrl+Shift+Z → element restored
2. Move element → Ctrl+Z → snapped back → Ctrl+Y → re-moved
3. Resize → change property → add band → Ctrl+Z x4 → all reversed
4. Add → undo → add again → Ctrl+Z → only the second add is undone (branched)
5. Load different report → history is empty (no bleed from previous report)

### Rollback
Revert all changes to InstrumentedMutation functions and restore simple inline mutations.

---

## 3. Element Alignment & Distribution Tools

### Problem
Manually positioning multiple elements to the same left/right/top/bottom or evenly distributing them requires pixel-pushing. No batch alignment tools exist.

### Solution
Add a toolbar button group with alignment actions: Align Left, Align Right, Align Top, Align Bottom, Align Middle (vertical center), Align Center (horizontal center), Distribute Horizontally, Distribute Vertically. Operates on all currently selected elements within the same band.

### Implementation Steps

**Designer.js — alignment methods**
1. `getSelectedElements()` — collect elements across all bands where `selected == true` (or matching `state.selectedElement` by id). Return array of `{el, band}` pairs.
2. `alignElements(direction)`:
   - Validate selected count >= 2 and same band.
   - For `left`/`right`/`top`/`bottom`: pick the reference value (min left, max right, etc.) from the first selected or from all (pick extremes). Apply to all.
   - For `middle` (vertical center): compute the average of `(top + height/2)` across selected, set each element's `top = avgCenter - height/2`.
   - For `center` (horizontal center): compute average of `(left + width/2)`, set each element's `left = avgCenter - width/2`.
   - Snap values using `snapValue()`.
   - Call `captureHistory()`, `renderCanvas()`, set dirty.
3. `distributeElements(direction)`:
   - Sort elements by `direction === 'horizontal' ? left : top`.
   - Compute total span from first element's left/top + width/height to last element's.
   - Space remaining = total span - sum of widths/heights of middle elements.
   - Divide by (count - 1) to get gap.
   - Position each element = prev element's left/top + width/height + gap.
   - Snap each position.

**Toolbar UI — designer.php**
4. Add a toolbar section in the designer view for alignment tools:
   ```html
   <div class="toolbar-group" title="Align">
       <button onclick="designer.alignElements('left')" title="Align Left"><i class="ph-align-left"></i></button>
       <button onclick="designer.alignElements('center')" title="Align Center"><i class="ph-align-center-horizontal"></i></button>
       <button onclick="designer.alignElements('right')" title="Align Right"><i class="ph-align-right"></i></button>
       <button onclick="designer.alignElements('top')" title="Align Top"><i class="ph-align-top"></i></button>
       <button onclick="designer.alignElements('middle')" title="Align Middle"><i class="ph-align-center-vertical"></i></button>
       <button onclick="designer.alignElements('bottom')" title="Align Bottom"><i class="ph-align-bottom"></i></button>
   </div>
   <div class="toolbar-group" title="Distribute">
       <button onclick="designer.distributeElements('horizontal')" title="Distribute Horizontally"><i class="ph-distribute-horizontal"></i></button>
       <button onclick="designer.distributeElements('vertical')" title="Distribute Vertically"><i class="ph-distribute-vertical"></i></button>
   </div>
   ```

**Icon considerations**
5. Use Phosphor icons if available (`ph-align-*`, `ph-distribute-*`). If not available in the project's icon set, use text labels or SVG inline.

**Disabled state**
6. When fewer than 2 elements are selected, disable (gray out) the toolbar buttons. Add a method `updateAlignmentButtons()` called from `selectElement()` / `deselectElement()`.

### Files to modify
- `js/designer/Designer.js` — `alignElements()`, `distributeElements()`, `getSelectedElements()`
- `views/reports/designer.php` — toolbar HTML
- `css/designer.css` — toolbar group styles if needed

### Edge cases
- Elements in different bands → show toast "Select elements in the same band"
- Single element selected → buttons disabled
- Distribute with 2 elements → places one at start, one at end (no middle elements to space)
- Distribute with overlap → elements may overlap after distribution (acceptable, user can adjust)

### Test Strategy
1. Place 3 elements at different left positions → select all → "Align Left" → all move to same left
2. Place 3 elements stacked → "Distribute Vertically" → equal spacing between them
3. Place 2 elements → "Distribute" → first stays, last moves to edge (or behaves predictably)
4. Elements in different bands → toast error

### Rollback
Remove toolbar buttons, revert alignment/distribution methods.

---

## 4. Visibility Expressions

### Problem
Elements can only be conditionally styled (CSS overrides). They cannot be conditionally hidden/shown based on data values at render time. This forces users to create duplicate report definitions for slight variations.

### Solution
Add a `visibleExpression` property (PHP expression string) to `BandElement`. At render time, evaluate it. If false, skip the element entirely (same path as `conditionalExpression` but controlling presence rather than style).

### Implementation Steps

**Backend — BandElement.php**
1. Add property:
   ```php
   public ?string $visibleExpression = null;
   ```
2. Update `fromArray()`:
   ```php
   $el->visibleExpression = $data['visibleExpression'] ?? null;
   ```
3. Update `toArray()`:
   ```php
   'visibleExpression' => $this->visibleExpression,
   ```

**Backend — HtmlRenderer.php**
4. In `renderSingleElement()`, before rendering, evaluate visibility:
   ```php
   if ($el->visibleExpression !== null && !ExpressionEvaluator::evaluateBool(
       $el->visibleExpression,
       $data instanceof AggregateAccumulator ? $data->getLastValues() : ($data ?: [])
   )) {
       return '';  // skip element entirely
   }
   ```
   Place this check BEFORE the conditionalExpression check (which skips on false).
5. In `calculateEffectiveBandHeight()`, add the same check before processing element.

**Backend — PdfRenderer.php**
6. Same change in `renderElementHtml()` and `calculateEffectiveBandHeight()`.

**Backend — ExpressionEvaluator.php**
7. No changes needed — `evaluateBool()` already handles PHP expression evaluation.

**Frontend — ElementEditor.js**
8. In `renderAdvancedTab()`, add a text input for the visible expression:
   ```javascript
   <div class="prop-group">
       <label>Visibility Expression (PHP)</label>
       <textarea class="prop-control" rows="3" style="font-family:var(--font-mono);font-size:12px"
           onchange="window.elementEditor.updateField('visibleExpression', this.value)"
           placeholder="e.g. field_value > 0">${escapeHtml(el.visibleExpression || '')}</textarea>
   </div>
   ```

**Frontend — Designer.js**
9. In `addElement()`, set default:
   ```javascript
   visibleExpression: null,
   ```

**Documentation**
10. Note in Advanced tab help text that returning `false` hides the element.

### Files to modify
- `src/Report/BandElement.php` — property, fromArray, toArray
- `src/Renderer/HtmlRenderer.php` — check before rendering
- `src/Renderer/PdfRenderer.php` — check before rendering
- `js/designer/ElementEditor.js` — UI input in Advanced tab
- `js/designer/Designer.js` — default in addElement

### Edge cases
- Expression syntax error → `ExpressionEvaluator` returns null/empty → treat as visible (fail-open)
- Expression referring to non-existent field → PHP notice/warning → wrap in `@` or handle gracefully in ExpressionEvaluator
- `visibleExpression` combined with `conditionalExpression` → visibility check runs first; if hidden, conditional style never evaluated
- Aggregate footer context → visibleExpression receives last-values array (same as conditionalExpression)

### Test Strategy
1. Set `visibleExpression` to `1 === 0` → element is never rendered
2. Set `visibleExpression` to `1 === 1` → element always renders (same as no expression)
3. Use a field expression like `amount > 100` → element only renders on rows where amount exceeds 100
4. Verify conditionalStyle still works on visible elements
5. Verify hidden elements do not affect band height (calculateEffectiveBandHeight skips them)

### Rollback
Remove property, revert renderer checks, remove UI input.

---

## 5. Enhanced Parameter UI

### Problem
Parameters are currently text/date/number/boolean types. No cascading, no dynamic choice lists from queries, no multi-select.

### Solution
Add a `dropdown` parameter type with optional `querySource` that loads options from a SQL query. Add a `cascade` parameter that depends on another parameter's value. Store dropdown options in the definition, not at render time, so the designer can preview available choices.

### Implementation Steps

**Backend — Parameter model**
1. If parameters are stored as array in `definition.query.parameters`, add new fields:
   - `type`: `'string' | 'number' | 'date' | 'boolean' | 'dropdown' | 'multi-select'`
   - `options`: `string[]` (static choices)
   - `querySource`: `string` (SQL that returns `{value, label}` or `{value}`)
   - `dependsOn`: `string` (name of another parameter this one cascades from)

2. In `QueryRunner.php` or equivalent, add a method `resolveParameterOptions(sql, context)` that executes the query and returns option list. Securely handle the case where `querySource` is user-supplied (validate table/view access).

**Backend — API endpoint for dynamic options**
3. Add `POST /api/reports/{id}/parameter-options` that:
   - Loads the report definition
   - Finds the requested parameter by name
   - If `querySource` is set, resolves `:parentParam` placeholders with current values
   - Executes query and returns `{data: [{value, label}]}`

**Frontend — preview.php**
4. In `renderParamForm()`, extend the dropdown type:
   - Add `'dropdown'` case rendering a `<select>` populated from `options` or from the API endpoint.
   - Add `'multi-select'` case rendering checkboxes or a multi-select widget.
5. If `dependsOn` is set, attach an `onchange` handler to the parent parameter that fetches fresh options for the child.

**Frontend — designer.php / Parameter editor**
6. Add a parameter editor UI (could be a modal or inline panel) accessible from the Data Source tab when parameters exist. Allow setting:
   - Name, Type (`string|number|date|boolean|dropdown|multi-select`), Default value
   - For dropdown: add/remove static options, or enter a query SQL
   - For cascading: select which parameter this depends on

**Backend — RenderController::preview() / render()**
7. When processing parameters for data fetch, resolve dropdown parameter values as literal values (not expressions).
8. For multi-select, accept comma-separated values or JSON arrays and pass them to the query as `IN (:param)` with proper array handling.

### Files to modify
- `src/Api/RenderController.php` — new endpoint for dynamic options, update fetchData
- `src/Query/QueryRunner.php` — resolveParameterOptions()
- `views/reports/preview.php` — extended param types, cascade support
- `views/reports/designer.php` — parameter editor UI
- `js/designer/Designer.js` — parameter editing state handling

### Edge cases
- Circular cascade (A→B→A) → detect and refuse
- `querySource` error → return empty options with a console.warn
- Multi-select + no items selected → omit parameter from query (or pass NULL)
- Parameter name collision with reserved names → validate on save

### Test Strategy
1. Create a dropdown parameter with static `['Yes', 'No', 'Maybe']` → rendered as select in preview
2. Create a cascading parameter A → dropdown B depends on A → changing A reloads B's options
3. Multi-select parameter → rendered as checkboxes, value passed as `val1,val2` or JSON array
4. `querySource` parameter → API call returns fresh options on page load

### Rollback
Remove new endpoint, revert preview.php parameter UI, remove option/cascade fields from parameter model.

---

## 6. Report Scheduling

### Problem
No way to automatically generate and deliver reports on a recurring basis.

### Solution
A `schedules` table, a Schedule model, API CRUD, a parameter input UI in the designer, and a standalone CLI entry point for cron.

### Implementation Steps

**Database**
```sql
CREATE TABLE schedules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    report_id INTEGER NOT NULL REFERENCES reports(id) ON DELETE CASCADE,
    name TEXT NOT NULL DEFAULT '',
    cron TEXT NOT NULL DEFAULT '0 6 * * *',
    format TEXT NOT NULL DEFAULT 'pdf',
    recipients TEXT NOT NULL DEFAULT '',
    params TEXT NOT NULL DEFAULT '{}',   -- JSON of parameter overrides
    enabled INTEGER NOT NULL DEFAULT 1,
    last_run_at TEXT,
    next_run_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
```

**Backend — Schedule model**
1. Create `src/Report/Schedule.php` with:
   - Properties matching table columns
   - `fromArray()`, `toArray()` static/dynamic methods
   - `computeNextRun()` that parses cron and calculates next timestamp (use `cron-expression/cron-expression` composer package or implement basic cron parsing)

**Backend — ScheduleController**
2. Create `src/Api/ScheduleController.php` with CRUD:
   - `GET /api/schedules/{reportId}` — list schedules for a report
   - `POST /api/schedules/{reportId}` — create schedule
   - `PUT /api/schedules/{id}` — update schedule
   - `DELETE /api/schedules/{id}` — delete schedule
   - `PUT /api/schedules/{id}/toggle` — enable/disable

**Backend — CLI runner**
3. Create `bin/send-scheduled`:
   ```php
   #!/usr/bin/env php
   <?php
   require __DIR__ . '/../vendor/autoload.php';
   // Load schedules where next_run_at <= now AND enabled=1
   // For each schedule:
   //   1. Load report definition
   //   2. Render to specified format
   //   3. Email to recipients (use PHPMailer or mail())
   //   4. Update last_run_at, compute and set next_run_at
   ```
   Install in composer scripts:
   ```json
   "scripts": {
       "schedule:run": "php bin/send-scheduled"
   }
   ```

**Backend — Email**
4. Add an email sender utility in `src/Core/Mailer.php`:
   - Accept: to, subject, body (HTML), attachment (PDF/HTML file content)
   - Use PHP's `mail()` for simplicity, or integrate PHPMailer/Symfony Mailer
   - Config in `config/app.php`: `'mail' => ['from' => 'reports@example.com', 'transport' => 'mail']`

**Frontend — Schedule UI**
5. In the designer, add a "Schedule" tab/button that opens a modal showing:
   - List of existing schedules with enable/disable toggle
   - "Add Schedule" form: name, cron expression, format select, recipients textarea, parameter overrides
   - Next run time preview (show human-readable time for the cron expression)
   - Last run time

**Frontend — designer.php**
6. Include the Schedule UI partial/modal HTML.

**Cron expression validator helper**
7. Add a JS function `validateCron(expr)` that returns an error string or null.

### Files to modify
- `src/Report/Schedule.php` — new file
- `src/Api/ScheduleController.php` — new file
- `bin/send-scheduled` — new file
- `src/Core/Mailer.php` — new file
- `src/routes.php` — register schedule routes
- `config/app.php` — mail config section
- `views/reports/designer.php` — schedule UI (modal, list)
- `js/designer/Designer.js` — schedule API calls
- `composer.json` — add cron-expression dependency, scripts entry

### Edge cases
- Invalid cron expression → reject with error message
- Recipients field empty → valid (generate but don't send; can be downloaded)
- Report deleted → cascade deletes schedules (ON DELETE CASCADE)
- Parameter override for parameters that no longer exist → warn but proceed
- Schedule due at server midnight → cron runs at odd hour; next_run_at computed from cron

### Test Strategy
1. Create schedule via UI → verify row inserted in schedules table
2. Toggle enable/disable → verify enabled flag toggled
3. Run `php bin/send-scheduled` → schedule marked as last_run_at, next_run_at advanced
4. Invalid cron → error shown in UI
5. Delete report → schedules cascade-deleted

### Rollback
Drop schedules table, remove Schedule files, revert routes and designer UI.

---

## 7. Subtotal / Grand Total Wizards

### Problem
Creating group subtotals requires manually: creating a group footer band, adding an aggregate element, setting function/scope/field/format. This is tedious for every grouped field.

### Solution
Context menu on field elements in the designer canvas: "Add Subtotal" auto-creates footer band + aggregate element. "Add Grand Total" does the same at report footer level.

### Implementation Steps

**Designer.js — subtotal method**
1. `addSubtotal(fieldElementId)`:
   - Locate the field element and its band.
   - Find its parent group definition (look at bands to find a group_header with matching `groupField`).
   - If no group exists for this field, show toast "Field is not grouped". (Or auto-create a group.)
   - Find or create a group_footer band for the group.
   - Create an aggregate element with:
     - `type: 'aggregate'`
     - `fieldName: fieldElement.fieldName`
     - `aggregateFunc: 'sum'`
     - `aggregateScope: 'group'`
     - `format: '#,##0.00'`
     - Position: placed at `left: fieldElement.left, top: 0` in the footer band.
     - Width/height matching the source field.
     - `fontFamily` inherited from the source field.
   - Call `captureHistory()`, `renderCanvas()`, set dirty.

2. `addGrandTotal(fieldElementId)`:
   - Same as subtotal but targets the report_footer band.
   - `aggregateScope: 'report'`.

**Context menu integration — ContextMenu.js**
3. In the context menu items, add "Add Subtotal" and "Add Grand Total" entries when the right-clicked element is a `field` type:
   ```javascript
   if (el.type === 'field') {
       items.push(
           { label: 'Add Subtotal', icon: 'ph-calculator', action: () => designer.addSubtotal(el.id) },
           { label: 'Add Grand Total', icon: 'ph-calculator', action: () => designer.addGrandTotal(el.id) }
       );
   }
   ```

**Method to locate band by group field**
4. Add `findBandByType(type, groupField)` helper that scans `definition.bands` for a band matching the type and optional group field.

**Aggregate scope / function editor — ElementEditor.js**
5. The aggregate function/scope fields already exist in ElementEditor. Ensure they render correctly for auto-created aggregates.

### Files to modify
- `js/designer/Designer.js` — `addSubtotal()`, `addGrandTotal()`, helper methods
- `js/designer/ContextMenu.js` — menu items for field types

### Edge cases
- Group footer band already exists → reuse it (don't duplicate)
- Aggregate element already exists for this field + scope → show toast "Subtotal already exists"
- Grand total with no report footer band → auto-create report_footer band
- Field not in any group → offer to auto-create the group (or show error with "Create Group" action)
- Format on source field uses a custom format → copy it to the aggregate element

### Test Strategy
1. Right-click a field element inside a group → "Add Subtotal" → footer band created with aggregate element
2. Right-click → "Add Grand Total" → aggregate added to report footer
3. Run preview → subtotal row shows sum per group, grand total shows sum of all
4. Repeat "Add Subtotal" → toast "already exists" (or skip duplicate)

### Rollback
Remove context menu items, revert Designer.js additions.

---

## 8. Barcode / QR Code Element Type

### Problem
No barcode or QR code rendering capability. Enterprise reports commonly need these for labels, invoices, shipment documents.

### Solution
New element type `barcode` that accepts a value expression and renders a barcode/QR image using a PHP barcode library. Configurable symbology (code128, qr, ean13, etc.) and display options (width, height, showText).

### Implementation Steps

**Composer dependency**
1. `composer require picqer/php-barcode-generator`

**Backend — BandElement.php**
2. Add properties:
   ```php
   public ?string $barcodeSymbology = 'code128';  // code128, qr, ean13, etc.
   public bool $barcodeShowText = true;
   public ?string $barcodeExpression = null;  // value expression (resolved at render time)
   ```
3. Update `fromArray()` / `toArray()`.

**Backend — Barcode rendering utility**
4. Create `src/Renderer/BarcodeRenderer.php`:
   ```php
   class BarcodeRenderer {
       public static function render(string $value, string $symbology = 'code128', bool $showText = true): string {
           // Returns base64-encoded PNG data URI or HTML img tag
           $generator = new \Picqer\Barcode\BarcodeGeneratorPNG();
           $barcode = $generator->getBarcode($value, $generator::TYPE_CODE_128);
           return 'data:image/png;base64,' . base64_encode($barcode);
       }
       public static function renderSvg(string $value, string $symbology): string {
           // SVG version for PDF compatibility
       }
   }
   ```

**Backend — HtmlRenderer.php**
5. In `getElementValue()`, add `'barcode'` case:
   ```php
   'barcode' => $this->renderBarcode($el, $data),
   ```
6. Add `renderBarcode()` method:
   ```php
   private function renderBarcode(BandElement $el, $data): string {
       $value = $el->barcodeExpression
           ? ExpressionEvaluator::evaluate($el->barcodeExpression, ...)
           : ($el->text ?? '');
       $src = BarcodeRenderer::render($value, $el->barcodeSymbology ?? 'code128', $el->barcodeShowText ?? true);
       return sprintf('<img src="%s" style="width:100%%;height:100%%;object-fit:contain">', $src);
   }
   ```

**Backend — PdfRenderer.php**
7. Same changes. Use mPDF's built-in barcode support if available (`$mpdf->WriteHTML('<barcode code="..." type="..." />')`) or embed the PNG.

**Frontend — Designer.js**
8. Add to `getElementDefaults()`:
   ```javascript
   barcode: { width: 50, height: 20, text: null },
   ```
9. In `addElement()`, add barcode-specific defaults:
   ```javascript
   if (type === 'barcode') {
       el.barcodeSymbology = 'code128';
       el.barcodeShowText = true;
       el.barcodeExpression = '';
   }
   ```
10. The `barcode` type should NOT be a text type — exclude it from `isTextEl` and from `wordWrap` / text-overflow logic.

**Frontend — toolbox**
11. In `views/reports/designer.php` or the toolbox rendering code, add a barcode draggable button:
    ```html
    <div class="toolbox-item" draggable="true" data-type="barcode">
        <i class="ph-barcode"></i> Barcode
    </div>
    ```

**Frontend — ElementEditor.js**
12. In `render`, add configuration fields for barcode:
    - Symbology select: `code128`, `qr`, `ean13`, `ean8`, `upca`, `code39`, `pdf417`, `datamatrix`
    - Show text checkbox
    - Value expression text input

**Icons**
13. Phosphor icon `ph-barcode` exists — use it.

### Files to modify
- `composer.json` — add picqer/php-barcode-generator
- `composer.lock` — generated
- `src/Report/BandElement.php` — barcode properties
- `src/Renderer/BarcodeRenderer.php` — new file
- `src/Renderer/HtmlRenderer.php` — getElementValue, renderBarcode
- `src/Renderer/PdfRenderer.php` — getElementValue, renderBarcode
- `js/designer/Designer.js` — defaults, addElement type handling
- `js/designer/ElementEditor.js` — barcode config UI
- `views/reports/designer.php` — toolbox button

### Edge cases
- Empty value expression → render nothing (or "Invalid barcode value" placeholder)
- Unsupported symbology → fall back to code128
- Value too long for symbology (e.g., EAN13 requires exactly 13 digits) → show warning in preview
- PDF renderer → mPDF may not support all symbologies; fall back to PNG embedding
- Barcode inside grouped footer → expression may reference group aggregates

### Test Strategy
1. Drop barcode element on canvas → configure value "1234567890" → preview shows rendered barcode
2. Test with QR code → value "https://example.com" → renders QR
3. Test with EAN13 → 13-digit value → renders EAN barcode
4. PDF export → barcode appears in PDF
5. Word wrap / overflow → barcode element should NOT have text-overflow/white-space treatment

### Rollback
Remove composer dependency, revert BandElement properties, remove BarcodeRenderer, revert renderer changes, remove toolbox button and editor UI.

---

## Summary Table

| # | Feature | Files Changed | New Files | Estimated Effort | Risk |
|---|---------|--------------|-----------|------------------|------|
| 1 | Font Metrics Cache | 3 | 0 | Small | Low |
| 2 | Undo/Redo | 3 | 0 | Medium | Medium |
| 3 | Alignment Tools | 2 | 0 | Medium | Low |
| 4 | Visibility Expressions | 5 | 0 | Small | Low |
| 5 | Enhanced Parameters | 4 | 0 | Large | High |
| 6 | Scheduling | 5 | 4 | Extra Large | High |
| 7 | Subtotal Wizards | 2 | 0 | Small | Low |
| 8 | Barcode Element | 6 | 2 | Medium | Medium |

**Recommended order:** 1 → 4 → 7 → 3 → 2 → 8 → 5 → 6

Each feature is independently rollbackable. No feature depends on another.

## Appendix: Naming Conventions

- PHP namespaces: `ReportingEngine\Report\`, `ReportingEngine\Renderer\`, `ReportingEngine\Api\`
- JS classes/methods: camelCase, e.g., `alignElements()`, `captureHistory()`
- Database tables: snake_case, plural, e.g., `schedules`
- CSS classes: kebab-case, e.g., `.toolbar-group`, `.barcode-config`
- API routes: `GET/POST/PUT/DELETE /api/resource/{id}`

## Appendix: Verification Checklist

For each feature, before marking complete:
- [ ] `php -l` on all modified PHP files
- [ ] `node -c` on all modified JS files
- [ ] Browser test: relevant UI renders without console errors
- [ ] Browser test: operation produces expected output
- [ ] Browser test: undo (if applicable) restores previous state
- [ ] Browser test: export PDF/HTML includes the feature's output
