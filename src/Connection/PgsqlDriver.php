<?php

namespace ReportingEngine\Connection;

use PDO;

class PgsqlDriver implements DriverInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function connect(): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $this->config['host'] ?? 'localhost',
            $this->config['port'] ?? 5432,
            $this->config['database']
        );
        $pdo = new PDO($dsn, $this->config['username'] ?? null, $this->config['password'] ?? null);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    public function testConnection(): bool
    {
        $pdo = $this->connect();
        $pdo->query('SELECT 1');
        return true;
    }

    public function getTables(): array
    {
        $pdo = $this->connect();
        $stmt = $pdo->query("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema') ORDER BY tablename");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getColumns(string $table): array
    {
        $pdo = $this->connect();
        $stmt = $pdo->prepare("
            SELECT column_name, data_type
            FROM information_schema.columns
            WHERE table_name = ?
            ORDER BY ordinal_position
        ");
        $stmt->execute([$table]);
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[] = ['name' => $row['column_name'], 'type' => $row['data_type']];
        }
        return $columns;
    }

    public function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function getLimitSyntax(int $limit, int $offset): string
    {
        return "LIMIT {$limit} OFFSET {$offset}";
    }

    public function getDriverName(): string
    {
        return 'pgsql';
    }
}
