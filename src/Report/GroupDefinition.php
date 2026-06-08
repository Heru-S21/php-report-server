<?php

namespace ReportingEngine\Report;

class GroupDefinition
{
    public string $id;
    public string $fieldName;
    public int $level = 0;
    public string $sortDirection = 'ASC';
    public bool $pageBreakBefore = false;
    public bool $reprintHeaderOnNewPage = false;
    public bool $showHeader = true;
    public bool $showFooter = true;
    public bool $startCollapsed = false;
    public bool $resetRowNo = false;

    public static function fromArray(array $data): self
    {
        $g = new self();
        $g->id = $data['id'] ?? '';
        $g->fieldName = $data['fieldName'] ?? '';
        $g->level = (int)($data['level'] ?? 0);
        $g->sortDirection = $data['sortDirection'] ?? 'ASC';
        $g->pageBreakBefore = (bool)($data['pageBreakBefore'] ?? false);
        $g->reprintHeaderOnNewPage = (bool)($data['reprintHeaderOnNewPage'] ?? false);
        $g->showHeader = (bool)($data['showHeader'] ?? true);
        $g->showFooter = (bool)($data['showFooter'] ?? true);
        $g->startCollapsed = (bool)($data['startCollapsed'] ?? false);
        $g->resetRowNo = (bool)($data['resetRowNo'] ?? false);
        return $g;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'fieldName' => $this->fieldName,
            'level' => $this->level,
            'sortDirection' => $this->sortDirection,
            'pageBreakBefore' => $this->pageBreakBefore,
            'reprintHeaderOnNewPage' => $this->reprintHeaderOnNewPage,
            'showHeader' => $this->showHeader,
            'showFooter' => $this->showFooter,
            'startCollapsed' => $this->startCollapsed,
            'resetRowNo' => $this->resetRowNo,
        ];
    }
}
