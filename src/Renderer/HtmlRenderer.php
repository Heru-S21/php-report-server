<?php

namespace ReportingEngine\Renderer;

use ReportingEngine\Report\ReportDefinition;
use ReportingEngine\Report\Band;
use ReportingEngine\Report\BandElement;
use ReportingEngine\Report\GroupDefinition;
use ReportingEngine\Report\AggregateAccumulator;

class HtmlRenderer implements RendererInterface
{
    private array $fontMetrics = [];

    public function render(ReportDefinition $definition, array $data, array $params = []): string
    {
        $this->fontMetrics = isset($params['_fontMetrics']) && is_array($params['_fontMetrics']) ? $params['_fontMetrics'] : [];
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
        $showPrint = !isset($params['no_print']);
        $html .= '<style>' . $this->getBaseStyles($usableWidth, $paperW, $showPrint) . '</style></head><body>';
        if ($showPrint) {
            $html .= '<button class="print-btn no-print" onclick="window.print()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><path d="M6 14h12v8H6z"/></svg> Print</button>';
        }

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
                $effH = $this->calculateEffectiveBandHeight($pfBand, $definition, null, null, $pageNum);
                $pageHtml .= $this->renderBandElement($pfBand, $definition, null, null, $pageNum, $effH);
            }
            $pageHtml .= '</div>';
            $pages[]   = $pageHtml;
            $pageNum++;
        };

        // Render everything that goes at the top of a (non-first) page.
        // $reprintGroups – list of group-indexes whose header should be reprinted.
        // $lastRowData  – row data for field values inside reprinted headers.
        $renderPageTop = function(array $reprintGroups, ?array $lastRowData, bool $isFirst)
            use (&$pageHtml, &$pageY, &$chOnPage, $has, $phBand, $chBand, $groups, $definition, &$pageNum)
        {
            // Page header
            if ($has($phBand) && ($isFirst || $phBand->printOnEveryPage)) {
                $effH = $this->calculateEffectiveBandHeight($phBand, $definition, null, null, $pageNum);
                $pageHtml .= $this->renderBandElement($phBand, $definition, null, null, $pageNum, $effH);
                $pageY += $effH;
            }
            // Reprint group headers that have reprintHeaderOnNewPage enabled
            foreach ($reprintGroups as $gi) {
                if (!$groups[$gi]->reprintHeaderOnNewPage) continue;
                $hdr = $this->findGroupHeader($definition, $groups[$gi]);
                if ($hdr && $has($hdr)) {
                    $effH = $this->calculateEffectiveBandHeight($hdr, $definition, $groups[$gi], $lastRowData, $pageNum);
                    $pageHtml .= $this->renderBandElement($hdr, $definition, $groups[$gi], $lastRowData, $pageNum, $effH);
                    $pageY += $effH;
                }
            }
            // Column header — only on page 1 or when printOnEveryPage is true
            if ($has($chBand) && ($isFirst || $chBand->printOnEveryPage)) {
                $effH = $this->calculateEffectiveBandHeight($chBand, $definition, null, null, $pageNum);
                $pageHtml .= $this->renderBandElement($chBand, $definition, null, null, $pageNum, $effH);
                $pageY += $effH;
                $chOnPage = true;
            }
        };

        // Reserve space for a band – break page if needed.
        // $rowData – current data row, passed for reprinted group-header field values.
        $contentLimit = $usableHeight - ($has($pfBand) ? $pfBand->height : 0);
        $fit = function(?Band $b, ?array $rowData = null, ?float $effectiveHeight = null) use (&$pageHtml, &$pageY, $contentLimit, &$closePage, &$openPage, &$renderPageTop, &$groupValues): float {
            if (!$b || !$b->visible || empty($b->elements)) return 0;
            $h = $effectiveHeight ?? $b->height;
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
            $effH = $this->calculateEffectiveBandHeight($phBand, $definition, null, null, $pageNum);
            $pageHtml .= $this->renderBandElement($phBand, $definition, null, null, $pageNum, $effH);
            $pageY += $effH;
        }

        // -- Report header --
        $effH = $has($rhBand) ? $this->calculateEffectiveBandHeight($rhBand, $definition, null, null, $pageNum) : 0;
        $pageY += $fit($rhBand, null, $effH);
        if ($has($rhBand)) {
            $pageHtml .= $this->renderBandElement($rhBand, $definition, null, null, $pageNum, $effH);
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
                            $effH = $ft && $has($ft) ? $this->calculateEffectiveBandHeight($ft, $definition, $groups[$inner], $groupAggs[$inner], $pageNum) : 0;
                            $pageY += $fit($ft, null, $effH);
                            if ($ft && $has($ft)) {
                                $pageHtml .= $this->renderBandElement($ft, $definition, $groups[$inner], $groupAggs[$inner], $pageNum, $effH);
                            }
                            $groupAggs[$inner]->reset();
                            if ($groups[$inner]->resetRowNo) $groupRowCounters[$inner] = 0;
                        }

                        // Open outer groups (forward order)
                        for ($outer = $g; $outer < count($groups); $outer++) {
                            $groupValues[$outer] = $row[$groups[$outer]->fieldName] ?? null;
                            $hdr = $this->findGroupHeader($definition, $groups[$outer]);
                            $effH = $hdr && $has($hdr) ? $this->calculateEffectiveBandHeight($hdr, $definition, $groups[$outer], $row, $pageNum) : 0;
                            $pageY += $fit($hdr, $row, $effH);
                            if ($hdr && $has($hdr)) {
                                $pageHtml .= $this->renderBandElement($hdr, $definition, $groups[$outer], $row, $pageNum, $effH);
                            }
                        }

                        // -- Column header after new group headers, once per page --
                        if (!$chOnPage && $has($chBand)) {
                            $effH = $this->calculateEffectiveBandHeight($chBand, $definition, null, null, $pageNum);
                            $pageY += $fit($chBand, null, $effH);
                            $pageHtml .= $this->renderBandElement($chBand, $definition, null, null, $pageNum, $effH);
                            $chOnPage = true;
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
                        $effH = $hdr && $has($hdr) ? $this->calculateEffectiveBandHeight($hdr, $definition, $groups[$g], $row, $pageNum) : 0;
                        $pageY += $fit($hdr, $row, $effH);
                        if ($hdr && $has($hdr)) {
                            $pageHtml .= $this->renderBandElement($hdr, $definition, $groups[$g], $row, $pageNum, $effH);
                        }
                    }
                    // Column header after group headers on first data page
                    if (!$chOnPage && $has($chBand)) {
                        $effH = $this->calculateEffectiveBandHeight($chBand, $definition, null, null, $pageNum);
                        $pageY += $fit($chBand, null, $effH);
                        $pageHtml .= $this->renderBandElement($chBand, $definition, null, null, $pageNum, $effH);
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

                // ------ detail band ------
                $effH = $has($dtBand) ? $this->calculateEffectiveBandHeight($dtBand, $definition, null, $row, $pageNum) : 0;
                $pageY += $fit($dtBand, $row, $effH);
                if ($has($dtBand)) {
                    $pageHtml .= $this->renderBandElement($dtBand, $definition, null, $row, $pageNum, $effH);
                }
            }

            // ------ close remaining groups ------
            for ($g = count($groups) - 1; $g >= 0; $g--) {
                $ft = $this->findGroupFooter($definition, $groups[$g]);
                $effH = $ft && $has($ft) ? $this->calculateEffectiveBandHeight($ft, $definition, $groups[$g], $groupAggs[$g], $pageNum) : 0;
                $pageY += $fit($ft, null, $effH);
                if ($ft && $has($ft)) {
                    $pageHtml .= $this->renderBandElement($ft, $definition, $groups[$g], $groupAggs[$g], $pageNum, $effH);
                }
                $groupAggs[$g]->reset();
            }

            // ------ report footer ------
            $effH = $has($rfBand) ? $this->calculateEffectiveBandHeight($rfBand, $definition, null, $reportAggs, $pageNum) : 0;
            $pageY += $fit($rfBand, null, $effH);
            if ($has($rfBand)) {
                $pageHtml .= $this->renderBandElement($rfBand, $definition, null, $reportAggs, $pageNum, $effH);
            }
        }

        $closePage();

        // Wrap each page in a paper-page for word-processor appearance on screen
        $totalPages = count($pages);
        $wrapped = [];
        foreach ($pages as $pageHtml) {
            $minH = '';
            if (preg_match('/min-height:([\d.]+)mm/', $pageHtml, $m)) {
                $minH = 'min-height:' . ((float)$m[1] + 30) . 'mm;';
            }
            $pageHtml = str_replace('{{PAGECOUNT}}', (string)$totalPages, $pageHtml);
            $wrapped[] = '<div class="paper-page" style="width:' . $paperW . 'mm;' . $minH . '">' . "\n" . $pageHtml . "\n" . '</div>';
        }
        $html .= implode("\n", $wrapped);
        if ($showPrint) {
            $html .= '<script>(function(){var b=document.querySelector(".print-btn");if(b&&window.location.search.includes("print"))setTimeout(function(){b.click()},500)})();</script>';
        }
        $html .= '</body></html>';
        return $html;
    }

    // ------------------------------------------------------------------ helpers

    private function renderBandElement(Band $band, ReportDefinition $def, $group, $data, int $pageNum = 1, ?float $effectiveHeight = null): string
    {
        $borderStyle = $band->border ? $band->border->toHtmlStyle() : '';
        $h = $effectiveHeight ?? $band->height;
        $style = sprintf(
            'position:relative; height:%.1fmm; background:%s; %s',
            $h,
            $band->backgroundColor ?: 'transparent',
            $borderStyle
        );
        $html = sprintf('<div class="band band-%s" style="%s">', $band->type, $style);
        foreach ($band->elements as $element) {
            $rowData = $data instanceof AggregateAccumulator ? $data->getLastValues() : ($data ?: []);
            if ($element->visibleExpression !== null && !ExpressionEvaluator::evaluateBool($element->visibleExpression, $rowData)) {
                continue;
            }
            if ($element->conditionalExpression && !ExpressionEvaluator::evaluateBool($element->conditionalExpression, $rowData)) {
                continue;
            }
            $html .= $this->renderSingleElement($element, $def, $group, $data, $pageNum);
        }
        $html .= '</div>';
        return $html;
    }

    private function renderSingleElement(BandElement $el, ReportDefinition $def, $group, $data, int $pageNum = 1): string
    {
        $rowData = $data instanceof AggregateAccumulator ? $data->getLastValues() : ($data ?: []);
        if ($el->visibleExpression !== null && !ExpressionEvaluator::evaluateBool($el->visibleExpression, $rowData)) {
            return '';
        }
        $value = $this->getElementValue($el, $def, $group, $data, $pageNum);
        $borderStyle = $el->border ? $el->border->toHtmlStyle() : '';
        $va = $el->verticalAlign ?? 'top';
        $ta = $el->textAlign ?: 'left';

        $condStyle = $this->resolveConditionalStyle($el, $data);

        $bold = $condStyle['bold'] ?? $el->bold;
        $italic = $condStyle['italic'] ?? $el->italic;
        $underline = $condStyle['underline'] ?? $el->underline;
        $color = $condStyle['color'] ?? $el->color ?: '#000000';
        $backgroundColor = $condStyle['backgroundColor'] ?? $el->backgroundColor ?: 'transparent';
        $fontFamily = $condStyle['fontFamily'] ?? $el->fontFamily ?: 'Arial';
        $fontSize = $condStyle['fontSize'] ?? $el->fontSize ?: 10;
        $textAlign = $condStyle['textAlign'] ?? $ta;
        $verticalAlign = $condStyle['verticalAlign'] ?? $va;

        $isTextType = !in_array($el->type, ['image', 'line', 'rect', 'barcode']);
        $wordWrap = $el->wordWrap ?? false;
        $textOverflow = $wordWrap ? '' : 'text-overflow:ellipsis;';
        $whiteSpace = $wordWrap ? 'white-space:normal; overflow-wrap:break-word;' : 'white-space:nowrap;';

        if ($wordWrap && $isTextType) {
            $textH = $this->estimateTextHeight(strip_tags($value), $fontSize, $el->width, $fontFamily, $bold, $italic);
            $effectiveElH = max((float)$el->height, $textH);
        } else {
            $effectiveElH = (float)$el->height;
        }

        if ($verticalAlign === 'top') {
            $style = sprintf(
                'position:absolute; top:%.1fmm; left:%.1fmm; width:%.1fmm; height:%.1fmm; font-family:%s; font-size:%dpt; font-weight:%s; font-style:%s; text-decoration:%s; color:%s; text-align:%s; overflow:hidden; %s %s background:%s; %s',
                $el->top, $el->left, $el->width, $effectiveElH,
                $fontFamily,
                $fontSize,
                $bold ? 'bold' : 'normal',
                $italic ? 'italic' : 'normal',
                $underline ? 'underline' : 'none',
                $color,
                $textAlign,
                $textOverflow,
                $whiteSpace,
                $backgroundColor,
                $borderStyle
            );
        } else {
            $alignItems = $verticalAlign === 'middle' ? 'center' : 'flex-end';
            $justify = match ($textAlign) {
                'center' => 'center',
                'right' => 'flex-end',
                default => 'flex-start',
            };
            $style = sprintf(
                'position:absolute; top:%.1fmm; left:%.1fmm; width:%.1fmm; height:%.1fmm; font-family:%s; font-size:%dpt; font-weight:%s; font-style:%s; text-decoration:%s; color:%s; display:flex; align-items:%s; justify-content:%s; overflow:hidden; background:%s; %s',
                $el->top, $el->left, $el->width, $effectiveElH,
                $fontFamily,
                $fontSize,
                $bold ? 'bold' : 'normal',
                $italic ? 'italic' : 'normal',
                $underline ? 'underline' : 'none',
                $color,
                $alignItems,
                $justify,
                $backgroundColor,
                $borderStyle
            );
        }

        if ($isTextType) {
            $value = sprintf(
                '<span style="overflow:hidden; %s %s display:block; width:100%%; min-width:0; text-align:%s">%s</span>',
                $textOverflow,
                $whiteSpace,
                $textAlign,
                $value
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
            $value = $this->getElementValue($el, $def, $group, $data, $pageNum);
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

    private function getElementValue(BandElement $el, ReportDefinition $def, $group, $data, int $pageNum = 1): string
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
            'aggregate' => $this->renderAggregate($el, $data),
            'image' => $el->imageUrl ? '<img src="' . htmlspecialchars($el->imageUrl) . '" style="width:100%;height:100%;object-fit:' . $this->imageFit($el->imageDisplay) . '">' : '',
            'line' => '<hr style="border:none;border-top:1px solid #000;margin:0;width:100%">',
            'rect' => '',
            'pageno' => (string)$pageNum,
            'pagecount' => '{{PAGECOUNT}}',
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

    private function imageFit(?string $display): string
    {
        return match ($display) {
            'original' => 'none',
            'stretch' => 'fill',
            default => 'contain',
        };
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

    private function getBaseStyles(float $usableWidth, float $paperW, bool $showPrint = false): string
    {
        $css = '
            body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #e2e8f0; }
            .report-page { width: ' . $usableWidth . 'mm; margin: 0 auto; background: white; box-shadow: 0 4px 16px rgba(0,0,0,0.12); position: relative; }
            .band { padding: 2px 4px; overflow: hidden; }
            .element { overflow: hidden; }
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
        if ($showPrint) {
            $css .= '
                .print-btn { position:fixed; top:16px; right:16px; z-index:9999; display:inline-flex; align-items:center; gap:6px; padding:10px 18px; font-size:14px; font-family:Arial,sans-serif; font-weight:600; border:none; border-radius:8px; background:#2563eb; color:#fff; cursor:pointer; box-shadow:0 4px 12px rgba(0,0,0,0.25); transition:background 0.15s,transform 0.1s; }
                .print-btn:hover { background:#1d4ed8; transform:scale(1.04); }
                .print-btn:active { transform:scale(0.96); }
                @media print {
                    .no-print { display: none !important; }
                }
            ';
        }
        return $css;
    }
}
