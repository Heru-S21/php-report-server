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

            if ($format === 'pdf') {
                $renderer = new PdfRenderer();
                $pdfContent = $renderer->render($definition, $data, $request->query);

                return new Response($pdfContent, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="report-' . $id . '.pdf"',
                    'Content-Length' => strlen($pdfContent),
                ]);
            }

            $renderer = new HtmlRenderer();
            $html = $renderer->render($definition, $data, $request->query);
            return new Response($html, 200, [
                'Content-Type' => 'text/html; charset=utf-8',
            ]);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function preview(Request $request): Response
    {
        try {
            $definitionJson = $request->body['definition'] ?? $request->body['json'] ?? '';
            $format = $request->body['format'] ?? 'html';

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
                $pdfContent = $renderer->render($definition, $data, $request->body);
                return new Response($pdfContent, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="preview.pdf"',
                ]);
            }

            $renderer = new HtmlRenderer();
            $html = $renderer->render($definition, $data, $request->body);
            return new Response($html, 200, [
                'Content-Type' => 'text/html; charset=utf-8',
            ]);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function settings(Request $request): Response
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->query("SELECT * FROM app_settings");
            $settings = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
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

        // Extract params from request query
        $params = [];
        foreach ($request->query as $key => $value) {
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
            return $data;
        }

        // Fallback to internal DB
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $i => &$row) {
            $row['_rowno'] = $i + 1;
        }
        return $rows;
    }
}
