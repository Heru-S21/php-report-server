<?php

namespace ReportingEngine\Report;

use PDO;
use ReportingEngine\Core\Database;

class ReportRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    public function all(): array
    {
        $stmt = $this->pdo->query("
            SELECT r.*, c.name as connection_name
            FROM reports r
            LEFT JOIN connections c ON r.connection_id = c.id
            ORDER BY r.updated_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT r.*, c.name as connection_name
            FROM reports r
            LEFT JOIN connections c ON r.connection_id = c.id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$report) return null;

        // Decode definition
        if (is_string($report['definition'])) {
            $report['definition'] = json_decode($report['definition'], true);
        }

        return $report;
    }

    public function create(array $data): int
    {
        $definition = $data['definition'] ?? '{}';
        if (is_array($definition)) {
            $definition = json_encode($definition, JSON_UNESCAPED_UNICODE);
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO reports (name, description, connection_id, definition, created_at, updated_at)
            VALUES (?, ?, ?, ?, datetime('now'), datetime('now'))
        ");
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['connection_id'] ?? null,
            $definition,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $values = [];

        foreach (['name', 'description', 'connection_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (array_key_exists('definition', $data)) {
            $fields[] = "definition = ?";
            $def = $data['definition'];
            $values[] = is_array($def) ? json_encode($def, JSON_UNESCAPED_UNICODE) : $def;
        }

        if (empty($fields)) return;

        $fields[] = "updated_at = datetime('now')";
        $values[] = $id;

        $sql = "UPDATE reports SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM reports WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function duplicate(int $id): int
    {
        $original = $this->find($id);
        if (!$original) {
            throw new \RuntimeException("Report not found: {$id}");
        }

        $definition = is_string($original['definition'])
            ? $original['definition']
            : json_encode($original['definition'], JSON_UNESCAPED_UNICODE);

        $stmt = $this->pdo->prepare("
            INSERT INTO reports (name, description, connection_id, definition, created_at, updated_at)
            VALUES (?, ?, ?, ?, datetime('now'), datetime('now'))
        ");
        $stmt->execute([
            $original['name'] . ' (Copy)',
            $original['description'],
            $original['connection_id'],
            $definition,
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
