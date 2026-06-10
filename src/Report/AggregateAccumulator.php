<?php

namespace ReportingEngine\Report;

class AggregateAccumulator
{
    private array $sums = [];
    private array $counts = [];
    private array $mins = [];
    private array $maxs = [];
    private array $lastValues = [];

    public function accumulate(string $field, mixed $value): void
    {
        $v = (float)$value;
        $this->sums[$field] = ($this->sums[$field] ?? 0) + $v;
        $this->counts[$field] = ($this->counts[$field] ?? 0) + 1;
        $this->mins[$field] = min($this->mins[$field] ?? $v, $v);
        $this->maxs[$field] = max($this->maxs[$field] ?? $v, $v);
        $this->lastValues[$field] = $value;
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

    public function getLastValue(string $field): mixed
    {
        return $this->lastValues[$field] ?? null;
    }

    public function reset(): void
    {
        $this->sums = $this->counts = $this->mins = $this->maxs = $this->lastValues = [];
    }
}
