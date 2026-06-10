<?php

namespace ReportingEngine\Renderer;

use ReportingEngine\Report\ReportDefinition;
use ReportingEngine\Report\Band;
use ReportingEngine\Report\BandElement;
use ReportingEngine\Report\GroupDefinition;
use ReportingEngine\Report\AggregateAccumulator;

class HtmlRenderer implements RendererInterface
{
    public function render(ReportDefinition $definition, array $data, array $params = []): string
    {
        $page = $definition->pageSettings;
        $groups = $definition->groups;
        usort($groups, fn(GroupDefinition $a, GroupDefinition $b) => $a->level <=> $b->level);

        $paperW = $page->getPaperWidthMm();
        $paperH = $page->getPaperHeightMm();
        if ($page->orientation === 'landscape') {
            [$paperW, $paperH] = [$paperH, $paperW];
        }
        $usableWidth  = $paperW - $page->marginLeft - $page->marginRight;
        $usableHeight = $paperH - $page->marginTop  - $page->marginBottom - 25;

        $html  = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
        $html .= '<title>' . htmlspecialchars($definition->name ?: 'Report') . '</title>';
        $html .= '<style>' . $this->getBaseStyles($usableWidth, $paperW) . '</style></head><body>';

        $has = function(?Band $b): bool {
            return $b && $b->visible && !empty($b->elements);
        };

        $phBand = $definition->bands->get('page_header');
        $pfBand = $definition->bands->get('page_footer');
        $rhBand = $definition->bands->get('report_header');
        $rfBand = $definition->bands->get('report_footer');
        $chBand = $definition->bands->get('column_header');
        $dtBand = $definition->bands->get('detail');

        // ---------- page-state helpers ----------
        $pageNum       = 1;
        $pages         = [];
        $pageHtml      = '';
        $pageY         = 0.0;
        $chOnPage      = false;

        $openPage = function() use (&$pageHtml, &$pageY, &$chOnPage, $usableWidth, $usableHeight) {
            $pageHtml = sprintf(
                '<div class="report-page" style="width:%.1fmm; min-height:%.1fmm; position:relative;">',
                $usableWidth, $usableHeight
            );
            $pageY    = 0;
            $chOnPage = false;
        };

        $closePage = function() use (&$pageHtml, &$pages, &$pageNum, $has, $pfBand, $definition) {
            if ($has($pfBand)) {
                $pageHtml .= $this->renderBandElement($pfBand, $definition, null, null, $pageNum);
            }
            $pageHtml .= '</div>';
            $pages[]   = $pageHtml;
            $pageNum++;
        };

        // Render everything that goes at the top of a (non-first) page.
        // $reprintGroups – list of group-indexes whose header should be reprinted.
        // $lastRowData  – row data for field values inside reprinted headers.
        $renderPageTop = function(array $reprintGroups, ?array $lastRowData, bool $isFirst)
            use (&$pageHtml, &$pageY, &$chOnPage, $has, $phBand, $chBand, $groups, $definition, $pageNum)
        {
            // Page header
            if ($has($phBand) && ($isFirst || $phBand->printOnEveryPage)) {
                $pageHtml .= $this->renderBandElement($phBand, $definition, null, null, $pageNum);
                $pageY += $phBand->height;
            }
            // Reprint group headers that have reprintHeaderOnNewPage enabled
            foreach ($reprintGroups as $gi) {
                if (!$groups[$gi]->reprintHeaderOnNewPage) continue;
                $hdr = $this->findGroupHeader($definition, $groups[$gi]);
                if ($hdr && $has($hdr)) {
                    $pageHtml .= $this->renderBandElement($hdr, $definition, $groups[$gi], $lastRowData, $pageNum);
                    $pageY += $hdr->height;
                }
            }
            // Column header
            if ($has($chBand)) {
                $pageHtml .= $this->renderBandElement($chBand, $definition, null, null, $pageNum);
                $pageY += $chBand->height;
                $chOnPage = true;
            }
        };

        // Reserve space for a band – break page if needed.
        // $rowData – current data row, passed for reprinted group-header field values.
        $contentLimit = $usableHeight - ($has($pfBand) ? $pfBand->height : 0);
        $fit = function(?Band $b, ?array $rowData = null) use (&$pageHtml, &$pageY, $contentLimit, &$closePage, &$openPage, &$renderPageTop, &$groupValues): float {
            if (!$b || !$b->visible || empty($b->elements)) return 0;
            $h = $b->height;
            if ($h <= 0) return 0;
            if ($pageY + $h > $contentLimit && $h <= $contentLimit) {
                $reprint = [];
                if (isset($groupValues)) {
                    foreach ($groupValues as $gi => $v) {
                        if ($v !== null) $reprint[] = $gi;
                    }
                }
                $closePage();
                $openPage();
                $renderPageTop($reprint, $rowData, false);
            }
            return $h;
        };

        // ---------- build ----------
        $openPage();

        // -- Page 1 header (report-level, not via renderPageTop) --
        if ($has($phBand)) {
            $pageHtml .= $this->renderBandElement($phBand, $definition, null, null, $pageNum);
            $pageY += $phBand->height;
        }

        // -- Report header --
        $pageY += $fit($rhBand);
        if ($has($rhBand)) {
            $pageHtml .= $this->renderBandElement($rhBand, $definition, null, null, $pageNum);
        }

        if (empty($data)) {
            $noDataH = 20;
            if ($pageY + $noDataH > $contentLimit && $noDataH <= $contentLimit) {
                $closePage();
                $openPage();
                $renderPageTop([], null, false);
            }
            $pageHtml .= '<div class="band band-detail" style="height:20px;padding:8px;">No data returned.</div>';
            $pageY += $noDataH;
        } else {
            $groupValues     = array_fill(0, count($groups), null);
            $groupRowCounters = array_fill(0, count($groups), 0);
            $groupAggs = [];
            foreach ($groups as $g => $_) {
                $groupAggs[$g] = new AggregateAccumulator();
            }
            $reportAggs = new AggregateAccumulator();

            // Pre-load first group values so we can detect reprint groups
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

                        // pageBreakBefore on the *new* group?
                        if ($groups[$g]->pageBreakBefore) {
                            $closePage();
                            $openPage();
                            // reprint groups above the changing one (they stay active)
                            $stale = [];
                            for ($r = 0; $r < $g; $r++) {
                                if ($groupValues[$r] !== null) $stale[] = $r;
                            }
                            $renderPageTop($stale, $row, false);
                        }

                        // Close inner groups (reverse order)
                        for ($inner = count($groups) - 1; $inner >= $g; $inner--) {
                            $ft = $this->findGroupFooter($definition, $groups[$inner]);
                            $pageY += $fit($ft);
                            if ($ft && $has($ft)) {
                                $pageHtml .= $this->renderBandElement($ft, $definition, $groups[$inner], $groupAggs[$inner], $pageNum);
                            }
                            $groupAggs[$inner]->reset();
                            if ($groups[$inner]->resetRowNo) $groupRowCounters[$inner] = 0;
                        }

                        // Open outer groups (forward order)
                        for ($outer = $g; $outer < count($groups); $outer++) {
                            $groupValues[$outer] = $row[$groups[$outer]->fieldName] ?? null;
                            $hdr = $this->findGroupHeader($definition, $groups[$outer]);
                            $pageY += $fit($hdr, $row);
                            if ($hdr && $has($hdr)) {
                                $pageHtml .= $this->renderBandElement($hdr, $definition, $groups[$outer], $row, $pageNum);
                            }
                        }

                        // -- Column header after new group headers, once per page --
                        if (!$chOnPage && $has($chBand)) {
                            $pageY += $fit($chBand);
                            if ($has($chBand)) {
                                $pageHtml .= $this->renderBandElement($chBand, $definition, null, null, $pageNum);
                                $chOnPage = true;
                            }
                        }

                        $groupChanged = true;
                        break;
                    }
                }

