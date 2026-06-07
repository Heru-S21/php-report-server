<?php

namespace ReportingEngine\Api;

use ReportingEngine\Core\Request;
use ReportingEngine\Core\Response;
use ReportingEngine\Core\Database;
use ReportingEngine\Connection\ConnectionManager;
use ReportingEngine\Query\QueryRunner;
use ReportingEngine\Query\QueryParser;
use ReportingEngine\Query\VisualQueryBuilder;
use PDO;

class QueryController
{
    private ConnectionManager $connectionManager;
    private QueryParser $parser;

    public function __construct()
    {
        $this->connectionManager = new ConnectionManager();
        $this->parser = new QueryParser();
    }

    public function execute(Request $request): Response
    {
        try {
            $connectionId = (int)($request->body['connection_id'] ?? 0);
            $sql = $request->body['sql'] ?? '';
            $params = $request->body['params'] ?? [];
            $limit = (int)($request->body['limit'] ?? 50);

            if (empty($sql)) {
                return Response::error('SQL query is required', 422);
            }

            // For connection_id=0 or no connection, use internal SQLite
            if ($connectionId <= 0) {
                $pdo = Database::getInstance();
                $runner = new QueryRunner(new \ReportingEngine\Connection\SqliteDriver(['database' => ':memory:']));
                // Actually we can't use the in-memory approach, redirect to internal DB
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $columns = [];
                for ($i = 0; $i < $stmt->columnCount(); $i++) {
                    $colMeta = $stmt->getColumnMeta($i);
                    $columns[] = ['name' => $colMeta['name'] ?? "col_{$i}", 'type' => $colMeta['native_type'] ?? 'text'];
                }
                $rows = $stmt->fetchAll(PDO::FETCH_NUM);
                return Response::json([
                    'columns' => $columns,
                    'rows' => $rows,
                    'rowCount' => count($rows),
                    'executionMs' => 0,
                ]);
            }

            $driver = $this->connectionManager->getDriver($connectionId);
            $runner = new QueryRunner($driver);
            $result = $runner->execute($sql, $params, $limit);
            return Response::json($result);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function fields(Request $request): Response
    {
        try {
            $connectionId = (int)($request->body['connection_id'] ?? 0);
            $sql = $request->body['sql'] ?? '';

            if (empty($sql)) {
                return Response::error('SQL query is required', 422);
            }

            if ($connectionId <= 0) {
                $pdo = Database::getInstance();
                $stmt = $pdo->prepare($sql);
                $stmt->execute();
                $columns = [];
                for ($i = 0; $i < $stmt->columnCount(); $i++) {
                    $colMeta = $stmt->getColumnMeta($i);
                    $columns[] = ['name' => $colMeta['name'] ?? "col_{$i}", 'type' => $colMeta['native_type'] ?? 'text'];
                }
                return Response::json($columns);
            }

            $driver = $this->connectionManager->getDriver($connectionId);
            $runner = new QueryRunner($driver);
            $columns = $runner->getFields($sql);
            return Response::json($columns);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function build(Request $request): Response
    {
        try {
            $visualJson = $request->body['visualJson'] ?? [];
            $connectionId = (int)($request->body['connection_id'] ?? 0);

            if (empty($visualJson)) {
                return Response::error('Visual query JSON is required', 422);
            }

            if ($connectionId > 0) {
                $driver = $this->connectionManager->getDriver($connectionId);
            } else {
                $driver = new \ReportingEngine\Connection\SqliteDriver(['database' => ':memory:']);
            }

            $builder = new VisualQueryBuilder();
            $sql = $builder->buildSql($visualJson, $driver);
            return Response::json(['sql' => $sql]);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function templates(Request $request): Response
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->query("SELECT * FROM query_templates ORDER BY name");
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Response::json($templates);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function storeTemplate(Request $request): Response
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("
                INSERT INTO query_templates (name, connection_id, sql_text, visual_json, created_at)
                VALUES (?, ?, ?, ?, datetime('now'))
            ");
            $stmt->execute([
                $request->body['name'] ?? 'Untitled',
                $request->body['connection_id'] ?? null,
                $request->body['sql_text'] ?? '',
                isset($request->body['visual_json']) ? json_encode($request->body['visual_json']) : null,
            ]);
            return Response::json(['id' => (int)$pdo->lastInsertId()], 201, 'Template saved');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }
}
