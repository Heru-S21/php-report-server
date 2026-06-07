<?php

namespace ReportingEngine\Renderer;

use ReportingEngine\Report\ReportDefinition;

interface RendererInterface
{
    public function render(ReportDefinition $definition, array $data, array $params = []): string;
}
