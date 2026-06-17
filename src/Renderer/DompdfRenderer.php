<?php

namespace ReportingEngine\Renderer;

use Dompdf\Dompdf;
use Dompdf\Options;
use ReportingEngine\Report\ReportDefinition;
use ReportingEngine\Report\Band;
use ReportingEngine\Report\BandElement;
use ReportingEngine\Report\GroupDefinition;
use ReportingEngine\Report\AggregateAccumulator;

class DompdfRenderer implements RendererInterface
{
    private array $fontMetrics = [];
    private array $fonts = [];
    private array $fontFamilyMap = [];

    /** Margin of safety subtracted from body height to prevent content overflow (mm) */
    private const BODY_HEIGHT_GUTTER = 2;

    public function render(ReportDefinition $definition, array $data, array $params = []): string
    {
        $this->initializeFonts($params);
        $page = $definition->pageSettings;

        $phBand = $definition->bands->get('page_header');
        $pfBand = $definition->bands->get('page_footer');
        $hdrBandH = $this->hasBand($phBand) ? ($phBand->height ?? 10) : 0;
        $ftBandH  = $this->hasBand($pfBand) ? ($pfBand->height ?? 10) : 0;

        $hdrTop = 1.0; // 1mm clearance from page edge to prevent printer clipping
        $ftBot  = $ftBandH > 0 ? max(3.0, $page->marginBottom * 0.3) : 0.0; // Footer clearance: minimum 3mm, scales with marginBottom
        $effectiveMarginTop    = $hdrBandH > 0 ? max($page->marginTop, $hdrTop + $hdrBandH) : $page->marginTop;
        $effectiveMarginBottom = $ftBandH > 0 ? max($page->marginBottom, $ftBot + $ftBandH) : $page->marginBottom;

        // Body padding for ALL spacing (margins + header/footer clearance).
        // Since Dompdf @page margins are unreliable, padding + explicit width handle everything.
        $paddingTop    = $hdrBandH > 0 ? $effectiveMarginTop : $page->marginTop;
        $paddingBottom = $ftBandH > 0 ? $effectiveMarginBottom : $page->marginBottom;
        $paddingLeft   = $page->marginLeft;
        $paddingRight  = $page->marginRight;

        $paperH = $page->getPaperHeightMm();
        $paperW = $page->getPaperWidthMm();
        if ($page->orientation === 'landscape') {
            $tmp    = $paperH;
            $paperH = $paperW;
            $paperW = $tmp;
        }

        $bodyWidth = $paperW - $paddingLeft - $paddingRight;

        // Body height = page height - top padding - bottom padding - gutter
        $bodyHeight = $paperH - $paddingTop - $paddingBottom - self::BODY_HEIGHT_GUTTER;
        if ($bodyHeight < 50) { // Minimum body height floor to prevent degenerate layouts
            $bodyHeight = 50;
        }
        $bodyHtml = $this->buildBodies($definition, $data, $bodyHeight);
        // Strip the body wrapper — buildBodies() returns a <body>...</body> fragment,
        // but render() provides its own document structure.
        // Extract only the content between <body ...> and </body>
        $bodyContent = '';
        if (preg_match('/<body[^>]*>(.*)<\/body>/s', $bodyHtml, $match)) {
            $bodyContent = $match[1];
        } else {
            $bodyContent = $bodyHtml;
        }

        // Generate header/footer HTML
        $hdrHtml = '';
        $ftHtml  = '';
        if ($this->hasBand($phBand)) {
            $hdrHtml = $this->renderBandsPlainHtml([$phBand], $definition, null, null);
        }
        if ($this->hasBand($pfBand)) {
            $ftHtml = $this->renderBandsPlainHtml([$pfBand], $definition, null, null);
        }

        // Build full HTML document
        $fullHtml = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . "\n";

        $fullHtml .= $this->getStyles() . "\n";

        // @page with zero margins — Dompdf's @page margin support is unreliable.
        // All spacing (margins + header/footer clearance) is handled via body padding
        // with explicit width, so elements position correctly within the content area.
        $fullHtml .= "@page { margin: 0; }\n";

        // @font-face CSS for custom fonts — register local TTF fonts so Dompdf can embed them
        if (!empty($this->fonts)) {
            $fontDir = realpath(__DIR__ . '/../../data/fonts');
            if ($fontDir !== false) {
                foreach ($this->fonts as $font) {
                    $family = $font['family'] ?? '';
                    $fname  = $font['filename'] ?? '';
                    if ($family === '' || $fname === '') continue;

                    $weight = $font['weight'] ?? 400;
                    $style = match (strtolower($font['style'] ?? 'normal')) {
                        'italic', 'oblique' => 'italic',
                        default => 'normal',
                    };

                    $fontPath = $fontDir . '/' . $fname;
                    if (!file_exists($fontPath)) continue;

                    $fullHtml .= sprintf(
                        "@font-face { font-family:'%s'; src:url('file://%s') format('truetype'); font-weight:%s; font-style:%s; }\n",
                        htmlspecialchars($family, ENT_QUOTES, 'UTF-8'),
                        $fontPath,
                        $weight,
                        $style
                    );
                }
            }
        }

        $fullHtml .= '</style></head>';

        // Body — padding handles ALL spacing (margins + header/footer clearance)
        // Explicit width ensures the body content width equals usableWidth.
        // Total body box = paddingLeft + bodyWidth + paddingRight = paperW = page width.
        $fullHtml .= sprintf(
            '<body style="padding:%.1fmm %.1fmm %.1fmm %.1fmm; width:%.1fmm;">',
            $paddingTop,
            $paddingRight,
            $paddingBottom,
            $paddingLeft,
            $bodyWidth
        );

        // Page header as position:fixed (inside <body> for valid HTML)
        if ($hdrHtml !== '') {
            $fullHtml .= sprintf(
                '<div class="page-header" style="position:fixed; top:%.1fmm; left:0; width:100%%; height:%.1fmm; z-index:100; overflow:hidden;">%s</div>',
                $hdrTop,
                $hdrBandH,
                $hdrHtml
            );
        }

        // Page footer as position:fixed (inside <body> for valid HTML)
        if ($ftHtml !== '') {
            $fullHtml .= sprintf(
                '<div class="page-footer" style="position:fixed; bottom:%.1fmm; left:0; width:100%%; height:%.1fmm; z-index:100; overflow:hidden;">%s</div>',
                $ftBot,
                $ftBandH,
                $ftHtml
            );
        }

        // Body content
        $fullHtml .= $bodyContent;

        $fullHtml .= '</body></html>';

        // Create Dompdf instance
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', false);

        // Allow Dompdf to load custom font files from the project fonts directory.
        // Without this, Dompdf's default chroot prevents @font-face file:// access
        // to files outside its own installation directory.
        $fontDir = realpath(__DIR__ . '/../../data/fonts');
        if ($fontDir !== false) {
            $chroot = $options->getChroot();
            if (!in_array($fontDir, $chroot, true)) {
                $chroot[] = $fontDir;
                $options->setChroot($chroot);
            }
        }

        // Self-heal: Dompdf 3.1.5 package install omits installed-fonts.dist.json
        // FontMetrics.php expects it for bundled font family definitions.
        $distFile = $options->getRootDir() . '/lib/fonts/installed-fonts.dist.json';
        if (!file_exists($distFile)) {
            $bundled = [
                'sans-serif'       => ['normal' => 'Helvetica', 'bold' => 'Helvetica-Bold', 'italic' => 'Helvetica-Oblique', 'bold_italic' => 'Helvetica-BoldOblique'],
                'serif'             => ['normal' => 'Times-Roman', 'bold' => 'Times-Bold', 'italic' => 'Times-Italic', 'bold_italic' => 'Times-BoldItalic'],
                'monospace'         => ['normal' => 'Courier', 'bold' => 'Courier-Bold', 'italic' => 'Courier-Oblique', 'bold_italic' => 'Courier-BoldOblique'],
                'times'             => ['normal' => 'Times-Roman', 'bold' => 'Times-Bold', 'italic' => 'Times-Italic', 'bold_italic' => 'Times-BoldItalic'],
                'times-roman'       => ['normal' => 'Times-Roman', 'bold' => 'Times-Bold', 'italic' => 'Times-Italic', 'bold_italic' => 'Times-BoldItalic'],
                'courier'           => ['normal' => 'Courier', 'bold' => 'Courier-Bold', 'italic' => 'Courier-Oblique', 'bold_italic' => 'Courier-BoldOblique'],
                'helvetica'         => ['normal' => 'Helvetica', 'bold' => 'Helvetica-Bold', 'italic' => 'Helvetica-Oblique', 'bold_italic' => 'Helvetica-BoldOblique'],
                'zapfdingbats'      => ['normal' => 'ZapfDingbats', 'bold' => 'ZapfDingbats', 'italic' => 'ZapfDingbats', 'bold_italic' => 'ZapfDingbats'],
                'symbol'            => ['normal' => 'Symbol', 'bold' => 'Symbol', 'italic' => 'Symbol', 'bold_italic' => 'Symbol'],
                'fixed'             => ['normal' => 'Courier', 'bold' => 'Courier-Bold', 'italic' => 'Courier-Oblique', 'bold_italic' => 'Courier-BoldOblique'],
                'dejavu sans'       => ['normal' => 'DejaVuSans', 'bold' => 'DejaVuSans-Bold', 'italic' => 'DejaVuSans-Oblique', 'bold_italic' => 'DejaVuSans-BoldOblique'],
                'dejavu sans mono'  => ['normal' => 'DejaVuSansMono', 'bold' => 'DejaVuSansMono-Bold', 'italic' => 'DejaVuSansMono-Oblique', 'bold_italic' => 'DejaVuSansMono-BoldOblique'],
                'dejavu serif'      => ['normal' => 'DejaVuSerif', 'bold' => 'DejaVuSerif-Bold', 'italic' => 'DejaVuSerif-Italic', 'bold_italic' => 'DejaVuSerif-BoldItalic'],
            ];
            $dir = dirname($distFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($distFile, json_encode($bundled, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        // Self-heal: generate .afm.json cache files for the 14 core PostScript fonts
        // so Cpdf's openFont() can load font metrics without real .afm files on disk.
        $coreFontNames = [
            'Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique', 'Helvetica-BoldOblique',
            'Times-Roman', 'Times-Bold', 'Times-Italic', 'Times-BoldItalic',
            'Courier', 'Courier-Bold', 'Courier-Oblique', 'Courier-BoldOblique',
            'Symbol', 'ZapfDingbats',
        ];
        $distDir = dirname($distFile);
        foreach ($coreFontNames as $fontName) {
            $cacheFile = $distDir . '/' . $fontName . '.afm.json';
            if (!file_exists($cacheFile)) {
                $data = $this->generateCoreFontMetrics($fontName);
                file_put_contents($cacheFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        }

        $dompdf = new Dompdf($options);
        $dompdf->setPaper($page->paperSize, $page->orientation);
        set_time_limit(120); // Allow up to 2 minutes for large reports
        $dompdf->loadHtml($fullHtml);
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Return the raw body HTML that would be passed to Dompdf's loadHtml.
     * Useful for debugging PDF layout issues.
     */
    public function getBodyHtml(ReportDefinition $definition, array $data, array $params = []): string
    {
        $this->initializeFonts($params);
        $page = $definition->pageSettings;

        $phBand = $definition->bands->get('page_header');
        $pfBand = $definition->bands->get('page_footer');
        $hdrBandH = $this->hasBand($phBand) ? ($phBand->height ?? 10) : 0;
        $ftBandH  = $this->hasBand($pfBand) ? ($pfBand->height ?? 10) : 0;

        $hdrTop = 1.0; // 1mm clearance from page edge to prevent printer clipping
        $ftBot  = $ftBandH > 0 ? max(3.0, $page->marginBottom * 0.3) : 0.0; // Footer clearance: minimum 3mm, scales with marginBottom
        $effectiveMarginTop    = $hdrBandH > 0 ? max($page->marginTop, $hdrTop + $hdrBandH) : $page->marginTop;
        $effectiveMarginBottom = $ftBandH > 0 ? max($page->marginBottom, $ftBot + $ftBandH) : $page->marginBottom;

        $paddingTop    = $hdrBandH > 0 ? $effectiveMarginTop : $page->marginTop;
        $paddingBottom = $ftBandH > 0 ? $effectiveMarginBottom : $page->marginBottom;

        $paperH = $page->getPaperHeightMm();
        if ($page->orientation === 'landscape') {
            $paperH = $page->getPaperWidthMm();
        }
        $usableHeight = $paperH - $paddingTop - $paddingBottom - self::BODY_HEIGHT_GUTTER;

        return $this->buildBodies($definition, $data, $usableHeight);
    }

    // ------------------------------------------------------------------ build

    private function buildBodies(
        ReportDefinition $definition,
        array $data,
        float $usableHeight
    ): string {
        $groups = $definition->groups;
        usort($groups, fn(GroupDefinition $a, GroupDefinition $b) => $a->level <=> $b->level);

        $rhBand = $definition->bands->get('report_header');
        $rfBand = $definition->bands->get('report_footer');
        $chBand = $definition->bands->get('column_header');
        $dtBand = $definition->bands->get('detail');

        // We collect output in a single HTML string, injecting
        // page-break-before divs whenever content would exceed the page.
        $page = $definition->pageSettings;
        $paperW = $page->getPaperWidthMm();
        if ($page->orientation === 'landscape') {
            $paperW = $page->getPaperHeightMm();
        }
        $usableWidth = $paperW - $page->marginLeft - $page->marginRight;
        $html = sprintf(
            '<body style="width:%.1fmm">',
            $usableWidth
        );

        $pageY    = 0.0;
        $chOnPage = false;

        // Helper: which active groups want header reprint on a new page?
        $reprintable = function(array $gv): array {
            $ids = [];
            foreach ($gv as $gi => $v) {
                if ($v !== null) $ids[] = $gi;
            }
            return $ids;
        };

        // Render everything that goes at the top of a content page
        $renderPageTop = function(array $reprintGroups, ?array $lastRowData)
            use (&$html, &$pageY, &$chOnPage, $chBand, $groups, $definition)
        {
            foreach ($reprintGroups as $gi) {
                $hdr = $this->findGroupHeader($definition, $groups[$gi]);
                if ($hdr && $this->hasBand($hdr) && $groups[$gi]->reprintHeaderOnNewPage) {
                    $effH = $this->calculateEffectiveBandHeight($hdr, $definition, $groups[$gi], $lastRowData, 1);
                    $html .= $this->renderSingleBandHtml($hdr, $definition, $groups[$gi], $lastRowData, $effH);
                    $pageY += $effH + $hdr->border->getVerticalHeightMm();
                }
            }
            if ($this->hasBand($chBand) && $chBand->printOnEveryPage) {
                $effH = $this->calculateEffectiveBandHeight($chBand, $definition, null, null, 1);
                $html .= $this->renderSingleBandHtml($chBand, $definition, null, null, $effH);
                $pageY += $effH + $chBand->border->getVerticalHeightMm();
                $chOnPage = true;
            }
        };

        // Track page Y position for manual page break decisions.
        // When content exceeds usable height, insert page-break and re-print
        // group/column headers on the new page.
        $fit = function(?Band $b, ?array $rowData = null, ?float $effectiveHeight = null)
            use (&$html, &$pageY, &$chOnPage, $usableHeight, &$renderPageTop, &$groupValues): float
        {
            if (!$this->hasBand($b)) return 0;
            $h = $effectiveHeight ?? $b->height;
            if ($h <= 0) return 0;
            $borderH = $b->border ? $b->border->getVerticalHeightMm() : 0;
            $totalH = $h + $borderH;
            if ($pageY + $totalH > $usableHeight && $totalH <= $usableHeight) {
                $html .= '<div style="page-break-before:always"></div>';
                $pageY = 0;
                $chOnPage = false;
                $reprint = [];
                if (isset($groupValues)) {
                    foreach ($groupValues as $gi => $v) {
                        if ($v !== null) $reprint[] = $gi;
                    }
                }
                $renderPageTop($reprint, $rowData);
            }
            return $totalH;
        };

        // ------ report header ------
        if ($this->hasBand($rhBand)) {
            $effH = $this->calculateEffectiveBandHeight($rhBand, $definition, null, null, 1);
            $pageY += $fit($rhBand, null, $effH);
            $html .= $this->renderSingleBandHtml($rhBand, $definition, null, null, $effH);
        }

        if (empty($data)) {
            if ($this->hasBand($chBand)) {
                $html .= $this->renderSingleBandHtml($chBand, $definition, null, null);
            }
            $html .= '<p>No data returned.</p>';
        } else {
            $groupValues   = array_fill(0, count($groups), null);
            $groupRowCounters = array_fill(0, count($groups), 0);
            $groupAggs = [];
            foreach ($groups as $g => $_) {
                $groupAggs[$g] = new AggregateAccumulator();
            }
            $reportAggs = new AggregateAccumulator();

            $firstRow = reset($data);
            for ($g = 0; $g < count($groups); $g++) {
                $groupValues[$g] = $firstRow[$groups[$g]->fieldName] ?? null;
            }

            foreach ($data as $rowIndex => $row) {
                $groupChanged = false;

                // ------ detect group break ------
                for ($g = 0; $g < count($groups); $g++) {
                    $field = $groups[$g]->fieldName;
                    if ($groupValues[$g] !== null && $groupValues[$g] !== ($row[$field] ?? null)) {

                        if ($groups[$g]->pageBreakBefore) {
                            $html .= '<div style="page-break-before:always"></div>';
                            $pageY = 0;
                            $chOnPage = false;
                            // reprint groups above the changing one
                            $stale = [];
                            for ($r = 0; $r < $g; $r++) {
                                if ($groupValues[$r] !== null) $stale[] = $r;
                            }
                            $renderPageTop($stale, $row);
                        }

                        for ($inner = count($groups) - 1; $inner >= $g; $inner--) {
                            $ft = $this->findGroupFooter($definition, $groups[$inner]);
                            $effH = $ft && $this->hasBand($ft) ? $this->calculateEffectiveBandHeight($ft, $definition, $groups[$inner], $groupAggs[$inner], 1) : 0;
                            $pageY += $fit($ft, $row, $effH);
                            if ($ft && $this->hasBand($ft)) {
                                $html .= $this->renderSingleBandHtml($ft, $definition, $groups[$inner], $groupAggs[$inner], $effH);
                            }
                            $groupAggs[$inner]->reset();
                            if ($groups[$inner]->resetRowNo) $groupRowCounters[$inner] = 0;
                        }

                        for ($outer = $g; $outer < count($groups); $outer++) {
                            $groupValues[$outer] = $row[$groups[$outer]->fieldName] ?? null;
                            $hdr = $this->findGroupHeader($definition, $groups[$outer]);
                            $effH = $hdr && $this->hasBand($hdr) ? $this->calculateEffectiveBandHeight($hdr, $definition, $groups[$outer], $row, 1) : 0;
                            $pageY += $fit($hdr, $row, $effH);
                            if ($hdr && $this->hasBand($hdr)) {
                                $html .= $this->renderSingleBandHtml($hdr, $definition, $groups[$outer], $row, $effH);
                            }
                        }

                        if (!$chOnPage && $this->hasBand($chBand)) {
                            $effH = $this->calculateEffectiveBandHeight($chBand, $definition, null, null, 1);
                            $pageY += $fit($chBand, $row, $effH);
                            $html .= $this->renderSingleBandHtml($chBand, $definition, null, null, $effH);
                            $chOnPage = true;
                        }

                        $groupChanged = true;
                        break;
                    }
                }

                // ------ first row ------
                if ($rowIndex === 0) {
                    for ($g = 0; $g < count($groups); $g++) {
                        $groupValues[$g] = $row[$groups[$g]->fieldName] ?? null;
                        $hdr = $this->findGroupHeader($definition, $groups[$g]);
                        $effH = $hdr && $this->hasBand($hdr) ? $this->calculateEffectiveBandHeight($hdr, $definition, $groups[$g], $row, 1) : 0;
                        $pageY += $fit($hdr, $row, $effH);
                        if ($hdr && $this->hasBand($hdr)) {
                            $html .= $this->renderSingleBandHtml($hdr, $definition, $groups[$g], $row, $effH);
                        }
                    }
                    if (!$chOnPage && $this->hasBand($chBand)) {
                        $effH = $this->calculateEffectiveBandHeight($chBand, $definition, null, null, 1);
                        $pageY += $fit($chBand, $row, $effH);
                        $html .= $this->renderSingleBandHtml($chBand, $definition, null, null, $effH);
                        $chOnPage = true;
                    }
                    $groupChanged = true;
                }

                // ------ row number ------
                for ($g = count($groups) - 1; $g >= 0; $g--) {
                    if ($groupValues[$g] !== null) {
                        $groupRowCounters[$g]++;
                        $row['_rowno'] = $groupRowCounters[$g];
                        break;
                    }
                }

                // ------ aggregates ------
                foreach ($row as $field => $value) {
                    for ($g = 0; $g < count($groups); $g++) {
                        $groupAggs[$g]->accumulate((string)$field, $value);
                    }
                    $reportAggs->accumulate((string)$field, $value);
                }

                // ------ detail ------
                $effH = $this->hasBand($dtBand) ? $this->calculateEffectiveBandHeight($dtBand, $definition, null, $row, 1) : 0;
                $pageY += $fit($dtBand, $row, $effH);
                if ($this->hasBand($dtBand)) {
                    $html .= $this->renderSingleBandHtml($dtBand, $definition, null, $row, $effH);
                }
            }

            // ------ close remaining groups ------
            for ($g = count($groups) - 1; $g >= 0; $g--) {
                $ft = $this->findGroupFooter($definition, $groups[$g]);
                $effH = $ft && $this->hasBand($ft) ? $this->calculateEffectiveBandHeight($ft, $definition, $groups[$g], $groupAggs[$g], 1) : 0;
                $pageY += $fit($ft, $row ?? null, $effH);
                if ($ft && $this->hasBand($ft)) {
                    $html .= $this->renderSingleBandHtml($ft, $definition, $groups[$g], $groupAggs[$g], $effH);
                }
                $groupAggs[$g]->reset();
            }

            // ------ report footer ------
            $effH = $this->hasBand($rfBand) ? $this->calculateEffectiveBandHeight($rfBand, $definition, null, $reportAggs, 1) : 0;
            $pageY += $fit($rfBand, null, $effH);
            if ($this->hasBand($rfBand)) {
                $html .= $this->renderSingleBandHtml($rfBand, $definition, null, $reportAggs, $effH);
            }
        }

        $html .= '</body>';
        return $html;
    }

    // --------------------------------------------------------------- helpers

    private function hasBand(?Band $b): bool
    {
        return $b && $b->visible && !empty($b->elements);
    }

    private function initializeFonts(array $params): void
    {
        $this->fontMetrics = isset($params['_fontMetrics']) && is_array($params['_fontMetrics']) ? $params['_fontMetrics'] : [];
        $this->fonts = isset($params['_fonts']) && is_array($params['_fonts']) ? $params['_fonts'] : [];
        $this->fontFamilyMap = [];

        if (!empty($this->fonts)) {
            foreach ($this->fonts as $font) {
                $family = isset($font['family']) ? strtolower(trim($font['family'])) : '';
                $fname  = $font['filename'] ?? '';
                if ($family === '' || $fname === '') {
                    continue;
                }
                // Store original family name (preserve case) so @font-face CSS matches the font-family value
                $this->fontFamilyMap[$family] = $font['family'];
            }
        }
    }

    private function renderBandsPlainHtml(array $bands, ReportDefinition $def, $group, $data): string
    {
        $out = '';
        foreach ($bands as $b) {
            if ($this->hasBand($b)) {
                $out .= $this->renderSingleBandHtml($b, $def, $group, $data);
            }
        }
        return $out;
    }

    private function renderSingleBandHtml(Band $band, ReportDefinition $def, $group, $data, ?float $effectiveHeight = null): string
    {
        $h = $effectiveHeight ?? $band->height;
        $style = sprintf(
            'style="position:relative; height:%.1fmm; overflow:hidden; background:%s; %s"',
            $h,
            $band->backgroundColor ?: 'transparent',
            $band->border ? $band->border->toHtmlStyle() : ''
        );
        $html = sprintf('<div class="band band-%s" %s>', $band->type, $style);

        // Group elements by top position into visual rows
        $rows = [];
        foreach ($band->elements as $element) {
            $rowData = $data instanceof AggregateAccumulator ? $data->getLastValues() : ($data ?: []);
            if ($element->visibleExpression !== null && !ExpressionEvaluator::evaluateBool($element->visibleExpression, $rowData)) {
                continue;
            }
            if ($element->conditionalExpression && !ExpressionEvaluator::evaluateBool($element->conditionalExpression, $rowData)) {
                continue;
            }
            $rows[(string)$element->top][] = $element;
        }
        ksort($rows, SORT_NUMERIC);

        $prevBottom = 0.0;
        foreach ($rows as $top => $elements) {
            // Vertical spacer to position this row at the correct top
            $gap = (float)$top - $prevBottom;
            if ($gap > 0) {
                $html .= sprintf('<div style="height:%.1fmm"></div>', $gap);
            }

            // Compute row height as max element height in this row
            $rowH = 0.0;
            foreach ($elements as $el) {
                $rowH = max($rowH, (float)$el->height);
            }

            $html .= sprintf('<div style="position:relative; overflow:hidden; height:%.1fmm">', $rowH);

            // Sort elements left-to-right
            usort($elements, fn(BandElement $a, BandElement $b) => $a->left <=> $b->left);

            foreach ($elements as $el) {
                $html .= $this->renderElementHtml($el, $def, $group, $data);
            }

            $html .= '</div>';
            $prevBottom = (float)$top + $rowH;
        }

        $html .= '</div>';
        return $html;
    }

    private function mapFontFamily(?string $family): string
    {
        $lower = strtolower(trim($family ?? ''));
        return $this->fontFamilyMap[$lower] ?? $lower;
    }

    private function fontFamilyCss(string $origFontFamily): string
    {
        $fontFamily = $this->mapFontFamily($origFontFamily);
        $lower = strtolower(trim($origFontFamily));

        $serif = ['times new roman', 'times', 'georgia', 'garamond', 'palatino',
                  'bookman', 'book antiqua', 'palatino linotype', 'didot',
                  'bodoni mt', 'new york', 'goudy old style', 'big caslon',
                  'dejavu serif', 'deja vu serif'];

        $mono = ['courier new', 'courier', 'consolas', 'monaco', 'menlo',
                 'dejavu sans mono', 'dejavu mono', 'liberation mono',
                 'source code pro', 'fira code', 'droid sans mono',
                 'jetbrains mono', 'sf mono', 'andale mono',
                 'lucida console', 'lucida sans typewriter'];

        if (isset($this->fontFamilyMap[$lower])) {
            if (str_contains($lower, 'mono') || str_contains($lower, 'code') || str_contains($lower, 'consol')) {
                return sprintf("font-family:'%s', monospace, Courier;", $fontFamily);
            }
            return sprintf("font-family:'%s', Helvetica, sans-serif;", $fontFamily);
        }

        if (in_array($lower, $mono)) {
            return 'font-family:monospace, Courier;';
        }
        if (in_array($lower, $serif)) {
            return 'font-family:serif, Times;';
        }

        return 'font-family:Helvetica, sans-serif;';
    }

    private function renderElementHtml(BandElement $el, ReportDefinition $def, $group, $data): string
    {
        $rowData = $data instanceof AggregateAccumulator ? $data->getLastValues() : ($data ?: []);
        if ($el->visibleExpression !== null && !ExpressionEvaluator::evaluateBool($el->visibleExpression, $rowData)) {
            return '';
        }
        $value = $this->getElementValue($el, $def, $group, $data);
        $borderStyle = $el->border ? $el->border->toHtmlStyle() : '';

        $condStyle = $this->resolveConditionalStyle($el, $data);

        $bold = $condStyle['bold'] ?? $el->bold;
        $italic = $condStyle['italic'] ?? $el->italic;
        $color = $condStyle['color'] ?? $el->color ?: '#000';
        $backgroundColor = $condStyle['backgroundColor'] ?? $el->backgroundColor ?: 'transparent';
        $origFontFamily = $condStyle['fontFamily'] ?? $el->fontFamily ?: 'Arial';
        $fontFamilyCss = $this->fontFamilyCss($origFontFamily);
        $fontSize = $condStyle['fontSize'] ?? $el->fontSize ?: 10;
        $textAlign = $condStyle['textAlign'] ?? $el->textAlign ?: 'left';
        $verticalAlign = $condStyle['verticalAlign'] ?? $el->verticalAlign ?? 'top';

        $isTextType = !in_array($el->type, ['image', 'line', 'rect', 'barcode']);
        $wordWrap = $el->wordWrap ?? false;

        $textOverflow = $wordWrap ? '' : 'text-overflow:ellipsis;';
        $whiteSpace = $wordWrap ? 'white-space:normal; overflow-wrap:break-word;' : 'white-space:nowrap;';

        // Pre-truncate nowrap text in PHP to prevent wrapping + overflow pagination.
        // Skip pageno/pagecount — their values are placeholders.
        if (!$wordWrap && $isTextType && $value !== '' && !in_array($el->type, ['pageno', 'pagecount'])) {
            // $value is already htmlspecialchars'd from getElementValue()
            // Decode for accurate measurement and clean truncation
            $plainText = strip_tags(html_entity_decode($value, ENT_QUOTES, 'UTF-8'));
            $truncKey = $origFontFamily . '-' . $fontSize . '-' . ($bold ? '1' : '0') . '-' . ($italic ? '1' : '0');
            $avgCharWidth = isset($this->fontMetrics[$truncKey])
                ? (float)$this->fontMetrics[$truncKey]
                : 2.0 * ($fontSize / 10); // Fallback avg char width (mm) when no font metrics available
            $textWidth = mb_strlen($plainText) * $avgCharWidth;
            if ($textWidth > $el->width) {
                $maxChars = max(1, (int)(($el->width - $avgCharWidth) / $avgCharWidth));
                $value = htmlspecialchars(mb_substr($plainText, 0, $maxChars)) . '…';
            }
        }

        if ($wordWrap && $isTextType) {
            $textH = $this->estimateTextHeight(strip_tags($value), $fontSize, $el->width, $origFontFamily, $bold, $italic);
            $effectiveElH = max((float)$el->height, $textH);
        } else {
            $effectiveElH = (float)$el->height;
        }

        // All elements use position:absolute within the position:relative row container
        $style = sprintf(
            'position:absolute; top:0; left:%.1fmm; width:%.1fmm; height:%.1fmm; overflow:hidden; background:%s; %s',
            $el->left,
            $el->width,
            $effectiveElH,
            $backgroundColor,
            $borderStyle
        );

        if ($isTextType) {
            $style .= sprintf(
                ' %s font-size:%dpt; font-weight:%s; font-style:%s; color:%s; text-align:%s; vertical-align:%s; %s %s',
                $fontFamilyCss,
                $fontSize,
                $bold ? 'bold' : 'normal',
                $italic ? 'italic' : 'normal',
                $color,
                $textAlign,
                $verticalAlign,
                $whiteSpace,
                $textOverflow
            );
        }

        if ($isTextType) {
            $nowrapTag = $wordWrap ? '' : '<nobr>';
            $nowrapEnd = $wordWrap ? '' : '</nobr>';
            $value = sprintf(
                '<span style="overflow:hidden; %s display:block; width:100%%; min-width:0; text-align:%s">%s%s%s</span>',
                $wordWrap ? 'word-wrap:break-word;' : 'text-overflow:ellipsis;',
                $textAlign,
                $nowrapTag,
                $value,
                $nowrapEnd
            );
        }

        return sprintf('<div class="element" style="%s">%s</div>', $style, $value);
    }

    private function estimateTextHeight(string $text, int $fontSize, float $widthMm, string $fontFamily = 'Arial', bool $bold = false, bool $italic = false): float
    {
        if ($text === '' || $text === null) {
            return ($fontSize * 1.4) * 0.3528;
        }
        $key = $fontFamily . '-' . $fontSize . '-' . ($bold ? '1' : '0') . '-' . ($italic ? '1' : '0');
        if (isset($this->fontMetrics[$key])) {
            $avgCharWidth = (float)$this->fontMetrics[$key];
        } else {
            $avgCharWidth = 2.0 * ($fontSize / 10); // Fallback avg char width (mm) when no font metrics available
        }
        $charsPerLine = max(1, $widthMm / $avgCharWidth);
        $lines = max(1, ceil(mb_strlen($text) / $charsPerLine));
        $lineHeightMm = ($fontSize * 1.4) * 0.3528;
        return $lines * $lineHeightMm;
    }

    private function calculateEffectiveBandHeight(Band $band, ReportDefinition $def, $group, $data, int $pageNum): float
    {
        $effH = $band->height;
        foreach ($band->elements as $el) {
            $rowData = $data instanceof AggregateAccumulator ? $data->getLastValues() : ($data ?: []);
            if ($el->visibleExpression !== null && !ExpressionEvaluator::evaluateBool($el->visibleExpression, $rowData)) {
                continue;
            }
            if ($el->conditionalExpression && !ExpressionEvaluator::evaluateBool(
                $el->conditionalExpression,
                $rowData
            )) {
                continue;
            }
            if (in_array($el->type, ['image', 'line', 'rect', 'barcode'])) continue;
            if (!($el->wordWrap ?? false)) continue;
            $value = $this->getElementValue($el, $def, $group, $data);
            if ($value === '' || $value === null) continue;
            $textH = $this->estimateTextHeight(strip_tags($value), $el->fontSize ?: 10, $el->width, $el->fontFamily ?: 'Arial', $el->bold ?? false, $el->italic ?? false);
            $elBottom = (float)$el->top + max((float)$el->height, $textH);
            $effH = max($effH, $elBottom);
        }
        return $effH;
    }

    private function resolveConditionalStyle(BandElement $el, $data): array
    {
        if (!$el->conditionalExpression || !$el->conditionalStyle) return [];
        $bool = ExpressionEvaluator::evaluateBool(
            $el->conditionalExpression,
            $data instanceof AggregateAccumulator ? $data->getLastValues() : ($data ?: [])
        );
        if (!$bool) return [];
        $parsed = json_decode($el->conditionalStyle, true);
        return is_array($parsed) ? $parsed : [];
    }

    private function getElementValue(BandElement $el, ReportDefinition $def, $group, $data): string
    {
        return match ($el->type) {
            'label' => htmlspecialchars($el->expression
                ? ExpressionEvaluator::evaluate($el->expression,
                    $data instanceof AggregateAccumulator ? $data->getLastValues() : ($data ?: []),
                    $data instanceof AggregateAccumulator ? $data : null)
                : ($el->text ?? '')),
            'field' => $data && $el->fieldName
                ? (is_array($data) && isset($data[$el->fieldName])
                    ? htmlspecialchars($this->formatValue($data[$el->fieldName], $el->format))
                    : ($data instanceof AggregateAccumulator && ($last = $data->getLastValue($el->fieldName)) !== null
                        ? htmlspecialchars($this->formatValue($last, $el->format))
                        : ''))
                : '',
            'aggregate' => $this->renderAggValue($el, $data),
            'image' => $el->imageUrl ? '<img src="' . htmlspecialchars($el->imageUrl, ENT_QUOTES, 'UTF-8') . '" style="width:100%;height:100%;object-fit:' . $this->imageFit($el->imageDisplay) . '">' : '',
            'line' => $this->renderLineValue($el, $data),
            'rect' => '',
            'pageno' => '<span class="dompdf-pageno"></span>',
            'pagecount' => '<span class="dompdf-pagecount">?</span>',
            'rowno' => $data && is_array($data) && isset($data['_rowno']) ? (string)$data['_rowno'] : '1',
            'datetime' => date($el->format ?? 'Y-m-d'),
            'barcode' => self::renderBarcodeValue($el, $data),
            default => htmlspecialchars($el->text ?? ''),
        };
    }

    /**
     * Render a line element (horizontal or vertical) centered within the element bounds.
     */
    private function renderLineValue(BandElement $el, $data): string
    {
        $color = $el->color ?: '#000';
        $orient = $el->orientation ?? 'horizontal';
        $lineAlign = $el->lineAlign ?? ($orient === 'horizontal' ? 'middle' : 'center');

        if ($orient === 'vertical') {
            $leftPos = match ($lineAlign) {
                'left'   => '0',
                'right'  => '100%',
                default  => '50%',
            };
            $transform = $lineAlign === 'center' ? 'transform:translateX(-50%);' : '';
            return sprintf(
                '<div style="position:absolute; top:0; left:%s; height:100%%; border-left:1px solid %s; %s"></div>',
                $leftPos,
                $color,
                $transform
            );
        }

        // Horizontal line
        $topPos = match ($lineAlign) {
            'top'    => '0',
            'bottom' => '100%',
            default  => '50%',
        };
        $transform = $lineAlign === 'middle' ? 'transform:translateY(-50%);' : '';
        return sprintf(
            '<div style="position:absolute; top:%s; left:0; width:100%%; border-top:1px solid %s; %s"></div>',
            $topPos,
            $color,
            $transform
        );
    }

    private static function renderBarcodeValue(BandElement $el, $data): string
    {
        $rowData = $data instanceof AggregateAccumulator ? $data->getLastValues() : ($data ?: []);
        $value = $el->barcodeExpression
            ? ExpressionEvaluator::evaluate($el->barcodeExpression, $rowData)
            : ($el->text ?? '');
        if (!$value) return '';
        $src = BarcodeRenderer::renderPng($value, $el->barcodeSymbology ?? 'code128', $el->barcodeShowText ?? true);
        return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" style="width:100%;height:100%;object-fit:contain" alt="barcode">';
    }

    private function renderAggValue(BandElement $el, $data): string
    {
        if (!$data || !$el->fieldName) return '';
        if ($data instanceof AggregateAccumulator) {
            $value = $data->resolve($el->aggregateFunc ?? 'sum', $el->fieldName);
        } elseif (is_array($data) && isset($data[$el->fieldName])) {
            $value = $data[$el->fieldName];
        } else {
            return '';
        }
        return htmlspecialchars($this->formatValue($value, $el->format));
    }

    private function formatValue(mixed $value, ?string $format): string
    {
        if ($format === null || $format === '') {
            return (string)$value;
        }

        // Try printf-style format for any value
        if (str_contains($format, '%')) {
            $v = $value;
            if (is_numeric($value)) $v = (float)$value;
            $result = sprintf($format, $v);
            if ($result !== false && $result !== $format) {
                return $result;
            }
        }

        // Try date format if the format looks like a date pattern
        if (!str_contains($format, '%') && !preg_match('/^[\d#,.\s]+$/', $format)) {
            $dateChars = ['Y', 'm', 'd', 'H', 'i', 's', 'F', 'M', 'j', 'n', 'y', 'g', 'h', 'G', 'A', 'a'];
            $hasDateChars = 0;
            foreach ($dateChars as $c) {
                if (str_contains($format, $c)) $hasDateChars++;
            }
            if ($hasDateChars >= 2) {
                $ts = strtotime((string)$value);
                if ($ts !== false && $ts > 0) {
                    $result = date($format, $ts);
                    if ($result !== false) return $result;
                }
            }
        }

        // Numeric-only formatting below
        if (!is_numeric($value)) {
            return (string)$value;
        }
        $v = (float)$value;

        if (preg_match('/^\d+$/', $format)) {
            return number_format($v, (int)$format, '.', ',');
        }

        $decPos = -1;
        $decSep = null;
        foreach (['.', ','] as $sep) {
            $pos = strrpos($format, $sep);
            if ($pos === false) continue;
            $tail = substr($format, $pos + 1);
            if (preg_match('/^0/', $tail)) {
                if ($pos > $decPos) {
                    $decPos = $pos;
                    $decSep = $sep;
                }
            }
        }

        $decimals     = 0;
        $decPoint     = '.';
        $thousandsSep = ',';

        if ($decSep !== null) {
            $decPoint     = $decSep;
            $thousandsSep = $decSep === '.' ? ',' : '.';
            $tail = substr($format, $decPos + 1);
            if (preg_match('/^[0#]+/', $tail, $m)) {
                $decimals = strlen($m[0]);
            }
        }

        return number_format($v, $decimals, $decPoint, $thousandsSep);
    }

    private function imageFit(?string $display): string
    {
        return match ($display) {
            'original' => 'none',
            'stretch' => 'fill',
            default => 'contain',
        };
    }

    private function findGroupHeader(ReportDefinition $def, GroupDefinition $group): ?Band
    {
        foreach ($def->bands->all() as $band) {
            if ($band->type === 'group_header' && $band->groupField === $group->fieldName) return $band;
        }
        return null;
    }

    private function findGroupFooter(ReportDefinition $def, GroupDefinition $group): ?Band
    {
        foreach ($def->bands->all() as $band) {
            if ($band->type === 'group_footer' && $band->groupField === $group->fieldName) return $band;
        }
        return null;
    }

    private function getStyles(): string
    {
        return '
            html { margin: 0; padding: 0; }
            body { font-family: Arial, sans-serif; font-size: 10pt; margin: 0; padding: 0; }
            .band { padding: 0; overflow: hidden; position: relative; }
            .element { overflow: hidden; }
            .page-header { z-index: 100; }
            .page-footer { z-index: 100; }
            .dompdf-pageno::after { content: counter(page); }
            .dompdf-pagecount::after { content: "?"; }
        ';
    }

    /**
     * Generate a complete .afm.json cache structure for one of the 14 core PostScript fonts.
     *
     * This is used by Dompdf's Cpdf engine to resolve font metrics when no real .afm
     * file exists on disk (which is the case for Dompdf 3.1.5 shipped without .afm files).
     *
     * @param string $fontName One of the 14 core font names (e.g. 'Helvetica', 'Times-Roman').
     * @return array The complete font data structure matching what Cpdf\openFont() expects.
     */
    private function generateCoreFontMetrics(string $fontName): array
    {
        // ---- Font metadata: all 14 core PostScript fonts ----
        $meta = [
            'Helvetica' => [
                'ascender' => '718', 'descender' => '-207', 'capHeight' => '718', 'xHeight' => '523',
                'fontBBox' => ['-166', '-225', '1000', '956'],
                'weight' => 'Medium', 'italicAngle' => '0', 'isFixedPitch' => 'false',
                'stdHW' => '76', 'stdVW' => '88', 'fullName' => 'Helvetica', 'familyName' => 'Helvetica',
            ],
            'Helvetica-Bold' => [
                'ascender' => '718', 'descender' => '-207', 'capHeight' => '718', 'xHeight' => '523',
                'fontBBox' => ['-170', '-228', '1003', '962'],
                'weight' => 'Bold', 'italicAngle' => '0', 'isFixedPitch' => 'false',
                'stdHW' => '76', 'stdVW' => '88', 'fullName' => 'Helvetica Bold', 'familyName' => 'Helvetica',
            ],
            'Helvetica-Oblique' => [
                'ascender' => '718', 'descender' => '-207', 'capHeight' => '718', 'xHeight' => '523',
                'fontBBox' => ['-170', '-225', '1116', '956'],
                'weight' => 'Medium', 'italicAngle' => '-12', 'isFixedPitch' => 'false',
                'stdHW' => '76', 'stdVW' => '88', 'fullName' => 'Helvetica Oblique', 'familyName' => 'Helvetica',
            ],
            'Helvetica-BoldOblique' => [
                'ascender' => '718', 'descender' => '-207', 'capHeight' => '718', 'xHeight' => '523',
                'fontBBox' => ['-174', '-228', '1114', '962'],
                'weight' => 'Bold', 'italicAngle' => '-12', 'isFixedPitch' => 'false',
                'stdHW' => '76', 'stdVW' => '88', 'fullName' => 'Helvetica Bold Oblique', 'familyName' => 'Helvetica',
            ],
            'Times-Roman' => [
                'ascender' => '683', 'descender' => '-217', 'capHeight' => '662', 'xHeight' => '450',
                'fontBBox' => ['-168', '-218', '1000', '898'],
                'weight' => 'Roman', 'italicAngle' => '0', 'isFixedPitch' => 'false',
                'stdHW' => '28', 'stdVW' => '84', 'fullName' => 'Times Roman', 'familyName' => 'Times',
            ],
            'Times-Bold' => [
                'ascender' => '683', 'descender' => '-217', 'capHeight' => '662', 'xHeight' => '450',
                'fontBBox' => ['-168', '-218', '1000', '935'],
                'weight' => 'Bold', 'italicAngle' => '0', 'isFixedPitch' => 'false',
                'stdHW' => '28', 'stdVW' => '84', 'fullName' => 'Times Bold', 'familyName' => 'Times',
            ],
            'Times-Italic' => [
                'ascender' => '683', 'descender' => '-217', 'capHeight' => '662', 'xHeight' => '450',
                'fontBBox' => ['-169', '-217', '1010', '883'],
                'weight' => 'Italic', 'italicAngle' => '-12', 'isFixedPitch' => 'false',
                'stdHW' => '28', 'stdVW' => '84', 'fullName' => 'Times Italic', 'familyName' => 'Times',
            ],
            'Times-BoldItalic' => [
                'ascender' => '683', 'descender' => '-217', 'capHeight' => '662', 'xHeight' => '450',
                'fontBBox' => ['-200', '-218', '996', '921'],
                'weight' => 'Bold Italic', 'italicAngle' => '-12', 'isFixedPitch' => 'false',
                'stdHW' => '28', 'stdVW' => '84', 'fullName' => 'Times Bold Italic', 'familyName' => 'Times',
            ],
            'Courier' => [
                'ascender' => '629', 'descender' => '-157', 'capHeight' => '562', 'xHeight' => '426',
                'fontBBox' => ['-23', '-250', '715', '805'],
                'weight' => 'Medium', 'italicAngle' => '0', 'isFixedPitch' => 'true',
                'stdHW' => '51', 'stdVW' => '51', 'fullName' => 'Courier', 'familyName' => 'Courier',
            ],
            'Courier-Bold' => [
                'ascender' => '629', 'descender' => '-157', 'capHeight' => '562', 'xHeight' => '426',
                'fontBBox' => ['-113', '-250', '749', '801'],
                'weight' => 'Bold', 'italicAngle' => '0', 'isFixedPitch' => 'true',
                'stdHW' => '51', 'stdVW' => '51', 'fullName' => 'Courier Bold', 'familyName' => 'Courier',
            ],
            'Courier-Oblique' => [
                'ascender' => '629', 'descender' => '-157', 'capHeight' => '562', 'xHeight' => '426',
                'fontBBox' => ['-27', '-250', '849', '805'],
                'weight' => 'Medium', 'italicAngle' => '-12', 'isFixedPitch' => 'true',
                'stdHW' => '51', 'stdVW' => '51', 'fullName' => 'Courier Oblique', 'familyName' => 'Courier',
            ],
            'Courier-BoldOblique' => [
                'ascender' => '629', 'descender' => '-157', 'capHeight' => '562', 'xHeight' => '426',
                'fontBBox' => ['-57', '-250', '869', '801'],
                'weight' => 'Bold', 'italicAngle' => '-12', 'isFixedPitch' => 'true',
                'stdHW' => '51', 'stdVW' => '51', 'fullName' => 'Courier Bold Oblique', 'familyName' => 'Courier',
            ],
            'Symbol' => [
                'ascender' => '1000', 'descender' => '0', 'capHeight' => '1000', 'xHeight' => '600',
                'fontBBox' => ['-180', '-293', '1090', '1010'],
                'weight' => 'Medium', 'italicAngle' => '0', 'isFixedPitch' => 'false',
                'stdHW' => '0', 'stdVW' => '0', 'fullName' => 'Symbol', 'familyName' => 'Symbol',
            ],
            'ZapfDingbats' => [
                'ascender' => '1000', 'descender' => '0', 'capHeight' => '1000', 'xHeight' => '600',
                'fontBBox' => ['-1', '-143', '1000', '931'],
                'weight' => 'Medium', 'italicAngle' => '0', 'isFixedPitch' => 'false',
                'stdHW' => '0', 'stdVW' => '0', 'fullName' => 'Zapf Dingbats', 'familyName' => 'ZapfDingbats',
            ],
        ];

        // ---- Fail fast on unknown font ----
        if (!isset($meta[$fontName])) {
            throw new \RuntimeException("Cannot generate metrics for unknown core font: {$fontName}");
        }

        // ---- Character width maps per font ----
        // Adobe standard widths in 1/1000ths of em for ASCII printable range 32-126
        $asciiWidths = [
            'Helvetica' => [
                32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667,
                39 => 222, 40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333,
                46 => 278, 47 => 278, 48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556,
                53 => 556, 54 => 556, 55 => 556, 56 => 556, 57 => 556, 58 => 278, 59 => 278,
                60 => 584, 61 => 584, 62 => 584, 63 => 611, 64 => 975, 65 => 667, 66 => 667,
                67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778, 72 => 722, 73 => 278,
                74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778, 80 => 667,
                81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
                88 => 667, 89 => 667, 90 => 611, 91 => 333, 92 => 278, 93 => 333, 94 => 469,
                95 => 500, 96 => 222, 97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556,
                102 => 278, 103 => 556, 104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222,
                109 => 833, 110 => 556, 111 => 556, 112 => 556, 113 => 556, 114 => 333, 115 => 500,
                116 => 278, 117 => 556, 118 => 500, 119 => 722, 120 => 500, 121 => 500, 122 => 500,
                123 => 334, 124 => 260, 125 => 334, 126 => 584,
            ],
            'Helvetica-Bold' => [
                32 => 278, 33 => 333, 34 => 474, 35 => 556, 36 => 556, 37 => 889, 38 => 722,
                39 => 278, 40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333,
                46 => 278, 47 => 278, 48 => 611, 49 => 611, 50 => 611, 51 => 611, 52 => 611,
                53 => 611, 54 => 611, 55 => 611, 56 => 611, 57 => 611, 58 => 333, 59 => 333,
                60 => 584, 61 => 584, 62 => 584, 63 => 611, 64 => 975, 65 => 722, 66 => 722,
                67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778, 72 => 722, 73 => 278,
                74 => 556, 75 => 722, 76 => 611, 77 => 833, 78 => 722, 79 => 778, 80 => 667,
                81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
                88 => 667, 89 => 667, 90 => 611, 91 => 333, 92 => 278, 93 => 333, 94 => 584,
                95 => 500, 96 => 278, 97 => 556, 98 => 611, 99 => 556, 100 => 611, 101 => 556,
                102 => 333, 103 => 611, 104 => 611, 105 => 278, 106 => 278, 107 => 556, 108 => 278,
                109 => 889, 110 => 611, 111 => 611, 112 => 611, 113 => 611, 114 => 389, 115 => 556,
                116 => 333, 117 => 611, 118 => 556, 119 => 778, 120 => 556, 121 => 556, 122 => 500,
                123 => 389, 124 => 280, 125 => 389, 126 => 584,
            ],
            'Times-Roman' => [
                32 => 250, 33 => 333, 34 => 408, 35 => 500, 36 => 500, 37 => 833, 38 => 778,
                39 => 180, 40 => 333, 41 => 333, 42 => 500, 43 => 564, 44 => 250, 45 => 333,
                46 => 250, 47 => 278, 48 => 500, 49 => 500, 50 => 500, 51 => 500, 52 => 500,
                53 => 500, 54 => 500, 55 => 500, 56 => 500, 57 => 500, 58 => 278, 59 => 278,
                60 => 564, 61 => 564, 62 => 564, 63 => 444, 64 => 921, 65 => 722, 66 => 667,
                67 => 667, 68 => 722, 69 => 611, 70 => 556, 71 => 722, 72 => 722, 73 => 333,
                74 => 389, 75 => 722, 76 => 611, 77 => 889, 78 => 722, 79 => 722, 80 => 556,
                81 => 722, 82 => 667, 83 => 556, 84 => 611, 85 => 722, 86 => 722, 87 => 944,
                88 => 722, 89 => 722, 90 => 611, 91 => 333, 92 => 278, 93 => 333, 94 => 469,
                95 => 500, 96 => 333, 97 => 444, 98 => 500, 99 => 444, 100 => 500, 101 => 444,
                102 => 333, 103 => 500, 104 => 500, 105 => 278, 106 => 278, 107 => 500, 108 => 278,
                109 => 778, 110 => 500, 111 => 500, 112 => 500, 113 => 500, 114 => 333, 115 => 389,
                116 => 278, 117 => 500, 118 => 500, 119 => 722, 120 => 500, 121 => 500, 122 => 444,
                123 => 480, 124 => 200, 125 => 480, 126 => 541,
            ],
            'Times-Bold' => [
                32 => 250, 33 => 333, 34 => 555, 35 => 500, 36 => 500, 37 => 1000, 38 => 833,
                39 => 278, 40 => 333, 41 => 333, 42 => 500, 43 => 570, 44 => 250, 45 => 333,
                46 => 250, 47 => 278, 48 => 500, 49 => 500, 50 => 500, 51 => 500, 52 => 500,
                53 => 500, 54 => 500, 55 => 500, 56 => 500, 57 => 500, 58 => 333, 59 => 333,
                60 => 570, 61 => 570, 62 => 570, 63 => 500, 64 => 930, 65 => 722, 66 => 667,
                67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778, 72 => 778, 73 => 389,
                74 => 500, 75 => 778, 76 => 667, 77 => 944, 78 => 722, 79 => 778, 80 => 611,
                81 => 778, 82 => 722, 83 => 556, 84 => 667, 85 => 722, 86 => 722, 87 => 1000,
                88 => 722, 89 => 722, 90 => 667, 91 => 333, 92 => 278, 93 => 333, 94 => 581,
                95 => 500, 96 => 333, 97 => 500, 98 => 556, 99 => 444, 100 => 556, 101 => 444,
                102 => 333, 103 => 500, 104 => 556, 105 => 278, 106 => 333, 107 => 556, 108 => 278,
                109 => 833, 110 => 556, 111 => 500, 112 => 556, 113 => 556, 114 => 444, 115 => 389,
                116 => 333, 117 => 556, 118 => 500, 119 => 722, 120 => 500, 121 => 500, 122 => 444,
                123 => 394, 124 => 220, 125 => 394, 126 => 520,
            ],
            'Times-Italic' => [
                32 => 250, 33 => 333, 34 => 420, 35 => 500, 36 => 500, 37 => 833, 38 => 778,
                39 => 214, 40 => 333, 41 => 333, 42 => 500, 43 => 675, 44 => 250, 45 => 333,
                46 => 250, 47 => 278, 48 => 500, 49 => 500, 50 => 500, 51 => 500, 52 => 500,
                53 => 500, 54 => 500, 55 => 500, 56 => 500, 57 => 500, 58 => 333, 59 => 333,
                60 => 675, 61 => 675, 62 => 675, 63 => 500, 64 => 920, 65 => 611, 66 => 611,
                67 => 667, 68 => 722, 69 => 611, 70 => 611, 71 => 722, 72 => 722, 73 => 333,
                74 => 444, 75 => 667, 76 => 556, 77 => 833, 78 => 667, 79 => 722, 80 => 611,
                81 => 722, 82 => 611, 83 => 500, 84 => 556, 85 => 722, 86 => 611, 87 => 833,
                88 => 611, 89 => 556, 90 => 556, 91 => 389, 92 => 278, 93 => 389, 94 => 422,
                95 => 500, 96 => 333, 97 => 500, 98 => 500, 99 => 444, 100 => 500, 101 => 444,
                102 => 278, 103 => 500, 104 => 500, 105 => 278, 106 => 278, 107 => 444, 108 => 278,
                109 => 722, 110 => 500, 111 => 500, 112 => 500, 113 => 500, 114 => 389, 115 => 389,
                116 => 278, 117 => 500, 118 => 444, 119 => 667, 120 => 444, 121 => 444, 122 => 389,
                123 => 400, 124 => 275, 125 => 400, 126 => 541,
            ],
            'Times-BoldItalic' => [
                32 => 250, 33 => 389, 34 => 555, 35 => 500, 36 => 500, 37 => 833, 38 => 778,
                39 => 278, 40 => 333, 41 => 333, 42 => 500, 43 => 570, 44 => 250, 45 => 333,
                46 => 250, 47 => 278, 48 => 500, 49 => 500, 50 => 500, 51 => 500, 52 => 500,
                53 => 500, 54 => 500, 55 => 500, 56 => 500, 57 => 500, 58 => 333, 59 => 333,
                60 => 570, 61 => 570, 62 => 570, 63 => 500, 64 => 832, 65 => 667, 66 => 667,
                67 => 667, 68 => 722, 69 => 667, 70 => 667, 71 => 722, 72 => 778, 73 => 389,
                74 => 500, 75 => 667, 76 => 611, 77 => 889, 78 => 722, 79 => 722, 80 => 611,
                81 => 722, 82 => 667, 83 => 556, 84 => 611, 85 => 722, 86 => 667, 87 => 889,
                88 => 667, 89 => 611, 90 => 611, 91 => 333, 92 => 278, 93 => 333, 94 => 500,
                95 => 500, 96 => 333, 97 => 500, 98 => 500, 99 => 444, 100 => 500, 101 => 444,
                102 => 333, 103 => 500, 104 => 556, 105 => 278, 106 => 278, 107 => 500, 108 => 278,
                109 => 778, 110 => 556, 111 => 500, 112 => 500, 113 => 500, 114 => 389, 115 => 389,
                116 => 278, 117 => 556, 118 => 444, 119 => 667, 120 => 500, 121 => 444, 122 => 389,
                123 => 348, 124 => 220, 125 => 348, 126 => 570,
            ],
        ];

        // Extended character widths (codes 128-255) shared per font family
        $extendedWidths = [
            'helvetica' => [
                128 => 556, 129 => 0, 130 => 222, 131 => 556, 132 => 222, 133 => 1000, 134 => 556,
                135 => 556, 136 => 333, 137 => 1000, 138 => 667, 139 => 333, 140 => 1000, 141 => 0,
                142 => 611, 143 => 0, 144 => 0, 145 => 222, 146 => 222, 147 => 333, 148 => 333,
                149 => 350, 150 => 500, 151 => 1000, 152 => 333, 153 => 1000, 154 => 500, 155 => 333,
                156 => 944, 157 => 0, 158 => 500, 159 => 667, 160 => 278, 161 => 333, 162 => 556,
                163 => 556, 164 => 556, 165 => 556, 166 => 260, 167 => 556, 168 => 333, 169 => 737,
                170 => 370, 171 => 556, 172 => 584, 173 => 333, 174 => 737, 175 => 333, 176 => 400,
                177 => 584, 178 => 333, 179 => 333, 180 => 333, 181 => 556, 182 => 537, 183 => 278,
                184 => 333, 185 => 333, 186 => 365, 187 => 556, 188 => 834, 189 => 834, 190 => 834,
                191 => 611, 192 => 667, 193 => 667, 194 => 667, 195 => 667, 196 => 667, 197 => 667,
                198 => 1000, 199 => 722, 200 => 667, 201 => 667, 202 => 667, 203 => 667, 204 => 278,
                205 => 278, 206 => 278, 207 => 278, 208 => 722, 209 => 722, 210 => 778, 211 => 778,
                212 => 778, 213 => 778, 214 => 778, 215 => 584, 216 => 778, 217 => 722, 218 => 722,
                219 => 722, 220 => 722, 221 => 667, 222 => 667, 223 => 611, 224 => 556, 225 => 556,
                226 => 556, 227 => 556, 228 => 556, 229 => 556, 230 => 889, 231 => 500, 232 => 556,
                233 => 556, 234 => 556, 235 => 556, 236 => 278, 237 => 278, 238 => 278, 239 => 278,
                240 => 556, 241 => 556, 242 => 556, 243 => 556, 244 => 556, 245 => 556, 246 => 556,
                247 => 584, 248 => 611, 249 => 556, 250 => 556, 251 => 556, 252 => 556, 253 => 500,
                254 => 556, 255 => 500,
            ],
            'times' => [
                128 => 500, 129 => 0, 130 => 333, 131 => 500, 132 => 444, 133 => 1000, 134 => 500,
                135 => 500, 136 => 333, 137 => 1000, 138 => 556, 139 => 333, 140 => 889, 141 => 0,
                142 => 611, 143 => 0, 144 => 0, 145 => 333, 146 => 333, 147 => 444, 148 => 444,
                149 => 350, 150 => 500, 151 => 1000, 152 => 333, 153 => 980, 154 => 389, 155 => 333,
                156 => 722, 157 => 0, 158 => 444, 159 => 722, 160 => 250, 161 => 333, 162 => 500,
                163 => 500, 164 => 500, 165 => 500, 166 => 200, 167 => 500, 168 => 333, 169 => 760,
                170 => 276, 171 => 500, 172 => 564, 173 => 333, 174 => 760, 175 => 333, 176 => 400,
                177 => 564, 178 => 300, 179 => 300, 180 => 333, 181 => 500, 182 => 453, 183 => 250,
                184 => 333, 185 => 300, 186 => 310, 187 => 500, 188 => 750, 189 => 750, 190 => 750,
                191 => 444, 192 => 722, 193 => 722, 194 => 722, 195 => 722, 196 => 722, 197 => 722,
                198 => 889, 199 => 667, 200 => 611, 201 => 611, 202 => 611, 203 => 611, 204 => 333,
                205 => 333, 206 => 333, 207 => 333, 208 => 722, 209 => 722, 210 => 722, 211 => 722,
                212 => 722, 213 => 722, 214 => 722, 215 => 564, 216 => 722, 217 => 722, 218 => 722,
                219 => 722, 220 => 722, 221 => 722, 222 => 556, 223 => 500, 224 => 444, 225 => 444,
                226 => 444, 227 => 444, 228 => 444, 229 => 444, 230 => 667, 231 => 444, 232 => 444,
                233 => 444, 234 => 444, 235 => 444, 236 => 278, 237 => 278, 238 => 278, 239 => 278,
                240 => 500, 241 => 500, 242 => 500, 243 => 500, 244 => 500, 245 => 500, 246 => 500,
                247 => 564, 248 => 500, 249 => 500, 250 => 500, 251 => 500, 252 => 500, 253 => 500,
                254 => 500, 255 => 500,
            ],
        ];

        // ---- Standard WinAnsiEncoding codeToName (shared by all Latin fonts) ----
        $standardCodeToName = static function (): array {
            return [
                '32' => 'space', '33' => 'exclam', '34' => 'quotedbl', '35' => 'numbersign',
                '36' => 'dollar', '37' => 'percent', '38' => 'ampersand', '39' => 'quotesingle',
                '40' => 'parenleft', '41' => 'parenright', '42' => 'asterisk', '43' => 'plus',
                '44' => 'comma', '45' => 'hyphen', '46' => 'period', '47' => 'slash',
                '48' => 'zero', '49' => 'one', '50' => 'two', '51' => 'three',
                '52' => 'four', '53' => 'five', '54' => 'six', '55' => 'seven',
                '56' => 'eight', '57' => 'nine', '58' => 'colon', '59' => 'semicolon',
                '60' => 'less', '61' => 'equal', '62' => 'greater', '63' => 'question',
                '64' => 'at', '65' => 'A', '66' => 'B', '67' => 'C', '68' => 'D',
                '69' => 'E', '70' => 'F', '71' => 'G', '72' => 'H', '73' => 'I',
                '74' => 'J', '75' => 'K', '76' => 'L', '77' => 'M', '78' => 'N',
                '79' => 'O', '80' => 'P', '81' => 'Q', '82' => 'R', '83' => 'S',
                '84' => 'T', '85' => 'U', '86' => 'V', '87' => 'W', '88' => 'X',
                '89' => 'Y', '90' => 'Z', '91' => 'bracketleft', '92' => 'backslash',
                '93' => 'bracketright', '94' => 'asciicircum', '95' => 'underscore',
                '96' => 'grave', '97' => 'a', '98' => 'b', '99' => 'c', '100' => 'd',
                '101' => 'e', '102' => 'f', '103' => 'g', '104' => 'h', '105' => 'i',
                '106' => 'j', '107' => 'k', '108' => 'l', '109' => 'm', '110' => 'n',
                '111' => 'o', '112' => 'p', '113' => 'q', '114' => 'r', '115' => 's',
                '116' => 't', '117' => 'u', '118' => 'v', '119' => 'w', '120' => 'x',
                '121' => 'y', '122' => 'z', '123' => 'braceleft', '124' => 'bar',
                '125' => 'braceright', '126' => 'asciitilde',
                '128' => 'Euro', '129' => '.notdef', '130' => 'quotesinglbase',
                '131' => 'florin', '132' => 'quotedblbase', '133' => 'ellipsis',
                '134' => 'dagger', '135' => 'daggerdbl', '136' => 'circumflex',
                '137' => 'perthousand', '138' => 'Scaron', '139' => 'guilsinglleft',
                '140' => 'OE', '141' => '.notdef', '142' => 'Zcaron', '143' => '.notdef',
                '144' => '.notdef', '145' => 'quoteleft', '146' => 'quoteright',
                '147' => 'quotedblleft', '148' => 'quotedblright', '149' => 'bullet',
                '150' => 'endash', '151' => 'emdash', '152' => 'tilde',
                '153' => 'trademark', '154' => 'scaron', '155' => 'guilsinglright',
                '156' => 'oe', '157' => '.notdef', '158' => 'zcaron',
                '159' => 'Ydieresis', '160' => 'space', '161' => 'exclamdown',
                '162' => 'cent', '163' => 'sterling', '164' => 'currency',
                '165' => 'yen', '166' => 'brokenbar', '167' => 'section',
                '168' => 'dieresis', '169' => 'copyright', '170' => 'ordfeminine',
                '171' => 'guillemotleft', '172' => 'logicalnot', '173' => 'hyphen',
                '174' => 'registered', '175' => 'macron', '176' => 'degree',
                '177' => 'plusminus', '178' => 'twosuperior', '179' => 'threesuperior',
                '180' => 'acute', '181' => 'mu', '182' => 'paragraph',
                '183' => 'periodcentered', '184' => 'cedilla', '185' => 'onesuperior',
                '186' => 'ordmasculine', '187' => 'guillemotright',
                '188' => 'onequarter', '189' => 'onehalf', '190' => 'threequarters',
                '191' => 'questiondown', '192' => 'Agrave', '193' => 'Aacute',
                '194' => 'Acircumflex', '195' => 'Atilde', '196' => 'Adieresis',
                '197' => 'Aring', '198' => 'AE', '199' => 'Ccedilla',
                '200' => 'Egrave', '201' => 'Eacute', '202' => 'Ecircumflex',
                '203' => 'Edieresis', '204' => 'Igrave', '205' => 'Iacute',
                '206' => 'Icircumflex', '207' => 'Idieresis', '208' => 'Eth',
                '209' => 'Ntilde', '210' => 'Ograve', '211' => 'Oacute',
                '212' => 'Ocircumflex', '213' => 'Otilde', '214' => 'Odieresis',
                '215' => 'multiply', '216' => 'Oslash', '217' => 'Ugrave',
                '218' => 'Uacute', '219' => 'Ucircumflex', '220' => 'Udieresis',
                '221' => 'Yacute', '222' => 'Thorn', '223' => 'germandbls',
                '224' => 'agrave', '225' => 'aacute', '226' => 'acircumflex',
                '227' => 'atilde', '228' => 'adieresis', '229' => 'aring',
                '230' => 'ae', '231' => 'ccedilla', '232' => 'egrave',
                '233' => 'eacute', '234' => 'ecircumflex', '235' => 'edieresis',
                '236' => 'igrave', '237' => 'iacute', '238' => 'icircumflex',
                '239' => 'idieresis', '240' => 'eth', '241' => 'ntilde',
                '242' => 'ograve', '243' => 'oacute', '244' => 'ocircumflex',
                '245' => 'otilde', '246' => 'odieresis', '247' => 'divide',
                '248' => 'oslash', '249' => 'ugrave', '250' => 'uacute',
                '251' => 'ucircumflex', '252' => 'udieresis', '253' => 'yacute',
                '254' => 'thorn', '255' => 'ydieresis',
            ];
        };

        // ---- Symbol font codeToName and widths ----
        $symbolCodeToName = static function (): array {
            $map = [];
            for ($i = 0; $i < 256; $i++) {
                $map[(string)$i] = '.notdef';
            }
            $map['32'] = 'space'; $map['33'] = 'exclam'; $map['34'] = 'universal';
            $map['35'] = 'numbersign'; $map['36'] = 'existential'; $map['37'] = 'percent';
            $map['38'] = 'ampersand'; $map['39'] = 'suchthat'; $map['40'] = 'parenleft';
            $map['41'] = 'parenright'; $map['42'] = 'asteriskmath'; $map['43'] = 'plus';
            $map['44'] = 'comma'; $map['45'] = 'minus'; $map['46'] = 'period';
            $map['47'] = 'slash'; $map['48'] = 'zero'; $map['49'] = 'one';
            $map['50'] = 'two'; $map['51'] = 'three'; $map['52'] = 'four';
            $map['53'] = 'five'; $map['54'] = 'six'; $map['55'] = 'seven';
            $map['56'] = 'eight'; $map['57'] = 'nine'; $map['58'] = 'colon';
            $map['59'] = 'semicolon'; $map['60'] = 'less'; $map['61'] = 'equal';
            $map['62'] = 'greater'; $map['63'] = 'question'; $map['64'] = 'congruent';
            $map['65'] = 'Alpha'; $map['66'] = 'Beta'; $map['67'] = 'Chi';
            $map['68'] = 'Delta'; $map['69'] = 'Epsilon'; $map['70'] = 'Phi';
            $map['71'] = 'Gamma'; $map['72'] = 'Eta'; $map['73'] = 'Iota';
            $map['74'] = 'theta1'; $map['75'] = 'Kappa'; $map['76'] = 'Lambda';
            $map['77'] = 'Mu'; $map['78'] = 'Nu'; $map['79'] = 'Omicron';
            $map['80'] = 'Pi'; $map['81'] = 'Theta'; $map['82'] = 'Rho';
            $map['83'] = 'Sigma'; $map['84'] = 'Tau'; $map['85'] = 'Upsilon';
            $map['86'] = 'sigma1'; $map['87'] = 'Omega'; $map['88'] = 'Xi';
            $map['89'] = 'Psi'; $map['90'] = 'Zeta';
            $map['91'] = 'bracketleft'; $map['92'] = 'therefore'; $map['93'] = 'bracketright';
            $map['94'] = 'perpendicular'; $map['95'] = 'underscore'; $map['96'] = 'radicalex';
            $map['97'] = 'alpha'; $map['98'] = 'beta'; $map['99'] = 'chi';
            $map['100'] = 'delta'; $map['101'] = 'epsilon'; $map['102'] = 'phi';
            $map['103'] = 'gamma'; $map['104'] = 'eta'; $map['105'] = 'iota';
            $map['106'] = 'phi1'; $map['107'] = 'kappa'; $map['108'] = 'lambda';
            $map['109'] = 'mu'; $map['110'] = 'nu'; $map['111'] = 'omicron';
            $map['112'] = 'pi'; $map['113'] = 'theta'; $map['114'] = 'rho';
            $map['115'] = 'sigma'; $map['116'] = 'tau'; $map['117'] = 'upsilon';
            $map['118'] = 'omega1'; $map['119'] = 'omega'; $map['120'] = 'xi';
            $map['121'] = 'psi'; $map['122'] = 'zeta';
            $map['123'] = 'braceleft'; $map['124'] = 'bar'; $map['125'] = 'braceright';
            $map['126'] = 'similar';
            $map['160'] = 'Euro'; $map['161'] = 'Upsilon1'; $map['162'] = 'minute';
            $map['163'] = 'lessequal'; $map['164'] = 'fraction'; $map['165'] = 'infinity';
            $map['166'] = 'florin'; $map['167'] = 'club'; $map['168'] = 'diamond';
            $map['169'] = 'heart'; $map['170'] = 'spade'; $map['171'] = 'arrowboth';
            $map['172'] = 'arrowleft'; $map['173'] = 'arrowup'; $map['174'] = 'arrowright';
            $map['175'] = 'arrowdown'; $map['176'] = 'degree'; $map['177'] = 'plusminus';
            $map['178'] = 'second'; $map['179'] = 'greaterequal'; $map['180'] = 'multiply';
            $map['181'] = 'proportional'; $map['182'] = 'partialdiff'; $map['183'] = 'bullet';
            $map['184'] = 'divide'; $map['185'] = 'notequal'; $map['186'] = 'equivalence';
            $map['187'] = 'approxequal'; $map['188'] = 'ellipsis'; $map['189'] = 'arrowvertex';
            $map['190'] = 'arrowhorizex'; $map['191'] = 'carriagereturn';
            $map['192'] = 'aleph'; $map['193'] = 'Ifraktur'; $map['194'] = 'Rfraktur';
            $map['195'] = 'weierstrass'; $map['196'] = 'circlemultiply'; $map['197'] = 'circleplus';
            $map['198'] = 'emptyset'; $map['199'] = 'intersection'; $map['200'] = 'union';
            $map['201'] = 'propersuperset'; $map['202'] = 'reflexsuperset'; $map['203'] = 'notsubset';
            $map['204'] = 'propersubset'; $map['205'] = 'reflexsubset'; $map['206'] = 'element';
            $map['207'] = 'notelement'; $map['208'] = 'angle'; $map['209'] = 'gradient';
            $map['210'] = 'registerserif'; $map['211'] = 'copyrightserif'; $map['212'] = 'trademarkserif';
            $map['213'] = 'product'; $map['214'] = 'radical'; $map['215'] = 'dotmath';
            $map['216'] = 'logicalnot'; $map['217'] = 'logicaland'; $map['218'] = 'logicalor';
            $map['219'] = 'arrowdblboth'; $map['220'] = 'arrowdblleft'; $map['221'] = 'arrowdblup';
            $map['222'] = 'arrowdblright'; $map['223'] = 'arrowdbldown';
            $map['224'] = 'lozenge'; $map['225'] = 'angleleft'; $map['226'] = 'registersans';
            $map['227'] = 'copyrightsans'; $map['228'] = 'trademarksans'; $map['229'] = 'summation';
            $map['230'] = 'parenlefttp'; $map['231'] = 'parenleftex'; $map['232'] = 'parenleftbt';
            $map['233'] = 'bracketlefttp'; $map['234'] = 'bracketleftex'; $map['235'] = 'bracketleftbt';
            $map['236'] = 'bracelefttp'; $map['237'] = 'braceleftmid'; $map['238'] = 'braceleftbt';
            $map['239'] = 'braceex';
            $map['240'] = 'integralex'; $map['241'] = 'integraltp'; $map['242'] = 'integralex';
            $map['243'] = 'integralbt'; $map['244'] = 'parenrighttp'; $map['245'] = 'parenrightex';
            $map['246'] = 'parenrightbt'; $map['247'] = 'bracketrighttp'; $map['248'] = 'bracketrightex';
            $map['249'] = 'bracketrightbt'; $map['250'] = 'bracerighttp'; $map['251'] = 'bracerightmid';
            $map['252'] = 'bracerightbt'; $map['253'] = 'apple';
            return $map;
        };

        $symbolWidths = static function (): array {
            $w = array_fill(0, 256, 0);
            $w[32] = 250; $w[33] = 333; $w[34] = 713; $w[35] = 500; $w[36] = 549;
            $w[37] = 833; $w[38] = 778; $w[39] = 439; $w[40] = 333; $w[41] = 333;
            $w[42] = 500; $w[43] = 549; $w[44] = 250; $w[45] = 549; $w[46] = 250;
            $w[47] = 278; $w[48] = 500; $w[49] = 500; $w[50] = 500; $w[51] = 500;
            $w[52] = 500; $w[53] = 500; $w[54] = 500; $w[55] = 500; $w[56] = 500;
            $w[57] = 500; $w[58] = 278; $w[59] = 278; $w[60] = 549; $w[61] = 549;
            $w[62] = 549; $w[63] = 444; $w[64] = 549; $w[65] = 722; $w[66] = 667;
            $w[67] = 722; $w[68] = 612; $w[69] = 611; $w[70] = 763; $w[71] = 603;
            $w[72] = 722; $w[73] = 333; $w[74] = 631; $w[75] = 722; $w[76] = 686;
            $w[77] = 889; $w[78] = 722; $w[79] = 722; $w[80] = 768; $w[81] = 741;
            $w[82] = 556; $w[83] = 592; $w[84] = 611; $w[85] = 690; $w[86] = 439;
            $w[87] = 768; $w[88] = 645; $w[89] = 795; $w[90] = 611; $w[91] = 333;
            $w[92] = 863; $w[93] = 333; $w[94] = 658; $w[95] = 500; $w[96] = 500;
            $w[97] = 631; $w[98] = 549; $w[99] = 549; $w[100] = 494; $w[101] = 439;
            $w[102] = 521; $w[103] = 411; $w[104] = 603; $w[105] = 329; $w[106] = 603;
            $w[107] = 549; $w[108] = 549; $w[109] = 576; $w[110] = 521; $w[111] = 549;
            $w[112] = 549; $w[113] = 521; $w[114] = 549; $w[115] = 603; $w[116] = 439;
            $w[117] = 576; $w[118] = 713; $w[119] = 686; $w[120] = 493; $w[121] = 686;
            $w[122] = 494; $w[123] = 480; $w[124] = 200; $w[125] = 480; $w[126] = 549;
            $w[160] = 750; $w[161] = 620; $w[162] = 247; $w[163] = 549; $w[164] = 167;
            $w[165] = 713; $w[166] = 500; $w[167] = 753; $w[168] = 753; $w[169] = 753;
            $w[170] = 753; $w[171] = 1042; $w[172] = 987; $w[173] = 603; $w[174] = 987;
            $w[175] = 603; $w[176] = 400; $w[177] = 549; $w[178] = 411; $w[179] = 549;
            $w[180] = 549; $w[181] = 713; $w[182] = 494; $w[183] = 460; $w[184] = 549;
            $w[185] = 549; $w[186] = 549; $w[187] = 549; $w[188] = 1000; $w[189] = 603;
            $w[190] = 603; $w[191] = 1000; $w[192] = 658; $w[193] = 823; $w[194] = 686;
            $w[195] = 795; $w[196] = 987; $w[197] = 768; $w[198] = 768; $w[199] = 823;
            $w[200] = 768; $w[201] = 768; $w[202] = 713; $w[203] = 713; $w[204] = 713;
            $w[205] = 713; $w[206] = 713; $w[207] = 713; $w[208] = 768; $w[209] = 713;
            $w[210] = 790; $w[211] = 790; $w[212] = 890; $w[213] = 823; $w[214] = 549;
            $w[215] = 250; $w[216] = 713; $w[217] = 603; $w[218] = 603; $w[219] = 1042;
            $w[220] = 987; $w[221] = 603; $w[222] = 987; $w[223] = 603; $w[224] = 494;
            $w[225] = 329; $w[226] = 790; $w[227] = 790; $w[228] = 786; $w[229] = 713;
            $w[230] = 384; $w[231] = 384; $w[232] = 384; $w[233] = 384; $w[234] = 384;
            $w[235] = 384; $w[236] = 494; $w[237] = 494; $w[238] = 494; $w[239] = 494;
            $w[240] = 494;
            $w[241] = 494; $w[242] = 494; $w[243] = 494; $w[244] = 494; $w[245] = 494;
            $w[246] = 494; $w[247] = 494; $w[248] = 494; $w[249] = 494; $w[250] = 494;
            $w[251] = 494; $w[252] = 494; $w[253] = 1000;
            return $w;
        };

        // ---- ZapfDingbats codeToName and widths ----
        $zapfCodeToName = static function (): array {
            $map = [];
            for ($i = 0; $i < 256; $i++) {
                $map[(string)$i] = '.notdef';
            }
            $map['32'] = 'space'; $map['33'] = 'a1'; $map['34'] = 'a2';
            $map['35'] = 'a202'; $map['36'] = 'a3'; $map['37'] = 'a4';
            $map['38'] = 'a5'; $map['39'] = 'a119'; $map['40'] = 'a118';
            $map['41'] = 'a117'; $map['42'] = 'a11'; $map['43'] = 'a12';
            $map['44'] = 'a13'; $map['45'] = 'a14'; $map['46'] = 'a15';
            $map['47'] = 'a16'; $map['48'] = 'a105'; $map['49'] = 'a17';
            $map['50'] = 'a18'; $map['51'] = 'a19'; $map['52'] = 'a20';
            $map['53'] = 'a21'; $map['54'] = 'a22'; $map['55'] = 'a23';
            $map['56'] = 'a24'; $map['57'] = 'a25'; $map['58'] = 'a26';
            $map['59'] = 'a27'; $map['60'] = 'a28'; $map['61'] = 'a6';
            $map['62'] = 'a7'; $map['63'] = 'a8'; $map['64'] = 'a9';
            $map['65'] = 'a10'; $map['66'] = 'a29'; $map['67'] = 'a30';
            $map['68'] = 'a31'; $map['69'] = 'a32'; $map['70'] = 'a33';
            $map['71'] = 'a34'; $map['72'] = 'a35'; $map['73'] = 'a36';
            $map['74'] = 'a37'; $map['75'] = 'a38'; $map['76'] = 'a39';
            $map['77'] = 'a40'; $map['78'] = 'a41'; $map['79'] = 'a42';
            $map['80'] = 'a43'; $map['81'] = 'a44'; $map['82'] = 'a45';
            $map['83'] = 'a46'; $map['84'] = 'a47'; $map['85'] = 'a48';
            $map['86'] = 'a49'; $map['87'] = 'a50'; $map['88'] = 'a51';
            $map['89'] = 'a52'; $map['90'] = 'a53'; $map['91'] = 'a54';
            $map['92'] = 'a55'; $map['93'] = 'a56'; $map['94'] = 'a57';
            $map['95'] = 'a58'; $map['96'] = 'a59'; $map['97'] = 'a60';
            $map['98'] = 'a61'; $map['99'] = 'a62'; $map['100'] = 'a63';
            $map['101'] = 'a64'; $map['102'] = 'a65'; $map['103'] = 'a66';
            $map['104'] = 'a67'; $map['105'] = 'a68'; $map['106'] = 'a69';
            $map['107'] = 'a70'; $map['108'] = 'a71'; $map['109'] = 'a72';
            $map['110'] = 'a73'; $map['111'] = 'a74'; $map['112'] = 'a203';
            $map['113'] = 'a75'; $map['114'] = 'a204'; $map['115'] = 'a76';
            $map['116'] = 'a77'; $map['117'] = 'a78'; $map['118'] = 'a79';
            $map['119'] = 'a81'; $map['120'] = 'a82'; $map['121'] = 'a83';
            $map['122'] = 'a84'; $map['123'] = 'a97'; $map['124'] = 'a98';
            $map['125'] = 'a99'; $map['126'] = 'a100';
            return $map;
        };

        $zapfWidths = static function (): array {
            $w = array_fill(0, 256, 0);
            $w[32] = 278; $w[33] = 974; $w[34] = 961; $w[35] = 974; $w[36] = 980;
            $w[37] = 719; $w[38] = 789; $w[39] = 790; $w[40] = 791; $w[41] = 690;
            $w[42] = 960; $w[43] = 939; $w[44] = 549; $w[45] = 855; $w[46] = 911;
            $w[47] = 933; $w[48] = 911; $w[49] = 945; $w[50] = 974; $w[51] = 755;
            $w[52] = 846; $w[53] = 762; $w[54] = 761; $w[55] = 571; $w[56] = 677;
            $w[57] = 763; $w[58] = 760; $w[59] = 759; $w[60] = 754; $w[61] = 494;
            $w[62] = 552; $w[63] = 537; $w[64] = 577; $w[65] = 692; $w[66] = 786;
            $w[67] = 788; $w[68] = 788; $w[69] = 790; $w[70] = 793; $w[71] = 794;
            $w[72] = 816; $w[73] = 823; $w[74] = 789; $w[75] = 841; $w[76] = 823;
            $w[77] = 833; $w[78] = 816; $w[79] = 831; $w[80] = 923; $w[81] = 744;
            $w[82] = 723; $w[83] = 749; $w[84] = 790; $w[85] = 792; $w[86] = 695;
            $w[87] = 776; $w[88] = 768; $w[89] = 792; $w[90] = 759; $w[91] = 707;
            $w[92] = 708; $w[93] = 682; $w[94] = 701; $w[95] = 826; $w[96] = 815;
            $w[97] = 789; $w[98] = 789; $w[99] = 707; $w[100] = 687; $w[101] = 696;
            $w[102] = 689; $w[103] = 786; $w[104] = 787; $w[105] = 713; $w[106] = 791;
            $w[107] = 785; $w[108] = 791; $w[109] = 873; $w[110] = 761; $w[111] = 762;
            $w[112] = 762; $w[113] = 759; $w[114] = 759; $w[115] = 892; $w[116] = 892;
            $w[117] = 788; $w[118] = 784; $w[119] = 438; $w[120] = 138; $w[121] = 277;
            $w[122] = 415; $w[123] = 392; $w[124] = 392; $w[125] = 668; $w[126] = 668;
            return $w;
        };

        // ---- Resolve widths for this font ----
        $familyKey = match (true) {
            str_starts_with($fontName, 'Helvetica') => 'helvetica',
            str_starts_with($fontName, 'Times') => 'times',
            str_starts_with($fontName, 'Courier') => 'courier',
            default => null,
        };

        $isSymbol = $fontName === 'Symbol';
        $isZapf = $fontName === 'ZapfDingbats';

        if ($isSymbol) {
            $codeToName = $symbolCodeToName();
            $widthMap = $symbolWidths();
        } elseif ($isZapf) {
            $codeToName = $zapfCodeToName();
            $widthMap = $zapfWidths();
        } elseif ($familyKey === 'courier') {
            $codeToName = $standardCodeToName();
            // Courier is monospace: all printable chars are 600
            $widthMap = [];
            for ($i = 0; $i < 256; $i++) {
                $widthMap[$i] = 0;
            }
            for ($i = 32; $i <= 126; $i++) {
                $widthMap[$i] = 600;
            }
            // Extended printable chars
            foreach ([128, 130, 131, 132, 133, 134, 135, 136, 137, 138, 139, 140, 142,
                      145, 146, 147, 148, 149, 150, 151, 152, 153, 154, 155, 156, 158, 159,
                      160, 161, 162, 163, 164, 165, 166, 167, 168, 169, 170, 171, 172, 173,
                      174, 175, 176, 177, 178, 179, 180, 181, 182, 183, 184, 185, 186, 187,
                      188, 189, 190, 191, 192, 193, 194, 195, 196, 197, 198, 199, 200, 201,
                      202, 203, 204, 205, 206, 207, 208, 209, 210, 211, 212, 213, 214, 215,
                      216, 217, 218, 219, 220, 221, 222, 223, 224, 225, 226, 227, 228, 229,
                      230, 231, 232, 233, 234, 235, 236, 237, 238, 239, 240, 241, 242, 243,
                      244, 245, 246, 247, 248, 249, 250, 251, 252, 253, 254, 255] as $ext) {
                $widthMap[$ext] = 600;
            }
        } else {
            $codeToName = $standardCodeToName();
            // Oblique/Italic variants share ASCII widths with their upright counterpart.
            // Times-Italic and Times-BoldItalic have separate entries in asciiWidths,
            // but Helvetica-Oblique → Helvetica, Helvetica-BoldOblique → Helvetica-Bold.
            $widthEntry = $fontName;
            if (!isset($asciiWidths[$fontName])) {
                // Remove style suffix to find the base font's width entry.
                // e.g. Helvetica-BoldOblique → Helvetica-Bold,  Helvetica-Oblique → Helvetica
                if (str_ends_with($fontName, 'Oblique')) {
                    $widthEntry = substr($fontName, 0, -7); // strip '-Oblique' (7 chars)
                } elseif (str_ends_with($fontName, 'Italic')) {
                    $widthEntry = substr($fontName, 0, -6); // strip '-Italic'
                }
                // Final safety net for any remaining variant
                if (!isset($asciiWidths[$widthEntry])) {
                    $widthEntry = match (true) {
                        str_starts_with($fontName, 'Helvetica') => 'Helvetica',
                        str_starts_with($fontName, 'Times') => 'Times-Roman',
                        default => $widthEntry,
                    };
                }
            }
            // Initialize all 256 codes to 0, then overlay ASCII and extended widths
            $widthMap = array_fill(0, 256, 0);
            foreach (($asciiWidths[$widthEntry] ?? []) as $code => $w) {
                $widthMap[$code] = $w;
            }
            foreach (($extendedWidths[$familyKey] ?? []) as $code => $w) {
                $widthMap[$code] = $w;
            }
        }

        // ---- Build the full data structure ----
        $info = $meta[$fontName];
        $charCount = count(array_filter($widthMap, fn(float|int $w): bool => $w > 0));

        return [
            'codeToName' => $codeToName,
            'isUnicode' => false,
            'FontName' => $fontName,
            'FullName' => $info['fullName'],
            'FamilyName' => $info['familyName'],
            'Weight' => $info['weight'],
            'ItalicAngle' => $info['italicAngle'],
            'IsFixedPitch' => $info['isFixedPitch'],
            'CharacterSet' => $isSymbol ? 'Special' : ($isZapf ? 'Dingbats' : 'ExtendedRoman'),
            'FontBBox' => $info['fontBBox'],
            'UnderlinePosition' => '-100',
            'UnderlineThickness' => '50',
            'Version' => '002.00',
            'EncodingScheme' => ($isSymbol || $isZapf) ? 'FontSpecific' : 'WinAnsiEncoding',
            'CapHeight' => $info['capHeight'],
            'XHeight' => $info['xHeight'],
            'Ascender' => $info['ascender'],
            'Descender' => $info['descender'],
            'StdHW' => $info['stdHW'],
            'StdVW' => $info['stdVW'],
            'StartCharMetrics' => (string)$charCount,
            'C' => $widthMap,
            '_version_' => 6,
            'CIDtoGID_Compressed' => true,
            'CIDtoGID' => base64_encode(gzcompress(str_repeat("\x00", 256 * 256 * 2))),
        ];
    }
}
