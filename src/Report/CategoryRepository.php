<?php

namespace ReportingEngine\Report;

use PDO;
use ReportingEngine\Core\Database;

class CategoryRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    public function all(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM report_categories ORDER BY name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM report_categories WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM report_categories WHERE name = ?");
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(string $name): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO report_categories (name) VALUES (?)");
        $stmt->execute([$name]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name): void
    {
        $stmt = $this->pdo->prepare("UPDATE report_categories SET name = ? WHERE id = ?");
        $stmt->execute([$name, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM report_categories WHERE id = ?");
        $stmt->execute([$id]);
    }
}
