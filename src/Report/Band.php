<?php

namespace ReportingEngine\Report;

class Band
{
    public string $type;
    public ?string $groupField = null;
    public int $groupLevel = 0;
    public int $height = 20;
    public bool $printOnEveryPage = false;
    public bool $visible = true;
    public bool $keepTogether = false;
    public string $backgroundColor = 'transparent';
    public BorderDefinition $border;
    public array $elements = [];

    public function __construct()
    {
        $this->border = new BorderDefinition();
    }

    public static function fromArray(array $data): self
    {
        $band = new self();
        $band->type = $data['type'] ?? 'detail';
        $band->groupField = $data['groupField'] ?? null;
        $band->groupLevel = (int)($data['groupLevel'] ?? 0);
        $band->height = (int)($data['height'] ?? 20);
        $band->printOnEveryPage = (bool)($data['printOnEveryPage'] ?? false);
        $band->visible = (bool)($data['visible'] ?? true);
        $band->keepTogether = (bool)($data['keepTogether'] ?? false);
        $band->backgroundColor = $data['backgroundColor'] ?? 'transparent';
        $band->border = BorderDefinition::fromArray($data['border'] ?? null);
        if (isset($data['elements']) && is_array($data['elements'])) {
            foreach ($data['elements'] as $elData) {
                $band->elements[] = BandElement::fromArray($elData);
            }
        }
        return $band;
    }

    public function toArray(): array
    {
        $elements = [];
        foreach ($this->elements as $el) {
            $elements[] = $el->toArray();
        }
        return [
            'type' => $this->type,
            'groupField' => $this->groupField,
            'groupLevel' => $this->groupLevel,
            'height' => $this->height,
            'printOnEveryPage' => $this->printOnEveryPage,
            'visible' => $this->visible,
            'keepTogether' => $this->keepTogether,
            'backgroundColor' => $this->backgroundColor,
            'border' => $this->border->toArray(),
            'elements' => $elements,
        ];
    }
}
