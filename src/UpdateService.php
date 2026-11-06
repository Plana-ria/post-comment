<?php
declare(strict_types=1);

namespace PostComment;

use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/**
 * Transaction-like filesystem deployment for verified portable releases.
 * Data and user configuration are never included in replacement targets.
 * Uses an application maintenance lock and preserves rollback copies.
 */
final class UpdateService
{
    public static function apply(
        string $root, string $webRoot, string $zipFile, string $signatureFile,
        string $publicKeyHex, string $databasePath, string $backupDir
    ): array {
        $root=realpath($root) ?: '';
        $webRoot=realpath($webRoot) ?: '';
        $storage=realpath(dirname($databasePath)) ?: '';
        if (!$root || !$webRoot || !$storage || !is_file($databasePath) || is_link($databasePath)) {
            throw new RuntimeException('アプリまたはデータベースの設置先が不正です。');
        }
        if (!is_dir($backupDir) && !mkdir($backupDir,0700,true)) {
            throw new RuntimeException('バックアップ領域を作成できません。');
        }
        if (is_link($backupDir) || !is_writable($backupDir)) {
            throw new RuntimeException('バックアップ領域に書き込めません。');
        }
        $current=ReleaseChecker::currentVersion($root);
        $package=SignedPackage::inspect($zipFile,$signatureFile,$publicKeyHex,$current);
        $id=bin2hex(random_bytes(10));
        $stage=$storage.'/update-stage-'.$id;
        $codeBackup=$backupDir.'/update-code-'.$id;
        $flag=$storage.'/maintenance.update';
        $updateLock=@fopen($storage.'/update.lock','c');
        if (!$updateLock || !flock($updateLock,LOCK_EX|LOCK_NB)) {
            if ($updateLock) fclose($updateLock);
            throw new RuntimeException('他の更新処理が進行中です。');
        }
        $maintenance=null;
        $changes=[];
        $backup=null;
        $switched=false;
        try {
            if (file_exists($flag)) {
                throw new RuntimeException('前回の更新保守フラグが残っています。管理者に連絡してください。');
            }
            if (file_put_contents($flag,$id,LOCK_EX)===false) {
                throw new RuntimeException('メンテナンス状態に切り替えられません。');
            }
            $maintenance=@fopen($storage.'/maintenance.lock','c');
            if (!$maintenance) throw new RuntimeException('排他ロックファイルを開けません。');
            $locked=false;
            // Let in-flight requests finish; new index.php requests see flag.
            for ($attempt=0;$attempt<100;$attempt++) {
                if (flock($maintenance,LOCK_EX|LOCK_NB)) {$locked=true;break;}
                usleep(100000);
            }
            if (!$locked) throw new RuntimeException('利用者の接続が終了しないため更新を中止しました。');
            if (!mkdir($stage,0700) || !mkdir($codeBackup,0700)) {
                throw new RuntimeException('更新作業用ディレクトリを作成できません。');
            }
            $zip=new ZipArchive();
            if ($zip->open($zipFile,ZipArchive::RDONLY)!==true) {
                throw new RuntimeException('ZIPを読み込めません。');
            }
            try {
                foreach ($package['entries'] as $entry=>$index) {
                    $target=$stage.'/'.$entry;
                    self::ensureDirectory(dirname($target));
                    $in=$zip->getStream($entry);
                    $out=@fopen($target,'xb');
                    if (!$in || !$out) throw new RuntimeException('ZIPの展開に失敗しました。');
                    try {
                        $bytes=stream_copy_to_stream($in,$out,16*1024*1024+1);
                        if ($bytes===false || $bytes>16*1024*1024) {
                            throw new RuntimeException('ZIP内のファイルが大きすぎます。');
                        }
                    } finally {
                        fclose($in);
                        fclose($out);
                    }
                }
            } finally {
                $zip->close();
            }
            $sourceDb=new PDO('sqlite:'.$databasePath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $backup=(new BackupService($sourceDb))->create($backupDir);
            $sourceDb=null;
            // Preflight paths before changing a single active file.
            foreach (array_keys($package['entries']) as $entry) {
                $target=self::target($entry,$root,$webRoot);
                if (is_link($target) || (file_exists($target) && !is_file($target))) {
                    throw new RuntimeException('書き換え対象に不正なファイルが見つかりました。');
                }
                if (!is_dir(dirname($target))) self::ensureDirectory(dirname($target));
                if (!is_writable(dirname($target)) || (is_file($target) && !is_readable($target))) {
                    throw new RuntimeException('更新先のファイル権限が不足しています。');
                }
            }
            foreach (array_keys($package['entries']) as $entry) {
                $target=self::target($entry,$root,$webRoot);
                $source=$stage.'/'.$entry;
                $saved=$codeBackup.'/'.$entry;
                $existed=is_file($target);
                if ($existed) {
                    self::ensureDirectory(dirname($saved));
                    if (!copy($target,$saved)) throw new RuntimeException('既存コードを退避できません。');
                }
                $temp=dirname($target).'/.pc-update-'.$id.'-'.bin2hex(random_bytes(4));
                if (!copy($source,$temp)) throw new RuntimeException('新しいコードを配置できません。');
                @chmod($temp,0644);
                if (!rename($temp,$target)) {
                    @unlink($temp);
                    throw new RuntimeException('コードの切替に失敗しました。');
                }
                $changes[]=['target'=>$target,'saved'=>$saved,'existed'=>$existed];
            }
            $switched=true;
            // Migration files have now been authenticated and installed.
            require_once $root.'/src/Migrator.php';
            $newDb=new PDO('sqlite:'.$databasePath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $newDb->exec('PRAGMA foreign_keys=ON');
            Migrator::migrate($newDb,$root.'/database/migrations');
            if ($newDb->query('PRAGMA integrity_check')->fetchColumn()!=='ok') {
                throw new RuntimeException('更新後DB整合性検証に失敗しました。');
            }
            $newDb=null;
            return [
                'version'=>$package['version'],
                'files'=>count($changes),
                'backup'=>$backup,
                'code_backup'=>$codeBackup,
            ];
        } catch (Throwable $e) {
            if ($changes !== []) {
                try {
                    foreach (array_reverse($changes) as $change) {
                        if ($change['existed']) {
                            $temp=$change['target'].'.pc-rollback-'.$id;
                            if (!copy($change['saved'],$temp) || !rename($temp,$change['target'])) {
                                throw new RuntimeException('コードの復旧に失敗しました。');
                            }
                        } elseif (is_file($change['target'])) {
                            if (!unlink($change['target'])) throw new RuntimeException('追加ファイルを復旧できません。');
                        }
                    }
                    if ($backup !== null && is_file($backup)) {
                        // On successful migration, restoring the old DB is mandatory.
                        $temp=$databasePath.'.pc-rollback-'.$id;
                        if (!copy($backup,$temp) || !rename($temp,$databasePath)) {
                            throw new RuntimeException('DBを元に戻せません。');
                        }
                    }
                } catch (Throwable $rollbackError) {
                    error_log('CRITICAL: PostComment update rollback failed: '.$rollbackError->getMessage());
                    throw new RuntimeException('更新・復旧が失敗しました。手動復旧が必要です。',0,$rollbackError);
                }
            }
            throw $e;
        } finally {
            if (is_dir($stage)) self::removeDirectory($stage);
            if (is_file($flag) && trim((string)@file_get_contents($flag))===$id) @unlink($flag);
            if ($maintenance) {
                flock($maintenance,LOCK_UN);
                fclose($maintenance);
            }
            flock($updateLock,LOCK_UN);
            fclose($updateLock);
        }
    }

    private static function target(string $path, string $root, string $webRoot): string
    {
        if (str_starts_with($path,'post-comment-private/')) {
            return $root.'/'.substr($path,strlen('post-comment-private/'));
        }
        if (str_starts_with($path,'public/')) {
            return $webRoot.'/'.substr($path,strlen('public/'));
        }
        throw new RuntimeException('更新対象外のパスです。');
    }

    private static function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            if (is_link($dir)) throw new RuntimeException('リンク先には配置できません。');
            return;
        }
        if (file_exists($dir)) throw new RuntimeException('不正なディレクトリです。');
        self::ensureDirectory(dirname($dir));
        if (!mkdir($dir,0700)) throw new RuntimeException('ディレクトリ作成に失敗しました。');
    }

    private static function removeDirectory(string $dir): void
    {
        $items=new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname());
            else @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
