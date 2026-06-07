<?php

namespace ReportingEngine\Report;

class BandCollection
{
    private array $bands = [];

    public function __construct(array $bands = [])
    {
        foreach ($bands as $band) {
            if ($band instanceof Band) {
                $this->bands[] = $band;
            } elseif (is_array($band)) {
                $this->bands[] = Band::fromArray($band);
            }
        }
    }

    public function add(Band $band): void
    {
        $this->bands[] = $band;
    }

    public function get(string $type): ?Band
    {
        foreach ($this->bands as $band) {
            if ($band->type === $type) return $band;
        }
        return null;
    }

    public function all(): array
    {
        return $this->bands;
    }

    public function getSorted(): array
    {
        $order = ['page_header', 'report_header', 'group_header', 'detail', 'group_footer', 'report_footer', 'page_footer'];
        $sorted = $this->bands;
        usort($sorted, function (Band $a, Band $b) use ($order) {
            $ia = array_search($a->type, $order);
            $ib = array_search($b->type, $order);
            return ($ia === false ? 999 : $ia) <=> ($ib === false ? 999 : $ib);
        });
        return $sorted;
    }

    public function filterByGroup(string $groupField): array
    {
        return array_filter($this->bands, fn(Band $b) =>
            $b->groupField === $groupField
        );
    }

    public function toArray(): array
    {
        return array_map(fn(Band $b) => $b->toArray(), $this->bands);
    }
}
