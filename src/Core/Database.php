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
                connection_id INTEGER NOT NULL REFERENCES connections(id) ON DELETE RESTRICT,
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
        ");

        // Add guid column to reports if not exists (no UNIQUE — SQLite ALTER TABLE doesn't support it)
        try {
            $pdo->exec("ALTER TABLE reports ADD COLUMN guid TEXT");
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
