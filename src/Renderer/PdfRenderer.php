<?php

namespace ReportingEngine\Renderer;

use Mpdf\Mpdf;
use ReportingEngine\Report\ReportDefinition;
use ReportingEngine\Report\Band;
use ReportingEngine\Report\BandElement;
use ReportingEngine\Report\GroupDefinition;
use ReportingEngine\Report\AggregateAccumulator;

class PdfRenderer implements RendererInterface
{
    public function render(ReportDefinition $definition, array $data, array $params = []): string
    {
        $page = $definition->pageSettings;

        $mpdf = new Mpdf([
            'mode'          => 'utf-8',
            'format'        => $page->paperSize,
            'orientation'   => $page->orientation,
            'margin_top'    => $page->marginTop,
            'margin_bottom' => $page->marginBottom,
            'margin_left'   => $page->marginLeft,
            'margin_right'  => $page->marginRight,
            'tempDir'       => sys_get_temp_dir() . '/mpdf',
        ]);

        $has = function(?Band $b): bool {
            return $b && $b->visible && !empty($b->elements);
        };

        // Page header/footer — delegated to mPDF
        $phBand = $definition->bands->get('page_header');
        $inlineHeader = null;
        if ($has($phBand)) {
            $hdrHtml = $this->renderBandsPlainHtml([$phBand], $definition, null, null);
            if ($phBand->printOnEveryPage) {
                $mpdf->SetHTMLHeader($hdrHtml);
            } else {
                $inlineHeader = $hdrHtml;
            }
        }

        $pfBand = $definition->bands->get('page_footer');
        if ($has($pfBand)) {
            $ftHtml = $this->renderBandsPlainHtml([$pfBand], $definition, null, null);
            if ($pfBand->printOnEveryPage) {
                $mpdf->SetHTMLFooter($ftHtml);
            }
        }

        // Build printable-area dimensions for page-break decisions
        $paperH = $page->getPaperHeightMm();
        if ($page->orientation === 'landscape') {
            $paperH = $page->getPaperWidthMm();
        }
        $usableHeight = $paperH - $page->marginTop - $page->marginBottom;

        $phHeight = $has($phBand) ? $phBand->height : 0;
        $bodyHtml = $this->buildBodies($definition, $data, $inlineHeader, $usableHeight, $phHeight);

        $mpdf->WriteHTML($bodyHtml);
        return $mpdf->Output('', 'S');
    }

    // ------------------------------------------------------------------ build

