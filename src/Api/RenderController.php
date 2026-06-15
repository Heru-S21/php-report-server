<?php

namespace ReportingEngine\Api;

use ReportingEngine\Core\Request;
use ReportingEngine\Core\Response;
use ReportingEngine\Core\Database;
use ReportingEngine\Report\ReportDefinition;
use ReportingEngine\Report\ReportRepository;
use ReportingEngine\Connection\ConnectionManager;
use ReportingEngine\Query\QueryRunner;
use ReportingEngine\Renderer\HtmlRenderer;
use ReportingEngine\Renderer\PdfRenderer;
use PDO;

class RenderController
{
    private ReportRepository $reportRepository;
    private ConnectionManager $connectionManager;

    public function __construct()
    {
        $this->reportRepository = new ReportRepository();
        $this->connectionManager = new ConnectionManager();
    }

    public function render(Request $request): Response
    {
        try {
            $idParam = $request->getParam('id');
            $format = $request->query['format'] ?? 'html';

            $report = is_numeric($idParam)
                ? $this->reportRepository->find((int)$idParam)
                : $this->reportRepository->findByGuid($idParam);
            if (!$report) {
                return Response::error('Report not found', 404);
            }

            $definition = $this->buildDefinition($report);
            $data = $this->fetchData($definition, $request);

            $params = $request->query;
            if (!empty($definition->fontMetrics)) {
                $params['_fontMetrics'] = $definition->fontMetrics;
            }
            $this->injectFontCache($params);

            if ($format === 'pdf') {
                $renderer = new PdfRenderer();
                $pdfContent = $renderer->render($definition, $data, $params);

                return new Response($pdfContent, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="report-' . $report['id'] . '.pdf"',
                    'Content-Length' => strlen($pdfContent),
                ]);
            }

            $renderer = new HtmlRenderer();
            $html = $renderer->render($definition, $data, $params);
            return new Response($html, 200, [
                'Content-Type' => 'text/html; charset=utf-8',
            ]);
        } catch (\Exception $e) {
            $sql = isset($definition) ? $definition->sqlQuery : '';
            $msg = $e->getMessage() . ($sql ? " | SQL: {$sql}" : '');
            return Response::error($msg, 500);
        }
    }

    public function preview(Request $request): Response
    {
        try {
            $definitionJson = $request->body['definition'] ?? $request->body['json'] ?? '';
            $format = $request->body['format'] ?? 'html';

            // Decode _fontMetrics if it arrived as a JSON string (form POST)
            $body = $request->body;
            $this->injectFontCache($body);
            if (isset($body['_fontMetrics']) && is_string($body['_fontMetrics'])) {
                $decoded = json_decode($body['_fontMetrics'], true);
                if (is_array($decoded)) {
                    $body['_fontMetrics'] = $decoded;
                } else {
                    unset($body['_fontMetrics']);
                }
            }

            if (is_string($definitionJson)) {
                $definition = ReportDefinition::fromJson($definitionJson);
            } elseif (is_array($definitionJson)) {
                $definition = ReportDefinition::fromArray($definitionJson);
            } else {
                return Response::error('Invalid definition', 422);
            }

            $data = $this->fetchData($definition, $request);

            if ($format === 'pdf') {
                $renderer = new PdfRenderer();
                $pdfContent = $renderer->render($definition, $data, $body);
                return new Response($pdfContent, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="preview.pdf"',
                ]);
            }

            $renderer = new HtmlRenderer();
            $html = $renderer->render($definition, $data, $body);
            return new Response($html, 200, [
                'Content-Type' => 'text/html; charset=utf-8',
            ]);
        } catch (\Exception $e) {
            $sql = isset($definition) ? $definition->sqlQuery : '';
            $msg = $e->getMessage() . ($sql ? " | SQL: {$sql}" : '');
            return Response::error($msg, 500);
        }
    }

    public function settings(Request $request): Response
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->query("SELECT * FROM app_settings");
            $settings = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['key'] === 'auth_password') continue;
                $settings[$row['key']] = $row['value'];
            }
            return Response::json($settings);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function updateSettings(Request $request): Response
    {
        try {
            $pdo = Database::getInstance();
            foreach ($request->body as $key => $value) {
                if ($key === 'auth_password') {
                    if ($value === '' || $value === null) continue;
                    $value = password_hash($value, PASSWORD_BCRYPT);
                    $key = 'auth_password';
                }
                $stmt = $pdo->prepare("INSERT OR REPLACE INTO app_settings (key, value) VALUES (?, ?)");
                $stmt->execute([$key, $value]);
            }
            return Response::json(null, 200, 'Settings updated');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    private function buildDefinition(array $report): ReportDefinition
    {
        $definitionData = $report['definition'];
        if (is_string($definitionData)) {
            $definitionData = json_decode($definitionData, true);
        }
        $definitionData['id'] = $report['id'];
        $definitionData['guid'] = $report['guid'] ?? Database::generateGuid();
        $definitionData['name'] = $report['name'];
        $definitionData['description'] = $report['description'];
        $definitionData['connectionId'] = (int)$report['connection_id'];
        return ReportDefinition::fromArray($definitionData);
    }

    private function fetchData(ReportDefinition $definition, Request $request): array
    {
        $sql = $definition->sqlQuery;
        if (empty($sql)) return [];

        $connId = $definition->connectionId;

        // Extract params from request query (GET params) and body (JSON/POST)
        $params = [];
        foreach (array_merge($request->query, $request->body) as $key => $value) {
            if (str_starts_with($key, 'param_')) {
                $paramName = substr($key, 6);
                $params[$paramName] = $value;
            }
        }

        if ($connId > 0) {
            $driver = $this->connectionManager->getDriver($connId);
            $runner = new QueryRunner($driver);
            $result = $runner->execute($sql, $params, 10000);

            // Convert rows to associative arrays
            $data = [];
            foreach ($result['rows'] as $i => $row) {
                $assocRow = [];
                foreach ($result['columns'] as $j => $col) {
                    $assocRow[$col['name']] = $row[$j] ?? null;
                }
                $assocRow['_rowno'] = $i + 1;
                $data[] = $assocRow;
            }
        } else {
            // Fallback to internal DB
            try {
                $pdo = Database::getInstance();
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($data as $i => &$row) {
                    $row['_rowno'] = $i + 1;
                }
                unset($row);
            } catch (\Exception $e) {
                error_log("fetchData fallback failed: " . $e->getMessage());
                return [];
            }
        }

        // Sort by group fields to respect group sortDirection
        $groups = $definition->groups;
        if (!empty($groups) && !empty($data)) {
            usort($groups, fn($a, $b) => $a->level <=> $b->level);
            usort($data, function (array $a, array $b) use ($groups) {
                foreach ($groups as $group) {
                    $field = $group->fieldName;
                    $dir = strtoupper($group->sortDirection) === 'DESC' ? -1 : 1;
                    $valA = $a[$field] ?? null;
                    $valB = $b[$field] ?? null;
                    if ($valA === null && $valB === null) continue;
                    if ($valA === null) return 1 * $dir;
                    if ($valB === null) return -1 * $dir;
                    $cmp = strnatcasecmp((string)$valA, (string)$valB);
                    if ($cmp !== 0) return $cmp * $dir;
                }
                return 0;
            });
            // Re-number row numbers after sorting
            $i = 1;
            foreach ($data as &$row) {
                $row['_rowno'] = $i++;
            }
            unset($row);
        }

        // Inject parameter values as pseudo-columns (:paramName) so they resolve as fields
        if (!empty($params) && !empty($data)) {
            foreach ($data as &$row) {
                foreach ($params as $name => $value) {
                    $key = str_starts_with($name, ':') ? $name : ':' . $name;
                    $row[$key] = $value;
                }
            }
            unset($row);
        }

        return $data;
    }

    private function injectFontCache(array &$params): void
    {
        $path = __DIR__ . '/../../data/fonts/cache.json';
        if (file_exists($path)) {
            $fonts = json_decode(file_get_contents($path), true);
            if (is_array($fonts)) {
                $params['_fonts'] = $fonts;
            }
        }
    }
}
