<?php

namespace ReportingEngine\Report;

class ReportDefinition
{
    public int $id = 0;
    public string $name = '';
    public string $description = '';
    public int $connectionId = 0;
    public string $sqlQuery = '';
    public ?string $visualQueryJson = null;
    public PageSettings $pageSettings;
    public BandCollection $bands;
    public array $groups = [];
    public array $parameters = [];
    public array $fontMetrics = [];

    public function __construct()
    {
        $this->pageSettings = new PageSettings();
        $this->bands = new BandCollection();
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!$data) {
            throw new \InvalidArgumentException('Invalid report definition JSON');
        }
        return self::fromArray($data);
    }

    public static function fromArray(array $data): self
    {
        $def = new self();
        $def->id = (int)($data['id'] ?? 0);
        $def->name = $data['name'] ?? '';
        $def->description = $data['description'] ?? '';
        $def->connectionId = (int)($data['connectionId'] ?? 0);
        $def->sqlQuery = $data['query']['sql'] ?? $data['sqlQuery'] ?? '';
        $def->visualQueryJson = $data['query']['visualJson'] ?? $data['visualQueryJson'] ?? null;
        $def->pageSettings = PageSettings::fromArray($data['page'] ?? $data['pageSettings'] ?? []);

        $bands = $data['bands'] ?? [];
        $def->bands = new BandCollection($bands);

        if (isset($data['groups']) && is_array($data['groups'])) {
            foreach ($data['groups'] as $g) {
                $def->groups[] = GroupDefinition::fromArray($g);
            }
        }

        if (isset($data['parameters']) && is_array($data['parameters'])) {
            $def->parameters = $data['parameters'];
        }

        if (isset($data['fontMetrics']) && is_array($data['fontMetrics'])) {
            $def->fontMetrics = $data['fontMetrics'];
        }

        return $def;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public function toArray(): array
    {
        $groups = array_map(fn(GroupDefinition $g) => $g->toArray(), $this->groups);

        return [
            'version' => '1.0',
            'name' => $this->name,
            'description' => $this->description,
            'connectionId' => $this->connectionId,
            'page' => $this->pageSettings->toArray(),
            'query' => [
                'sql' => $this->sqlQuery,
                'visualJson' => $this->visualQueryJson,
                'parameters' => $this->parameters,
            ],
            'groups' => $groups,
            'bands' => $this->bands->toArray(),
            'fontMetrics' => $this->fontMetrics,
        ];
    }
}
