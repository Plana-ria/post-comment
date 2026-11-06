<?php
declare(strict_types=1);

/**
 * Isolated recovery entrypoint. Does not load the main router or keep an
 * active SQLite connection while replacing the database.
 */
require dirname(__DIR__).'/src/BackupService.php';
require dirname(__DIR__).'/src/RestoreService.php';

use PostComment\RestoreService;

function failRestore(string $message, int $status=400): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="ja"><meta charset="utf-8"><title>復元エラー</title><h1>復元できませんでした</h1><p>'
        .htmlspecialchars($message,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')
        .'</p><p><a href="index.php?route=operations">管理画面に戻る</a></p></html>';
    exit;
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'");

if (($_SERVER['REQUEST_METHOD']??'') !== 'POST') failRestore('復元は管理画面から開始してください。',405);
$configFile=dirname(__DIR__).'/config.php';
if (!is_file($configFile)) failRestore('設定ファイルがありません。',503);
$config=require $configFile;
if (!is_array($config)) failRestore('設定ファイルが不正です。',503);
if (!extension_loaded('pdo_sqlite')) failRestore('PDO_SQLITEが必要です。',503);
$secure=(bool)($config['session']['secure_cookie']??true);
if ($secure && (($_SERVER['HTTPS']??'')!=='on')) failRestore('HTTPSでアクセスしてください。',403);
session_name($config['session']['name']??'post_comment_session');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
session_start();
$csrf=(string)($_SESSION['csrf']??'');
if ($csrf==='' || !is_string($_POST['csrf']??null) || !hash_equals($csrf,$_POST['csrf'])) {
    failRestore('画面を開き直して実行してください。',403);
}
$dbPath=(string)($config['storage']['database_path']??'');
$backupDir=(string)($config['storage']['backup_path']??'');
if ($dbPath==='' || $backupDir==='' || !is_file($dbPath) || is_link($dbPath)) failRestore('データベースの設定を確認してください。',503);
$pdo=new PDO('sqlite:'.$dbPath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$stmt=$pdo->prepare("SELECT auth_version FROM administrators WHERE id=? AND role='system' AND disabled_at IS NULL");
$stmt->execute([(string)($_SESSION['admin_id']??'')]);
$version=$stmt->fetchColumn();
if ($version===false || (int)$version !== (int)($_SESSION['admin_auth_version']??-1)) {
    failRestore('システム管理者としてログインしてください。',403);
}
$pdo=null;
$file=$_FILES['backup']??null;
if (!is_array($file) || ($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK ||
    !is_uploaded_file((string)($file['tmp_name']??''))) failRestore('バックアップファイルを選択してください。');
$size=(int)($file['size']??0);
if ($size<=0 || $size>128*1024*1024) failRestore('バックアップは128MB以下で指定してください。');
if (!is_dir($backupDir) || !is_writable($backupDir) || is_link($backupDir)) failRestore('バックアップ保存先を確認してください。',503);
$staged=$backupDir.'/restore-upload-'.bin2hex(random_bytes(12)).'.sqlite';
if (!move_uploaded_file($file['tmp_name'],$staged)) failRestore('アップロードを保存できませんでした。',503);
@chmod($staged,0600);
try {
    $lockPath=dirname($dbPath).'/maintenance.lock';
    $saved=(new RestoreService())->restore($dbPath,$staged,$backupDir,$lockPath);
    $_SESSION=[];
    session_regenerate_id(true);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="ja"><meta charset="utf-8"><title>復元完了</title><h1>データを復元しました</h1>';
    echo '<p>復元前のDBはサーバーの非公開バックアップ領域に保存されています。</p>';
    echo '<p><a href="index.php?route=login">再ログインする</a></p></html>';
} catch (Throwable $e) {
    error_log('Database restore failed: '.$e->getMessage());
    failRestore($e->getMessage(),503);
} finally {
    @unlink($staged);
}
