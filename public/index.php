<?php
declare(strict_types=1);

/**
 * Phase 1: safe browser-based installation and basic admin sign-in.
 * Deploy with this public/ directory as the *only* document root.
 */
require __DIR__.'/bootstrap.php';
try {
    $appRoot=postCommentRoot();
} catch (Throwable $e) {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="ja"><meta charset="utf-8"><title>設置エラー</title><h1>設置先を確認してください</h1><p>'
        .htmlspecialchars($e->getMessage(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p></html>';
    exit;
}
require $appRoot . '/src/Database.php';
require $appRoot . '/src/Migrator.php';
require $appRoot . '/src/FormDefinition.php';
require $appRoot . '/src/ChatService.php';
require $appRoot . '/src/WebRoutes.php';
require $appRoot . '/src/RoomForms.php';
require $appRoot . '/src/ManagementRoutes.php';
require $appRoot . '/src/AuthThrottle.php';
require $appRoot . '/src/BackupService.php';
require $appRoot . '/src/OperationsRoutes.php';
require $appRoot . '/src/LegacyCsvImporter.php';
require $appRoot . '/src/LegacyRoutes.php';

use PostComment\Database;
use PostComment\Migrator;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

function html(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function page(string $title, string $content, int $status = 200): never
{
    http_response_code($status);
    echo '<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . html($title) . ' | Post Comment</title>';
    echo '<style>body{font-family:system-ui,sans-serif;max-width:48rem;margin:3rem auto;padding:0 1rem;line-height:1.7}input{display:block;margin:.3rem 0 1rem;padding:.6rem;width:100%;max-width:28rem;box-sizing:border-box}button{padding:.6rem 1.2rem}a{color:#075fa4}main{border:1px solid #ddd;border-radius:.6rem;padding:1.5rem}label{display:block;font-weight:600}.err{color:#a00}</style>';
    echo '<main><h1>' . html($title) . '</h1>' . $content . '</main></html>';
    exit;
}
function csrfToken(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function checkCsrf(): void
{
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals(csrfToken(), $_POST['csrf'])) {
        page('送信エラー', '<p>画面を再読み込みして再度お試しください。</p>', 403);
    }
}
function redirect(string $route): never
{
    header('Location: ?route=' . rawurlencode($route), true, 303);
    exit;
}

$configFile = $appRoot . '/config.php';
if (!is_file($configFile)) {
    header('Location: install.php', true, 302);
    exit;
}
$config = require $configFile;
if (!is_array($config)) {
    page('設定エラー', '<p>config.php の設定形式が不正です。</p>', 503);
}
date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Tokyo');
if (!extension_loaded('pdo_sqlite')) {
    page('環境に対応していません', '<p>PHPの PDO_SQLITE 拡張機能が必要です。</p>', 503);
}
$secure = (bool) ($config['session']['secure_cookie'] ?? true);
if ($secure && (($_SERVER['HTTPS'] ?? '') !== 'on')) {
    page('HTTPSが必要です', '<p>このシステムはHTTPS接続で利用してください。</p>', 503);
}
session_name($config['session']['name'] ?? 'post_comment_session');
$cookiePath=(string)($config['url']['base_path']??'');
$cookiePath=$cookiePath===''?'/':rtrim($cookiePath,'/').'/';
session_set_cookie_params(['lifetime'=>0,'path'=>$cookiePath,'secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
session_start();

// Every HTTP request holds a shared lock; restoring a database requires
// an exclusive lock and never races against active requests.
$lockDirectory=dirname((string)($config['storage']['database_path']??''));
$maintenanceHandle=@fopen($lockDirectory.'/maintenance.lock','c');
if ($maintenanceHandle === false || !flock($maintenanceHandle, LOCK_SH | LOCK_NB)) {
    page('メンテナンス中','<p>データを更新しています。しばらくしてからアクセスしてください。</p>',503);
}

try {
    $db = Database::connect($config);
} catch (Throwable $e) {
    error_log('PostComment database initialization failed: ' . $e->getMessage());
    page('保存領域を確認してください', '<p>データ保存先を開けません。storage ディレクトリの配置と書き込み権限を確認してください。</p>', 503);
}
$migrations = $appRoot . '/database/migrations';
try {
    $pending = Migrator::pending($db, $migrations);
} catch (Throwable $e) {
    error_log('PostComment migration inspection failed: ' . $e->getMessage());
    page('DBの状態を確認してください', '<p>DB更新履歴の確認に失敗しました。管理者に連絡してください。</p>', 503);
}
$route = (string) ($_GET['route'] ?? 'home');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
// A pending schema migration must NEVER reopen first-install setup when
// administrators already exist. Check installation state independently.
$hasAdminTable = (bool) $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='administrators'")->fetchColumn();
$installed = $hasAdminTable && (int) $db->query('SELECT COUNT(*) FROM administrators')->fetchColumn() > 0;
if ($installed && $pending && $route !== 'login') {
    $stmt = $db->prepare("SELECT id FROM administrators WHERE id=? AND role='system' AND disabled_at IS NULL");
    $stmt->execute([(string)($_SESSION['admin_id'] ?? '')]);
    $authorized = (bool)$stmt->fetchColumn();
    if ($route !== 'db-upgrade' || !$authorized) {
        page('データベース更新が必要です', '<p>システム管理者でログインして更新してください。</p><p><a href="?route=login">管理者ログイン</a> / <a href="?route=db-upgrade">DB更新画面</a></p>', 503);
    }
    if ($method === 'POST') {
        checkCsrf();
        try {
            $storage = dirname((string)$config['storage']['database_path']);
            $backupDir = (string)($config['storage']['backup_path'] ?? ($storage.'/backups'));
            if (!is_dir($backupDir) && !mkdir($backupDir,0700,true)) {
                throw new RuntimeException('Backup directory could not be created');
            }
            if (!is_dir($backupDir) || !is_writable($backupDir) || is_link($backupDir)) {
                throw new RuntimeException('Backup directory is not safe');
            }
            $target = $backupDir.'/pre-migration-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)).'.sqlite';
            $q = $db->prepare('VACUUM INTO ?');
            $q->execute([$target]);
            if (!is_file($target) || filesize($target) === 0) throw new RuntimeException('Backup was not created');
            $verification = new PDO('sqlite:'.$target);
            if ($verification->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('Backup integrity check failed');
            }
            $verification = null;
            Migrator::migrate($db,$migrations);
            redirect('home');
        } catch (Throwable $e) {
            error_log('PostComment migration failed: '.$e->getMessage());
            page('更新に失敗しました', '<p>更新を停止しました。バックアップは保存されています。サーバー管理者に連絡してください。</p>', 503);
        }
    }
    page('データベース更新', '<p>DBスキーマの更新があります。更新前にバックアップを自動作成し、変更を適用します。</p>'
        . '<form method="post" action="?route=db-upgrade"><input type="hidden" name="csrf" value="'.html(csrfToken()).'"><button>バックアップして更新</button></form>',503);
}

if (!$installed) {
    if ($route !== 'setup') {
        redirect('setup');
    }
    $setupKey = (string) ($config['installation']['setup_key'] ?? '');
    if (strlen($setupKey) < 32) {
        page('インストールの準備', '<p>config.php の installation.setup_key に32文字以上のランダムな秘密文字列を設定してください。これは最初の管理者作成を保護します。</p>', 503);
    }
    if ($method === 'POST') {
        checkCsrf();
        $provided = (string) ($_POST['setup_key'] ?? '');
        $loginId = trim((string) ($_POST['login_id'] ?? ''));
        $secret = (string) ($_POST['secret'] ?? '');
        if (!hash_equals($setupKey, $provided) || !preg_match('/^[a-zA-Z0-9_.-]{3,64}$/', $loginId) || strlen($secret) < 12) {
            page('設定を確認してください', '<p class="err">セットアップキー、管理者ID、または管理者用認証文字列が不正です。</p><p><a href="?route=setup">戻る</a></p>', 400);
        }
        try {
            $db->exec('BEGIN IMMEDIATE');
            if (Migrator::pending($db, $migrations)) {
                $db->commit();
                Migrator::migrate($db, $migrations);
                $db->exec('BEGIN IMMEDIATE');
            }
            $count = (int) $db->query('SELECT COUNT(*) FROM administrators')->fetchColumn();
            if ($count !== 0) {
                $db->rollBack();
                page('設定済み', '<p>初期管理者はすでに作成されています。</p>', 409);
            }
            $insert = $db->prepare("INSERT INTO administrators (id, login_id, secret_hash, role) VALUES (?, ?, ?, 'system')");
            $insert->execute([bin2hex(random_bytes(16)), $loginId, password_hash($secret, PASSWORD_DEFAULT)]);
            $db->commit();
            session_regenerate_id(true);
            redirect('login');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('PostComment setup failed: ' . $e->getMessage());
            page('初期設定エラー', '<p>初期設定を完了できませんでした。サーバー管理者に連絡してください。</p>', 503);
        }
    }
    page('初回セットアップ', '<p>インストール用の秘密キーを入力し、最初のシステム管理者を作成します。</p><form method="post" action="?route=setup">'
        . '<input type="hidden" name="csrf" value="' . html(csrfToken()) . '">'
        . '<label>セットアップキー<input required type="password" name="setup_key"></label>'
        . '<label>管理者ID<input required name="login_id" minlength="3" maxlength="64"></label>'
        . '<label>管理者用認証文字列（12文字以上）<input required type="password" name="secret" minlength="12"></label>'
        . '<button>設定を完了する</button></form>');
}

if ($pending && $installed && $route !== 'login') {
    page('データベース更新が必要です', '<p>システム管理者でログインして更新してください。</p>',503);
}

// Resetting an administrator code invalidates previously authenticated browsers.
if (!$pending && !empty($_SESSION['admin_id'])) {
    $authStmt=$db->prepare('SELECT auth_version FROM administrators WHERE id=? AND disabled_at IS NULL');
    $authStmt->execute([$_SESSION['admin_id']]);
    $currentVersion=$authStmt->fetchColumn();
    if ($currentVersion===false || (int)$currentVersion !== (int)($_SESSION['admin_auth_version']??-1)) {
        unset($_SESSION['admin_id'],$_SESSION['admin_auth_version']);
        session_regenerate_id(true);
    }
}

if (\PostComment\LegacyRoutes::handle($db,$route,$method)) {
    exit;
}
if (\PostComment\OperationsRoutes::handle($db,$config,$route,$method)) {
    exit;
}
if (\PostComment\ManagementRoutes::handle($db, $route, $method)) {
    exit;
}

if (\PostComment\WebRoutes::handle($db, $route, $method)) {
    exit;
}

if ($route === 'logout') {
    if ($method !== 'POST') page('操作できません', '', 405);
    checkCsrf();
    $_SESSION = [];
    session_regenerate_id(true);
    redirect('login');
}
if ($route === 'login') {
    if ($method === 'POST') {
        checkCsrf();
        $throttle = $pending ? null : new \PostComment\AuthThrottle($db);
        $identity = strtolower(trim((string)($_POST['login_id'] ?? ''))) . '|' . (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($throttle && !$throttle->allow('admin', $identity)) {
            page('ログイン制限中', '<p>しばらくしてからやり直してください。</p>', 429);
        }
        $stmt = $db->prepare($pending
            ? "SELECT id, secret_hash FROM administrators WHERE login_id = ? AND disabled_at IS NULL"
            : "SELECT id, secret_hash, auth_version FROM administrators WHERE login_id = ? AND disabled_at IS NULL");
        $stmt->execute([(string) ($_POST['login_id'] ?? '')]);
        $admin = $stmt->fetch();
        if ($admin && password_verify((string) ($_POST['secret'] ?? ''), $admin['secret_hash'])) {
            if ($throttle) $throttle->success('admin', $identity);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_auth_version'] = (int)($admin['auth_version'] ?? 1);
            redirect('home');
        }
        if ($throttle) $throttle->failure('admin', $identity);
        page('ログイン失敗', '<p>認証情報を確認してください。</p><p><a href="?route=login">戻る</a></p>', 401);
    }
    page('管理者ログイン', '<form method="post" action="?route=login">'
        . '<input type="hidden" name="csrf" value="' . html(csrfToken()) . '">'
        . '<label>管理者ID<input name="login_id" required></label>'
        . '<label>認証文字列<input type="password" name="secret" required></label>'
        . '<button>ログイン</button></form>');
}
if (empty($_SESSION['admin_id'])) redirect('login');
$stmt = $db->prepare('SELECT login_id FROM administrators WHERE id = ? AND disabled_at IS NULL');
$stmt->execute([$_SESSION['admin_id']]);
$admin = $stmt->fetch();
if (!$admin) {
    $_SESSION = [];
    redirect('login');
}
if ($route !== 'home') {
    page('未実装', '<p>この機能は開発中です。</p><p><a href="?route=home">戻る</a></p>', 404);
}
page('管理ダッシュボード', '<p>ログイン中：' . html($admin['login_id']) . '</p><p>SQLite基盤の初期設定が完了しました。ルーム・投稿・フォーム編集・更新機能は今後実装します。</p>'
    . '<form method="post" action="?route=logout"><input type="hidden" name="csrf" value="' . html(csrfToken()) . '"><button>ログアウト</button></form>');
