<?php

namespace ReportingEngine\Connection;

use PDO;

class MysqlDriver implements DriverInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function connect(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config['host'] ?? 'localhost',
            $this->config['port'] ?? 3306,
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
        $stmt = $pdo->query("SHOW TABLES");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getColumns(string $table): array
    {
        $pdo = $this->connect();
        $stmt = $pdo->prepare("SHOW COLUMNS FROM " . $this->quoteIdentifier($table));
        $stmt->execute();
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[] = ['name' => $row['Field'], 'type' => $row['Type']];
        }
        return $columns;
    }

    public function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function getLimitSyntax(int $limit, int $offset): string
    {
        return "LIMIT {$limit} OFFSET {$offset}";
    }

    public function getDriverName(): string
    {
        return 'mysql';
    }
}
