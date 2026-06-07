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
            'mode' => 'utf-8',
            'format' => $page->paperSize,
            'orientation' => $page->orientation,
            'margin_top' => $page->marginTop,
            'margin_bottom' => $page->marginBottom,
            'margin_left' => $page->marginLeft,
            'margin_right' => $page->marginRight,
            'tempDir' => sys_get_temp_dir() . '/mpdf',
        ]);

        $hasElements = function(?Band $b): bool {
            return $b && $b->visible && !empty($b->elements);
        };

        // Page header (set via mPDF header)
        $pageHeaderBand = $definition->bands->get('page_header');
        if ($hasElements($pageHeaderBand)) {
            $headerHtml = $this->renderBandHtml($pageHeaderBand, $definition, null, null);
            $mpdf->SetHTMLHeader($headerHtml);
        }

        // Page footer
        $pageFooterBand = $definition->bands->get('page_footer');
        if ($hasElements($pageFooterBand)) {
            $footerHtml = $this->renderBandHtml($pageFooterBand, $definition, null, null);
            $mpdf->SetHTMLFooter($footerHtml);
        }

        // Build body content
        $html = $this->buildBody($definition, $data);

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    private function buildBody(ReportDefinition $definition, array $data): string
    {
        $groups = $definition->groups;
        usort($groups, fn(GroupDefinition $a, GroupDefinition $b) => $a->level <=> $b->level);

        $hasElements = function(?Band $b): bool {
            return $b && $b->visible && !empty($b->elements);
        };

        $html = '<html><head><style>';
        $html .= $this->getStyles();
        $html .= '</style></head><body>';

        // Report header
        $reportHeaderBand = $definition->bands->get('report_header');
        if ($hasElements($reportHeaderBand)) {
            $html .= $this->renderBandHtml($reportHeaderBand, $definition, null, null);
        }

        if (empty($data)) {
            $html .= '<p>No data returned.</p>';
        } else {
            $groupValues = array_fill(0, count($groups), null);
            $groupAggregates = [];
            for ($g = 0; $g < count($groups); $g++) {
                $groupAggregates[$g] = new AggregateAccumulator();
            }
            $reportAggregates = new AggregateAccumulator();

            foreach ($data as $rowIndex => $row) {
                for ($g = 0; $g < count($groups); $g++) {
                    $field = $groups[$g]->fieldName;
                    if ($groupValues[$g] !== null && $groupValues[$g] !== ($row[$field] ?? null)) {
                        for ($inner = count($groups) - 1; $inner >= $g; $inner--) {
                            $footerBand = $this->findGroupFooter($definition, $groups[$inner]);
                            if ($footerBand && $hasElements($footerBand)) {
                                $html .= $this->renderBandHtml($footerBand, $definition, $groups[$inner], $groupAggregates[$inner]);
                            }
                            $groupAggregates[$inner]->reset();
                        }
                        for ($outer = $g; $outer < count($groups); $outer++) {
                            $groupValues[$outer] = $row[$groups[$outer]->fieldName] ?? null;
                            $headerBand = $this->findGroupHeader($definition, $groups[$outer]);
                            if ($headerBand && $hasElements($headerBand)) {
                                $html .= $this->renderBandHtml($headerBand, $definition, $groups[$outer], $row);
                            }
                        }
                        break;
                    }
                }

                if ($rowIndex === 0) {
                    for ($g = 0; $g < count($groups); $g++) {
                        $groupValues[$g] = $row[$groups[$g]->fieldName] ?? null;
                        $headerBand = $this->findGroupHeader($definition, $groups[$g]);
                        if ($headerBand && $hasElements($headerBand)) {
                            $html .= $this->renderBandHtml($headerBand, $definition, $groups[$g], $row);
                        }
                    }
                }

                foreach ($row as $field => $value) {
                    for ($g = 0; $g < count($groups); $g++) {
                        $groupAggregates[$g]->accumulate((string)$field, $value);
                    }
                    $reportAggregates->accumulate((string)$field, $value);
                }

                $detailBand = $definition->bands->get('detail');
                if ($hasElements($detailBand)) {
                    $html .= $this->renderBandHtml($detailBand, $definition, null, $row);
                }
            }

            for ($g = count($groups) - 1; $g >= 0; $g--) {
                $footerBand = $this->findGroupFooter($definition, $groups[$g]);
                if ($footerBand && $hasElements($footerBand)) {
                    $html .= $this->renderBandHtml($footerBand, $definition, $groups[$g], $groupAggregates[$g]);
                }
                $groupAggregates[$g]->reset();
            }

            $reportFooterBand = $definition->bands->get('report_footer');
            if ($hasElements($reportFooterBand)) {
                $html .= $this->renderBandHtml($reportFooterBand, $definition, null, $reportAggregates);
            }
        }

        $html .= '</body></html>';
        return $html;
    }

    private function renderBandHtml(Band $band, ReportDefinition $def, $group, $data): string
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
            'position:relative; top:%dpt; left:%dpt; width:%dpt; height:%dpt; font-family:%s; font-size:%dpt; font-weight:%s; font-style:%s; color:%s; text-align:%s; background:%s; %s',
            $el->top,
            $el->left,
            $el->width,
            $el->height,
            $el->fontFamily ?: 'Arial',
            $el->fontSize ?: 10,
            $el->bold ? 'bold' : 'normal',
            $el->italic ? 'italic' : 'normal',
            $el->color ?: '#000',
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
        if ($format === null || $format === '') return (string)$value;
        if (is_numeric($value)) {
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
