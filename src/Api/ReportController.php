<?php

namespace ReportingEngine\Api;

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
}
