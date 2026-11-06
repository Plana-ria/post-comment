<?php
declare(strict_types=1);

namespace PostComment;

use PDO;
use Throwable;

final class WebRoutes
{
    public static function handle(PDO $db, string $route, string $method): bool
    {
        $service = new ChatService($db);
        $admin = self::admin($db);
        $isSystem = ($admin['role'] ?? '') === 'system';

        if ($route === 'room-create') {
            self::requireAdmin($admin);
            if (!$isSystem) page('権限がありません', '<p>ルーム作成はシステム管理者のみ可能です。</p>', 403);
            if ($method === 'POST') {
                checkCsrf();
                try {
                    $service->createRoom(trim((string)($_POST['name'] ?? '')), (string)($_POST['slug'] ?? ''), (string)($_POST['auth_mode'] ?? ''), $admin['id']);
                    redirect('home');
                } catch (Throwable $e) {
                    self::error($e);
                }
            }
            page('新規ルーム作成', '<form method="post" action="?route=room-create">' . self::csrf()
                . '<label>ルーム名<input name="name" required maxlength="160"></label>'
                . '<label>URL識別子（英小文字・数字・ハイフン）<input name="slug" pattern="[a-z0-9][a-z0-9-]{1,62}" required></label>'
                . '<label>認証方式<select name="auth_mode"><option value="required">ユーザーIDとログインコード</option><option value="none">認証なし</option></select></label>'
                . '<button>作成</button></form><p><a href="?route=home">戻る</a></p>');
        }
        if ($route === 'rooms') {
            self::requireAdmin($admin);
            page('ルーム一覧', self::roomList($service, $admin));
        }
        if ($route === 'user-register') {
            if ($method !== 'POST') page('操作できません', '', 405);
            checkCsrf();
            $room = $service->roomBySlug((string)($_POST['slug'] ?? ''));
            if (!$room || $room['status'] !== 'active' || $room['auth_mode'] !== 'required') page('ルームがありません', '', 404);
            try {
                $user = $service->registerUser();
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_code_version'] = 1;
                page('ユーザー登録完了', '<p>以下の情報を保存してください。他の端末でも使用できます。ログインコードは再表示できません。</p>'
                    . '<p>ユーザーID：<strong>' . html($user['login_id']) . '</strong></p>'
                    . '<p>ログインコード：<strong>' . html($user['code']) . '</strong></p>'
                    . '<p><a href="?route=room&amp;slug=' . rawurlencode($room['slug']) . '">コメント投稿へ</a></p>');
            } catch (Throwable $e) { self::error($e); }
        }
        if ($route === 'user-login') {
            if ($method !== 'POST') page('操作できません', '', 405);
            checkCsrf();
            $room = $service->roomBySlug((string)($_POST['slug'] ?? ''));
            if (!$room || $room['auth_mode'] !== 'required') page('ルームがありません', '', 404);
            $login = trim((string)($_POST['login_id'] ?? ''));
            $code = (string)($_POST['login_code'] ?? '');
            // Rate limiting is required before exposing to production.
            $user = $service->authenticateUser($login, $code);
            if (!$user) page('ログインできません', '<p>IDまたはコードを確認してください。</p><p><a href="?route=room&amp;slug='.rawurlencode($room['slug']).'">戻る</a></p>', 401);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_code_version'] = (int)$user['code_version'];
            header('Location: ?route=room&slug=' . rawurlencode($room['slug']), true, 303); exit;
        }
        if ($route === 'user-logout') {
            if ($method !== 'POST') page('操作できません', '', 405);
            checkCsrf();
            unset($_SESSION['user_id'], $_SESSION['user_code_version']);
            session_regenerate_id(true);
            redirect('rooms-public');
        }
        if ($route === 'rooms-public') {
            page('参加可能なルーム', '<p>参加するルームのURLを開いてください。</p>');
        }
        if (in_array($route, ['room', 'submit', 'own-csv'], true)) {
            $room = $service->roomBySlug((string)($_REQUEST['slug'] ?? ''));
            if (!$room) page('ルームがありません', '', 404);
            $userId = null;
            $anonId = null;
            if ($room['auth_mode'] === 'required') {
                if (!empty($_SESSION['user_id'])) {
                    $user = $service->activeUser((string) $_SESSION['user_id']);
                    $userId = ($user && (int)$user['code_version'] === (int)($_SESSION['user_code_version'] ?? -1)) ? $user['id'] : null;
                    if ($userId === null) unset($_SESSION['user_id'], $_SESSION['user_code_version']);
                }
                if (!$userId) {
                    if ($route !== 'room') page('ログインが必要です', '', 401);
                    page($room['name'], '<h2>ログイン</h2><form method="post" action="?route=user-login">'.self::csrf()
                        .'<input type="hidden" name="slug" value="'.html($room['slug']).'">'
                        .'<label>ユーザーID<input name="login_id" required></label><label>ログインコード<input type="password" name="login_code" required></label>'
                        .'<button>ログイン</button></form><h2>初めて利用する方</h2><form method="post" action="?route=user-register">'.self::csrf()
                        .'<input type="hidden" name="slug" value="'.html($room['slug']).'"><button>新規ユーザー登録</button></form>');
                }
            } else {
                $cookieName = 'pc_anon_' . substr(hash('sha256',$room['id']),0,16);
                $token = is_string($_COOKIE[$cookieName] ?? null) ? $_COOKIE[$cookieName] : null;
                if ($route === 'room' || $route === 'submit') {
                    $anon = $service->acceptAnonymous($room['id'],$token);
                    $anonId = $anon['id'];
                    if ($anon['token'] !== null) {
                        $https = (($_SERVER['HTTPS'] ?? '') === 'on');
                        setcookie($cookieName, $anon['token'], [
                            'expires'=>time()+86400, 'path'=>'/', 'secure'=>$https, 'httponly'=>true, 'samesite'=>'Lax'
                        ]);
                        // During the initial response, the new token is not in $_COOKIE.
                        $token = $anon['token'];
                    }
                } else {
                    $anonId = $service->anonymousId($room['id'],$token);
                }
            }
            if ($route === 'submit') {
                if ($method !== 'POST') page('操作できません', '', 405);
                checkCsrf();
                try {
                    $service->submit($room,$service->form($room),$_POST,$userId,$anonId);
                    header('Location: ?route=room&slug=' . rawurlencode($room['slug']), true, 303); exit;
                } catch (Throwable $e) { self::error($e); }
            }
            if ($route === 'own-csv') {
                if ($method !== 'GET') page('操作できません', '', 405);
                self::csv('my-comments.csv',['time','comment'], array_map(
                    static fn(array $r):array => [$r['created_at'],$r['comment_text']],
                    $service->ownComments($room['id'],$userId,$anonId)
                ));
            }
            $form = $service->form($room);
            $body = '<p>' . html((string)($form['description'] ?? '')) . '</p>';
            if ($room['status'] === 'active') {
                $body .= '<form method="post" action="?route=submit">'.self::csrf()
                    .'<input type="hidden" name="slug" value="'.html($room['slug']).'">';
                foreach ($form['fields'] as $field) {
                    $key=$field['key'];
                    $required = !empty($field['required']) ? ' required' : '';
                    $body.='<label>'.html($field['label']).($required?'（必須）':'');
                    if ($field['type'] === 'textarea') {
                        $body.='<textarea name="'.html($key).'"'.$required.' maxlength="10000"></textarea>';
                    } elseif (in_array($field['type'],['select','radio'],true)) {
                        $body.='<select name="'.html($key).'"'.$required.'><option value="">選択してください</option>';
                        foreach (($field['options']??[]) as $option) {
                            $body.='<option value="'.html((string)$option).'">'.html((string)$option).'</option>';
                        }
                        $body.='</select>';
                    } elseif ($field['type'] === 'checkbox') {
                        $body.='<input type="checkbox" name="'.html($key).'" value="1"'.$required.'>';
                    } else {
                        $type = $field['type']==='email'?'email':'text';
                        $body.='<input type="'.$type.'" name="'.html($key).'"'.$required.' maxlength="1000">';
                    }
                    $body.='</label>';
                }
                $body.='<button>'.html((string)($form['submit_label']??'送信')).'</button></form>';
            } else $body .= '<p>このルームの投稿受付は終了しました。</p>';
            $body.='<p><a href="?route=own-csv&amp;slug='.rawurlencode($room['slug']).'">自分のコメントをCSVで取得</a></p>';
            if ($userId) $body.='<form method="post" action="?route=user-logout">'.self::csrf().'<button>ログアウト</button></form>';
            page($room['name'],$body);
        }
        if (in_array($route,['room-admin','admin-csv','reset-user'],true)) {
            self::requireAdmin($admin);
            $roomId = (string) ($_REQUEST['room_id']??'');
            $room=$service->roomById($roomId);
            if (!$room || !$service->isManager($admin['id'],$roomId,$isSystem)) page('権限がありません', '', 403);
            if ($route === 'reset-user') {
                if ($method !== 'POST') page('操作できません', '', 405);
                checkCsrf();
                $userId=(string)($_POST['user_id']??'');
                // A room manager may reset only users who posted or joined their room.
                if (!$isSystem && !in_array($userId,array_column($service->usersInRoom($roomId),'id'),true)) {
                    page('権限がありません', '', 403);
                }
                try {
                    $code=$service->resetCode($userId,$admin['id']);
                    page('コード再発行', '<p>新しいログインコード（一度だけ表示）：</p><p><strong>'.html($code).'</strong></p>'
                        .'<p><a href="?route=room-admin&amp;room_id='.rawurlencode($roomId).'">戻る</a></p>');
                } catch (Throwable $e) { self::error($e); }
            }
            $comments=$service->adminComments($roomId, 0, $route === 'admin-csv' ? null : 500);
            if ($route === 'admin-csv') {
                if ($method !== 'GET') page('操作できません', '', 405);
                // Include all recorded form fields (including fields from previous form versions).
                $all = [];
                foreach ($comments as $row) {
                    foreach (array_keys(json_decode($row['submitted_values_json'],true,512,JSON_THROW_ON_ERROR)) as $key) $all[$key]=true;
                }
                $keys=array_keys($all);
                $records=[];
                foreach ($comments as $row) {
                    $values=json_decode($row['submitted_values_json'],true,512,JSON_THROW_ON_ERROR);
                    $records[]=array_merge([$row['created_at'],$row['comment_text']],array_map(static fn($k)=>$values[$k]??'',$keys));
                }
                self::csv('room-comments.csv',array_merge(['time','comment'],$keys),$records);
            }
            $body='<p><a href="?route=admin-csv&amp;room_id='.rawurlencode($roomId).'">全件CSVを取得</a> | <a href="?route=form-edit&amp;room_id='.rawurlencode($roomId).'">投稿フォームを編集</a></p>';
            $body.='<h2>コメント（最新500件まで）</h2>';
            foreach ($comments as $entry) {
                $values=json_decode($entry['submitted_values_json'],true,512,JSON_THROW_ON_ERROR);
                $body.='<article><p><small>'.html($entry['created_at']).'</small></p><p>'.nl2br(html($entry['comment_text'])).'</p>';
                foreach ($values as $key=>$value) {
                    if ($key==='comment') continue;
                    $body.='<small>'.html((string)$key).'：'.html((string)$value).'</small> ';
                }
                $body.='</article><hr>';
            }
            $body.='<h2>利用者のコード再発行</h2>';
            foreach ($service->usersInRoom($roomId) as $user) {
                $body.='<form method="post" action="?route=reset-user">'.self::csrf()
                    .'<input type="hidden" name="room_id" value="'.html($roomId).'">'
                    .'<input type="hidden" name="user_id" value="'.html($user['id']).'">'
                    .'<span>'.html($user['login_id']).'（投稿 '.(int)$user['posts'].'件）</span> '
                    .'<button>新コードを発行</button></form>';
            }
            $body.='<p><a href="?route=rooms">ルーム一覧に戻る</a></p>';
            page('受信画面：'.$room['name'],$body);
        }
        return false;
    }