                // ------ first row: open groups ------
                if ($rowIndex === 0) {
                    for ($g = 0; $g < count($groups); $g++) {
                        $groupValues[$g] = $row[$groups[$g]->fieldName] ?? null;
                        $hdr = $this->findGroupHeader($definition, $groups[$g]);
                        $pageY += $fit($hdr, $row);
                        if ($hdr && $has($hdr)) {
                            $pageHtml .= $this->renderBandElement($hdr, $definition, $groups[$g], $row, $pageNum);
                        }
                    }
                    // Column header after group headers on first data page
                    if (!$chOnPage && $has($chBand)) {
                        $pageY += $fit($chBand);
                        if ($has($chBand)) {
                            $pageHtml .= $this->renderBandElement($chBand, $definition, null, null, $pageNum);
                            $chOnPage = true;
                        }
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

                // ------ detail band ------
                $pageY += $fit($dtBand, $row);
                if ($has($dtBand)) {
                    $pageHtml .= $this->renderBandElement($dtBand, $definition, null, $row, $pageNum);
                }
            }

            // ------ close remaining groups ------
            for ($g = count($groups) - 1; $g >= 0; $g--) {
                $ft = $this->findGroupFooter($definition, $groups[$g]);
                $pageY += $fit($ft);
                if ($ft && $has($ft)) {
                    $pageHtml .= $this->renderBandElement($ft, $definition, $groups[$g], $groupAggs[$g], $pageNum);
                }
                $groupAggs[$g]->reset();
            }

            // ------ report footer ------
            $pageY += $fit($rfBand);
            if ($has($rfBand)) {
                $pageHtml .= $this->renderBandElement($rfBand, $definition, null, $reportAggs, $pageNum);
            }
        }

