<?php

namespace ReportingEngine\Query;

use PDO;
use ReportingEngine\Connection\DriverInterface;

class QueryRunner
{
    private DriverInterface $driver;
    private PDO $pdo;

    public function __construct(DriverInterface $driver)
    {
        $this->driver = $driver;
        $this->pdo = $driver->connect();
    }

    public function execute(string $sql, array $params = [], int $limit = 50): array
    {
        $startTime = microtime(true);

        // Apply limit
        $sql = $this->applyLimit($sql, $limit);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $columns = [];
        $colCount = $stmt->columnCount();
        for ($i = 0; $i < $colCount; $i++) {
            $colMeta = $stmt->getColumnMeta($i);
            $columns[] = [
                'name' => $colMeta['name'] ?? "col_{$i}",
                'type' => $colMeta['native_type'] ?? 'text',
            ];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        $executionMs = (int)((microtime(true) - $startTime) * 1000);

        return [
            'columns' => $columns,
            'rows' => $rows,
            'rowCount' => count($rows),
            'executionMs' => $executionMs,
        ];
    }

    public function getFields(string $sql): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        $columns = [];
        $colCount = $stmt->columnCount();
        for ($i = 0; $i < $colCount; $i++) {
            $colMeta = $stmt->getColumnMeta($i);
            $columns[] = [
                'name' => $colMeta['name'] ?? "col_{$i}",
                'type' => $colMeta['native_type'] ?? 'text',
            ];
        }

        return $columns;
    }

    public function extractParameters(string $sql): array
    {
        preg_match_all('/:(\w+)/', $sql, $matches);
        return array_unique($matches[1]);
    }

    private function applyLimit(string $sql, int $limit): string
    {
        // Remove any existing LIMIT clause
        $sql = preg_replace('/\s+LIMIT\s+\d+(?:\s+OFFSET\s+\d+)?/i', '', $sql);
        $sql = preg_replace('/\s+OFFSET\s+\d+\s+ROWS\s+FETCH\s+NEXT\s+\d+\s+ROWS\s+ONLY/i', '', $sql);

        $driverName = $this->driver->getDriverName();
        if ($driverName === 'mssql') {
            // Wrap for MSSQL
            $orderBy = '';
            if (preg_match('/\s+ORDER\s+BY\s+(.+)$/i', $sql, $m)) {
                $orderBy = $m[1];
                $sql = preg_replace('/\s+ORDER\s+BY\s+.+$/i', '', $sql);
            }
            $orderClause = $orderBy ? "ORDER BY {$orderBy}" : 'ORDER BY 1';
            $sql = "SELECT * FROM ({$sql}) AS _sub {$orderClause} " . $this->driver->getLimitSyntax($limit, 0);
        } else {
            $sql .= ' ' . $this->driver->getLimitSyntax($limit, 0);
        }

        return $sql;
    }
}
