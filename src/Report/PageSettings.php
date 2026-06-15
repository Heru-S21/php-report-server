<?php

namespace ReportingEngine\Report;

class PageSettings
{
    public string $paperSize = 'A4';
    public string $orientation = 'portrait';
    public float $marginTop = 10;
    public float $marginBottom = 10;
    public float $marginLeft = 15;
    public float $marginRight = 15;
    public int $width = 0;
    public int $height = 0;

    public static function fromArray(array $data): self
    {
        $config = \ReportingEngine\Core\Database::getConfig();
        $dm = $config['default_margins'] ?? [];

        // Check app_settings for runtime overrides
        $dbMargins = [];
        try {
            $pdo = \ReportingEngine\Core\Database::getInstance();
            $stmt = $pdo->query("SELECT key, value FROM app_settings WHERE key LIKE 'default_margins_%'");
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $dbMargins[$row['key']] = (float)$row['value'];
            }
        } catch (\Exception) {
            // Fall back to config defaults
        }

        $p = new self();
        $p->paperSize = $data['paperSize'] ?? 'A4';
        $p->orientation = $data['orientation'] ?? 'portrait';
        $p->marginTop = (float)($data['marginTop'] ?? $dbMargins['default_margins_top'] ?? $dm['top'] ?? 10);
        $p->marginBottom = (float)($data['marginBottom'] ?? $dbMargins['default_margins_bottom'] ?? $dm['bottom'] ?? 10);
        $p->marginLeft = (float)($data['marginLeft'] ?? $dbMargins['default_margins_left'] ?? $dm['left'] ?? 15);
        $p->marginRight = (float)($data['marginRight'] ?? $dbMargins['default_margins_right'] ?? $dm['right'] ?? 15);
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
