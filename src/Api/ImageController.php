<?php

namespace ReportingEngine\Api;

use ReportingEngine\Core\Database;
use ReportingEngine\Core\Request;
use ReportingEngine\Core\Response;
use ReportingEngine\Report\ImageRepository;
use ReportingEngine\Report\ReportRepository;

class ImageController
{
    private ImageRepository $repository;
    private string $storageDir;
    private int $maxSize;

    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct()
    {
        $this->repository = new ImageRepository();
        $config = Database::getConfig();
        $this->storageDir = ($config['data_path'] ?? __DIR__ . '/../../data') . '/images';
        $this->maxSize = (int)($config['max_upload_size'] ?? 1048576);
        // Check for runtime override in app_settings (set via /settings page)
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("SELECT value FROM app_settings WHERE key = 'max_upload_size'");
            $stmt->execute();
            $dbVal = $stmt->fetchColumn();
            if ($dbVal !== false && $dbVal !== null) {
                $this->maxSize = (int)$dbVal;
            }
        } catch (\Exception $e) {
            // Ignore — fall back to config default
        }
    }

    public function index(Request $request): Response
    {
        try {
            $images = $this->repository->all();
            return Response::json($images);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function show(Request $request): Response
    {
        try {
            $id = (int)$request->getParam('id');
            $image = $this->repository->find($id);
            if (!$image) {
                return Response::error('Image not found', 404);
            }
            return Response::json($image);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function upload(Request $request): Response
    {
        try {
            $files = $request->files;
            if (empty($files['file'])) {
                return Response::error('No file uploaded', 422);
            }

            $file = $files['file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $messages = [
                    UPLOAD_ERR_INI_SIZE => 'File exceeds PHP upload max size',
                    UPLOAD_ERR_FORM_SIZE => 'File exceeds form max size',
                    UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                    UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload directory',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                ];
                return Response::error($messages[$file['error']] ?? 'Unknown upload error', 422);
            }

            if ($file['size'] > $this->maxSize) {
                $maxMb = $this->maxSize / 1048576;
                return Response::error("File exceeds maximum size of {$maxMb}MB", 422);
            }

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);
            if (!in_array($mimeType, self::ALLOWED_MIMES, true)) {
                return Response::error('Invalid file type. Allowed: JPEG, PNG, GIF, WebP', 422);
            }

            // Check for duplicate by hash
            $hash = hash_file('sha256', $file['tmp_name']);
            $existing = $this->repository->findByHash($hash);
            if ($existing) {
                return Response::json($existing, 200, 'Image already exists');
            }

            $dimensions = @getimagesize($file['tmp_name']);
            $width = $dimensions ? $dimensions[0] : null;
            $height = $dimensions ? $dimensions[1] : null;

            $ext = match ($mimeType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'bin',
            };

            $guid = Database::generateGuid();
            $filename = $guid . '.' . $ext;
            $destPath = $this->storageDir . '/' . $filename;

            if (!is_dir($this->storageDir)) {
                mkdir($this->storageDir, 0755, true);
            }

            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                return Response::error('Failed to store uploaded file', 500);
            }

            chmod($destPath, 0644);

            $imageId = $this->repository->create([
                'filename' => $filename,
                'original_name' => $file['name'],
                'mime_type' => $mimeType,
                'file_size' => $file['size'],
                'width' => $width,
                'height' => $height,
                'guid' => $guid,
                'hash' => $hash,
            ]);

            $created = $this->repository->find($imageId);
            return Response::json($created, 201, 'Image uploaded');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function destroy(Request $request): Response
    {
        try {
            $id = (int)$request->getParam('id');
            $image = $this->repository->find($id);
            if (!$image) {
                return Response::error('Image not found', 404);
            }

            // Check if image is used in any saved report
            $reportRepo = new ReportRepository();
            $usedBy = $reportRepo->findByImageGuid($image['guid']);
            if (!empty($usedBy)) {
                $names = array_map(fn($r) => "'{$r['name']}'", $usedBy);
                return Response::error(
                    'Cannot delete: image is used in report(s): ' . implode(', ', $names),
                    409
                );
            }

            $filePath = $this->storageDir . '/' . $image['filename'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            $this->repository->delete($id);
            return Response::json(null, 200, 'Image deleted');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function file(Request $request): Response
    {
        try {
            $guid = $request->getParam('guid');
            if (!$guid) {
                return Response::error('Image GUID is required', 422);
            }
            $image = $this->repository->findByGuid($guid);
            if (!$image) {
                return Response::error('Image not found', 404);
            }
            $filePath = $this->storageDir . '/' . $image['filename'];
            if (!file_exists($filePath)) {
                return Response::error('Image file not found on disk', 404);
            }
            $data = file_get_contents($filePath);
            if ($data === false) {
                return Response::error('Failed to read image file', 500);
            }
            return new Response($data, 200, [
                'Content-Type' => $image['mime_type'],
                'Content-Length' => (string)$image['file_size'],
                'Cache-Control' => 'public, max-age=31536000',
            ]);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }
}
