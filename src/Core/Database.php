<?php

namespace ReportingEngine\Core;

use PDO;

class Database
{
    private static ?PDO $instance = null;
    private static array $config = [];

    public static function init(array $config): void
    {
        self::$config = $config;
    }

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $path = self::$config['sqlite_path'] ?? __DIR__ . '/../../data/reporting.sqlite';
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            self::$instance = new PDO('sqlite:' . $path);
            self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$instance->exec('PRAGMA foreign_keys = ON;');
            self::$instance->exec('PRAGMA journal_mode = WAL;');
            self::migrate(self::$instance);
        }
        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS connections (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT NOT NULL UNIQUE,
                driver      TEXT NOT NULL CHECK(driver IN ('sqlite','mysql','mssql','pgsql')),
                host        TEXT,
                port        INTEGER,
                database    TEXT NOT NULL,
                username    TEXT,
                password    TEXT,
                options     TEXT,
                created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS reports (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                name          TEXT NOT NULL,
                description   TEXT,
                connection_id INTEGER REFERENCES connections(id) ON DELETE SET NULL,
                definition    TEXT NOT NULL,
                guid          TEXT,
                created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS report_categories (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                name  TEXT NOT NULL UNIQUE
            );

            CREATE TABLE IF NOT EXISTS report_category_map (
                report_id   INTEGER REFERENCES reports(id) ON DELETE CASCADE,
                category_id INTEGER REFERENCES report_categories(id) ON DELETE CASCADE,
                PRIMARY KEY (report_id, category_id)
            );

            CREATE TABLE IF NOT EXISTS query_templates (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                name          TEXT NOT NULL,
                connection_id INTEGER REFERENCES connections(id) ON DELETE SET NULL,
                sql_text      TEXT NOT NULL,
                visual_json   TEXT,
                created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS app_settings (
                key   TEXT PRIMARY KEY,
                value TEXT
            );

            CREATE TABLE IF NOT EXISTS report_templates (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT NOT NULL,
                description TEXT,
                definition  TEXT NOT NULL,
                created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS report_images (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                filename      TEXT NOT NULL,
                original_name TEXT NOT NULL,
                mime_type     TEXT NOT NULL,
                file_size     INTEGER NOT NULL,
                width         INTEGER,
                height        INTEGER,
                guid          TEXT UNIQUE,
                created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // Add guid column to reports if not exists (no UNIQUE — SQLite ALTER TABLE doesn't support it)
        try {
            $pdo->exec("ALTER TABLE reports ADD COLUMN guid TEXT");
        } catch (\PDOException $e) {
            // Column already exists — ignore
        }

        // Add hash column to report_images
        try {
            $pdo->exec("ALTER TABLE report_images ADD COLUMN hash TEXT");
        } catch (\PDOException $e) {
            // Column already exists — ignore
        }
        // Backfill GUIDs for existing reports that don't have one
        try {
            $stmt = $pdo->query("SELECT id FROM reports WHERE guid IS NULL");
            $backfill = $pdo->prepare("UPDATE reports SET guid = ? WHERE id = ?");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $backfill->execute([self::generateGuid(), $row['id']]);
            }
        } catch (\PDOException $e) {
            // Column doesn't exist yet — skip backfill
        }

        $stmt = $pdo->query("SELECT COUNT(*) FROM app_settings WHERE key = 'app_key'");
        if ($stmt->fetchColumn() == 0) {
            $key = bin2hex(random_bytes(16));
            $pdo->prepare("INSERT OR IGNORE INTO app_settings VALUES ('app_key', ?)")->execute([$key]);
        }

        $defaults = [
            ['pdf_engine', 'mpdf'],
            ['date_format', 'Y-m-d'],
            ['number_format_decimals', '2'],
            ['number_format_dec_point', '.'],
            ['number_format_thousands_sep', ','],
        ];

        foreach ($defaults as [$key, $value]) {
            $pdo->prepare("INSERT OR IGNORE INTO app_settings (key, value) VALUES (?, ?)")->execute([$key, $value]);
        }

        // Seed report templates if table is empty
        $count = $pdo->query("SELECT COUNT(*) FROM report_templates")->fetchColumn();
        if ($count == 0) {
            self::seedTemplates($pdo);
        }
    }

    private static function seedTemplates(PDO $pdo): void
    {
        $stmt = $pdo->prepare("INSERT INTO report_templates (name, description, definition) VALUES (?, ?, ?)");

        // 1. Blank Report
        $stmt->execute(['Blank Report', 'Start with a clean, empty report layout', json_encode([
            'version' => '1.0',
            'name' => 'Untitled Report',
            'description' => '',
            'connectionId' => null,
            'page' => ['paperSize' => 'A4', 'orientation' => 'portrait', 'marginTop' => 20, 'marginBottom' => 20, 'marginLeft' => 15, 'marginRight' => 15],
            'query' => ['sql' => '', 'visualJson' => null, 'parameters' => []],
            'groups' => [],
            'bands' => [
                ['type' => 'page_header', 'height' => 30, 'printOnEveryPage' => true, 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'elements' => []],
                ['type' => 'report_header', 'height' => 20, 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'elements' => []],
                ['type' => 'column_header', 'height' => 16, 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'elements' => []],
                ['type' => 'detail', 'height' => 16, 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'elements' => []],
                ['type' => 'report_footer', 'height' => 22, 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'elements' => []],
                ['type' => 'page_footer', 'height' => 16, 'printOnEveryPage' => true, 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'elements' => []],
            ],
            'showGrid' => true,
            'snapToGrid' => true,
            'gridSize' => 2,
            'defaultStyle' => ['fontFamily' => 'Arial', 'fontSize' => 10, 'color' => '#000000', 'backgroundColor' => 'transparent'],
        ], JSON_UNESCAPED_UNICODE)]);

        // 2. Sales by Customer
        $stmt->execute(['Sales by Customer', 'Grouped sales report with column headers, group summary, and grand total', json_encode([
            'version' => '1.0',
            'name' => 'Sales by Customer',
            'description' => '',
            'connectionId' => null,
            'page' => ['paperSize' => 'A4', 'orientation' => 'portrait', 'marginTop' => 20, 'marginBottom' => 20, 'marginLeft' => 15, 'marginRight' => 15],
            'query' => ['sql' => "-- Write your query here\nSELECT c.name, p.product_name, oi.qty, oi.unit_price, (oi.qty * oi.unit_price) AS line_total\nFROM order_items oi\nJOIN orders o ON oi.order_id = o.id\nJOIN customers c ON o.customer_id = c.id\nJOIN products p ON oi.product_id = p.id\nORDER BY c.name, p.product_name", 'visualJson' => null, 'parameters' => []],
            'groups' => [
                ['id' => 'grp-001', 'fieldName' => 'name', 'level' => 0, 'sortDirection' => 'ASC', 'pageBreakBefore' => false, 'reprintHeaderOnNewPage' => true, 'showHeader' => true, 'showFooter' => true],
            ],
            'bands' => [
                ['type' => 'page_header', 'height' => 30, 'printOnEveryPage' => true, 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'elements' => [
                    ['id' => 'el-ph-1', 'type' => 'label', 'top' => 8, 'left' => 10, 'width' => 200, 'height' => 16, 'text' => 'Sales Report', 'fontFamily' => 'Arial', 'fontSize' => 14, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#1a1a2e', 'textAlign' => 'left', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                    ['id' => 'el-ph-2', 'type' => 'datetime', 'top' => 10, 'left' => 400, 'width' => 120, 'height' => 12, 'format' => 'Y-m-d', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => false, 'italic' => false, 'underline' => false, 'color' => '#666666', 'textAlign' => 'right', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                ]],
                ['type' => 'report_header', 'height' => 20, 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'elements' => []],
                ['type' => 'column_header', 'height' => 16, 'backgroundColor' => '#f8fafc', 'border' => ['top' => ['enabled' => true, 'width' => 1, 'style' => 'solid', 'color' => '#ccc'], 'right' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'bottom' => ['enabled' => true, 'width' => 1, 'style' => 'solid', 'color' => '#999'], 'left' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000']], 'elements' => [
                    ['id' => 'el-ch-1', 'type' => 'label', 'top' => 2, 'left' => 10, 'width' => 150, 'height' => 12, 'text' => 'Customer', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#000000', 'textAlign' => 'left', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                    ['id' => 'el-ch-2', 'type' => 'label', 'top' => 2, 'left' => 170, 'width' => 150, 'height' => 12, 'text' => 'Product', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#000000', 'textAlign' => 'left', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                    ['id' => 'el-ch-3', 'type' => 'label', 'top' => 2, 'left' => 330, 'width' => 60, 'height' => 12, 'text' => 'Qty', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#000000', 'textAlign' => 'right', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                    ['id' => 'el-ch-4', 'type' => 'label', 'top' => 2, 'left' => 400, 'width' => 80, 'height' => 12, 'text' => 'Unit Price', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#000000', 'textAlign' => 'right', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                    ['id' => 'el-ch-5', 'type' => 'label', 'top' => 2, 'left' => 490, 'width' => 90, 'height' => 12, 'text' => 'Line Total', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#000000', 'textAlign' => 'right', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                ]],
                ['type' => 'group_header', 'groupField' => 'name', 'groupLevel' => 0, 'height' => 18, 'backgroundColor' => '#fff8e1', 'border' => ['bottom' => ['enabled' => true, 'width' => 1, 'style' => 'dashed', 'color' => '#f59e0b'], 'top' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'right' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'left' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000']], 'elements' => [
                    ['id' => 'el-gh-1', 'type' => 'field', 'top' => 3, 'left' => 10, 'width' => 200, 'height' => 14, 'fieldName' => 'name', 'fontFamily' => 'Arial', 'fontSize' => 10, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#92400e', 'textAlign' => 'left', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                ]],
                ['type' => 'detail', 'height' => 16, 'backgroundColor' => 'transparent', 'border' => ['bottom' => ['enabled' => true, 'width' => 1, 'style' => 'solid', 'color' => '#eee'], 'top' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'right' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'left' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000']], 'elements' => [
                    ['id' => 'el-dt-1', 'type' => 'field', 'top' => 2, 'left' => 170, 'width' => 150, 'height' => 12, 'fieldName' => 'product_name', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => false, 'italic' => false, 'underline' => false, 'color' => '#000000', 'textAlign' => 'left', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                    ['id' => 'el-dt-2', 'type' => 'field', 'top' => 2, 'left' => 330, 'width' => 60, 'height' => 12, 'fieldName' => 'qty', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => false, 'italic' => false, 'underline' => false, 'color' => '#000000', 'textAlign' => 'right', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true, 'format' => '%d'],
                    ['id' => 'el-dt-3', 'type' => 'field', 'top' => 2, 'left' => 400, 'width' => 80, 'height' => 12, 'fieldName' => 'unit_price', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => false, 'italic' => false, 'underline' => false, 'color' => '#000000', 'textAlign' => 'right', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true, 'format' => '%.2f'],
                    ['id' => 'el-dt-4', 'type' => 'field', 'top' => 2, 'left' => 490, 'width' => 90, 'height' => 12, 'fieldName' => 'line_total', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => false, 'italic' => false, 'underline' => false, 'color' => '#000000', 'textAlign' => 'right', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true, 'format' => '%.2f'],
                ]],
                ['type' => 'group_footer', 'groupField' => 'name', 'groupLevel' => 0, 'height' => 18, 'backgroundColor' => '#fef9c3', 'border' => ['top' => ['enabled' => true, 'width' => 1, 'style' => 'solid', 'color' => '#f59e0b'], 'bottom' => ['enabled' => true, 'width' => 2, 'style' => 'double', 'color' => '#f59e0b'], 'right' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'left' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000']], 'elements' => [
                    ['id' => 'el-gf-1', 'type' => 'label', 'top' => 3, 'left' => 380, 'width' => 100, 'height' => 12, 'text' => 'Customer Total:', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#92400e', 'textAlign' => 'right', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                    ['id' => 'el-gf-2', 'type' => 'aggregate', 'top' => 3, 'left' => 490, 'width' => 90, 'height' => 12, 'fieldName' => 'line_total', 'aggregateFunc' => 'sum', 'aggregateScope' => 'group', 'format' => '%.2f', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#92400e', 'textAlign' => 'right', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                ]],
                ['type' => 'report_footer', 'height' => 22, 'backgroundColor' => '#e8f0fe', 'border' => ['top' => ['enabled' => true, 'width' => 2, 'style' => 'solid', 'color' => '#3f51b5'], 'bottom' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'right' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'left' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000']], 'elements' => [
                    ['id' => 'el-rf-1', 'type' => 'label', 'top' => 5, 'left' => 350, 'width' => 130, 'height' => 14, 'text' => 'Grand Total:', 'fontFamily' => 'Arial', 'fontSize' => 11, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#1a237e', 'textAlign' => 'right', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                    ['id' => 'el-rf-2', 'type' => 'aggregate', 'top' => 5, 'left' => 490, 'width' => 90, 'height' => 14, 'fieldName' => 'line_total', 'aggregateFunc' => 'sum', 'aggregateScope' => 'report', 'format' => '%.2f', 'fontFamily' => 'Arial', 'fontSize' => 11, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#1a237e', 'textAlign' => 'right', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                ]],
                ['type' => 'page_footer', 'height' => 16, 'printOnEveryPage' => true, 'backgroundColor' => '#f0f4f8', 'border' => ['top' => ['enabled' => true, 'width' => 1, 'style' => 'solid', 'color' => '#2196F3'], 'bottom' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'right' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'left' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000']], 'elements' => [
                    ['id' => 'el-pf-1', 'type' => 'pageno', 'top' => 3, 'left' => 10, 'width' => 100, 'height' => 10, 'text' => 'Page {page} of {pages}', 'fontFamily' => 'Arial', 'fontSize' => 8, 'bold' => false, 'italic' => false, 'underline' => false, 'color' => '#666666', 'textAlign' => 'left', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                ]],
            ],
            'showGrid' => true,
            'snapToGrid' => true,
            'gridSize' => 2,
            'defaultStyle' => ['fontFamily' => 'Arial', 'fontSize' => 10, 'color' => '#000000', 'backgroundColor' => 'transparent'],
        ], JSON_UNESCAPED_UNICODE)]);

        // 3. Simple List
        $stmt->execute(['Simple List', 'Clean tabular layout with header row and repeating detail rows', json_encode([
            'version' => '1.0',
            'name' => 'Simple List',
            'description' => '',
            'connectionId' => null,
            'page' => ['paperSize' => 'A4', 'orientation' => 'portrait', 'marginTop' => 20, 'marginBottom' => 20, 'marginLeft' => 15, 'marginRight' => 15],
            'query' => ['sql' => "-- Write your query here\nSELECT * FROM your_table LIMIT 100", 'visualJson' => null, 'parameters' => []],
            'groups' => [],
            'bands' => [
                ['type' => 'page_header', 'height' => 24, 'printOnEveryPage' => true, 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'elements' => [
                    ['id' => 'el-ls-ph-1', 'type' => 'label', 'top' => 6, 'left' => 10, 'width' => 200, 'height' => 14, 'text' => 'Report Title', 'fontFamily' => 'Arial', 'fontSize' => 12, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#1a1a2e', 'textAlign' => 'left', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                ]],
                ['type' => 'report_header', 'height' => 10, 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'elements' => []],
                ['type' => 'column_header', 'height' => 16, 'backgroundColor' => '#f1f5f9', 'border' => ['bottom' => ['enabled' => true, 'width' => 1, 'style' => 'solid', 'color' => '#94a3b8'], 'top' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'right' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'left' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000']], 'elements' => [
                    ['id' => 'el-ls-ch-1', 'type' => 'label', 'top' => 2, 'left' => 10, 'width' => 30, 'height' => 12, 'text' => '#', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#475569', 'textAlign' => 'center', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                    ['id' => 'el-ls-ch-2', 'type' => 'label', 'top' => 2, 'left' => 50, 'width' => 150, 'height' => 12, 'text' => 'Column 1', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#475569', 'textAlign' => 'left', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                    ['id' => 'el-ls-ch-3', 'type' => 'label', 'top' => 2, 'left' => 210, 'width' => 150, 'height' => 12, 'text' => 'Column 2', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#475569', 'textAlign' => 'left', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                    ['id' => 'el-ls-ch-4', 'type' => 'label', 'top' => 2, 'left' => 370, 'width' => 100, 'height' => 12, 'text' => 'Column 3', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#475569', 'textAlign' => 'right', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                ]],
                ['type' => 'detail', 'height' => 14, 'backgroundColor' => 'transparent', 'border' => ['bottom' => ['enabled' => true, 'width' => 1, 'style' => 'solid', 'color' => '#f1f5f9'], 'top' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'right' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'left' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000']], 'elements' => [
                    ['id' => 'el-ls-dt-1', 'type' => 'rowno', 'top' => 1, 'left' => 10, 'width' => 30, 'height' => 12, 'text' => '{ROWNO}', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => false, 'italic' => false, 'underline' => false, 'color' => '#94a3b8', 'textAlign' => 'center', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                    ['id' => 'el-ls-dt-2', 'type' => 'field', 'top' => 1, 'left' => 50, 'width' => 150, 'height' => 12, 'fieldName' => 'column_1', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => false, 'italic' => false, 'underline' => false, 'color' => '#000000', 'textAlign' => 'left', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                    ['id' => 'el-ls-dt-3', 'type' => 'field', 'top' => 1, 'left' => 210, 'width' => 150, 'height' => 12, 'fieldName' => 'column_2', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => false, 'italic' => false, 'underline' => false, 'color' => '#000000', 'textAlign' => 'left', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                    ['id' => 'el-ls-dt-4', 'type' => 'field', 'top' => 1, 'left' => 370, 'width' => 100, 'height' => 12, 'fieldName' => 'column_3', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => false, 'italic' => false, 'underline' => false, 'color' => '#000000', 'textAlign' => 'right', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                ]],
                ['type' => 'report_footer', 'height' => 10, 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'elements' => []],
                ['type' => 'page_footer', 'height' => 16, 'printOnEveryPage' => true, 'backgroundColor' => 'transparent', 'border' => ['top' => ['enabled' => true, 'width' => 1, 'style' => 'solid', 'color' => '#ccc'], 'bottom' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'right' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'left' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000']], 'elements' => [
                    ['id' => 'el-ls-pf-1', 'type' => 'pageno', 'top' => 3, 'left' => 10, 'width' => 100, 'height' => 10, 'text' => 'Page {page} of {pages}', 'fontFamily' => 'Arial', 'fontSize' => 8, 'bold' => false, 'italic' => false, 'underline' => false, 'color' => '#94a3b8', 'textAlign' => 'left', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                ]],
            ],
            'showGrid' => true,
            'snapToGrid' => true,
            'gridSize' => 2,
            'defaultStyle' => ['fontFamily' => 'Arial', 'fontSize' => 10, 'color' => '#000000', 'backgroundColor' => 'transparent'],
        ], JSON_UNESCAPED_UNICODE)]);

        // 4. Summary Report
        $stmt->execute(['Summary Report', 'Report with header, detail rows, and summary aggregates in the footer', json_encode([
            'version' => '1.0',
            'name' => 'Summary Report',
            'description' => '',
            'connectionId' => null,
            'page' => ['paperSize' => 'A4', 'orientation' => 'portrait', 'marginTop' => 20, 'marginBottom' => 20, 'marginLeft' => 15, 'marginRight' => 15],
            'query' => ['sql' => "-- Write your query here\nSELECT category, amount\nFROM your_table\nORDER BY category", 'visualJson' => null, 'parameters' => []],
            'groups' => [],
            'bands' => [
                ['type' => 'page_header', 'height' => 26, 'printOnEveryPage' => true, 'backgroundColor' => 'transparent', 'border' => ['bottom' => ['enabled' => true, 'width' => 2, 'style' => 'solid', 'color' => '#2563EB'], 'top' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'right' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'left' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000']], 'elements' => [
                    ['id' => 'el-sm-ph-1', 'type' => 'label', 'top' => 6, 'left' => 10, 'width' => 300, 'height' => 16, 'text' => 'Summary Report', 'fontFamily' => 'Arial', 'fontSize' => 14, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#1e3a5f', 'textAlign' => 'left', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                ]],
                ['type' => 'report_header', 'height' => 14, 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'elements' => [
                    ['id' => 'el-sm-rh-1', 'type' => 'label', 'top' => 2, 'left' => 10, 'width' => 80, 'height' => 12, 'text' => 'Prepared on:', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => false, 'italic' => true, 'underline' => false, 'color' => '#64748b', 'textAlign' => 'left', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                    ['id' => 'el-sm-rh-2', 'type' => 'datetime', 'top' => 2, 'left' => 90, 'width' => 100, 'height' => 12, 'format' => 'Y-m-d', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => false, 'italic' => true, 'underline' => false, 'color' => '#64748b', 'textAlign' => 'left', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                ]],
                ['type' => 'column_header', 'height' => 16, 'backgroundColor' => '#e2e8f0', 'border' => ['bottom' => ['enabled' => true, 'width' => 1, 'style' => 'solid', 'color' => '#94a3b8'], 'top' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'right' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'left' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000']], 'elements' => [
                    ['id' => 'el-sm-ch-1', 'type' => 'label', 'top' => 2, 'left' => 10, 'width' => 200, 'height' => 12, 'text' => 'Category', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#334155', 'textAlign' => 'left', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                    ['id' => 'el-sm-ch-2', 'type' => 'label', 'top' => 2, 'left' => 220, 'width' => 80, 'height' => 12, 'text' => 'Count', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#334155', 'textAlign' => 'right', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                    ['id' => 'el-sm-ch-3', 'type' => 'label', 'top' => 2, 'left' => 310, 'width' => 100, 'height' => 12, 'text' => 'Total', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#334155', 'textAlign' => 'right', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                ]],
                ['type' => 'detail', 'height' => 14, 'backgroundColor' => 'transparent', 'border' => ['bottom' => ['enabled' => true, 'width' => 1, 'style' => 'solid', 'color' => '#f1f5f9'], 'top' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'right' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'left' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000']], 'elements' => [
                    ['id' => 'el-sm-dt-1', 'type' => 'field', 'top' => 1, 'left' => 10, 'width' => 200, 'height' => 12, 'fieldName' => 'category', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => false, 'italic' => false, 'underline' => false, 'color' => '#000000', 'textAlign' => 'left', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                    ['id' => 'el-sm-dt-2', 'type' => 'field', 'top' => 1, 'left' => 220, 'width' => 80, 'height' => 12, 'fieldName' => 'count', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => false, 'italic' => false, 'underline' => false, 'color' => '#000000', 'textAlign' => 'right', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true, 'format' => '%d'],
                    ['id' => 'el-sm-dt-3', 'type' => 'field', 'top' => 1, 'left' => 310, 'width' => 100, 'height' => 12, 'fieldName' => 'amount', 'fontFamily' => 'Arial', 'fontSize' => 9, 'bold' => false, 'italic' => false, 'underline' => false, 'color' => '#000000', 'textAlign' => 'right', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true, 'format' => '%.2f'],
                ]],
                ['type' => 'report_footer', 'height' => 24, 'backgroundColor' => '#f8fafc', 'border' => ['top' => ['enabled' => true, 'width' => 2, 'style' => 'solid', 'color' => '#2563EB'], 'bottom' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'right' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000'], 'left' => ['enabled' => false, 'width' => 1, 'style' => 'solid', 'color' => '#000']], 'elements' => [
                    ['id' => 'el-sm-rf-1', 'type' => 'label', 'top' => 6, 'left' => 120, 'width' => 80, 'height' => 12, 'text' => 'Total Rows:', 'fontFamily' => 'Arial', 'fontSize' => 10, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#1e3a5f', 'textAlign' => 'right', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                    ['id' => 'el-sm-rf-2', 'type' => 'aggregate', 'top' => 6, 'left' => 210, 'width' => 80, 'height' => 12, 'fieldName' => 'count', 'aggregateFunc' => 'count', 'aggregateScope' => 'report', 'format' => '%d', 'fontFamily' => 'Arial', 'fontSize' => 10, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#1e3a5f', 'textAlign' => 'left', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                    ['id' => 'el-sm-rf-3', 'type' => 'aggregate', 'top' => 6, 'left' => 310, 'width' => 100, 'height' => 12, 'fieldName' => 'amount', 'aggregateFunc' => 'sum', 'aggregateScope' => 'report', 'format' => '%.2f', 'fontFamily' => 'Arial', 'fontSize' => 10, 'bold' => true, 'italic' => false, 'underline' => false, 'color' => '#1e3a5f', 'textAlign' => 'right', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                ]],
                ['type' => 'page_footer', 'height' => 16, 'printOnEveryPage' => true, 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'elements' => [
                    ['id' => 'el-sm-pf-1', 'type' => 'pageno', 'top' => 3, 'left' => 10, 'width' => 100, 'height' => 10, 'text' => 'Page {page} of {pages}', 'fontFamily' => 'Arial', 'fontSize' => 8, 'bold' => false, 'italic' => false, 'underline' => false, 'color' => '#94a3b8', 'textAlign' => 'left', 'verticalAlign' => 'middle', 'backgroundColor' => 'transparent', 'border' => new \stdClass, 'inheritStyle' => true],
                ]],
            ],
            'showGrid' => true,
            'snapToGrid' => true,
            'gridSize' => 2,
            'defaultStyle' => ['fontFamily' => 'Arial', 'fontSize' => 10, 'color' => '#000000', 'backgroundColor' => 'transparent'],
        ], JSON_UNESCAPED_UNICODE)]);
    }

    public static function getConfig(): array
    {
        return self::$config;
    }

    public static function generateGuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function getAppKey(): string
    {
        $pdo = self::getInstance();
        $stmt = $pdo->query("SELECT value FROM app_settings WHERE key = 'app_key'");
        return $stmt->fetchColumn() ?: '';
    }
}