        $closePage();

        // Wrap each page in a paper-page for word-processor appearance on screen
        $wrapped = [];
        foreach ($pages as $pageHtml) {
            $minH = '';
            if (preg_match('/min-height:([\d.]+)mm/', $pageHtml, $m)) {
                $minH = 'min-height:' . ((float)$m[1] + 30) . 'mm;';
            }
            $wrapped[] = '<div class="paper-page" style="width:' . $paperW . 'mm;' . $minH . '">' . "\n" . $pageHtml . "\n" . '</div>';
        }
        $html .= implode("\n", $wrapped);
        $html .= '</body></html>';
        return $html;
    }

    // ------------------------------------------------------------------ helpers

    private function renderBandElement(Band $band, ReportDefinition $def, $group, $data, int $pageNum = 1): string
    {
        $borderStyle = $band->border ? $band->border->toHtmlStyle() : '';
        $style = sprintf(
            'position:relative; height:%.1fmm; background:%s; %s',
            $band->height,
            $band->backgroundColor ?: 'transparent',
            $borderStyle
        );
        $html = sprintf('<div class="band band-%s" style="%s">', $band->type, $style);
        foreach ($band->elements as $element) {
            $html .= $this->renderSingleElement($element, $def, $group, $data, $pageNum);
        }
        $html .= '</div>';
        return $html;
    }

    private function renderSingleElement(BandElement $el, ReportDefinition $def, $group, $data, int $pageNum = 1): string
    {
        $value = $this->getElementValue($el, $def, $group, $data, $pageNum);
        $borderStyle = $el->border ? $el->border->toHtmlStyle() : '';
        $va = $el->verticalAlign ?? 'top';
        $ta = $el->textAlign ?: 'left';

        if ($va === 'top') {
            $style = sprintf(
                'position:absolute; top:%.1fmm; left:%.1fmm; width:%.1fmm; height:%.1fmm; font-family:%s; font-size:%dpt; font-weight:%s; font-style:%s; text-decoration:%s; color:%s; text-align:%s; background:%s; %s',
                $el->top, $el->left, $el->width, $el->height,
                $el->fontFamily ?: 'Arial',
                $el->fontSize ?: 10,
                $el->bold ? 'bold' : 'normal',
                $el->italic ? 'italic' : 'normal',
                $el->underline ? 'underline' : 'none',
                $el->color ?: '#000000',
                $ta,
                $el->backgroundColor ?: 'transparent',
                $borderStyle
            );
        } else {
            $alignItems = $va === 'middle' ? 'center' : 'flex-end';
            $justify = match ($ta) {
                'center' => 'center',
                'right' => 'flex-end',
                default => 'flex-start',
            };
            $style = sprintf(
                'position:absolute; top:%.1fmm; left:%.1fmm; width:%.1fmm; height:%.1fmm; font-family:%s; font-size:%dpt; font-weight:%s; font-style:%s; text-decoration:%s; color:%s; display:flex; align-items:%s; justify-content:%s; background:%s; %s',
                $el->top, $el->left, $el->width, $el->height,
                $el->fontFamily ?: 'Arial',
                $el->fontSize ?: 10,
                $el->bold ? 'bold' : 'normal',
                $el->italic ? 'italic' : 'normal',
                $el->underline ? 'underline' : 'none',
                $el->color ?: '#000000',
                $alignItems,
                $justify,
                $el->backgroundColor ?: 'transparent',
                $borderStyle
            );
        }

        return sprintf('<div class="element" style="%s">%s</div>', $style, $value);
    }

    private function getElementValue(BandElement $el, ReportDefinition $def, $group, $data, int $pageNum = 1): string
    {
        return match ($el->type) {
            'label' => htmlspecialchars($el->text ?? ''),
            'field' => $data && isset($data[$el->fieldName])
                ? htmlspecialchars($this->formatValue($data[$el->fieldName], $el->format))
                : '',
            'aggregate' => $this->renderAggregate($el, $data),
            'image' => $el->imageUrl ? '<img src="' . htmlspecialchars($el->imageUrl) . '" style="max-width:100%;max-height:100%">' : '',
            'line' => '<hr style="border:none;border-top:1px solid #000;margin:0;width:100%">',
            'rect' => '',
            'pageno' => (string)$pageNum,
            'rowno' => $data && is_array($data) && isset($data['_rowno']) ? (string)$data['_rowno'] : '1',
            'datetime' => date($el->format ?? 'Y-m-d'),
            default => htmlspecialchars($el->text ?? ''),
        };
    }

    private function renderAggregate(BandElement $el, $data): string
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

        // If the format is just a number, use it as decimal count
        if (preg_match('/^\d+$/', $format)) {
            return number_format($v, (int)$format, '.', ',');
        }

        // The LAST separator (.,) followed immediately by a mandatory digit (0)
        // is the decimal separator. If followed by # it's a thousands grouping sep.
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
            if ($band->type === 'group_header' && $band->groupField === $group->fieldName) {
                return $band;
            }
        }
        return null;
    }

    private function findGroupFooter(ReportDefinition $def, GroupDefinition $group): ?Band
    {
        foreach ($def->bands->all() as $band) {
            if ($band->type === 'group_footer' && $band->groupField === $group->fieldName) {
                return $band;
            }
        }
        return null;
    }

    private function getBaseStyles(float $usableWidth, float $paperW): string
    {
        return '
            body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #e2e8f0; }
            .report-page { width: ' . $usableWidth . 'mm; margin: 0 auto; background: white; box-shadow: 0 4px 16px rgba(0,0,0,0.12); position: relative; }
            .band { padding: 2px 4px; overflow: hidden; }
            .element { overflow: hidden; white-space: nowrap; }
            .paper-page { background: #fff; box-shadow: 0 2px 20px rgba(0,0,0,0.18); margin: 0 auto 32px auto; padding: 15mm 0; position: relative; page-break-after: always; }
            .paper-page:last-child { margin-bottom: 0; page-break-after: auto; }
            .paper-page .report-page { margin: 0 auto !important; box-shadow: none !important; background: transparent !important; }
            @media print {
                body { background: white; padding: 0; }
                .report-page { box-shadow: none; margin: 0; }
                .paper-page { box-shadow: none !important; margin: 0 auto !important; padding: 0 !important; page-break-after: always; }
                .paper-page:last-child { page-break-after: auto; }
                .paper-page .report-page { box-shadow: none !important; margin: 0 !important; }
            }
        ';
    }
}
