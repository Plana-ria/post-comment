<?php
declare(strict_types=1);

namespace PostComment;

use PDO;
use RuntimeException;

final class Migrator
{
    public static function migrate(PDO $pdo, string $directory): array
    {
        if (!is_dir($directory)) {
            throw new RuntimeException('Migration directory not found.');
        }

        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version TEXT PRIMARY KEY,
            checksum TEXT NOT NULL,
            applied_at TEXT NOT NULL
        )');

        $files = glob(rtrim($directory, '/') . '/*.sql');
        if ($files === false) {
            throw new RuntimeException('Could not list migration files.');
        }
        sort($files, SORT_STRING);
        $applied = [];
        $stmt = $pdo->query('SELECT version, checksum FROM schema_migrations');
        foreach ($stmt->fetchAll() as $row) {
            $applied[$row['version']] = $row['checksum'];
        }

        $result = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (!preg_match('/^[0-9]{3}_[a-z0-9_]+$/', $version)) {
                throw new RuntimeException('Invalid migration filename.');
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('Cannot read migration: ' . $version);
            }
            $checksum = hash('sha256', $sql);
            if (isset($applied[$version])) {
                if (!hash_equals($applied[$version], $checksum)) {
                    throw new RuntimeException('Applied migration changed: ' . $version);
                }
                continue;
            }

            // SQLite PDO::exec supports multiple statements. Migrations must not
            // include explicit BEGIN/COMMIT or transaction-breaking statements.
            $pdo->beginTransaction();
            try {
                $pdo->exec($sql);
                $insert = $pdo->prepare('INSERT INTO schema_migrations(version, checksum, applied_at)
                    VALUES (?, ?, ?)');
                $insert->execute([$version, $checksum, gmdate('Y-m-d\TH:i:s\Z')]);
                $pdo->commit();
                $result[] = $version;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
        return $result;
    }

    public static function pending(PDO $pdo, string $directory): bool
    {
        $files = glob(rtrim($directory, '/') . '/*.sql') ?: [];
        $existing = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='schema_migrations'")->fetchColumn();
        if (!$existing) {
            return count($files) > 0;
        }
        $applied = $pdo->query('SELECT version, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('Migration unreadable: ' . $version);
            }
            if (!isset($applied[$version]) || !hash_equals($applied[$version], hash('sha256', $sql))) {
                return true;
            }
        }
        return false;
    }
}
