<?php
declare(strict_types=1);

namespace PostComment;

use PDO;
use Throwable;

final class LegacyRoutes
{
    public static function handle(PDO $db, string $route, string $method): bool
    {
        if ($route!=='legacy-import') return false;
        $stmt=$db->prepare('SELECT id,role FROM administrators WHERE id=? AND disabled_at IS NULL');
        $stmt->execute([(string)($_SESSION['admin_id']??'')]);
        $admin=$stmt->fetch();
        if (!$admin) page('ログインが必要です','<p><a href="?route=login">管理者ログイン</a></p>',401);
        $roomId=(string)($_REQUEST['room_id']??'');
        $service=new ChatService($db);
        $room=$service->roomById($roomId);
        if (!$room || !$service->isManager($admin['id'],$roomId,$admin['role']==='system')) {
            page('権限がありません','',403);
        }
        if ($method==='POST') {
            checkCsrf();
            $file=$_FILES['legacy_csv']??null;
            if (!is_array($file) || ($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK
                || !is_uploaded_file((string)($file['tmp_name']??''))) {
                page('CSVを選択してください','<p><a href="?route=legacy-import&amp;room_id='.rawurlencode($roomId).'">戻る</a></p>',400);
            }
            try {
                $result=(new LegacyCsvImporter($db))->import($roomId,(string)$file['tmp_name'],100000,(string)($_POST['encoding']??'UTF-8'));
                $db->prepare('INSERT INTO audit_events(actor_id,action,target_type,target_id,created_at) VALUES(?,?,?,?,?)')
                    ->execute([$admin['id'],'legacy_csv_import','room',$roomId,gmdate('Y-m-d\TH:i:s\Z')]);
                page('インポート完了','<p>取り込んだ投稿：'.(int)$result['imported'].'件</p>'
                    .'<p>重複としてスキップ：'.(int)$result['duplicate'].'件</p>'
                    .'<p>旧投稿は新しいユーザーIDに紐付けません。</p>'
                    .'<p><a href="?route=room-admin&amp;room_id='.rawurlencode($roomId).'">受信画面に戻る</a></p>');
            } catch (Throwable $e) {
                error_log('Legacy CSV import failed: '.$e->getMessage());
                page('取り込めませんでした','<p>'.html($e->getMessage()).'</p>'
                    .'<p>途中で失敗した場合はDBの変更を取り消しています。</p>'
                    .'<p><a href="?route=legacy-import&amp;room_id='.rawurlencode($roomId).'">戻る</a></p>',400);
            }
        }
        page('過去コメントのインポート：'.$room['name'],
            '<p>旧post-commentの管理者用「全件CSV」を指定してください。'
            .'標準ルームとguestルームの形式に対応します。5dlab_oldは対象外です。</p>'
            .'<p>インポート済みの同一CSVを再送信しても重複登録しません。'
            .'投稿は履歴として保存され、現在のユーザーIDには自動関連付けしません。</p>'
            .'<form method="post" action="?route=legacy-import" enctype="multipart/form-data">'
            .'<input type="hidden" name="csrf" value="'.html(csrfToken()).'">'
            .'<input type="hidden" name="room_id" value="'.html($roomId).'">'
            .'<label>旧管理画面から出力したCSV（最大20MB）'
            .'<input type="file" name="legacy_csv" accept=".csv,text/csv" required></label>'
            .'<label>CSVの文字コード<select name="encoding"><option value="UTF-8">UTF-8（通常はこちら）</option><option value="CP932">Shift_JIS / Windows-31J（旧Excel形式）</option></select></label>'
            .'<button>コメント履歴を取り込む</button></form>'
            .'<p><a href="?route=room-admin&amp;room_id='.rawurlencode($roomId).'">戻る</a></p>');
    }
}
