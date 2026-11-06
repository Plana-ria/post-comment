<?php
declare(strict_types=1);

/**
 * One-time browser installer. It requires proving write access to a file
 * outside the web document root, preventing remote first-visitor takeover.
 */
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
$configFile=$root.'/config.php';
$storage=$root.'/storage';
$proofFile=$storage.'/install-proof.txt';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

function installPage(string $title, string $body, int $status=200): never
{
    http_response_code($status);
    echo '<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
      .'<title>'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</title>'
      .'<style>body{font-family:system-ui,sans-serif;line-height:1.7;max-width:700px;padding:24px;margin:auto}'
      .'main{padding:20px;border:1px solid #ddd;border-radius:8px}label{display:block;margin:15px 0;font-weight:600}'
      .'input{display:block;max-width:100%;width:100%;box-sizing:border-box;padding:9px}button{padding:10px 18px}'
      .'code{overflow-wrap:anywhere}small{color:#555}</style>'
      .'<main><h1>'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</h1>'.$body.'</main></html>';
    exit;
}
function escInstall(string $value): string
{
    return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}

if (is_file($configFile)) installPage('セットアップ済み','<p>このアプリは既に設定されています。<a href="index.php?route=login">ログイン画面</a>を開いてください。</p>',403);
if (!postCommentHttps()) installPage('HTTPSが必要です','<p>https:// のURLで開いてください。</p>',403);
if (PHP_VERSION_ID < 80300 || !extension_loaded('pdo_sqlite')) {
    installPage('PHP環境が対応していません','<p>PHP 8.3以降とPDO_SQLITEが必要です。</p>',503);
}
if (!is_dir($storage) || !is_writable($storage) || !is_writable($root) || is_link($storage)) {
    installPage('ファイルの書き込み権限が必要です','<p>アプリのルートとstorageフォルダにPHPから書き込めるようにしてください。</p>',503);
}
session_name('post_comment_install');
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict','path'=>'/']);
session_start();
$_SESSION['csrf']??=bin2hex(random_bytes(32));

