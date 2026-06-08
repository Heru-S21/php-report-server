<?php

namespace ReportingEngine\Connection;

use PDO;

class MssqlDriver implements DriverInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function connect(): PDO
    {
        $opts = $this->config['options'] ?? [];
        $trust = $this->config['trustServerCertificate']
            ?? $opts['trustServerCertificate']
            ?? false;
        $dsn = sprintf(
            'sqlsrv:Server=%s,%d;Database=%s%s',
            $this->config['host'] ?? 'localhost',
            $this->config['port'] ?? 1433,
            $this->config['database'],
            $trust ? ';TrustServerCertificate=1' : ''
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
        $stmt = $pdo->query("SELECT TABLE_SCHEMA + '.' + TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='BASE TABLE' ORDER BY TABLE_SCHEMA, TABLE_NAME");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getColumns(string $table): array
    {
        $parts = explode('.', $table, 2);
        $schema = count($parts) > 1 ? $parts[0] : null;
        $tableName = count($parts) > 1 ? $parts[1] : $parts[0];

        $pdo = $this->connect();
        if ($schema) {
            $stmt = $pdo->prepare("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
            $stmt->execute([$schema, $tableName]);
        } else {
            $stmt = $pdo->prepare("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
            $stmt->execute([$tableName]);
        }
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[] = ['name' => $row['COLUMN_NAME'], 'type' => $row['DATA_TYPE']];
        }
        return $columns;
    }

    public function quoteIdentifier(string $name): string
    {
        if (str_contains($name, '.')) {
            $parts = explode('.', $name);
            return implode('.', array_map(fn($p) => '[' . str_replace(']', ']]', $p) . ']', $parts));
        }
        return '[' . str_replace(']', ']]', $name) . ']';
    }

    public function getLimitSyntax(int $limit, int $offset): string
    {
        return "OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
    }

    public function getDriverName(): string
    {
        return 'mssql';
    }
}
