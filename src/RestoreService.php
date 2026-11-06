<?php
declare(strict_types=1);

namespace PostComment;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Restore a verified SQLite backup with an exclusive maintenance lock.
 * All normal HTTP requests must hold LOCK_SH on the same lock file.
 */
final class RestoreService
{
    public function restore(string $databasePath, string $stagedPath, string $backupDir, string $lockPath): string
    {
        if (!is_file($stagedPath) || is_link($stagedPath)) {
            throw new RuntimeException('復元ファイルが見つかりません。');
        }
        if (!is_file($databasePath) || is_link($databasePath)) {
            throw new RuntimeException('現在のデータベースを確認できません。');
        }
        if (!is_dir($backupDir) || !is_writable($backupDir) || is_link($backupDir)) {
            throw new RuntimeException('バックアップ保存先に書き込めません。');
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false) throw new RuntimeException('メンテナンスロックを作成できません。');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('現在ほかの利用者がアクセスしています。少し経ってから再実行してください。');
        }
        try {
            BackupService::validateImport($stagedPath);

            $schema = new PDO('sqlite:'.$stagedPath, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $applied = $schema->query('SELECT version,checksum FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC);
            $schema = null;
            $local = glob(dirname(__DIR__).'/database/migrations/*.sql') ?: [];
            $known = [];
            foreach ($local as $file) {
                $known[basename($file,'.sql')]=hash_file('sha256',$file);
            }
            foreach ($applied as $migration) {
                if (!isset($known[$migration['version']])) {
                    throw new RuntimeException('このバックアップは新しいバージョンで作成されています。アプリを先に更新してください。');
                }
                if (!hash_equals($known[$migration['version']],$migration['checksum'])) {
                    throw new RuntimeException('DBの更新履歴がこのアプリと一致しません。');
                }
            }

            $db = new PDO('sqlite:'.$databasePath, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $existingBackup = (new BackupService($db))->create($backupDir);
            $db = null;

            // Stop instead of risking a split database when journal sidecars exist.
            foreach (['-wal','-shm','-journal'] as $suffix) {
                if (file_exists($databasePath.$suffix)) {
                    throw new RuntimeException('DBの処理が終了していません。時間をおいてやり直してください。');
                }
            }
            $previous = $backupDir.'/pre-restore-'.bin2hex(random_bytes(12)).'.sqlite';
            if (!rename($databasePath, $previous)) {
                throw new RuntimeException('現在のDBを退避できませんでした。');
            }
            if (!rename($stagedPath, $databasePath)) {
                // If activating the staged DB fails, immediately restore the old DB.
                if (!rename($previous, $databasePath)) {
                    throw new RuntimeException('DB切替に失敗しました。退避DBから手動復旧が必要です。');
                }
                throw new RuntimeException('DBの切替に失敗しました。元のDBに戻しました。');
            }
            @chmod($databasePath, 0600);
            @unlink($previous);
            return $existingBackup;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
