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
        $usableWidth = $paperW - $page->marginLeft - $page->marginRight;
        $usableHeight = $paperH - $page->marginTop - $page->marginBottom;

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
        $html .= '<style>';
        $html .= $this->getBaseStyles($usableWidth);
        $html .= '</style></head><body>';

        $hasElements = function(?Band $b): bool {
            return $b && $b->visible && !empty($b->elements);
        };

        $pageHeaderBand = $definition->bands->get('page_header');
        $pageFooterBand = $definition->bands->get('page_footer');
        $reportHeaderBand = $definition->bands->get('report_header');
        $reportFooterBand = $definition->bands->get('report_footer');
        $columnHeaderBand = $definition->bands->get('column_header');

        $pageNum = 1;
        $currentPageHtml = '';
        $currentPageY = 0;
        $pages = [];
        $columnRenderedOnPage = false;
        $lastRowData = null;

        $openPage = function() use (&$currentPageHtml, &$currentPageY, &$columnRenderedOnPage, $usableWidth, $usableHeight) {
            $currentPageHtml = sprintf(
                '<div class="report-page" style="width:%.1fmm; min-height:%.1fmm; position:relative;">',
                $usableWidth, $usableHeight
            );
            $currentPageY = 0;
            $columnRenderedOnPage = false;
        };

        $closePage = function() use (&$currentPageHtml, &$currentPageY, &$pages, &$pageNum, $hasElements, $pageFooterBand, $definition) {
            if ($hasElements($pageFooterBand)) {
                $footerHtml = $this->renderBandElement($pageFooterBand, $definition, null, null, $pageNum);
                $currentPageHtml .= $footerHtml;
                $currentPageY += $pageFooterBand->height;
            }
            $currentPageHtml .= '</div>';
            $pages[] = $currentPageHtml;
            $pageNum++;
        };

        $renderPageTop = function() use (&$currentPageHtml, &$currentPageY, &$columnRenderedOnPage, $hasElements, $pageHeaderBand, $columnHeaderBand, $definition, &$pageNum, &$groupValues, $groups, &$lastRowData) {
            if ($hasElements($pageHeaderBand) && $pageHeaderBand->printOnEveryPage) {
                $currentPageHtml .= $this->renderBandElement($pageHeaderBand, $definition, null, null, $pageNum);
                $currentPageY += $pageHeaderBand->height;
            }
            // Reprint open group headers
            if (!empty($groupValues)) {
                for ($g = 0; $g < count($groups); $g++) {
                    if ($groupValues[$g] !== null && $groups[$g]->reprintHeaderOnNewPage) {
                        $headerBand = $this->findGroupHeader($definition, $groups[$g]);
                        if ($headerBand && $hasElements($headerBand)) {
                            $currentPageHtml .= $this->renderBandElement($headerBand, $definition, $groups[$g], $lastRowData, $pageNum);
                            $currentPageY += $headerBand->height;
                        }
                    }
                }
            }
            if ($hasElements($columnHeaderBand)) {
                $currentPageHtml .= $this->renderBandElement($columnHeaderBand, $definition, null, null, $pageNum);
                $currentPageY += $columnHeaderBand->height;
                $columnRenderedOnPage = true;
            }
        };

        $renderInitialPageHeader = function() use (&$currentPageHtml, &$currentPageY, $hasElements, $pageHeaderBand, $definition, &$pageNum) {
            if ($hasElements($pageHeaderBand) && !$pageHeaderBand->printOnEveryPage) {
                $currentPageHtml .= $this->renderBandElement($pageHeaderBand, $definition, null, null, $pageNum);
                $currentPageY += $pageHeaderBand->height;
            }
        };

        $ensureFits = function(Band $band) use (&$currentPageHtml, &$currentPageY, $usableHeight, &$closePage, &$openPage, &$renderPageTop, &$pageNum) {
            $bandHeight = $band->height;
            if ($bandHeight <= 0) return;
            if ($currentPageY + $bandHeight > $usableHeight) {
                if ($bandHeight <= $usableHeight) {
                    $closePage();
                    $openPage();
                    $renderPageTop();
                }
            }
        };

        // --- Rendering ---
        $openPage();

        // Page header on page 1 (not via renderPageTop — no group/column headers yet)
        if ($hasElements($pageHeaderBand)) {
            if ($pageHeaderBand->printOnEveryPage) {
                $currentPageHtml .= $this->renderBandElement($pageHeaderBand, $definition, null, null, $pageNum);
                $currentPageY += $pageHeaderBand->height;
            } else {
                $renderInitialPageHeader();
            }
        }

        // Report header
        if ($hasElements($reportHeaderBand)) {
            $ensureFits($reportHeaderBand);
            $currentPageHtml .= $this->renderBandElement($reportHeaderBand, $definition, null, null, $pageNum);
            $currentPageY += $reportHeaderBand->height;
        }

        if (empty($data)) {
            $noDataHeight = 20;
            if ($currentPageY + $noDataHeight > $usableHeight && $noDataHeight <= $usableHeight) {
                $closePage();
                $openPage();
                $renderPageTop();
            }
            $currentPageHtml .= '<div class="band band-detail" style="height:20px;padding:8px;">No data returned.</div>';
            $currentPageY += $noDataHeight;
        } else {
            $groupValues = array_fill(0, count($groups), null);
            $groupRowCounters = array_fill(0, count($groups), 0);
            $groupAggregates = [];
            for ($g = 0; $g < count($groups); $g++) {
                $groupAggregates[$g] = new AggregateAccumulator();
            }
            $reportAggregates = new AggregateAccumulator();

            foreach ($data as $rowIndex => $row) {
                $lastRowData = $row;
                $groupChanged = false;

                // Detect group breaks
                for ($g = 0; $g < count($groups); $g++) {
                    $field = $groups[$g]->fieldName;
                    if ($groupValues[$g] !== null && $groupValues[$g] !== ($row[$field] ?? null)) {
                        // Close inner groups
                        for ($inner = count($groups) - 1; $inner >= $g; $inner--) {
                            $footerBand = $this->findGroupFooter($definition, $groups[$inner]);
                            if ($footerBand && $hasElements($footerBand)) {
                                $ensureFits($footerBand);
                                $currentPageHtml .= $this->renderBandElement($footerBand, $definition, $groups[$inner], $groupAggregates[$inner], $pageNum);
                                $currentPageY += $footerBand->height;
                            }
                            $groupAggregates[$inner]->reset();
                            if ($groups[$inner]->resetRowNo) $groupRowCounters[$inner] = 0;
                        }
                        // Reopen outer groups
                        for ($outer = $g; $outer < count($groups); $outer++) {
                            $groupValues[$outer] = $row[$groups[$outer]->fieldName] ?? null;
                            $headerBand = $this->findGroupHeader($definition, $groups[$outer]);
                            if ($headerBand && $hasElements($headerBand)) {
                                $ensureFits($headerBand);
                                $currentPageHtml .= $this->renderBandElement($headerBand, $definition, $groups[$outer], $row, $pageNum);
                                $currentPageY += $headerBand->height;
                            }
                        }
                        $groupChanged = true;
                        break;
                    }
                }

                // Open groups on first row
                if ($rowIndex === 0) {
                    for ($g = 0; $g < count($groups); $g++) {
                        $groupValues[$g] = $row[$groups[$g]->fieldName] ?? null;
                        $headerBand = $this->findGroupHeader($definition, $groups[$g]);
                        if ($headerBand && $hasElements($headerBand)) {
                            $ensureFits($headerBand);
                            $currentPageHtml .= $this->renderBandElement($headerBand, $definition, $groups[$g], $row, $pageNum);
                            $currentPageY += $headerBand->height;
                        }
                    }
                    $groupChanged = true;
                }

                // Render column header once per page, after group headers, before detail
                if ($groupChanged && !$columnRenderedOnPage && $hasElements($columnHeaderBand)) {
                    $ensureFits($columnHeaderBand);
                    $currentPageHtml .= $this->renderBandElement($columnHeaderBand, $definition, null, null, $pageNum);
                    $currentPageY += $columnHeaderBand->height;
                    $columnRenderedOnPage = true;
                }

                // Increment group row counters (deepest active group)
                for ($g = count($groups) - 1; $g >= 0; $g--) {
                    if ($groupValues[$g] !== null) {
                        $groupRowCounters[$g]++;
                        $row['_rowno'] = $groupRowCounters[$g];
                        break;
                    }
                }

                // Accumulate aggregates
                foreach ($row as $field => $value) {
                    for ($g = 0; $g < count($groups); $g++) {
                        $groupAggregates[$g]->accumulate((string)$field, $value);
                    }
                    $reportAggregates->accumulate((string)$field, $value);
                }

                // Detail band
                $detailBand = $definition->bands->get('detail');
                if ($hasElements($detailBand)) {
                    $ensureFits($detailBand);
                    $currentPageHtml .= $this->renderBandElement($detailBand, $definition, null, $row, $pageNum);
                    $currentPageY += $detailBand->height;
                }

            }

            // Close remaining groups
            for ($g = count($groups) - 1; $g >= 0; $g--) {
                $footerBand = $this->findGroupFooter($definition, $groups[$g]);
                if ($footerBand && $hasElements($footerBand)) {
                    $ensureFits($footerBand);
                    $currentPageHtml .= $this->renderBandElement($footerBand, $definition, $groups[$g], $groupAggregates[$g], $pageNum);
                    $currentPageY += $footerBand->height;
                }
                $groupAggregates[$g]->reset();
            }

            // Report footer
            if ($hasElements($reportFooterBand)) {
                $ensureFits($reportFooterBand);
                $currentPageHtml .= $this->renderBandElement($reportFooterBand, $definition, null, $reportAggregates, $pageNum);
                $currentPageY += $reportFooterBand->height;
            }
        }

        $closePage();

        $html .= implode("\n", $pages);
        $html .= '</body></html>';
        return $html;
    }

    private function renderBandElement(Band $band, ReportDefinition $def, $group, $data, int $pageNum = 1): string
    {
        $borderStyle = $band->border ? $band->border->toHtmlStyle() : '';
        $style = sprintf(
            'position:relative; height:%.1fmm; background:%s; %s',
            $band->height,
            $band->backgroundColor ?: 'transparent',
            $borderStyle
        );

        $html = sprintf(
            '<div class="band band-%s" style="%s">',
            $band->type,
            $style
        );

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

        $style = sprintf(
            'position:absolute; top:%.1fmm; left:%.1fmm; width:%.1fmm; height:%.1fmm; font-family:%s; font-size:%dpt; font-weight:%s; font-style:%s; text-decoration:%s; color:%s; text-align:%s; vertical-align:%s; background:%s; %s',
            $el->top,
            $el->left,
            $el->width,
            $el->height,
            $el->fontFamily ?: 'Arial',
            $el->fontSize ?: 10,
            $el->bold ? 'bold' : 'normal',
            $el->italic ? 'italic' : 'normal',
            $el->underline ? 'underline' : 'none',
            $el->color ?: '#000000',
            $el->textAlign ?: 'left',
            $el->verticalAlign ?? 'top',
            $el->backgroundColor ?: 'transparent',
            $borderStyle
        );

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
        if ($format === null || $format === '') {
            return (string)$value;
        }
        if (is_numeric($value)) {
            if (str_contains($format, '%')) {
                return sprintf($format, (float)$value);
            }
            $decimals = 2;
            $decPoint = '.';
            $thousandsSep = ',';
            return number_format((float)$value, $decimals, $decPoint, $thousandsSep);
        }
        return (string)$value;
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

    private function getBaseStyles(float $usableWidth): string
    {
        return '
            body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #e2e8f0; }
            .report-page { width: ' . $usableWidth . 'mm; margin: 0 auto 24px auto; background: white; box-shadow: 0 4px 16px rgba(0,0,0,0.12); page-break-after: always; position: relative; }
            .report-page:last-child { page-break-after: auto; margin-bottom: 0; }
            .band { padding: 2px 4px; border-bottom: 1px solid #eee; overflow: hidden; }
            .element { overflow: hidden; white-space: nowrap; }
            .band-page_header { background: #fee2e2; }
            .band-page_footer { background: #fee2e2; }
            .band-report_header { background: #e9d5ff; }
            .band-report_footer { background: #e9d5ff; }
            .band-group_header { background: #fef3c7; }
            .band-group_footer { background: #fef3c7; }
            .band-column_header { background: #fef9c3; }
            .band-detail { background: #f0fdf4; }
            @media print {
                body { background: white; padding: 0; }
                .report-page { box-shadow: none; margin: 0; page-break-after: always; }
                .report-page:last-child { page-break-after: auto; }
            }
        ';
    }
}
