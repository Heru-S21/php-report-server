<?php

namespace ReportingEngine\Api;

use ReportingEngine\Connection\ConnectionManager;
use ReportingEngine\Core\Request;
use ReportingEngine\Core\Response;
use ReportingEngine\Report\ReportRepository;

class ReportController
{
    private ReportRepository $repository;

    public function __construct()
    {
        $this->repository = new ReportRepository();
    }

    public function index(Request $request): Response
    {
        try {
            $reports = $this->repository->all();
            return Response::json($reports);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    private function resolveReport(string $id): ?array
    {
        if (is_numeric($id)) {
            return $this->repository->find((int)$id);
        }
        return $this->repository->findByGuid($id);
    }

    public function show(Request $request): Response
    {
        try {
            $report = $this->resolveReport($request->getParam('id'));
            if (!$report) {
                return Response::error('Report not found', 404);
            }
            return Response::json($report);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function store(Request $request): Response
    {
        try {
            if (empty($request->body['name'])) {
                return Response::error('Report name is required', 422);
            }
            if ($this->repository->findByName($request->body['name'])) {
                return Response::error("A report named '{$request->body['name']}' already exists", 422);
            }
            $result = $this->repository->create($request->body);
            return Response::json($result, 201, 'Report created');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function update(Request $request): Response
    {
        try {
            $report = $this->resolveReport($request->getParam('id'));
            if (!$report) {
                return Response::error('Report not found', 404);
            }
            if (isset($request->body['name'])) {
                $existing = $this->repository->findByName($request->body['name']);
                if ($existing && (int)$existing['id'] !== (int)$report['id']) {
                    return Response::error("A report named '{$request->body['name']}' already exists", 422);
                }
            }
            $this->repository->update((int)$report['id'], $request->body);
            return Response::json(null, 200, 'Report updated');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function destroy(Request $request): Response
    {
        try {
            $report = $this->resolveReport($request->getParam('id'));
            if (!$report) {
                return Response::error('Report not found', 404);
            }
            $this->repository->delete((int)$report['id']);
            return Response::json(null, 200, 'Report deleted');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function duplicate(Request $request): Response
    {
        try {
            $report = $this->resolveReport($request->getParam('id'));
            if (!$report) {
                return Response::error('Report not found', 404);
            }
            $result = $this->repository->duplicate((int)$report['id']);
            return Response::json($result, 201, 'Report duplicated');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function export(Request $request): Response
    {
        try {
            $report = $this->resolveReport($request->getParam('id'));
            if (!$report) {
                return Response::error('Report not found', 404);
            }
            $definition = is_string($report['definition'])
                ? json_decode($report['definition'], true)
                : $report['definition'];
            $exportData = [
                'version' => '1.0',
                'type' => 'report-export',
                'name' => $report['name'],
                'description' => $report['description'],
                'connection_name' => $report['connection_name'] ?? null,
                'definition' => $definition,
                'exported_at' => date('c'),
            ];
            return Response::json($exportData, 200, 'Report exported');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function import(Request $request): Response
    {
        try {
            $data = $request->body;
            if (empty($data)) {
                return Response::error('No import data provided', 422);
            }
            if (empty($data['name'])) {
                return Response::error('Report name is required', 422);
            }
            $definition = $data['definition'] ?? '{}';
            if (is_array($definition)) {
                $definition = json_encode($definition, JSON_UNESCAPED_UNICODE);
            }

            // Ensure unique name
            $name = $data['name'];
            $existing = $this->repository->findByName($name);
            if ($existing) {
                $counter = 1;
                while ($this->repository->findByName($name . " ({$counter})")) {
                    $counter++;
                }
                $name = $name . " ({$counter})";
            }

            // Match connection by name if provided
            $connectionId = null;
            if (!empty($data['connection_name'])) {
                $connManager = new ConnectionManager();
                $conn = $connManager->findByName($data['connection_name']);
                if ($conn) {
                    $connectionId = $conn['id'];
                }
            }

            $reportData = [
                'name' => $name,
                'description' => $data['description'] ?? '',
                'connection_id' => $connectionId,
                'definition' => $definition,
            ];
            $result = $this->repository->create($reportData);
            return Response::json($result, 201, 'Report imported successfully');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }
}
