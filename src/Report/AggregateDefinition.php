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

class AggregateAccumulator
{
    private array $sums = [];
    private array $counts = [];
    private array $mins = [];
    private array $maxs = [];

    public function accumulate(string $field, mixed $value): void
    {
        $v = (float)$value;
        $this->sums[$field] = ($this->sums[$field] ?? 0) + $v;
        $this->counts[$field] = ($this->counts[$field] ?? 0) + 1;
        $this->mins[$field] = min($this->mins[$field] ?? $v, $v);
        $this->maxs[$field] = max($this->maxs[$field] ?? $v, $v);
    }

    public function resolve(string $func, string $field): float|int
    {
        return match ($func) {
            'sum' => $this->sums[$field] ?? 0,
            'count' => $this->counts[$field] ?? 0,
            'avg' => ($this->counts[$field] ?? 0) > 0
                ? ($this->sums[$field] ?? 0) / $this->counts[$field] : 0,
            'min' => $this->mins[$field] ?? 0,
            'max' => $this->maxs[$field] ?? 0,
            default => 0,
        };
    }

    public function reset(): void
    {
        $this->sums = $this->counts = $this->mins = $this->maxs = [];
    }
}
