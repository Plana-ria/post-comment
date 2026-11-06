<?php
declare(strict_types=1);

/**
 * Engineer-only emergency recovery. The operator MUST have FTP/file-manager
 * write access to storage/ outside the public document root.
 */
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'");

function escapeRecovery(string $s): string {return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function showRecovery(string $title, string $body, int $status=200): never
{
    http_response_code($status);
    echo '<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        .'<title>'.escapeRecovery($title).'</title><style>body{font-family:system-ui,sans-serif;max-width:600px;margin:3em auto;line-height:1.6;padding:1em}'
        .'input,select{display:block;width:100%;padding:.6em;margin:.5em 0 1em;box-sizing:border-box}'
        .'button{padding:.6em 1em}</style><h1>'.escapeRecovery($title).'</h1>'.$body.'</html>';
    exit;
}
require __DIR__.'/bootstrap.php';
try {
    $root=postCommentRoot();
} catch (Throwable $e) {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="ja"><meta charset="utf-8"><title>設置エラー</title><h1>設置先を確認してください</h1><p>'
        .htmlspecialchars($e->getMessage(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p></html>';
    exit;
}
if (!is_file($root.'/config.php') || (!postCommentHttps())) {
    showRecovery('アクセスできません','',404);
}
$config=require $root.'/config.php';
$proof=$root.'/storage/emergency-proof.txt';
if (!is_file($proof) || is_link($proof) || filesize($proof)>256) {
    showRecovery('アクセスできません','',404);
}
if (($_SERVER['REQUEST_METHOD']??'GET')==='GET') {
    showRecovery('管理者の非常復旧',
        '<p>この機能はサーバーの非公開領域に設置した復旧確認ファイルがある間だけ使用できます。</p>'
        .'<form method="post"><label>復旧確認コード（64文字）<input type="password" name="proof" required minlength="64" maxlength="64"></label>'
        .'<input type="hidden" name="action" value="list"><button>管理者を確認する</button></form>');
}
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') showRecovery('操作できません','',405);
$provided=(string)($_POST['proof']??'');
if (!preg_match('/^[a-f0-9]{64}$/',$provided)
    || !hash_equals(trim((string)file_get_contents($proof)),$provided)) {
    showRecovery('確認できません','<p>復旧確認コードが一致しません。</p>',403);
}
$lockPath=dirname((string)($config['storage']['database_path']??'' )).'/maintenance.lock';
$lock=@fopen($lockPath,'c');
if ($lock===false || !flock($lock,LOCK_EX | LOCK_NB)) {
    showRecovery('利用できません','<p>現在処理中のアクセスがあります。しばらくしてから再実行してください。</p>',503);
}
require $root.'/src/EmergencyRecovery.php';
try {
    $path=(string)($config['storage']['database_path']??'');
    if (!is_file($path) || is_link($path)) throw new RuntimeException('DBが見つかりません。');
    $pdo=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $admins=$pdo->query("SELECT login_id FROM administrators WHERE role='system' AND disabled_at IS NULL ORDER BY login_id")->fetchAll(PDO::FETCH_COLUMN);
    if (($_POST['action']??'')==='list') {
        $options='';
        foreach ($admins as $admin) $options.='<option value="'.escapeRecovery($admin).'">'.escapeRecovery($admin).'</option>';
        showRecovery('復旧する管理者を選択',
            '<form method="post"><input type="hidden" name="proof" value="'.escapeRecovery($provided).'">'
            .'<input type="hidden" name="action" value="reset">'
            .'<label>対象のシステム管理者<select name="login_id">'.$options.'</select></label>'
            .'<button>新しい認証コードを発行</button></form>');
    }
    if (($_POST['action']??'')!=='reset') showRecovery('操作できません','',400);
    $login=(string)($_POST['login_id']??'');
    if (!in_array($login,$admins,true)) showRecovery('管理者が見つかりません','',404);
    $newCode=(new \PostComment\EmergencyRecovery($pdo))->regenerate($login);
    $pdo=null;
    // A one-time proof is never accepted again after a successful reset.
    if (!unlink($proof)) {
        error_log('Emergency recovery succeeded but failed to remove proof file');
        showRecovery('復旧ファイルを削除してください','<p>認証情報の再発行は完了しています。復旧確認ファイルを手動で削除してください。</p>',503);
    }
    showRecovery('新しい認証コードを発行しました',
        '<p>管理者ID：'.escapeRecovery($login).'</p>'
        .'<p>新しいコード（一度だけ表示）：<strong>'.escapeRecovery($newCode).'</strong></p>'
        .'<p>復旧確認ファイルは削除しました。<a href="index.php?route=login">ログインする</a></p>');
} catch (Throwable $e) {
    error_log('Emergency recovery failed: '.$e->getMessage());
    showRecovery('復旧できませんでした','<p>システムの状態を確認してください。</p>',503);
} finally {
    if (is_resource($lock)) {
        flock($lock,LOCK_UN);
        fclose($lock);
    }
}
