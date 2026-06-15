<?php

namespace ReportingEngine\Api;

use ReportingEngine\Core\Database;
use ReportingEngine\Core\Request;
use ReportingEngine\Core\Response;

class FontController
{
    private string $fontDir;

    public function __construct()
    {
        $config = Database::getConfig();
        $this->fontDir = ($config['data_path'] ?? __DIR__ . '/../../data') . '/fonts';
    }

    public function index(Request $request): Response
    {
        $cache = $this->loadCache();
        return Response::json($cache ?: []);
    }

    public function reload(Request $request): Response
    {
        try {
            $extensions = ['ttf', 'otf', 'woff', 'woff2'];
            $cache = [];

            if (!is_dir($this->fontDir)) {
                mkdir($this->fontDir, 0755, true);
            }

            $files = scandir($this->fontDir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || $file === 'cache.json') continue;
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (!in_array($ext, $extensions, true)) continue;

                $path = $this->fontDir . '/' . $file;
                try {
                    $font = \FontLib\Font::load($path);
                    $font->parse();
                    $cache[] = [
                        'filename' => $file,
                        'family'   => $font->getFontName() ?: pathinfo($file, PATHINFO_FILENAME),
                        'style'    => $font->getFontSubfamily() ?: 'Regular',
                        'weight'   => $font->getFontWeight() ?: 400,
                    ];
                } catch (\Exception $e) {
                    error_log("FontController: failed to parse {$file}: " . $e->getMessage());
                }
            }

            file_put_contents(
                $this->fontDir . '/cache.json',
                json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            return Response::json($cache, 200, 'Font cache reloaded');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function file(Request $request): Response
    {
        $filename = $request->getParam('filename');
        if (!$filename) {
            return Response::error('Filename is required', 422);
        }

        $path = $this->fontDir . '/' . basename($filename);
        if (!file_exists($path)) {
            return Response::error('Font file not found', 404);
        }

        $data = file_get_contents($path);
        if ($data === false) {
            return Response::error('Failed to read font file', 500);
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'ttf'   => 'font/ttf',
            'otf'   => 'font/otf',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            default => 'application/octet-stream',
        };

        return new Response($data, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }

    private function loadCache(): array
    {
        $path = $this->fontDir . '/cache.json';
        if (!file_exists($path)) return [];
        $data = file_get_contents($path);
        if ($data === false) return [];
        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : [];
    }
}
