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

    public function all(?int $categoryId = null, ?string $name = null): array
    {
        $sql = "
            SELECT r.*, c.name as connection_name,
                (SELECT rc.name FROM report_category_map rcm
                 JOIN report_categories rc ON rcm.category_id = rc.id
                 WHERE rcm.report_id = r.id LIMIT 1) AS category_name,
                (SELECT rcm.category_id FROM report_category_map rcm
                 WHERE rcm.report_id = r.id LIMIT 1) AS category_id
            FROM reports r
            LEFT JOIN connections c ON r.connection_id = c.id
        ";
        $params = [];
        $conditions = [];
        if ($categoryId !== null) {
            $conditions[] = "EXISTS (SELECT 1 FROM report_category_map WHERE report_id = r.id AND category_id = ?)";
            $params[] = $categoryId;
        }
        if ($name !== null && $name !== '') {
            $conditions[] = "r.name LIKE ?";
            $params[] = '%' . $name . '%';
        }
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        $sql .= " ORDER BY r.updated_at DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT r.*, c.name as connection_name,
                (SELECT rc.name FROM report_category_map rcm
                 JOIN report_categories rc ON rcm.category_id = rc.id
                 WHERE rcm.report_id = r.id LIMIT 1) AS category_name,
                (SELECT rcm.category_id FROM report_category_map rcm
                 WHERE rcm.report_id = r.id LIMIT 1) AS category_id
            FROM reports r
            LEFT JOIN connections c ON r.connection_id = c.id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$report) return null;

        return $report;
    }

    public function findByGuid(string $guid): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT r.*, c.name as connection_name,
                (SELECT rc.name FROM report_category_map rcm
                 JOIN report_categories rc ON rcm.category_id = rc.id
                 WHERE rcm.report_id = r.id LIMIT 1) AS category_name,
                (SELECT rcm.category_id FROM report_category_map rcm
                 WHERE rcm.report_id = r.id LIMIT 1) AS category_id
            FROM reports r
            LEFT JOIN connections c ON r.connection_id = c.id
            WHERE r.guid = ?
        ");
        $stmt->execute([$guid]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$report) return null;

        return $report;
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT r.*, c.name as connection_name,
                (SELECT rc.name FROM report_category_map rcm
                 JOIN report_categories rc ON rcm.category_id = rc.id
                 WHERE rcm.report_id = r.id LIMIT 1) AS category_name,
                (SELECT rcm.category_id FROM report_category_map rcm
                 WHERE rcm.report_id = r.id LIMIT 1) AS category_id
            FROM reports r
            LEFT JOIN connections c ON r.connection_id = c.id
            WHERE r.name = ?
        ");
        $stmt->execute([$name]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$report) return null;

        return $report;
    }

    public function create(array $data): array
    {
        $definition = $data['definition'] ?? '{}';
        if (is_array($definition)) {
            $definition = json_encode($definition, JSON_UNESCAPED_UNICODE);
        }

        $guid = Database::generateGuid();

        $stmt = $this->pdo->prepare("
            INSERT INTO reports (name, description, connection_id, definition, guid, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, datetime('now'), datetime('now'))
        ");
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['connection_id'] ?? null,
            $definition,
            $guid,
        ]);
        $reportId = (int) $this->pdo->lastInsertId();

        if (!empty($data['category_id'])) {
            $this->setCategory($reportId, (int) $data['category_id']);
        }

        return ['id' => $reportId, 'guid' => $guid];
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

        if (!empty($fields)) {
            $fields[] = "updated_at = datetime('now')";
            $values[] = $id;
            $sql = "UPDATE reports SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($values);
        }

        if (array_key_exists('category_id', $data)) {
            if (!empty($data['category_id'])) {
                $this->setCategory($id, (int) $data['category_id']);
            } else {
                $this->removeCategory($id);
            }
        }
    }

    public function setCategory(int $reportId, int $categoryId): void
    {
        // Single-category enforcement: delete existing, then insert
        // Wrapped in transaction to prevent data loss if INSERT fails
        $this->pdo->beginTransaction();
        $this->pdo->prepare("DELETE FROM report_category_map WHERE report_id = ?")->execute([$reportId]);
        $stmt = $this->pdo->prepare("INSERT INTO report_category_map (report_id, category_id) VALUES (?, ?)");
        $stmt->execute([$reportId, $categoryId]);
        $this->pdo->commit();
    }

    public function removeCategory(int $reportId): void
    {
        $this->pdo->prepare("DELETE FROM report_category_map WHERE report_id = ?")->execute([$reportId]);
    }

    public function findByImageGuid(string $guid): array
    {
        $stmt = $this->pdo->prepare("SELECT id, name, definition FROM reports WHERE definition LIKE ?");
        $stmt->execute(['%' . $guid . '%']);
        $reports = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $def = is_string($row['definition']) ? json_decode($row['definition'], true) : $row['definition'];
            if ($def && $this->definitionHasImage($def, $guid)) {
                $reports[] = ['id' => $row['id'], 'name' => $row['name']];
            }
        }
        return $reports;
    }

    private function definitionHasImage(array $def, string $guid): bool
    {
        $bands = $def['bands'] ?? [];
        foreach ($bands as $band) {
            $elements = $band['elements'] ?? [];
            foreach ($elements as $el) {
                if (($el['type'] ?? '') === 'image' && !empty($el['imageUrl']) && str_contains($el['imageUrl'], $guid)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM reports WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function duplicate(int $id): array
    {
        $original = $this->find($id);
        if (!$original) {
            throw new \RuntimeException("Report not found: {$id}");
        }

        $definition = is_string($original['definition'])
            ? $original['definition']
            : json_encode($original['definition'], JSON_UNESCAPED_UNICODE);

        $guid = Database::generateGuid();

        $stmt = $this->pdo->prepare("
            INSERT INTO reports (name, description, connection_id, definition, guid, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, datetime('now'), datetime('now'))
        ");
        $stmt->execute([
            $original['name'] . ' (Copy)',
            $original['description'],
            $original['connection_id'],
            $definition,
            $guid,
        ]);
        $newId = (int) $this->pdo->lastInsertId();

        // Copy category if set
        if (!empty($original['category_id'])) {
            $this->setCategory($newId, (int) $original['category_id']);
        }

        return ['id' => $newId, 'guid' => $guid];
    }
}