if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    $csrf=(string)($_POST['csrf']??'');
    if ($csrf==='' || !hash_equals($_SESSION['csrf'],$csrf)) {
        installPage('送信エラー','<p>画面を開き直してください。</p>',403);
    }
    $key=(string)($_POST['proof']??'');
    if (!preg_match('/^[a-f0-9]{64}$/',$key) || !is_file($proofFile) || is_link($proofFile)
        || filesize($proofFile)>256) {
        installPage('設置権限を確認できません','<p>storage/install-proof.txtを作成し、画面の64文字のコードを保存してください。</p>',403);
    }
    $onDisk=trim((string)file_get_contents($proofFile));
    if (!hash_equals($onDisk,$key)) {
        installPage('設置権限を確認できません','<p>ファイルと入力欄のコードが一致しません。</p>',403);
    }

    $name=trim((string)($_POST['admin_login']??''));
    $secret=(string)($_POST['admin_secret']??'');
    $url=trim((string)($_POST['site_url']??''));
    $parts=parse_url($url);
    if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/',$name)
        || strlen($secret)<12
        || $parts===false || ($parts['scheme']??'')!=='https'
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) {
        installPage('入力内容を確認してください','<p>管理者ID、認証文字列、HTTPSのURLを確認してください。</p>',400);
    }
    $origin='https://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');
    $basePath=rtrim($parts['path']??'','/');
    if ($basePath==='/' || $basePath==='') $basePath='';
    if (str_contains($basePath,'..') || str_contains($basePath,'\\')) {
        installPage('URLが不正です','<p>サブディレクトリを確認してください。</p>',400);
    }

    $data=[
        'app'=>['name'=>'Post Comment','timezone'=>'Asia/Tokyo','debug'=>false],
        'url'=>['base_url'=>$origin,'base_path'=>$basePath],
        'storage'=>['database_path'=>null,'backup_path'=>null],
        'session'=>['name'=>'pc_'.bin2hex(random_bytes(10)),'secure_cookie'=>true],
        'installation'=>['setup_key'=>''],
        'update'=>['repository'=>'Plana-ria/post-comment','auto_apply'=>false],
    ];
    $encoded=var_export($data,true);
    $encoded=str_replace("'database_path' => NULL","'database_path' => __DIR__ . '/storage/database.sqlite'",$encoded);
    $encoded=str_replace("'backup_path' => NULL","'backup_path' => __DIR__ . '/storage/backups'",$encoded);
    $tmp=$root.'/.config-install-'.bin2hex(random_bytes(12));
    if (file_put_contents($tmp,"<?php\n// Automatically generated during installation.\nreturn ".$encoded.";\n",LOCK_EX)===false) {
        installPage('保存エラー','<p>config.phpを作成できませんでした。</p>',503);
    }
    @chmod($tmp,0600);
    if (!rename($tmp,$configFile)) {
        @unlink($tmp);
        installPage('保存エラー','<p>設定ファイルを有効化できませんでした。</p>',503);
    }

    require $root.'/src/Database.php';
    require $root.'/src/Migrator.php';
    try {
        $config=require $configFile;
        $pdo=\PostComment\Database::connect($config);
        \PostComment\Migrator::migrate($pdo,$root.'/database/migrations');
        if ((int)$pdo->query('SELECT COUNT(*) FROM administrators')->fetchColumn()!==0) {
            throw new RuntimeException('Administrator already exists');
        }
        $stmt=$pdo->prepare("INSERT INTO administrators(id,login_id,secret_hash,role) VALUES(?,?,?,'system')");
        $stmt->execute([bin2hex(random_bytes(16)),$name,password_hash($secret,PASSWORD_DEFAULT)]);
        $pdo=null;
        @unlink($proofFile);
        $_SESSION=[];
        session_destroy();
        installPage('インストール完了','<p>SQLiteデータベースと最初のシステム管理者を作成しました。</p>'
            .'<p><a href="index.php?route=login">管理者ログイン画面へ進む</a></p>');
    } catch (Throwable $e) {
        error_log('Post Comment install failed: '.$e->getMessage());
        @unlink($configFile);
        installPage('初期設定できませんでした','<p>サーバーの保存領域を確認してください。データを削除せず、必要に応じて管理者に連絡してください。</p>',503);
    }
}

$host=(string)($_SERVER['HTTP_HOST']??'example.com');
$path=rtrim(str_replace('\\','/',dirname((string)($_SERVER['SCRIPT_NAME']??'/install.php'))),'/');
$suggested='https://'.$host.($path===''?'':$path);
installPage('Post Comment 初回セットアップ',
    '<p>以下の操作は最初の1回だけです。FTPまたはサーバーのファイルマネージャーを利用します。</p>'
    .'<ol><li>「設置確認コードを生成」を押します。</li>'
    .'<li>表示された64文字をコピーして、サーバーの <code>storage/install-proof.txt</code> に保存します。'
    .'このファイルはWeb公開領域 <code>public/</code> の外にあります。</li>'
    .'<li>下のフォームで管理者IDと認証文字列を設定して「インストール」を押します。</li></ol>'
    .'<form method="post" action="install.php">'
    .'<input type="hidden" name="csrf" value="'.escInstall($_SESSION['csrf']).'">'
    .'<label>設置確認コード（64文字）<input id="setup-proof" name="proof" required minlength="64" maxlength="64" pattern="[a-f0-9]{64}" autocomplete="off"></label>'
    .'<button type="button" id="generate-proof">設置確認コードを生成</button>'
    .'<label>サイトURL（設置先に合わせて確認）<input name="site_url" required value="'.escInstall($suggested).'"></label>'
    .'<label>システム管理者ID<input name="admin_login" required minlength="3" maxlength="64"></label>'
    .'<label>管理者の認証文字列（12文字以上）<input name="admin_secret" type="password" minlength="12" required autocomplete="new-password"></label>'
    .'<button type="submit">インストール</button></form>'
    .'<p><small>この確認コードはブラウザ上で生成されます。ほかの人と共有しないでください。</small></p>'
    .'<script defer src="assets/install.js"></script>');
