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
        $inlineFooter = null;
        if ($has($pfBand)) {
            $ftHtml = $this->renderBandsPlainHtml([$pfBand], $definition, null, null);
            if ($pfBand->printOnEveryPage) {
                $mpdf->SetHTMLFooter($ftHtml);
            } else {
                $inlineFooter = $ftHtml;
            }
        }

        // Build printable-area dimensions for page-break decisions
        $paperH = $page->getPaperHeightMm();
        if ($page->orientation === 'landscape') {
            $paperH = $page->getPaperWidthMm();
        }
        $usableHeight = $paperH - $page->marginTop - $page->marginBottom;

        $phHeight = $has($phBand) ? $phBand->height : 0;
        $bodyHtml = $this->buildBodies($definition, $data, $inlineHeader, $inlineFooter, $usableHeight, $phHeight);

        $mpdf->WriteHTML($bodyHtml);
        return $mpdf->Output('', 'S');
    }

    // ------------------------------------------------------------------ build

    private function buildBodies(
        ReportDefinition $definition,
        array $data,
        ?string $inlineHeader,
        ?string $inlineFooter,
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
        $html = '<html><head><style>' . $this->getStyles() . '</style></head><body>';

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
        $ensureFits = function(?Band $b) use (&$html, &$pageY, $usableHeight, &$chOnPage, &$groupValues, $reprintable, $renderPageTop): float {
            if (!$b || !$b->visible || empty($b->elements)) return 0;
            $h = $b->height;
            if ($h <= 0) return 0;
            if ($pageY + $h > $usableHeight && $h <= $usableHeight) {
                $html .= "<pagebreak />\n";
                $pageY = 0;
                $chOnPage = false;
                $renderPageTop($reprintable($groupValues), null);
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
                            $pageY += $ensureFits($ft);
                            if ($ft && $has($ft)) {
                                $html .= $this->renderSingleBandHtml($ft, $definition, $groups[$inner], $groupAggs[$inner]);
                            }
                            $groupAggs[$inner]->reset();
                            if ($groups[$inner]->resetRowNo) $groupRowCounters[$inner] = 0;
                        }

                        for ($outer = $g; $outer < count($groups); $outer++) {
                            $groupValues[$outer] = $row[$groups[$outer]->fieldName] ?? null;
                            $hdr = $this->findGroupHeader($definition, $groups[$outer]);
                            $pageY += $ensureFits($hdr);
                            if ($hdr && $has($hdr)) {
                                $html .= $this->renderSingleBandHtml($hdr, $definition, $groups[$outer], $row);
                            }
                        }

                        if (!$chOnPage && $has($chBand)) {
                            $pageY += $ensureFits($chBand);
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
                        $pageY += $ensureFits($hdr);
                        if ($hdr && $has($hdr)) {
                            $html .= $this->renderSingleBandHtml($hdr, $definition, $groups[$g], $row);
                        }
                    }
                    if (!$chOnPage && $has($chBand)) {
                        $pageY += $ensureFits($chBand);
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
                $pageY += $ensureFits($dtBand);
                if ($has($dtBand)) {
                    $html .= $this->renderSingleBandHtml($dtBand, $definition, null, $row);
                }
            }

            // ------ close remaining groups ------
            for ($g = count($groups) - 1; $g >= 0; $g--) {
                $ft = $this->findGroupFooter($definition, $groups[$g]);
                $pageY += $ensureFits($ft);
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

        if ($inlineFooter) {
            $html .= $inlineFooter;
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
            'style="height:%dpt; background:%s; %s"',
            $band->height,
            $band->backgroundColor ?: 'transparent',
            $band->border ? $band->border->toHtmlStyle() : ''
        );
        $html = sprintf('<div class="band band-%s" %s>', $band->type, $style);
        foreach ($band->elements as $element) {
            $html .= $this->renderElementHtml($element, $def, $group, $data);
        }
        $html .= '</div>';
        return $html;
    }

    private function renderElementHtml(BandElement $el, ReportDefinition $def, $group, $data): string
    {
        $value = $this->getElementValue($el, $def, $group, $data);
        $borderStyle = $el->border ? $el->border->toHtmlStyle() : '';
        $style = sprintf(
            'position:relative; top:%dpt; left:%dpt; width:%dpt; height:%dpt; font-family:%s; font-size:%dpt; font-weight:%s; font-style:%s; color:%s; text-align:%s; vertical-align:%s; background:%s; %s',
            $el->top, $el->left, $el->width, $el->height,
            $el->fontFamily ?: 'Arial',
            $el->fontSize ?: 10,
            $el->bold ? 'bold' : 'normal',
            $el->italic ? 'italic' : 'normal',
            $el->color ?: '#000',
            $el->textAlign ?: 'left',
            $el->verticalAlign ?? 'top',
            $el->backgroundColor ?: 'transparent',
            $borderStyle
        );
        return sprintf('<div class="element" style="%s">%s</div>', $style, $value);
    }

    private function getElementValue(BandElement $el, ReportDefinition $def, $group, $data): string
    {
        return match ($el->type) {
            'label' => htmlspecialchars($el->text ?? ''),
            'field' => $data && isset($data[$el->fieldName])
                ? htmlspecialchars($this->formatValue($data[$el->fieldName], $el->format))
                : '',
            'aggregate' => $this->renderAggValue($el, $data),
            'image' => $el->imageUrl ? '<img src="' . $el->imageUrl . '" style="max-width:100%">' : '',
            'line' => '<hr style="border:none;border-top:1px solid #000">',
            'rect' => '',
            'pageno' => '{PAGENO}',
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
            .band { padding: 2px 4px; overflow: hidden; }
            .element { display: inline-block; overflow: hidden; }
            .band-page_header { background: #fee2e2; }
            .band-page_footer { background: #fee2e2; }
            .band-report_header { background: #e9d5ff; }
            .band-report_footer { background: #e9d5ff; }
            .band-group_header { background: #fef3c7; }
            .band-group_footer { background: #fef3c7; }
            .band-column_header { background: #fef9c3; }
            .band-detail { background: #f0fdf4; }
        ';
    }
}
