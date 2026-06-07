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

    public function show(Request $request): Response
    {
        try {
            $id = (int)$request->getParam('id');
            $report = $this->repository->find($id);
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
            $id = $this->repository->create($request->body);
            return Response::json(['id' => $id], 201, 'Report created');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function update(Request $request): Response
    {
        try {
            $id = (int)$request->getParam('id');
            $exists = $this->repository->find($id);
            if (!$exists) {
                return Response::error('Report not found', 404);
            }
            $this->repository->update($id, $request->body);
            return Response::json(null, 200, 'Report updated');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function destroy(Request $request): Response
    {
        try {
            $id = (int)$request->getParam('id');
            $this->repository->delete($id);
            return Response::json(null, 200, 'Report deleted');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function duplicate(Request $request): Response
    {
        try {
            $id = (int)$request->getParam('id');
            $newId = $this->repository->duplicate($id);
            return Response::json(['id' => $newId], 201, 'Report duplicated');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }
}
