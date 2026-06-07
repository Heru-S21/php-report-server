<?php

namespace ReportingEngine\Connection;

use PDO;
use ReportingEngine\Core\Database;

class ConnectionManager
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    public function all(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM connections ORDER BY name");
        $connections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($connections as &$conn) {
            unset($conn['password']);
            if ($conn['options']) {
                $conn['options'] = json_decode($conn['options'], true);
            }
        }
        return $connections;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM connections WHERE id = ?");
        $stmt->execute([$id]);
        $conn = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$conn) return null;
        if ($conn['password']) {
            $conn['password'] = $this->decrypt($conn['password']);
        }
        if ($conn['options']) {
            $conn['options'] = json_decode($conn['options'], true);
        }
        return $conn;
    }

    public function create(array $data): int
    {
        $password = null;
        if (!empty($data['password'])) {
            $password = $this->encrypt($data['password']);
        }
        $options = null;
        if (!empty($data['options'])) {
            $options = is_string($data['options']) ? $data['options'] : json_encode($data['options']);
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO connections (name, driver, host, port, database, username, password, options, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
        ");
        $stmt->execute([
            $data['name'],
            $data['driver'],
            $data['host'] ?? null,
            $data['port'] ?? null,
            $data['database'],
            $data['username'] ?? null,
            $password,
            $options,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $values = [];

        foreach (['name', 'driver', 'host', 'port', 'database', 'username'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (array_key_exists('password', $data)) {
            if (!empty($data['password'])) {
                $fields[] = "password = ?";
                $values[] = $this->encrypt($data['password']);
            }
        }

        if (array_key_exists('options', $data)) {
            $fields[] = "options = ?";
            $values[] = is_string($data['options']) ? $data['options'] : json_encode($data['options']);
        }

        if (empty($fields)) return;

        $fields[] = "updated_at = datetime('now')";
        $values[] = $id;

        $sql = "UPDATE connections SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM connections WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function getDriver(int $id): DriverInterface
    {
        $conn = $this->find($id);
        if (!$conn) {
            throw new \RuntimeException("Connection not found: {$id}");
        }
        return $this->createDriver($conn);
    }

    public function getDriverFromConfig(array $config): DriverInterface
    {
        return $this->createDriver($config);
    }

    public function testById(int $id): array
    {
        try {
            $driver = $this->getDriver($id);
            $driver->testConnection();
            return ['ok' => true, 'message' => 'Connection successful'];
        } catch (\Exception $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function testByConfig(array $config): array
    {
        try {
            $driver = $this->createDriver($config);
            $driver->testConnection();
            return ['ok' => true, 'message' => 'Connection successful'];
        } catch (\Exception $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function createDriver(array $config): DriverInterface
    {
        return match ($config['driver']) {
            'sqlite' => new SqliteDriver($config),
            'mysql' => new MysqlDriver($config),
            'mssql' => new MssqlDriver($config),
            'pgsql' => new PgsqlDriver($config),
            default => throw new \InvalidArgumentException("Unsupported driver: {$config['driver']}"),
        };
    }

    private function encrypt(string $plaintext): string
    {
        $key = Database::getAppKey();
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    private function decrypt(string $ciphertext): string
    {
        $key = Database::getAppKey();
        $data = base64_decode($ciphertext);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    }
}
