<?php

namespace ReportingEngine\Api;

use PDO;
use ReportingEngine\Core\Database;
use ReportingEngine\Core\Request;
use ReportingEngine\Core\Response;

class TemplateController
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    public function index(Request $request): Response
    {
        try {
            $stmt = $this->pdo->query("SELECT id, name, description, created_at, updated_at FROM report_templates ORDER BY name ASC");
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Response::json($templates);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function show(Request $request): Response
    {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM report_templates WHERE id = ?");
            $stmt->execute([$request->getParam('id')]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$template) {
                return Response::error('Template not found', 404);
            }
            return Response::json($template);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function store(Request $request): Response
    {
        try {
            if (empty($request->body['name'])) {
                return Response::error('Template name is required', 422);
            }
            $definition = $request->body['definition'] ?? '{}';
            if (is_array($definition)) {
                $definition = json_encode($definition, JSON_UNESCAPED_UNICODE);
            }
            $stmt = $this->pdo->prepare("
                INSERT INTO report_templates (name, description, definition, created_at, updated_at)
                VALUES (?, ?, ?, datetime('now'), datetime('now'))
            ");
            $stmt->execute([
                $request->body['name'],
                $request->body['description'] ?? '',
                $definition,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            return Response::json(['id' => $id], 201, 'Template created');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function update(Request $request): Response
    {
        try {
            $id = $request->getParam('id');
            $stmt = $this->pdo->prepare("SELECT id FROM report_templates WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                return Response::error('Template not found', 404);
            }

            $fields = [];
            $values = [];

            foreach (['name', 'description'] as $field) {
                if (array_key_exists($field, $request->body)) {
                    $fields[] = "{$field} = ?";
                    $values[] = $request->body[$field];
                }
            }

            if (array_key_exists('definition', $request->body)) {
                $fields[] = "definition = ?";
                $def = $request->body['definition'];
                $values[] = is_array($def) ? json_encode($def, JSON_UNESCAPED_UNICODE) : $def;
            }

            if (empty($fields)) {
                return Response::error('Nothing to update', 422);
            }

            $fields[] = "updated_at = datetime('now')";
            $values[] = $id;

            $sql = "UPDATE report_templates SET " . implode(', ', $fields) . " WHERE id = ?";
            $this->pdo->prepare($sql)->execute($values);

            return Response::json(null, 200, 'Template updated');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function destroy(Request $request): Response
    {
        try {
            $id = $request->getParam('id');
            $stmt = $this->pdo->prepare("DELETE FROM report_templates WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                return Response::error('Template not found', 404);
            }
            return Response::json(null, 200, 'Template deleted');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }
}
