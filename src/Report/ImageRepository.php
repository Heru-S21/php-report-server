<?php

namespace ReportingEngine\Report;

use PDO;
use ReportingEngine\Core\Database;

class ImageRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    public function all(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM report_images ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM report_images WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByGuid(string $guid): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM report_images WHERE guid = ?");
        $stmt->execute([$guid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO report_images (filename, original_name, mime_type, file_size, width, height, guid, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))
        ");
        $stmt->execute([
            $data['filename'],
            $data['original_name'],
            $data['mime_type'],
            $data['file_size'],
            $data['width'] ?? null,
            $data['height'] ?? null,
            $data['guid'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM report_images WHERE id = ?");
        $stmt->execute([$id]);
    }
}