    public static function roomList(ChatService $service, array $admin): string
    {
        $body = '<p><a href="?route=home">ダッシュボードへ</a></p>';
        if ($admin['role']==='system') $body.='<p><a href="?route=room-create">新規ルーム作成</a></p>';
        foreach ($service->roomsForAdmin($admin['id'],$admin['role']==='system') as $room) {
            $body.='<article><strong>'.html($room['name']).'</strong>（'.html($room['auth_mode']).'） '
                .'<a href="?route=room&amp;slug='.rawurlencode($room['slug']).'">投稿画面</a> '
                .'<a href="?route=room-admin&amp;room_id='.rawurlencode($room['id']).'">受信画面</a></article><hr>';
        }
        return $body;
    }

    private static function admin(PDO $db): ?array
    {
        if (empty($_SESSION['admin_id'])) return null;
        $stmt=$db->prepare('SELECT id,login_id,role FROM administrators WHERE id=? AND disabled_at IS NULL');
        $stmt->execute([$_SESSION['admin_id']]);
        return $stmt->fetch() ?: null;
    }

    private static function requireAdmin(?array $admin): void
    {
        if (!$admin) page('ログインが必要です','<p><a href="?route=login">管理者ログイン</a></p>',401);
    }

    private static function csrf(): string
    {
        return '<input type="hidden" name="csrf" value="'.html(csrfToken()).'">';
    }

    private static function error(Throwable $e): never
    {
        error_log('PostComment action failed: '.$e->getMessage());
        page('処理できませんでした','<p>入力内容と設定を確認してください。</p><p>ブラウザの戻る操作で入力画面に戻ってください。</p>',400);
    }

    private static function csv(string $filename, array $headers, array $rows): never
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Cache-Control: private, no-store');
        $out=fopen('php://output','wb');
        fwrite($out,"\xEF\xBB\xBF");
        fputcsv($out,$headers);
        foreach ($rows as $row) {
            $clean=array_map(static function ($v):string {
                $s=(string)$v;
                // Prevent spreadsheet formula injection while retaining source values in DB.
                return preg_match('/^[\x00-\x20]*[=+\-@\t\r]/u',$s) ? "'".$s : $s;
            },$row);
            fputcsv($out,$clean);
        }
        fclose($out);
        exit;
    }
}
