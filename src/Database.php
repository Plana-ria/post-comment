<?php
declare(strict_types=1);

namespace PostComment;

use PDO;
use RuntimeException;

final class Database
{
    public static function connect(array $config): PDO
    {
        $path = (string) ($config['storage']['database_path'] ?? '');
        if ($path === '' || $path === ':memory:') {
            throw new RuntimeException('SQLite database path is not configured.');
        }

        $parent = dirname($path);
        if (!is_dir($parent) || !is_writable($parent)) {
            throw new RuntimeException('SQLite storage directory is unavailable or not writable.');
        }
        if (is_link($path) || is_link($parent)) {
            throw new RuntimeException('SQLite storage cannot use symlinked paths.');
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        // Do not presume WAL is safe on shared/network filesystems.
        $pdo->exec('PRAGMA journal_mode = DELETE');
        return $pdo;
    }
}
