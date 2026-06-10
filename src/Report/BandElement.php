<?php

namespace ReportingEngine\Report;

class BandElement
{
    public string $id;
    public string $type;
    public float $top;
    public float $left;
    public float $width;
    public float $height;
    public ?string $text = null;
    public ?string $fieldName = null;
    public ?string $aggregateFunc = null;
    public ?string $aggregateScope = null;
    public ?string $format = null;
    public ?string $imageUrl = null;
    public ?string $imageDisplay = null;
    public ?string $expression = null;
    public string $fontFamily = 'Arial';
    public int $fontSize = 10;
    public bool $bold = false;
    public bool $italic = false;
    public bool $underline = false;
    public string $color = '#000000';
    public string $textAlign = 'left';
    public string $verticalAlign = 'middle';
    public string $backgroundColor = 'transparent';
    public BorderDefinition $border;
    public bool $wordWrap = true;
    public ?string $conditionalExpression = null;
    public ?string $conditionalStyle = null;

    public function __construct()
    {
        $this->border = new BorderDefinition();
    }

    public static function fromArray(array $data): self
    {
        $el = new self();
        $el->id = $data['id'] ?? '';
        $el->type = $data['type'] ?? 'label';
        $el->top = (float)($data['top'] ?? 0);
        $el->left = (float)($data['left'] ?? 0);
        $el->width = (float)($data['width'] ?? 40);
        $el->height = (float)($data['height'] ?? 12);
        $el->text = $data['text'] ?? null;
        $el->fieldName = $data['fieldName'] ?? null;
        $el->aggregateFunc = $data['aggregateFunc'] ?? null;
        $el->aggregateScope = $data['aggregateScope'] ?? null;
        $el->format = $data['format'] ?? null;
        $el->imageUrl = $data['imageUrl'] ?? null;
        $el->imageDisplay = $data['imageDisplay'] ?? null;
        $el->expression = $data['expression'] ?? null;
        $el->fontFamily = $data['fontFamily'] ?? 'Arial';
        $el->fontSize = (int)($data['fontSize'] ?? 10);
        $el->bold = (bool)($data['bold'] ?? false);
        $el->italic = (bool)($data['italic'] ?? false);
        $el->underline = (bool)($data['underline'] ?? false);
        $el->color = $data['color'] ?? '#000000';
        $el->textAlign = $data['textAlign'] ?? 'left';
        $el->verticalAlign = $data['verticalAlign'] ?? 'middle';
        $el->backgroundColor = $data['backgroundColor'] ?? 'transparent';
        $el->border = BorderDefinition::fromArray($data['border'] ?? null);
        $el->wordWrap = (bool)($data['wordWrap'] ?? true);
        $el->conditionalExpression = $data['conditionalExpression'] ?? null;
        $el->conditionalStyle = $data['conditionalStyle'] ?? null;
        return $el;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'top' => $this->top,
            'left' => $this->left,
            'width' => $this->width,
            'height' => $this->height,
            'text' => $this->text,
            'fieldName' => $this->fieldName,
            'aggregateFunc' => $this->aggregateFunc,
            'aggregateScope' => $this->aggregateScope,
            'format' => $this->format,
            'imageUrl' => $this->imageUrl,
            'imageDisplay' => $this->imageDisplay,
            'expression' => $this->expression,
            'fontFamily' => $this->fontFamily,
            'fontSize' => $this->fontSize,
            'bold' => $this->bold,
            'italic' => $this->italic,
            'underline' => $this->underline,
            'color' => $this->color,
            'textAlign' => $this->textAlign,
            'verticalAlign' => $this->verticalAlign,
            'backgroundColor' => $this->backgroundColor,
            'border' => $this->border->toArray(),
            'wordWrap' => $this->wordWrap,
            'conditionalExpression' => $this->conditionalExpression,
            'conditionalStyle' => $this->conditionalStyle,
        ];
    }
}
