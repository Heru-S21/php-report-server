<?php

namespace ReportingEngine\Report;

class BorderSide
{
    public bool $enabled = false;
    public int $width = 1;
    public string $style = 'solid';
    public string $color = '#000000';

    public static function fromArray(array $data): self
    {
        $side = new self();
        $side->enabled = $data['enabled'] ?? false;
        $side->width = $data['width'] ?? 1;
        $side->style = $data['style'] ?? 'solid';
        $side->color = $data['color'] ?? '#000000';
        return $side;
    }

    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'width' => $this->width,
            'style' => $this->style,
            'color' => $this->color,
        ];
    }
}

class BorderDefinition
{
    public BorderSide $top;
    public BorderSide $right;
    public BorderSide $bottom;
    public BorderSide $left;

    public function __construct()
    {
        $this->top = new BorderSide();
        $this->right = new BorderSide();
        $this->bottom = new BorderSide();
        $this->left = new BorderSide();
    }

    public static function none(): self
    {
        return new self();
    }

    public static function all(string $color, int $width, string $style): self
    {
        $b = new self();
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $b->$side->enabled = true;
            $b->$side->color = $color;
            $b->$side->width = $width;
            $b->$side->style = $style;
        }
        return $b;
    }

    public static function fromArray(?array $data): self
    {
        $b = new self();
        if (!$data) return $b;
        $b->top = BorderSide::fromArray($data['top'] ?? []);
        $b->right = BorderSide::fromArray($data['right'] ?? []);
        $b->bottom = BorderSide::fromArray($data['bottom'] ?? []);
        $b->left = BorderSide::fromArray($data['left'] ?? []);
        return $b;
    }

    public function toArray(): array
    {
        return [
            'top' => $this->top->toArray(),
            'right' => $this->right->toArray(),
            'bottom' => $this->bottom->toArray(),
            'left' => $this->left->toArray(),
        ];
    }

    public function toCssString(): string
    {
        $parts = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $s = $this->$side;
            if ($s->enabled) {
                $parts[] = "border-{$side}: {$s->width}px {$s->style} {$s->color};";
            }
        }
        return implode(' ', $parts);
    }

    public function toHtmlStyle(): string
    {
        return $this->toCssString();
    }
}
