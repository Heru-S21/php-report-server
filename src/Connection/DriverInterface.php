<?php

namespace ReportingEngine\Connection;

use PDO;

interface DriverInterface
{
    public function connect(): PDO;
    public function testConnection(): bool;
    public function getTables(): array;
    public function getColumns(string $table): array;
    public function quoteIdentifier(string $name): string;
    public function getLimitSyntax(int $limit, int $offset): string;
    public function getDriverName(): string;
}
