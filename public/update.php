<?php
declare(strict_types=1);

/**
 * Separate signed updater, deliberately not overwritten by in-app updates.
 * No arbitrary URL, PHP code, manifest or target path is accepted from users.
 */
require __DIR__.'/bootstrap.php';
try { $root=postCommentRoot(); }
catch (Throwable $e) { http_response_code(503); exit('設置構成を確認してください。'); }
require $root.'/src/BackupService.php';
require $root.'/src/ReleaseChecker.php';
require $root.'/src/ReleaseDownloader.php';
require $root.'/src/SignedPackage.php';
require $root.'/src/UpdateService.php';

use PostComment\UpdateService;

function updateEscape(string $s): string {
    return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
function updatePage(string $title,string $html,int $status=200): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.updateEscape($title).'</title>';
    echo '<style>body{font-family:system-ui,sans-serif;max-width:48rem;margin:3rem auto;padding:1rem;line-height:1.7}input{display:block;margin:.5rem 0 1rem}button{padding:.65rem 1.2rem}</style>';
    echo '<h1>'.updateEscape($title).'</h1>'.$html.'<p><a href="index.php?route=operations">システム保守へ</a></p>';
    exit;
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

$configFile=$root.'/config.php';
if (!is_file($configFile)) updatePage('更新できません','<p>初回インストールを完了してください。</p>',503);
$config=require $configFile;
if (!is_array($config)) updatePage('更新できません','<p>設定が不正です。</p>',503);
$secure=(bool)($config['session']['secure_cookie']??true);
if ($secure && ($_SERVER['HTTPS']??'')!=='on') updatePage('HTTPSが必要です','',403);
session_name((string)($config['session']['name']??'post_comment_session'));
$cookiePath=(string)($config['url']['base_path']??'');
$cookiePath=$cookiePath===''?'/':rtrim($cookiePath,'/').'/';
session_set_cookie_params(['lifetime'=>0,'path'=>$cookiePath,'secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
session_start();
$csrf=$_SESSION['csrf']??=bin2hex(random_bytes(32));
$dbPath=(string)($config['storage']['database_path']??'');
$backupDir=(string)($config['storage']['backup_path']??'');
if (!is_file($dbPath) || $backupDir==='') updatePage('更新できません','<p>DBと保存先を確認してください。</p>',503);
$db=new PDO('sqlite:'.$dbPath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$stmt=$db->prepare("SELECT auth_version FROM administrators WHERE id=? AND role='system' AND disabled_at IS NULL");
$stmt->execute([(string)($_SESSION['admin_id']??'')]);
$version=$stmt->fetchColumn();
if ($version===false || (int)$version !== (int)($_SESSION['admin_auth_version']??-1)) {
    updatePage('認証が必要です','<p><a href="index.php?route=login">システム管理者としてログインしてください。</a></p>',403);
}
$db=null;
$key=(string)($config['update']['public_key']??'');
if (!preg_match('/^[a-f0-9]{64}$/iD',$key)) {
    updatePage('署名鍵の初期設定が必要です','<p>エンジニアが非公開 config.php に信頼するEd25519公開鍵を設定してください。未設定の状態では更新できません。</p>',503);
}
if (!extension_loaded('sodium') || !class_exists(ZipArchive::class)) {
    updatePage('サーバーの拡張機能が不足','<p>sodium と zip が必要です。</p>',503);
}
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    if (!is_string($_POST['csrf']??null) || !hash_equals((string)$csrf,$_POST['csrf'])) {
        updatePage('操作を確認してください','<p>画面を開き直してください。</p>',403);
    }
    $downloaded=[];
    if (($_POST['mode']??'')==='github') {
        try {
            $latest=\PostComment\ReleaseChecker::latest();
            if (!$latest || version_compare($latest['version'],\PostComment\ReleaseChecker::currentVersion($root),'<=')) {
                updatePage('更新はありません','<p>適用できる新しい正式リリースはありません。</p>');
            }
            if (!is_dir($backupDir) && !mkdir($backupDir,0700,true)) {
                throw new RuntimeException('非公開のバックアップ領域を作成できません。');
            }
            $downloaded=\PostComment\ReleaseDownloader::download($latest,$backupDir);
            [$archivePath,$signaturePath]=$downloaded;
        } catch (Throwable $e) {
            error_log('GitHub release download failed: '.$e->getMessage());
            updatePage('リリースを取得できませんでした',
                '<p>'.updateEscape($e->getMessage()).'</p><p>手動ZIPアップロードをご利用ください。</p>',503);
        }
    } else {
        $archive=$_FILES['archive']??null;
        $signature=$_FILES['signature']??null;
        foreach ([$archive,$signature] as $upload) {
            if (!is_array($upload) || ($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK ||
                !is_uploaded_file((string)($upload['tmp_name']??''))) {
                updatePage('ファイルを確認してください','<p>ZIPと署名ファイルを両方選択してください。</p>',400);
            }
        }
        if ((int)($archive['size']??0)>50*1024*1024 ||
            (int)($signature['size']??0)>4096) updatePage('ファイルが大きすぎます','',413);
        $archivePath=(string)$archive['tmp_name'];
        $signaturePath=(string)$signature['tmp_name'];
    }
    try {
        $result=UpdateService::apply($root,__DIR__,$archivePath,$signaturePath,$key,$dbPath,$backupDir);
        unset($_SESSION['admin_id'],$_SESSION['admin_auth_version']);
        session_regenerate_id(true);
        updatePage('更新が完了しました',
            '<p>新バージョン：'.updateEscape($result['version']).'</p>'
            .'<p>更新ファイル数：'.(int)$result['files'].'</p>'
            .'<p>更新前バックアップはサーバーの非公開領域に保存しました。</p>'
            .'<p><a href="index.php?route=login">再ログインする</a></p>');
    } catch (Throwable $e) {
        error_log('Signed update rejected or failed: '.$e->getMessage());
        updatePage('更新できませんでした',
            '<p>'.updateEscape($e->getMessage()).'</p>'
            .'<p>更新前の状態とバックアップを確認してください。署名に問題があるZIPは適用されません。</p>',503);
    } finally {
        foreach ($downloaded as $file) @unlink($file);
    }
}
if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') updatePage('操作できません','',405);
updatePage('署名付きリリースの適用',
    '<form method="post"><input type="hidden" name="csrf" value="'.updateEscape($csrf).'"><input type="hidden" name="mode" value="github"><button>GitHubの最新正式版を取得して適用</button></form>'
    .'<p>GitHub Releasesから <code>post-comment-portable.zip</code> と <code>post-comment-portable.zip.sig</code> を取得してください。'
    .'署名検証後、更新前のSQLiteバックアップと旧コードの退避を行い、失敗時には元の状態への復旧を試みます。</p>'
    .'<form method="post" enctype="multipart/form-data">'
    .'<input type="hidden" name="csrf" value="'.updateEscape($csrf).'">'
    .'<label>配布ZIP<input type="file" name="archive" accept=".zip" required></label>'
    .'<label>Ed25519署名ファイル<input type="file" name="signature" accept=".sig,.txt" required></label>'
    .'<button>署名を検証して更新する</button></form>'
    .'<p>更新中は一時的に投稿を停止します。PHPのアップロードサイズ制限にご注意ください。</p>');
