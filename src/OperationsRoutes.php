<?php
declare(strict_types=1);

namespace PostComment;

use PDO;
use Throwable;

/** Administration for backed-up data and emergency code reissue. */
final class OperationsRoutes
{
    public static function handle(PDO $db, array $config, string $route, string $method): bool
    {
        if (!in_array($route,['operations','download-backup','admin-reset'],true)) return false;
        $stmt = $db->prepare("SELECT id,login_id,role FROM administrators WHERE id=? AND disabled_at IS NULL");
        $stmt->execute([(string)($_SESSION['admin_id']??'')]);
        $admin = $stmt->fetch();
        if (!$admin || $admin['role'] !== 'system') {
            page('権限がありません','<p>システム管理者の認証が必要です。</p>',403);
        }

        if ($route === 'download-backup') {
            if ($method !== 'POST') page('操作できません','',405);
            checkCsrf();
            try {
                $destination = (string)($config['storage']['backup_path'] ?? '');
                if ($destination === '') throw new \RuntimeException('Backup path is not configured.');
                if (!is_dir($destination) && !mkdir($destination,0700,true)) {
                    throw new \RuntimeException('Cannot create backup directory.');
                }
                $backup=(new BackupService($db))->create($destination);
                $db->prepare('INSERT INTO audit_events(actor_id,action,target_type,target_id,created_at) VALUES(?,?,?,?,?)')
                    ->execute([$admin['id'],'backup_download','system',null,gmdate('Y-m-d\TH:i:s\Z')]);
                BackupService::downloadAndRemove($backup);
            } catch (Throwable $e) {
                error_log('Backup failed: '.$e->getMessage());
                page('バックアップ失敗','<p>保存先と書き込み権限を確認してください。</p>',503);
            }
        }
        if ($route === 'admin-reset') {
            if ($method !== 'POST') page('操作できません','',405);
            checkCsrf();
            $target=(string)($_POST['admin_id'] ?? '');
            if ($target==='') page('入力が不足しています','',400);
            // A newly generated code is not persisted as plaintext.
            $secret=implode('-',str_split(strtoupper(bin2hex(random_bytes(20))),10));
            $db->beginTransaction();
            try {
                $stmt=$db->prepare('UPDATE administrators SET secret_hash=?,auth_version=auth_version+1 WHERE id=? AND disabled_at IS NULL');
                $stmt->execute([password_hash($secret,PASSWORD_DEFAULT),$target]);
                if ($stmt->rowCount()!==1) throw new \RuntimeException('Administrator missing');
                $db->prepare('INSERT INTO audit_events(actor_id,action,target_type,target_id,created_at) VALUES(?,?,?,?,?)')
                    ->execute([$admin['id'],'admin_secret_reset','administrator',$target,gmdate('Y-m-d\TH:i:s\Z')]);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('Admin reset failed: '.$e->getMessage());
                page('再発行できませんでした','<p>管理者アカウントを確認してください。</p>',400);
            }
            // Resetting oneself terminates this browser's current authorization.
            if ($target===$admin['id']) {
                unset($_SESSION['admin_id'],$_SESSION['admin_auth_version']);
                session_regenerate_id(true);
            }
            page('管理者認証情報を再発行しました',
                '<p>この画面に一度だけ表示します。対象の管理者に伝えてください。</p>'
                .'<p><strong>'.html($secret).'</strong></p><p><a href="?route=login">ログイン画面</a></p>');
        }

        $admins=$db->query('SELECT id,login_id,role FROM administrators WHERE disabled_at IS NULL ORDER BY login_id')->fetchAll();
        $body='<h2>バックアップを取得</h2><p>SQLiteの一貫したコピーをダウンロードします。'
            .'投稿・認証情報を含むため、取得したファイルは安全な場所に保管してください。</p>'
            .'<form method="post" action="?route=download-backup">'
            .'<input type="hidden" name="csrf" value="'.html(csrfToken()).'"><button>DBをバックアップ</button></form>'
            .'<h2>バックアップから復元</h2><p>管理画面から取得したDBファイルを選択してください。'
            .'復元前に現在のDBを自動バックアップし、復元完了後は再ログインが必要です。'
            .'既存の投稿・設定は復元元の内容へ置き換わります。</p>'
            .'<form method="post" action="restore.php" enctype="multipart/form-data">'
            .'<input type="hidden" name="csrf" value="'.html(csrfToken()).'">'
            .'<label>SQLiteバックアップ<input type="file" name="backup" accept=".sqlite,.db,application/octet-stream" required></label>'
            .'<button>DBを復元</button></form>'
            .'<h2>管理者の認証情報を再発行</h2><p>新しい認証コードを発行すると以前の認証情報とセッションは使用できなくなります。</p>';
        foreach($admins as $a) {
            $body.='<form method="post" action="?route=admin-reset">'
                .'<input type="hidden" name="csrf" value="'.html(csrfToken()).'">'
                .'<input type="hidden" name="admin_id" value="'.html($a['id']).'">'
                .'<span>'.html($a['login_id']).'（'.html($a['role']).'）</span> '
                .'<button>再発行</button></form>';
        }
        $body.='<p><a href="?route=home">管理画面に戻る</a></p>';
        page('システム保守',$body);
    }
}
