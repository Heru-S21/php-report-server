<?php

namespace ReportingEngine\Report;

class AggregateDefinition
{
    public string $func = 'sum';
    public string $fieldName = '';
    public string $scope = 'group';
    public int $groupLevel = 0;
    public string $format = '#,##0.00';
    public string $label = '';

    public static function fromArray(array $data): self
    {
        $a = new self();
        $a->func = $data['func'] ?? 'sum';
        $a->fieldName = $data['fieldName'] ?? '';
        $a->scope = $data['scope'] ?? 'group';
        $a->groupLevel = (int)($data['groupLevel'] ?? 0);
        $a->format = $data['format'] ?? '#,##0.00';
        $a->label = $data['label'] ?? '';
        return $a;
    }

    public function toArray(): array
    {
        return [
            'func' => $this->func,
            'fieldName' => $this->fieldName,
            'scope' => $this->scope,
            'groupLevel' => $this->groupLevel,
            'format' => $this->format,
            'label' => $this->label,
        ];
    }
}


