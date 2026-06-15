<?php

namespace ReportingEngine\Api;

use ReportingEngine\Connection\ConnectionManager;
use ReportingEngine\Core\Database;
use ReportingEngine\Core\Request;
use ReportingEngine\Core\Response;
use ReportingEngine\Report\ImageRepository;
use ReportingEngine\Report\ReportRepository;

class ReportController
{
    private ReportRepository $repository;

    public function __construct()
    {
        $this->repository = new ReportRepository();
    }

    public function index(Request $request): Response
    {
        try {
            $reports = $this->repository->all();
            return Response::json($reports);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    private function resolveReport(string $id): ?array
    {
        if (is_numeric($id)) {
            return $this->repository->find((int)$id);
        }
        return $this->repository->findByGuid($id);
    }

    public function show(Request $request): Response
    {
        try {
            $report = $this->resolveReport($request->getParam('id'));
            if (!$report) {
                return Response::error('Report not found', 404);
            }
            return Response::json($report);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function store(Request $request): Response
    {
        try {
            if (empty($request->body['name'])) {
                return Response::error('Report name is required', 422);
            }
            if ($this->repository->findByName($request->body['name'])) {
                return Response::error("A report named '{$request->body['name']}' already exists", 422);
            }
            $result = $this->repository->create($request->body);
            return Response::json($result, 201, 'Report created');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function update(Request $request): Response
    {
        try {
            $report = $this->resolveReport($request->getParam('id'));
            if (!$report) {
                return Response::error('Report not found', 404);
            }
            if (isset($request->body['name'])) {
                $existing = $this->repository->findByName($request->body['name']);
                if ($existing && (int)$existing['id'] !== (int)$report['id']) {
                    return Response::error("A report named '{$request->body['name']}' already exists", 422);
                }
            }
            $this->repository->update((int)$report['id'], $request->body);
            return Response::json(null, 200, 'Report updated');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function destroy(Request $request): Response
    {
        try {
            $report = $this->resolveReport($request->getParam('id'));
            if (!$report) {
                return Response::error('Report not found', 404);
            }
            $this->repository->delete((int)$report['id']);
            return Response::json(null, 200, 'Report deleted');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function duplicate(Request $request): Response
    {
        try {
            $report = $this->resolveReport($request->getParam('id'));
            if (!$report) {
                return Response::error('Report not found', 404);
            }
            $result = $this->repository->duplicate((int)$report['id']);
            return Response::json($result, 201, 'Report duplicated');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function export(Request $request): Response
    {
        try {
            $report = $this->resolveReport($request->getParam('id'));
            if (!$report) {
                return Response::error('Report not found', 404);
            }
            $definition = is_string($report['definition'])
                ? json_decode($report['definition'], true)
                : $report['definition'];

            // Embed local images
            $config = Database::getConfig();
            $storageDir = ($config['data_path'] ?? __DIR__ . '/../../data') . '/images';
            $embeddedImages = [];
            $bands = &$definition['bands'];
            if (is_array($bands)) {
                foreach ($bands as &$band) {
                    $elements = &$band['elements'];
                    if (!is_array($elements)) continue;
                    foreach ($elements as &$el) {
                        if (($el['type'] ?? '') === 'image' && !empty($el['imageUrl']) && preg_match('#/api/images/file/([a-f0-9-]+)#i', $el['imageUrl'], $m)) {
                            $guid = $m[1];
                            if (isset($embeddedImages[$guid])) continue;
                            $imgRepo = new ImageRepository();
                            $img = $imgRepo->findByGuid($guid);
                            if (!$img) continue;
                            $filePath = $storageDir . '/' . $img['filename'];
                            if (!file_exists($filePath)) continue;
                            $imgData = base64_encode(file_get_contents($filePath));
                            $embeddedImages[$guid] = [
                                'filename' => $img['filename'],
                                'original_name' => $img['original_name'],
                                'mime_type' => $img['mime_type'],
                                'file_size' => $img['file_size'],
                                'width' => $img['width'],
                                'height' => $img['height'],
                                'data' => $imgData,
                            ];
                        }
                    }
                    unset($el);
                }
                unset($band);
            }

            $exportData = [
                'version' => '1.0',
                'type' => 'report-export',
                'name' => $report['name'],
                'description' => $report['description'],
                'connection_name' => $report['connection_name'] ?? null,
                'definition' => $definition,
                '_embeddedImages' => $embeddedImages,
                'exported_at' => date('c'),
            ];
            return Response::json($exportData, 200, 'Report exported');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function import(Request $request): Response
    {
        try {
            $data = $request->body;
            if (empty($data)) {
                return Response::error('No import data provided', 422);
            }
            if (empty($data['name'])) {
                return Response::error('Report name is required', 422);
            }

            // Import embedded images into library
            $embeddedImages = $data['_embeddedImages'] ?? [];
            $guidMap = [];
            if (!empty($embeddedImages)) {
                $config = Database::getConfig();
                $storageDir = ($config['data_path'] ?? __DIR__ . '/../../data') . '/images';
                if (!is_dir($storageDir)) {
                    mkdir($storageDir, 0755, true);
                }
                $imgRepo = new ImageRepository();
                foreach ($embeddedImages as $guid => $imgData) {
                    $decoded = base64_decode($imgData['data'], true);
                    if ($decoded === false) continue;

                    // Deduplicate by content hash
                    $hash = hash('sha256', $decoded);
                    $existing = $imgRepo->findByHash($hash);
                    if ($existing) {
                        $guidMap[$guid] = $existing['guid'];
                        continue;
                    }

                    $existing = $imgRepo->findByGuid($guid);
                    if ($existing) continue;

                    $filePath = $storageDir . '/' . $imgData['filename'];
                    file_put_contents($filePath, $decoded);
                    chmod($filePath, 0644);
                    $imgRepo->create([
                        'filename' => $imgData['filename'],
                        'original_name' => $imgData['original_name'],
                        'mime_type' => $imgData['mime_type'],
                        'file_size' => $imgData['file_size'] ?? strlen($decoded),
                        'width' => $imgData['width'] ?? null,
                        'height' => $imgData['height'] ?? null,
                        'guid' => $guid,
                        'hash' => $hash,
                    ]);
                }
            }

            $definition = $data['definition'] ?? '{}';
            if (is_array($definition)) {
                $definition = json_encode($definition, JSON_UNESCAPED_UNICODE);
            }

            // Remap image URLs to deduplicated GUIDs
            if (!empty($guidMap)) {
                foreach ($guidMap as $oldGuid => $newGuid) {
                    $definition = str_replace(
                        "/api/images/file/{$oldGuid}",
                        "/api/images/file/{$newGuid}",
                        $definition
                    );
                }
            }

            // Ensure unique name
            $name = $data['name'];
            $existing = $this->repository->findByName($name);
            if ($existing) {
                $counter = 1;
                while ($this->repository->findByName($name . " ({$counter})")) {
                    $counter++;
                }
                $name = $name . " ({$counter})";
            }

            // Match connection by name if provided
            $connectionId = null;
            if (!empty($data['connection_name'])) {
                $connManager = new ConnectionManager();
                $conn = $connManager->findByName($data['connection_name']);
                if ($conn) {
                    $connectionId = $conn['id'];
                }
            }

            $reportData = [
                'name' => $name,
                'description' => $data['description'] ?? '',
                'connection_id' => $connectionId,
                'definition' => $definition,
            ];
            $result = $this->repository->create($reportData);
            return Response::json($result, 201, 'Report imported successfully');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }
}
