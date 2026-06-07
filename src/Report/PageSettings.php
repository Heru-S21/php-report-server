<?php

namespace ReportingEngine\Report;

class PageSettings
{
    public string $paperSize = 'A4';
    public string $orientation = 'portrait';
    public float $marginTop = 20;
    public float $marginBottom = 20;
    public float $marginLeft = 15;
    public float $marginRight = 15;
    public int $width = 0;
    public int $height = 0;

    public static function fromArray(array $data): self
    {
        $p = new self();
        $p->paperSize = $data['paperSize'] ?? 'A4';
        $p->orientation = $data['orientation'] ?? 'portrait';
        $p->marginTop = (float)($data['marginTop'] ?? 20);
        $p->marginBottom = (float)($data['marginBottom'] ?? 20);
        $p->marginLeft = (float)($data['marginLeft'] ?? 15);
        $p->marginRight = (float)($data['marginRight'] ?? 15);
        $p->width = (int)($data['width'] ?? 0);
        $p->height = (int)($data['height'] ?? 0);
        return $p;
    }

    public function toArray(): array
    {
        return [
            'paperSize' => $this->paperSize,
            'orientation' => $this->orientation,
            'marginTop' => $this->marginTop,
            'marginBottom' => $this->marginBottom,
            'marginLeft' => $this->marginLeft,
            'marginRight' => $this->marginRight,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }

    public function getPaperWidthMm(): float
    {
        return match ($this->paperSize) {
            'A4' => 210,
            'Letter' => 215.9,
            'Legal' => 215.9,
            default => 210,
        };
    }

    public function getPaperHeightMm(): float
    {
        return match ($this->paperSize) {
            'A4' => 297,
            'Letter' => 279.4,
            'Legal' => 355.6,
            default => 297,
        };
    }
}
