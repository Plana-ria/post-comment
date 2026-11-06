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
        if (!in_array($route,['operations','updates','download-backup','admin-reset','legacy-rooms'],true)) return false;
        $stmt = $db->prepare("SELECT id,login_id,role FROM administrators WHERE id=? AND disabled_at IS NULL");
        $stmt->execute([(string)($_SESSION['admin_id']??'')]);
        $admin = $stmt->fetch();
        if (!$admin || $admin['role'] !== 'system') {
            page('権限がありません','<p>システム管理者の認証が必要です。</p>',403);
        }

        if ($route === 'legacy-rooms') {
            if ($method === 'POST') {
                checkCsrf();
                $selected = $_POST['rooms'] ?? [];
                if (!is_array($selected) || count($selected) > 18) page('入力エラー','',400);
                foreach ($selected as $value) {
                    if (!is_string($value) || !preg_match('/^\d{1,2}$/',$value)) page('入力エラー','',400);
                }
                try {
                    $summary=LegacyRoomCatalog::import(new ChatService($db),new RoomForms($db),$admin['id'],$selected);
                    page('旧ルームのひな形を作成しました',
                        '<p>作成：'.(int)$summary['created'].'件、既存のためスキップ：'.(int)$summary['skipped'].'件</p>'
                        .'<p>投稿データは別途、各ルームのCSVインポート機能で取り込んでください。</p>'
                        .'<p><a href="?route=rooms">ルーム一覧へ</a></p>');
                } catch (Throwable $e) {
                    error_log('Legacy room template import failed: '.$e->getMessage());
                    page('ルーム作成失敗','<p>一部だけ作成された可能性があります。ルーム一覧を確認してください。</p>',503);
                }
            }
            if ($method !== 'GET') page('操作できません','',405);
            $body='<p>旧システムのZIPから抽出したルーム名とフォーム種別です。旧認証情報・投稿データは取り込みません。新しいルームURLになります。</p>'
                .'<form method="post" action="?route=legacy-rooms"><input type="hidden" name="csrf" value="'.html(csrfToken()).'">';
            foreach (LegacyRoomCatalog::entries() as $entry) {
                $body.='<label><input type="checkbox" name="rooms[]" value="'.html($entry['id']).'"> '
                    .html($entry['name']).'（'.($entry['auth_mode']==='none'?'認証なし':'個人認証').'）</label>';
            }
            $body.='<button>選択した旧ルームのひな形を作成する</button></form>'
                .'<p>作成済みのURL識別子は再作成しません。変更済みフォームを上書きしません。</p>';
            page('旧ルームの移行支援',$body);
        }
        if ($route === 'updates') {
            if ($method !== 'GET') page('操作できません','',405);
            $current=ReleaseChecker::currentVersion(dirname(__DIR__));
            $body='<p>現在のバージョン：<strong>'.html($current).'</strong></p>';
            try {
                $latest=ReleaseChecker::latest();
                if (!$latest) {
                    $body.='<p>対応する正式リリースが公開されていません。</p>';
                } else {
                    $body.='<p>GitHubの最新安定版：<strong>'.html($latest['tag']).'</strong></p>';
                    if (version_compare($latest['version'],$current,'>')) {
                        $body.='<p>新しいバージョンがあります。</p>';
                    } else $body.='<p>現在のバージョン以上の更新はありません。</p>';
                    $body.='<p>配布ZIP：<a href="'.html($latest['url']).'" rel="noopener noreferrer">GitHub Releasesから取得</a></p>';
                    $body.='<p>SHA-256：<code>'.html(substr($latest['digest'],7)).'</code></p>';
                }
            } catch (Throwable $e) {
                error_log('Release check failed: '.$e->getMessage());
                $body.='<p>最新情報を取得できませんでした。GitHubの接続とサーバー設定を確認してください。</p>';
            }
            $body.='<p><a href="update.php">署名付き配布ZIPをアップロードして更新</a></p>'
                .'<p>GitHubからの取得・適用と手動ZIPアップロードに対応します。公開鍵の初期設定が必要です。</p>'
                .'<p><a href="?route=operations">システム保守に戻る</a></p>';
            page('GitHub更新確認',$body);
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
        $body='<p><a href="?route=updates">GitHubの最新版を確認する</a> | <a href="update.php">署名付きZIPで更新する</a> | <a href="?route=legacy-rooms">旧ルームのひな形を取り込む</a></p><h2>バックアップを取得</h2><p>SQLiteの一貫したコピーをダウンロードします。'
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
