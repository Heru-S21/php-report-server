## Progress

### Done
- (previous entries preserved; see git log for full history)
- **Feature 1 — Font Metrics Cache**: `ReportDefinition.fontMetrics` stored in definition JSON; `measureFontMetrics()` returns cached; cleared on element mutations; GET render path passes definition-stored metrics
- **Feature 4 — Visibility Expressions**: `visibleExpression` on BandElement; skip-render check in HtmlRenderer/PdfRenderer (renderSingleElement, renderElementHtml, renderBandElement, calculateEffectiveBandHeight); UI input in ElementEditor Advanced tab
- **Feature 7 — Subtotal / Grand Total Wizards**: context menu on field elements auto-creates footer band + aggregate element (sum/group or sum/report); `findBandByType()` helper; band auto-creation at correct position
- **Feature 3 — Element Alignment & Distribution Tools**: multi-select with Ctrl+Click; `getSelectedElements()`, `alignElements()` (left/right/top/bottom/middle/center), `distributeElements()` (horizontal/vertical); toolbar buttons with disabled state; CSS dashed outline for multi-selected
- **Feature 2 — Undo/Redo History Stack**: single `history` array + index replaces dual-stack; deep-clone snapshots (definition + bands + selection); proper branching; 100-entry limit; all 20+ mutation points instrumented
- **Feature 8 — Barcode / QR Code Element Type**: `picqer/php-barcode-generator` installed; `BandElement` barcode properties; `BarcodeRenderer` (PNG data URI + SVG); `getElementValue` barcode case in both renderers; toolbox button; editor UI (symbology, value expression, show text)
- **Feature 5 — Enhanced Parameter UI**: `dropdown` and `multi-select` parameter types; static options textarea; `dependsOn` cascading; preview renders select/checkboxes; comma-separated multi-select values
- **Font Embedding System**: `src/Api/FontController.php` (index/reload/file); font cache in `data/fonts/cache.json`; routes `GET/POST /api/fonts`, `GET /api/fonts/file/{filename}`; `@font-face` CSS in `HtmlRenderer::getBaseStyles()`; mPDF `fontDir` + `fontdata` in `PdfRenderer`; "Uploaded Fonts" optgroup in `ElementEditor.js` (fetches `/api/fonts`); "Reload Font Cache" button in designer hamburger menu; `updateFontFaceStyles()` for canvas `@font-face`; `/api/fonts/file/` in Auth bypass list; `RenderController::injectFontCache()` passes `_fonts` param to both renderers

### Skipped
- (none intentionally; Feature 6 — Scheduling deferred)

### Blocked
- (none)