    private function buildBodies(
        ReportDefinition $definition,
        array $data,
        ?string $inlineHeader,
        float $usableHeight,
        float $phHeight = 0
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
                    $html .= $this->renderSingleBandHtml($hdr, $definition, $groups[$gi], $lastRowData);
                    $pageY += $hdr->height;
                }
            }
            if ($has($chBand)) {
                $html .= $this->renderSingleBandHtml($chBand, $definition, null, null);
                $pageY += $chBand->height;
                $chOnPage = true;
            }
        };

        // Insert a page break if the given band height does not fit.
        // On break, reprint active group headers + column header on the new page.
        $ensureFits = function(?Band $b, ?array $rowData = null) use (&$html, &$pageY, $usableHeight, &$chOnPage, &$groupValues, $reprintable, $renderPageTop): float {
            if (!$b || !$b->visible || empty($b->elements)) return 0;
            $h = $b->height;
            if ($h <= 0) return 0;
            if ($pageY + $h > $usableHeight && $h <= $usableHeight) {
                $html .= "<pagebreak />\n";
                $pageY = 0;
                $chOnPage = false;
                $renderPageTop($reprintable($groupValues), $rowData);
            }
            return $h;
        };

        // ------ inline page header (page 1 only, not reprinting) ------
        if ($inlineHeader) {
            $html .= $inlineHeader;
            $pageY += $phHeight;
        }

        // ------ report header ------
        if ($has($rhBand)) {
            $pageY += $ensureFits($rhBand);
            $html .= $this->renderSingleBandHtml($rhBand, $definition, null, null);
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
                            $pageY += $ensureFits($ft, $row);
                            if ($ft && $has($ft)) {
                                $html .= $this->renderSingleBandHtml($ft, $definition, $groups[$inner], $groupAggs[$inner]);
                            }
                            $groupAggs[$inner]->reset();
                            if ($groups[$inner]->resetRowNo) $groupRowCounters[$inner] = 0;
                        }

                        for ($outer = $g; $outer < count($groups); $outer++) {
                            $groupValues[$outer] = $row[$groups[$outer]->fieldName] ?? null;
                            $hdr = $this->findGroupHeader($definition, $groups[$outer]);
                            $pageY += $ensureFits($hdr, $row);
                            if ($hdr && $has($hdr)) {
                                $html .= $this->renderSingleBandHtml($hdr, $definition, $groups[$outer], $row);
                            }
                        }

                        if (!$chOnPage && $has($chBand)) {
                            $pageY += $ensureFits($chBand, $row);
                            $html .= $this->renderSingleBandHtml($chBand, $definition, null, null);
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
                        $pageY += $ensureFits($hdr, $row);
                        if ($hdr && $has($hdr)) {
                            $html .= $this->renderSingleBandHtml($hdr, $definition, $groups[$g], $row);
                        }
                    }
                    if (!$chOnPage && $has($chBand)) {
                        $pageY += $ensureFits($chBand, $row);
                        $html .= $this->renderSingleBandHtml($chBand, $definition, null, null);
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
                $pageY += $ensureFits($dtBand, $row);
                if ($has($dtBand)) {
                    $html .= $this->renderSingleBandHtml($dtBand, $definition, null, $row);
                }
            }

            // ------ close remaining groups ------
            for ($g = count($groups) - 1; $g >= 0; $g--) {
                $ft = $this->findGroupFooter($definition, $groups[$g]);
                $pageY += $ensureFits($ft, $row);
                if ($ft && $has($ft)) {
                    $html .= $this->renderSingleBandHtml($ft, $definition, $groups[$g], $groupAggs[$g]);
                }
                $groupAggs[$g]->reset();
            }

            // ------ report footer ------
            $pageY += $ensureFits($rfBand);
            if ($has($rfBand)) {
                $html .= $this->renderSingleBandHtml($rfBand, $definition, null, $reportAggs);
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

    private function renderSingleBandHtml(Band $band, ReportDefinition $def, $group, $data): string
    {
        $style = sprintf(
            'style="position:relative; height:%.1fmm; background:%s; %s"',
            $band->height,
            $band->backgroundColor ?: 'transparent',
            $band->border ? $band->border->toHtmlStyle() : ''
        );
        $html = sprintf('<div class="band band-%s" %s>', $band->type, $style);

        // Group elements by top position into visual rows
        $rows = [];
        foreach ($band->elements as $element) {
            if ($element->conditionalExpression && !ExpressionEvaluator::evaluateBool($element->conditionalExpression, $data instanceof AggregateAccumulator ? $data->getLastValues() : ($data ?: []))) {
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

    private function renderElementHtml(BandElement $el, ReportDefinition $def, $group, $data, float $marginLeft = 0.0): string
    {
        $value = $this->getElementValue($el, $def, $group, $data);
        $borderStyle = $el->border ? $el->border->toHtmlStyle() : '';

        $condStyle = $this->resolveConditionalStyle($el, $data);

        $bold = $condStyle['bold'] ?? $el->bold;
        $italic = $condStyle['italic'] ?? $el->italic;
        $color = $condStyle['color'] ?? $el->color ?: '#000';
        $backgroundColor = $condStyle['backgroundColor'] ?? $el->backgroundColor ?: 'transparent';
        $fontFamily = $condStyle['fontFamily'] ?? $el->fontFamily ?: 'Arial';
        $fontSize = $condStyle['fontSize'] ?? $el->fontSize ?: 10;
        $textAlign = $condStyle['textAlign'] ?? $el->textAlign ?: 'left';
        $verticalAlign = $condStyle['verticalAlign'] ?? $el->verticalAlign ?? 'top';

        $style = sprintf(
            'float:left; margin-left:%.1fmm; width:%.1fmm; height:%.1fmm; font-family:%s; font-size:%dpt; font-weight:%s; font-style:%s; color:%s; text-align:%s; vertical-align:%s; overflow:hidden; background:%s; %s',
            $marginLeft, $el->width, $el->height,
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

        if (!in_array($el->type, ['image', 'line', 'rect'])) {
            $value = sprintf(
                '<span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:block; width:100%%; min-width:0; text-align:%s">%s</span>',
                $textAlign,
                $value
            );
        }

        return sprintf('<div class="element" style="%s">%s</div>', $style, $value);
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
            'line' => '<hr style="border:none;border-top:1px solid #000">',
            'rect' => '',
            'pageno' => '{PAGENO}',
            'pagecount' => '{nb}',
            'rowno' => $data && is_array($data) && isset($data['_rowno']) ? (string)$data['_rowno'] : '1',
            'datetime' => date($el->format ?? 'Y-m-d'),
            default => htmlspecialchars($el->text ?? ''),
        };
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
        if ($format === null || $format === '' || !is_numeric($value)) {
            return (string)$value;
        }
        $v = (float)$value;

        if (str_contains($format, '%')) {
            return sprintf($format, $v);
        }

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
