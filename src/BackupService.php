<?php
declare(strict_types=1);

namespace PostComment;

use PDO;
use RuntimeException;

/** Backups are created using SQLite's consistency-preserving VACUUM INTO. */
final class BackupService
{
    public function __construct(private PDO $db) {}

    public function create(string $backupDir): string
    {
        if (!is_dir($backupDir) || !is_writable($backupDir) || is_link($backupDir)) {
            throw new RuntimeException('バックアップ保存先を確認してください。');
        }
        $path = rtrim($backupDir, '/').'/backup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(8)).'.sqlite';
        // Run VACUUM on a fresh connection: unrelated open PDO cursors in
        // this HTTP request must not make a consistent backup fail.
        $list = $this->db->query('PRAGMA database_list');
        $databases = $list->fetchAll(PDO::FETCH_ASSOC);
        $list->closeCursor();
        $main = array_values(array_filter($databases, static fn($row) => $row['name'] === 'main'));
        $dbPath = (string)($main[0]['file'] ?? '');
        if ($dbPath === '' || !is_file($dbPath) || is_link($dbPath)) {
            throw new RuntimeException('バックアップ元のSQLiteファイルが不正です。');
        }
        $source = new PDO('sqlite:'.$dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $statement = $source->prepare('VACUUM INTO ?');
        $statement->execute([$path]);
        $statement->closeCursor();
        $source = null;
        if (!is_file($path) || filesize($path) === 0) {
            @unlink($path);
            throw new RuntimeException('バックアップを作成できませんでした。');
        }
        $verify = new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $result = $verify->query('PRAGMA integrity_check')->fetchColumn();
        $foreignKeyFailure=$verify->query('PRAGMA foreign_key_check')->fetch(PDO::FETCH_ASSOC);
        $verify = null;
        if ($result !== 'ok' || $foreignKeyFailure !== false) {
            @unlink($path);
            throw new RuntimeException('バックアップの整合性を確認できませんでした。');
        }
        return $path;
    }

    /** Authenticated download: never expose a path or a filename from input. */
    public static function downloadAndRemove(string $path): never
    {
        if (!is_file($path)) throw new RuntimeException('Backup file is missing.');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="post-comment-backup-'.gmdate('Ymd-His').'.sqlite"');
        header('Content-Length: '.filesize($path));
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        @unlink($path);
        exit;
    }

    public static function validateImport(string $path): void
    {
        if (!is_file($path) || is_link($path) || filesize($path) === 0) {
            throw new RuntimeException('復元元ファイルが不正です。');
        }
        $verify = new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        if ($verify->query('PRAGMA integrity_check')->fetchColumn() !== 'ok'
            || $verify->query('PRAGMA foreign_key_check')->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new RuntimeException('SQLiteファイルまたは参照関係の整合性に問題があります。');
        }
        $tables = $verify->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['administrators','rooms','users','comments','form_versions','schema_migrations'] as $required) {
            if (!in_array($required,$tables,true)) {
                throw new RuntimeException('本システムのバックアップではありません。');
            }
        }
    }
}
