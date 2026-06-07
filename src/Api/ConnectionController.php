<?php

namespace ReportingEngine\Api;

use ReportingEngine\Core\Request;
use ReportingEngine\Core\Response;
use ReportingEngine\Connection\ConnectionManager;

class ConnectionController
{
    private ConnectionManager $manager;

    public function __construct()
    {
        $this->manager = new ConnectionManager();
    }

    public function index(Request $request): Response
    {
        try {
            $connections = $this->manager->all();
            return Response::json($connections);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function show(Request $request): Response
    {
        try {
            $id = (int)$request->getParam('id');
            $connection = $this->manager->find($id);
            if (!$connection) {
                return Response::error('Connection not found', 404);
            }
            return Response::json($connection);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function store(Request $request): Response
    {
        try {
            $data = $request->body;
            $required = ['name', 'driver', 'database'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return Response::error("Missing required field: {$field}", 422);
                }
            }
            $id = $this->manager->create($data);
            return Response::json(['id' => $id], 201, 'Connection created');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function update(Request $request): Response
    {
        try {
            $id = (int)$request->getParam('id');
            $exists = $this->manager->find($id);
            if (!$exists) {
                return Response::error('Connection not found', 404);
            }
            $this->manager->update($id, $request->body);
            return Response::json(null, 200, 'Connection updated');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function destroy(Request $request): Response
    {
        try {
            $id = (int)$request->getParam('id');
            $this->manager->delete($id);
            return Response::json(null, 200, 'Connection deleted');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function test(Request $request): Response
    {
        try {
            $id = (int)$request->getParam('id');
            if ($id > 0) {
                $result = $this->manager->testById($id);
            } else {
                $result = $this->manager->testByConfig($request->body);
            }
            return Response::json(
                ['ok' => $result['ok']],
                $result['ok'] ? 200 : 400,
                $result['message']
            );
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function tables(Request $request): Response
    {
        try {
            $id = (int)$request->getParam('id');
            $driver = $this->manager->getDriver($id);
            $tables = $driver->getTables();
            return Response::json($tables);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function columns(Request $request): Response
    {
        try {
            $id = (int)$request->getParam('id');
            $table = $request->getParam('table');
            $driver = $this->manager->getDriver($id);
            $columns = $driver->getColumns($table);
            return Response::json($columns);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }
}
