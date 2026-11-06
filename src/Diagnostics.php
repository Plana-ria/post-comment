<?php
declare(strict_types=1);

namespace PostComment;

use PDO;

/**
 * Read-only installation diagnostics. Deliberately hides absolute local paths,
 * configuration secrets, account identifiers and personal records.
 */
final class Diagnostics
{
    public static function check(array $config, PDO $db, string $appRoot, string $webRoot, array $server): array
    {
        $checks = [];
        $add = static function (string $key,string $label,bool $ok,string $help) use (&$checks): void {
            $checks[]=['key'=>$key,'label'=>$label,'ok'=>$ok,'help'=>$help];
        };
        $add('php','PHP 8.3以上',PHP_VERSION_ID>=80300,
            'ホスティングのPHPバージョンを8.3以降へ切り替えてください。');
        foreach (['pdo_sqlite','mbstring','openssl'] as $extension) {
            $add('extension_'.$extension,'PHP拡張：'.$extension,extension_loaded($extension),
                'サーバー管理画面で'.$extension.'を有効にしてください。');
        }
        $add('https','HTTPS接続',postCommentHttps($server),
            'HTTPSのURLでアクセスし、サーバー側でSSLを設定してください。');
        $root=realpath($appRoot);
        $documentRoot=realpath((string)($server['DOCUMENT_ROOT']??''));
        $add('private_root','設定・アプリ本体の非公開配置',
            $root!==false && $documentRoot!==false
                && $root!==$documentRoot
                && !str_starts_with($root,$documentRoot.DIRECTORY_SEPARATOR),
            '非公開のアプリ本体はWeb公開領域の外に置いてください。');
        $dbPath=(string)($config['storage']['database_path']??'');
        $dir=$dbPath!==''?dirname($dbPath):'';
        $add('db_private','SQLiteの配置',
            $dbPath!=='' && is_file($dbPath) && !is_link($dbPath)
                && $documentRoot!==false
                && !str_starts_with((string)realpath($dbPath),$documentRoot.DIRECTORY_SEPARATOR),
            'SQLiteファイルをWeb公開領域外に置いてください。');
        $add('storage','SQLite保存領域の書込権限',
            $dir!=='' && is_dir($dir) && is_writable($dir) && !is_link($dir),
            '非公開storageディレクトリをPHPから書込可能にしてください。');
        $backup=(string)($config['storage']['backup_path']??'');
        $add('backups','バックアップ保存先',
            $backup!=='' && is_dir($backup) && is_writable($backup) && !is_link($backup),
            '非公開バックアップ用ディレクトリを作成して、書込権限を確認してください。');
        try {
            $integrity=$db->query('PRAGMA quick_check')->fetchColumn()==='ok';
        } catch (\Throwable $e) {
            $integrity=false;
        }
        $add('integrity','SQLite簡易整合性', $integrity,
            '管理者がバックアップを取得し、エンジニアにDB整合性を確認してもらってください。');
        $lockOk=false;
        $lockPath=$dir.'/diagnostic.lock';
        if ($dir!=='' && is_dir($dir)) {
            $handle=@fopen($lockPath,'c');
            if ($handle!==false) {
                $lockOk=flock($handle,LOCK_EX|LOCK_NB);
                if ($lockOk) flock($handle,LOCK_UN);
                fclose($handle);
            }
        }
        $add('file_lock','ファイルロック（基本確認）',$lockOk,
            '保存先ファイルシステムのflock対応を確認してください。共有ネットワークストレージでは追加検証が必要です。');
        $add('zip','ZIP拡張',class_exists(\ZipArchive::class),
            '署名付きアプリ更新にはPHP zip拡張が必要です。');
        $add('sodium','署名検証（sodium）',extension_loaded('sodium'),
            '署名付きアプリ更新にはPHP sodium拡張が必要です。');
        $key=(string)($config['update']['public_key']??'');
        $add('signing_key','配布署名の公開鍵',preg_match('/^[a-f0-9]{64}$/iD',$key)===1,
            'エンジニアが配布元のEd25519公開鍵を非公開config.phpに設定してください。');
        return $checks;
    }
}
