<?php

namespace ReportingEngine\Connection;

use PDO;

class SqliteDriver implements DriverInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function connect(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->config['database']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON;');
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
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getColumns(string $table): array
    {
        $pdo = $this->connect();
        $stmt = $pdo->prepare("PRAGMA table_info(" . $this->quoteIdentifier($table) . ")");
        $stmt->execute();
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[] = ['name' => $row['name'], 'type' => $row['type']];
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
        return 'sqlite';
    }
}
