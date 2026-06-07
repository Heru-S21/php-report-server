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

        // Sort groups by level
        usort($groups, fn(GroupDefinition $a, GroupDefinition $b) => $a->level <=> $b->level);

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
        $html .= '<style>';
        $html .= $this->getBaseStyles($page);
        $html .= '</style></head><body>';
        $html .= '<div class="report-container">';

        // Page header
        $pageHeaderBand = $definition->bands->get('page_header');
        if ($pageHeaderBand && $pageHeaderBand->visible) {
            $html .= $this->renderBandElement($pageHeaderBand, $definition, null, null);
        }

        // Report header
        $reportHeaderBand = $definition->bands->get('report_header');
        if ($reportHeaderBand && $reportHeaderBand->visible) {
            $html .= $this->renderBandElement($reportHeaderBand, $definition, null, null);
        }

        if (empty($data)) {
            $html .= '<div class="band band-detail" style="height:20px;padding:8px;">No data returned.</div>';
        } else {
            // Group tracking
            $groupValues = array_fill(0, count($groups), null);
            $groupAggregates = [];
            for ($g = 0; $g < count($groups); $g++) {
                $groupAggregates[$g] = new AggregateAccumulator();
            }
            $reportAggregates = new AggregateAccumulator();

            foreach ($data as $rowIndex => $row) {
                // Detect group breaks
                for ($g = 0; $g < count($groups); $g++) {
                    $field = $groups[$g]->fieldName;
                    if ($groupValues[$g] !== null && $groupValues[$g] !== ($row[$field] ?? null)) {
                        // Close inner groups
                        for ($inner = count($groups) - 1; $inner >= $g; $inner--) {
                            $footerBand = $this->findGroupFooter($definition, $groups[$inner]);
                            if ($footerBand) {
                                $html .= $this->renderBandElement($footerBand, $definition, $groups[$inner], $groupAggregates[$inner]);
                            }
                            $groupAggregates[$inner]->reset();
                        }
                        // Reopen outer groups
                        for ($outer = $g; $outer < count($groups); $outer++) {
                            $groupValues[$outer] = $row[$groups[$outer]->fieldName] ?? null;
                            $headerBand = $this->findGroupHeader($definition, $groups[$outer]);
                            if ($headerBand) {
                                $html .= $this->renderBandElement($headerBand, $definition, $groups[$outer], $row);
                            }
                        }
                        break;
                    }
                }

                // Open groups on first row
                if ($rowIndex === 0) {
                    for ($g = 0; $g < count($groups); $g++) {
                        $groupValues[$g] = $row[$groups[$g]->fieldName] ?? null;
                        $headerBand = $this->findGroupHeader($definition, $groups[$g]);
                        if ($headerBand) {
                            $html .= $this->renderBandElement($headerBand, $definition, $groups[$g], $row);
                        }
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
                if ($detailBand && $detailBand->visible) {
                    $html .= $this->renderBandElement($detailBand, $definition, null, $row);
                }
            }

            // Close remaining groups
            for ($g = count($groups) - 1; $g >= 0; $g--) {
                $footerBand = $this->findGroupFooter($definition, $groups[$g]);
                if ($footerBand) {
                    $html .= $this->renderBandElement($footerBand, $definition, $groups[$g], $groupAggregates[$g]);
                }
                $groupAggregates[$g]->reset();
            }

            // Report footer
            $reportFooterBand = $definition->bands->get('report_footer');
            if ($reportFooterBand && $reportFooterBand->visible) {
                $html .= $this->renderBandElement($reportFooterBand, $definition, null, $reportAggregates);
            }
        }

        // Page footer
        $pageFooterBand = $definition->bands->get('page_footer');
        if ($pageFooterBand && $pageFooterBand->visible) {
            $html .= $this->renderBandElement($pageFooterBand, $definition, null, null);
        }

        $html .= '</div></body></html>';
        return $html;
    }

    private function renderBandElement(Band $band, ReportDefinition $def, $group, $data): string
    {
        $borderStyle = $band->border ? $band->border->toHtmlStyle() : '';
        $style = sprintf(
            'height:%.1fmm; background:%s; %s',
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
            $html .= $this->renderSingleElement($element, $def, $group, $data);
        }

        $html .= '</div>';
        return $html;
    }

    private function renderSingleElement(BandElement $el, ReportDefinition $def, $group, $data): string
    {
        $value = $this->getElementValue($el, $def, $group, $data);
        $borderStyle = $el->border ? $el->border->toHtmlStyle() : '';

        $style = sprintf(
            'position:relative; top:%.1fmm; left:%.1fmm; width:%.1fmm; height:%.1fmm; font-family:%s; font-size:%dpt; font-weight:%s; font-style:%s; text-decoration:%s; color:%s; text-align:%s; background:%s; %s',
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
            'aggregate' => $this->renderAggregate($el, $data),
            'image' => $el->imageUrl ? '<img src="' . htmlspecialchars($el->imageUrl) . '" style="max-width:100%;max-height:100%">' : '',
            'line' => '<hr style="border:none;border-top:1px solid #000;margin:0;width:100%">',
            'rect' => '',
            'pageno' => htmlspecialchars($el->text ?? '{PAGENO}'),
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
            // Custom format handling
            if (str_contains($format, '%')) {
                return sprintf($format, (float)$value);
            }
            // Simple number format
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

    private function getBaseStyles($page): string
    {
        return '
            body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
            .report-container { max-width: ' . $page->getPaperWidthMm() . 'mm; margin: 0 auto; background: white; padding: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
            .band { padding: 2px 4px; border-bottom: 1px solid #eee; overflow: hidden; }
            .element { display: inline-block; overflow: hidden; white-space: nowrap; }
            .band-page_header { background: #e8f4f8; }
            .band-page_footer { background: #e8f4f8; }
            .band-report_header { background: #e8f0fe; }
            .band-report_footer { background: #e8f0fe; }
            .band-group_header { background: #fef3c7; }
            .band-group_footer { background: #fef3c7; }
            .band-detail { background: #f0fdf4; }
        ';
    }
}
