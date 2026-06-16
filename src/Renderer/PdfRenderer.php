<?php

namespace ReportingEngine\Renderer;

use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use ReportingEngine\Report\ReportDefinition;
use ReportingEngine\Report\Band;
use ReportingEngine\Report\BandElement;
use ReportingEngine\Report\GroupDefinition;
use ReportingEngine\Report\AggregateAccumulator;

class PdfRenderer implements RendererInterface
{
    private array $fontMetrics = [];
    private array $fonts = [];
    private array $fontFamilyMap = [];

    public function render(ReportDefinition $definition, array $data, array $params = []): string
    {
        $this->fontMetrics = isset($params['_fontMetrics']) && is_array($params['_fontMetrics']) ? $params['_fontMetrics'] : [];
        $this->fonts = isset($params['_fonts']) && is_array($params['_fonts']) ? $params['_fonts'] : [];
        $this->fontFamilyMap = [];
        $page = $definition->pageSettings;

        $fontDir = null;
        $customFontdata = [];
        if (!empty($this->fonts)) {
            $config = \ReportingEngine\Core\Database::getConfig();
            $fontDir = ($config['data_path'] ?? __DIR__ . '/../../data') . '/fonts';
            foreach ($this->fonts as $font) {
                $family = isset($font['family']) ? strtolower(trim($font['family'])) : '';
                $fname  = $font['filename'] ?? '';
                if ($family === '' || $fname === '') {
                    continue;
                }
                $style  = strtolower($font['style'] ?? 'regular');
                if ($style === 'regular' || $style === 'normal') $style = 'R';
                elseif ($style === 'bold') $style = 'B';
                elseif ($style === 'italic') $style = 'I';
                elseif ($style === 'bold italic') $style = 'BI';
                else $style = 'R';
                $sanitized = preg_replace('/[^a-z0-9\-]/', '', $family);
                $this->fontFamilyMap[$family] = $sanitized;
                $customFontdata[$sanitized][$style] = $fname;
            }
        }

        // Merge custom fontdata with mPDF defaults so built-in fonts remain available
        $fontVars = new FontVariables();
        $defaults = $fontVars->getDefaults();
        $fontdata = array_merge($defaults['fontdata'], $customFontdata);

        $has = function(?Band $b): bool {
            return $b && $b->visible && !empty($b->elements);
        };

        // Compute header/footer margins from actual band heights so the
        // footer area (margin_bottom - margin_footer) can fit the band.
        $phBand = $definition->bands->get('page_header');
        $pfBand = $definition->bands->get('page_footer');
        $hdrBandH = $has($phBand) ? ($phBand->height ?? 10) : 0;
        $ftBandH  = $has($pfBand) ? ($pfBand->height ?? 10) : 0;

        $hdrTop = 1;  // small gap from page top prevents printer clipping for the header
        $ftBot  = max(3, $page->marginBottom * 0.3);  // footer bottom: at least 3mm or 30% of margin from paper edge

        $mpdfConfig = [
            'mode'          => 'utf-8',
            'format'        => $page->paperSize,
            'orientation'   => $page->orientation,
            'margin_top'    => $hdrBandH > 0
                ? max($page->marginTop, $hdrTop + $hdrBandH)
                : $page->marginTop,
            'margin_bottom' => $ftBandH > 0
                ? max($page->marginBottom, $ftBot + $ftBandH)
                : $page->marginBottom,
            'margin_left'   => $page->marginLeft,
            'margin_right'  => $page->marginRight,
            'margin_header' => $hdrBandH > 0 ? $hdrTop : $page->marginTop,
            'margin_footer' => $ftBandH > 0 ? $ftBot : $page->marginBottom,
            'tempDir'       => sys_get_temp_dir() . '/mpdf',
            'fontdata'      => $fontdata,
        ];
        if ($fontDir) {
            $mpdfConfig['fontDir'] = [$fontDir, __DIR__ . '/../../vendor/mpdf/mpdf/ttfonts'];
        }

        $mpdf = new Mpdf($mpdfConfig);
        // Re-assert auto page break margin to match computed margin_bottom (mPDF constructor
        // defaults to a smaller value that can overflow into the footer area).
        $mpdf->SetAutoPageBreak(true, $mpdfConfig['margin_bottom']);

        // Page header/footer — always render on every page if they have elements
        if ($has($phBand)) {
            $hdrHtml = $this->renderBandsPlainHtml([$phBand], $definition, null, null);
            $mpdf->SetHTMLHeader($hdrHtml);
        }

        if ($has($pfBand)) {
            $ftHtml = $this->renderBandsPlainHtml([$pfBand], $definition, null, null);
            $mpdf->SetHTMLFooter($ftHtml);
        }

        // Build printable-area dimensions for page-break decisions
        $paperH = $page->getPaperHeightMm();
        if ($page->orientation === 'landscape') {
            $paperH = $page->getPaperWidthMm();
        }
        $usableHeight = $paperH - $mpdfConfig['margin_top'] - $mpdfConfig['margin_bottom'];

        $bodyHtml = $this->buildBodies($definition, $data, $usableHeight);

        // Chunk at band boundaries to avoid mPDF pcre.backtrack_limit exhaustion
        $parts = explode('<div class="band ', $bodyHtml);
        $mpdf->WriteHTML(array_shift($parts));
        foreach ($parts as $part) {
            $mpdf->WriteHTML('<div class="band ' . $part);
        }
        return $mpdf->Output('', 'S');
    }

    // ------------------------------------------------------------------ build

    private function buildBodies(
        ReportDefinition $definition,
        array $data,
        float $usableHeight
    ): string {
        $groups = $definition->groups;
        usort($groups, fn(GroupDefinition $a, GroupDefinition $b) => $a->level <=> $b->level);

        $has = function(?Band $b): bool {
            return $b && $b->visible && !empty($b->elements);
        };

        $rhBand = $definition->bands->get('report_header');
        $rfBand = $definition->bands->get('report_footer');
        $chBand = $definition->bands->get('column_header');
        $dtBand = $definition->bands->get('detail');

        // We collect output in an array of page-strings, joined later.
        // mPDF page breaks are triggered via <pagebreak /> inside the stream.
        // Unlike HtmlRenderer we cannot use a true multi-page array because
        // mPDF header/footer require a single WriteHTML call. Instead we
        // inject <pagebreak /> into one long HTML string whenever content
        // would exceed the page.
        $page = $definition->pageSettings;
        $paperW = $page->getPaperWidthMm();
        if ($page->orientation === 'landscape') {
            $paperW = $page->getPaperHeightMm();
        }
        $usableWidth = $paperW - $page->marginLeft - $page->marginRight;
        $html = sprintf(
            '<html><head><style>%s</style></head><body style="width:%.1fmm">',
            $this->getStyles(),
            $usableWidth
        );

        // mPDF margin-top = Y=0 start; we track Y from content top.
        $pageY    = 0.0;
        $chOnPage = false;
        $pageNum  = 1; // only used for {PAGENO} placeholder (handled by mPDF)

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
            use (&$html, &$pageY, &$chOnPage, $has, $chBand, $groups, $definition)
        {
            foreach ($reprintGroups as $gi) {
                $hdr = $this->findGroupHeader($definition, $groups[$gi]);
                if ($hdr && $has($hdr) && $groups[$gi]->reprintHeaderOnNewPage) {
                    $effH = $this->calculateEffectiveBandHeight($hdr, $definition, $groups[$gi], $lastRowData, 1);
                    $html .= $this->renderSingleBandHtml($hdr, $definition, $groups[$gi], $lastRowData, $effH);
                    $pageY += $effH + $hdr->border->getVerticalHeightMm();
                }
            }
            if ($has($chBand) && $chBand->printOnEveryPage) {
                $effH = $this->calculateEffectiveBandHeight($chBand, $definition, null, null, 1);
                $html .= $this->renderSingleBandHtml($chBand, $definition, null, null, $effH);
                $pageY += $effH + $chBand->border->getVerticalHeightMm();
                $chOnPage = true;
            }
        };

        // Track page Y position for manual page break decisions.
        // When content exceeds usable height, insert <pagebreak /> and re-print
        // group/column headers on the new page.
        $fit = function(?Band $b, ?array $rowData = null, ?float $effectiveHeight = null)
            use (&$html, &$pageY, &$chOnPage, $usableHeight, &$renderPageTop, &$groupValues): float
        {
            if (!$b || !$b->visible || empty($b->elements)) return 0;
            $h = $effectiveHeight ?? $b->height;
            if ($h <= 0) return 0;
            $borderH = $b->border ? $b->border->getVerticalHeightMm() : 0;
            $totalH = $h + $borderH;
            if ($pageY + $totalH > $usableHeight && $totalH <= $usableHeight) {
                $html .= "<pagebreak />\n";
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
        if ($has($rhBand)) {
            $effH = $this->calculateEffectiveBandHeight($rhBand, $definition, null, null, 1);
            $pageY += $fit($rhBand, null, $effH);
            $html .= $this->renderSingleBandHtml($rhBand, $definition, null, null, $effH);
        }

        if (empty($data)) {
            if ($has($chBand)) {
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
                            $html .= "<pagebreak />\n";
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
                            $effH = $ft && $has($ft) ? $this->calculateEffectiveBandHeight($ft, $definition, $groups[$inner], $groupAggs[$inner], 1) : 0;
                            $pageY += $fit($ft, $row, $effH);
                            if ($ft && $has($ft)) {
                                $html .= $this->renderSingleBandHtml($ft, $definition, $groups[$inner], $groupAggs[$inner], $effH);
                            }
                            $groupAggs[$inner]->reset();
                            if ($groups[$inner]->resetRowNo) $groupRowCounters[$inner] = 0;
                        }

                        for ($outer = $g; $outer < count($groups); $outer++) {
                            $groupValues[$outer] = $row[$groups[$outer]->fieldName] ?? null;
                            $hdr = $this->findGroupHeader($definition, $groups[$outer]);
                            $effH = $hdr && $has($hdr) ? $this->calculateEffectiveBandHeight($hdr, $definition, $groups[$outer], $row, 1) : 0;
                            $pageY += $fit($hdr, $row, $effH);
                            if ($hdr && $has($hdr)) {
                                $html .= $this->renderSingleBandHtml($hdr, $definition, $groups[$outer], $row, $effH);
                            }
                        }

                        if (!$chOnPage && $has($chBand)) {
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
                        $effH = $hdr && $has($hdr) ? $this->calculateEffectiveBandHeight($hdr, $definition, $groups[$g], $row, 1) : 0;
                        $pageY += $fit($hdr, $row, $effH);
                        if ($hdr && $has($hdr)) {
                            $html .= $this->renderSingleBandHtml($hdr, $definition, $groups[$g], $row, $effH);
                        }
                    }
                    if (!$chOnPage && $has($chBand)) {
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
                $effH = $has($dtBand) ? $this->calculateEffectiveBandHeight($dtBand, $definition, null, $row, 1) : 0;
                $pageY += $fit($dtBand, $row, $effH);
                if ($has($dtBand)) {
                    $html .= $this->renderSingleBandHtml($dtBand, $definition, null, $row, $effH);
                }
            }

            // ------ close remaining groups ------
            for ($g = count($groups) - 1; $g >= 0; $g--) {
                $ft = $this->findGroupFooter($definition, $groups[$g]);
                $effH = $ft && $has($ft) ? $this->calculateEffectiveBandHeight($ft, $definition, $groups[$g], $groupAggs[$g], 1) : 0;
                $pageY += $fit($ft, $row ?? null, $effH);
                if ($ft && $has($ft)) {
                    $html .= $this->renderSingleBandHtml($ft, $definition, $groups[$g], $groupAggs[$g], $effH);
                }
                $groupAggs[$g]->reset();
            }

            // ------ report footer ------
            $effH = $has($rfBand) ? $this->calculateEffectiveBandHeight($rfBand, $definition, null, $reportAggs, 1) : 0;
            $pageY += $fit($rfBand, null, $effH);
            if ($has($rfBand)) {
                $html .= $this->renderSingleBandHtml($rfBand, $definition, null, $reportAggs, $effH);
            }
        }

        $html .= '</body></html>';
        return $html;
    }

    // --------------------------------------------------------------- helpers

    private function renderBandsPlainHtml(array $bands, ReportDefinition $def, $group, $data): string
    {
        $out = '';
        $has = function(?Band $b): bool {
            return $b && $b->visible && !empty($b->elements);
        };
        foreach ($bands as $b) {
            if ($has($b)) {
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

            $html .= sprintf('<div style="overflow:hidden; height:%.1fmm">', $rowH);

            // Sort elements left-to-right
            usort($elements, fn(BandElement $a, BandElement $b) => $a->left <=> $b->left);

            $prevRight = 0.0;
            foreach ($elements as $el) {
                $marginLeft = (float)$el->left - $prevRight;
                $html .= $this->renderElementHtml($el, $def, $group, $data, $marginLeft);
                $prevRight = (float)$el->left + (float)$el->width;
            }

            $html .= '</div>';
            $prevBottom = (float)$top + $rowH;
        }

        $html .= '</div>';
        return $html;
    }

    private function mPdfFontFamily(?string $family): string
    {
        $lower = strtolower(trim($family ?? ''));
        return $this->fontFamilyMap[$lower] ?? $lower;
    }

    private function renderElementHtml(BandElement $el, ReportDefinition $def, $group, $data, float $marginLeft = 0.0): string
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
        $fontFamily = $this->mPdfFontFamily($origFontFamily);
        $fontSize = $condStyle['fontSize'] ?? $el->fontSize ?: 10;
        $textAlign = $condStyle['textAlign'] ?? $el->textAlign ?: 'left';
        $verticalAlign = $condStyle['verticalAlign'] ?? $el->verticalAlign ?? 'top';

        $isTextType = !in_array($el->type, ['image', 'line', 'rect', 'barcode']);
        $wordWrap = $el->wordWrap ?? false;
        $textOverflow = $wordWrap ? '' : 'text-overflow:ellipsis;';
        $whiteSpace = $wordWrap ? 'white-space:normal; overflow-wrap:break-word;' : 'white-space:nowrap;';

        // mPDF ignores white-space:nowrap on non-table elements, so we must
        // pre-truncate nowrap text in PHP to prevent wrapping + overflow pagination.
        // Skip pageno/pagecount — their values are mPDF placeholders ({PAGENO}/{nb})
        // that get replaced during PDF generation and must not be truncated.
        if (!$wordWrap && $isTextType && $value !== '' && !in_array($el->type, ['pageno', 'pagecount'])) {
            $plainText = strip_tags($value);
            $truncKey = $origFontFamily . '-' . $fontSize . '-' . ($bold ? '1' : '0') . '-' . ($italic ? '1' : '0');
            $avgCharWidth = isset($this->fontMetrics[$truncKey])
                ? (float)$this->fontMetrics[$truncKey]
                : 2.0 * ($fontSize / 10);
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

        if ($el->type === 'line') {
            $orient = $el->orientation ?? 'horizontal';
            $lineAlign = $el->lineAlign ?? ($orient === 'horizontal' ? 'middle' : 'center');
            if ($orient === 'horizontal') {
                // mPDF doesn't support flexbox, so use a line-height trick for vertical centering.
                // The line at the top, middle, or bottom is achieved by adjusting line-height.
                $lh = match ($lineAlign) {
                    'top'    => '0',
                    'bottom' => ($effectiveElH * 2) . 'mm',
                    default  => $effectiveElH . 'mm',
                };
                $style = sprintf(
                    'float:left; margin-left:%.1fmm; width:%.1fmm; height:%.1fmm; line-height:%s; font-size:0; overflow:hidden; background:%s; %s',
                    $marginLeft, $el->width, $effectiveElH,
                    $lh,
                    $backgroundColor,
                    $borderStyle
                );
            } else {
                $ta = match ($lineAlign) { 'left' => 'left', 'right' => 'right', default => 'center' };
                $style = sprintf(
                    'float:left; margin-left:%.1fmm; width:%.1fmm; height:%.1fmm; text-align:%s; font-size:0; line-height:0; overflow:hidden; background:%s; %s',
                    $marginLeft, $el->width, $effectiveElH,
                    $ta,
                    $backgroundColor,
                    $borderStyle
                );
            }
        } else {
            $style = sprintf(
                'float:left; margin-left:%.1fmm; width:%.1fmm; height:%.1fmm; font-family:%s; font-size:%dpt; font-weight:%s; font-style:%s; color:%s; text-align:%s; vertical-align:%s; overflow:hidden; background:%s; %s',
                $marginLeft, $el->width, $effectiveElH,
                $fontFamily,
                $fontSize,
                $bold ? 'bold' : 'normal',
                $italic ? 'italic' : 'normal',
                $color,
                $textAlign,
                $verticalAlign,
                $backgroundColor,
                $borderStyle
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
            $avgCharWidth = 2.0 * ($fontSize / 10);
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
            'image' => $el->imageUrl ? '<img src="' . $el->imageUrl . '" style="width:100%;height:100%;object-fit:' . $this->imageFit($el->imageDisplay) . '">' : '',
            'line' => ($el->orientation ?? 'horizontal') === 'vertical'
                ? '<div style="border-left:1px solid ' . ($el->color ?: '#000') . '; height:' . max(1, $el->height ?? 40) . 'mm;"></div>'
                : '<div style="border-top:1px solid ' . ($el->color ?: '#000') . '; width:100%;"></div>',
            'rect' => '',
            'pageno' => '{PAGENO}',
            'pagecount' => '{nb}',
            'rowno' => $data && is_array($data) && isset($data['_rowno']) ? (string)$data['_rowno'] : '1',
            'datetime' => date($el->format ?? 'Y-m-d'),
            'barcode' => self::renderBarcodeValue($el, $data),
            default => htmlspecialchars($el->text ?? ''),
        };
    }

    private static function renderBarcodeValue(BandElement $el, $data): string
    {
        $rowData = $data instanceof AggregateAccumulator ? $data->getLastValues() : ($data ?: []);
        $value = $el->barcodeExpression
            ? ExpressionEvaluator::evaluate($el->barcodeExpression, $rowData)
            : ($el->text ?? '');
        if (!$value) return '';
        $src = BarcodeRenderer::renderPng($value, $el->barcodeSymbology ?? 'code128', $el->barcodeShowText ?? true);
        return '<img src="' . $src . '" style="width:100%;height:100%;object-fit:contain" alt="barcode">';
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
            $result = @sprintf($format, $v);
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
                    $result = @date($format, $ts);
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
            body { font-family: Arial, sans-serif; font-size: 10pt; margin: 0; padding: 0; }
            .band { padding: 0; overflow: hidden; }
            .element { display: inline-block; overflow: hidden; }

        ';
    }
}
