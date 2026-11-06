<?php
declare(strict_types=1);

namespace PostComment;

use PDO;
use Throwable;

/** Browser-only room form editor and multi-administrator assignment UI. */
final class ManagementRoutes
{
    public static function handle(PDO $db, string $route, string $method): bool
    {
        if (!in_array($route, ['form-edit','room-settings','admins','admin-create','admin-assign','admin-unassign'], true)) return false;
        $admin = self::admin($db);
        if (!$admin) page('ログインが必要です', '<p><a href="?route=login">管理者ログイン</a></p>', 401);
        $system = $admin['role'] === 'system';
        $service = new ChatService($db);
        if ($route === 'room-settings') {
            $roomId=(string)($_REQUEST['room_id']??'');
            $room=$service->roomById($roomId);
            if (!$room || !$service->isManager($admin['id'],$roomId,$system)) page('権限がありません','',403);
            if ($method==='POST') {
                checkCsrf();
                try {
                    $service->updateRoom($roomId,(string)($_POST['name']??''),(string)($_POST['status']??''));
                    header('Location: ?route=room-admin&room_id='.rawurlencode($roomId),true,303);exit;
                } catch (Throwable $e) {
                    page('設定を保存できませんでした','<p>ルーム名と公開状態を確認してください。</p>',400);
                }
            }
            $body='<p>認証方式とURL識別子は作成後に変更できません。投稿データの混在を防ぐためです。</p>'
                .'<form method="post" action="?route=room-settings">'.self::csrf()
                .'<input type="hidden" name="room_id" value="'.html($roomId).'">'
                .'<label>ルーム名<input name="name" required maxlength="160" value="'.html($room['name']).'"></label>'
                .'<label>投稿受付<select name="status">'
                .'<option value="active"'.($room['status']==='active'?' selected':'').'>受付中</option>'
                .'<option value="closed"'.($room['status']==='closed'?' selected':'').'>受付終了</option>'
                .'</select></label><button>保存</button></form>'
                .'<p><a href="?route=room-admin&amp;room_id='.rawurlencode($roomId).'">受信画面に戻る</a></p>';
            page('ルーム設定',$body);
        }
        if ($route === 'form-edit') {
            $roomId=(string)($_REQUEST['room_id']??'');
            $room=$service->roomById($roomId);
            if (!$room || !$service->isManager($admin['id'],$roomId,$system)) {
                page('権限がありません','',403);
            }
            $editor = new RoomForms($db);
            $form = $service->form($room);
            $error = '';
            if ($method === 'POST') {
                checkCsrf();
                try {
                    $newForm = RoomForms::fromEditor($_POST);
                    // Avoid accidental overwrites when two administrators edit simultaneously.
                    if (!hash_equals((string)$room['active_form_version_id'], (string)($_POST['original_version']??''))) {
                        throw new \RuntimeException('Another administrator has updated this form. Reload before saving.');
                    }
                    $version=$editor->publish($roomId,$newForm,$admin['id']);
                    page('フォームを公開しました','<p>バージョン '.(int)$version.' を公開しました。過去の投稿は変更されません。</p>'
                        .'<p><a href="?route=form-edit&amp;room_id='.rawurlencode($roomId).'">フォーム編集へ</a></p>'
                        .'<p><a href="?route=room&amp;slug='.rawurlencode($room['slug']).'">投稿画面を確認</a></p>');
                } catch (Throwable $e) {
                    $error = 'フォームを保存できませんでした：'.html($e->getMessage());
                }
            }
            $body = '<p>投稿項目を追加・削除・並べ替えできます。保存すると新しいフォーム版を公開します。</p>';
            if ($error !== '') $body.='<p class="err">'.$error.'</p>';
            $body.='<form id="form-editor" method="post" action="?route=form-edit">'.self::csrf()
                .'<input type="hidden" name="room_id" value="'.html($roomId).'">'
                .'<input type="hidden" name="original_version" value="'.html($room['active_form_version_id']).'">'
                .'<label>フォームタイトル<input name="title" maxlength="160" required value="'.html($form['title']??'コメント投稿').'"></label>'
                .'<label>説明文<textarea name="description" maxlength="5000">'.html($form['description']??'').'</textarea></label>'
                .'<label>送信ボタン<input name="submit_label" maxlength="60" required value="'.html($form['submit_label']??'送信').'"></label>'
                .'<p>行は上から順に表示します。不要な行は「削除」にチェックを入れてください。キーは半角英数字とアンダースコア（英小文字で始める）です。</p>';
            $body.='<div id="form-fields">';
            $fields=$form['fields'];
            $rows=max(count($fields)+4,8);
            $rows=min(40,$rows);
            for ($i=0;$i<$rows;$i++) {
                $field=$fields[$i]??[];
                $type=$field['type']??'text';
                $options=implode("\n",$field['options']??[]);
                $body.='<fieldset class="form-field"><legend>項目 '.($i+1).'</legend>'
                    .'<label>項目キー<input name="fields['.$i.'][key]" maxlength="50" value="'.html($field['key']??'').'"></label>'
                    .'<label>表示名<input name="fields['.$i.'][label]" maxlength="160" value="'.html($field['label']??'').'"></label>'
                    .'<label>入力形式<select name="fields['.$i.'][type]">';
                foreach (['text'=>'1行テキスト','textarea'=>'複数行','email'=>'メールアドレス','select'=>'プルダウン','radio'=>'ラジオボタン','checkbox'=>'チェックボックス'] as $v=>$label) {
                    $body.='<option value="'.$v.'"'.($type===$v?' selected':'').'>'.$label.'</option>';
                }
                $body.='</select></label><label>補足説明<input name="fields['.$i.'][description]" maxlength="500" value="'.html($field['description']??'').'"></label>'
                    .'<label>入力例（プレースホルダー）<input name="fields['.$i.'][placeholder]" maxlength="200" value="'.html($field['placeholder']??'').'"></label>'
                    .'<label>選択肢（選択式の場合・1行1件）<textarea name="fields['.$i.'][options]" maxlength="3000">'.html($options).'</textarea></label>'
                    .'<label><input type="checkbox" name="fields['.$i.'][required]" value="1"'.(!empty($field['required'])?' checked':'').'>必須</label>'
                    .'<label><input type="checkbox" name="fields['.$i.'][comment_body]" value="1"'.(($field['role']??'')==='comment_body'?' checked':'').'>コメント本文として使う（必須・1か所だけ）</label>'
                    .'<label><input type="checkbox" name="fields['.$i.'][omit]" value="1">削除する</label>'
                    .'<button type="button" data-move="up">上へ</button> <button type="button" data-move="down">下へ</button></fieldset>';
            }
            $body.='</div><p><button type="button" id="add-field">項目を追加</button> '
                .'<button type="button" id="preview-form">プレビュー</button></p>'
                .'<section id="form-preview" hidden><h2>投稿フォームのプレビュー</h2><div id="preview-fields"></div></section>'
                .'<button>このフォームを公開する</button></form>'
                .'<script defer src="assets/form-builder.js"></script><h2>公開履歴</h2><ul>';
            foreach ($editor->versions($roomId) as $version) $body.='<li>v'.(int)$version['version_no'].'：'.html($version['published_at']).'</li>';
            $body.='</ul><p>さらに項目を追加する場合は、既存の空欄を使い切った後に公開すると、新しい空欄が表示されます。</p>'
                .'<p><a href="?route=room-admin&amp;room_id='.rawurlencode($roomId).'">受信画面へ戻る</a></p>';
            page('フォーム編集：'.$room['name'],$body);
        }
        if (!$system) page('権限がありません','',403);
        if ($route === 'admin-create') {
            if ($method !== 'POST') page('操作できません','',405);
            checkCsrf();
            $login=trim((string)($_POST['login_id']??''));
            $code=(string)($_POST['secret']??'');
            $role=(string)($_POST['role']??'room');
            if (!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/',$login) || strlen($code)<12 || !in_array($role,['system','room'],true)) {
                page('入力エラー','<p>管理者ID・認証文字列・権限を確認してください。</p>',400);
            }
            try {
                $stmt=$db->prepare('INSERT INTO administrators(id,login_id,secret_hash,role) VALUES(?,?,?,?)');
                $stmt->execute([ChatService::id(),$login,password_hash($code,PASSWORD_DEFAULT),$role]);
                header('Location: ?route=admins',true,303);exit;
            } catch (Throwable $e) {
                error_log('Admin creation failed: '.$e->getMessage());
                page('登録失敗','<p>管理者IDの重複などを確認してください。</p>',400);
            }
        }
        if ($route === 'admin-unassign') {
            if ($method !== 'POST') page('操作できません','',405);
            checkCsrf();
            $db->prepare('DELETE FROM admin_room_roles WHERE admin_id=? AND room_id=?')
                ->execute([(string)($_POST['admin_id']??''),(string)($_POST['room_id']??'')]);
            header('Location: ?route=admins',true,303);exit;
        }
        if ($route === 'admin-assign') {
            if ($method !== 'POST') page('操作できません','',405);
            checkCsrf();
            $target=(string)($_POST['admin_id']??'');
            $room=(string)($_POST['room_id']??'');
            $stmt=$db->prepare("SELECT id FROM administrators WHERE id=? AND role='room' AND disabled_at IS NULL");
            $stmt->execute([$target]);
            if (!$stmt->fetchColumn() || !$service->roomById($room)) page('対象がありません','',404);
            $db->prepare("INSERT OR IGNORE INTO admin_room_roles(admin_id,room_id,role) VALUES(?,?,'manager')")->execute([$target,$room]);
            header('Location: ?route=admins',true,303);exit;
        }
        $admins=$db->query('SELECT id,login_id,role FROM administrators WHERE disabled_at IS NULL ORDER BY login_id')->fetchAll();
        $rooms=$service->roomsForAdmin($admin['id'],true);
        $body='<h2>管理者一覧</h2><ul>';
        foreach ($admins as $a) {
            $body.='<li>'.html($a['login_id']).'（'.html($a['role']).'）';
            if ($a['role']==='room') {
                $body.='<form method="post" action="?route=admin-assign">'.self::csrf()
                    .'<input type="hidden" name="admin_id" value="'.html($a['id']).'"><select name="room_id">';
                foreach ($rooms as $room) $body.='<option value="'.html($room['id']).'">'.html($room['name']).'</option>';
                $body.='</select><button>担当ルームを追加</button></form>';
                $assigned=$db->prepare('SELECT r.id,r.name FROM admin_room_roles ar JOIN rooms r ON r.id=ar.room_id WHERE ar.admin_id=? ORDER BY r.name');
                $assigned->execute([$a['id']]);
                foreach ($assigned->fetchAll() as $assignedRoom) {
                    $body.='<form method="post" action="?route=admin-unassign">'.self::csrf()
                        .'<input type="hidden" name="admin_id" value="'.html($a['id']).'">'
                        .'<input type="hidden" name="room_id" value="'.html($assignedRoom['id']).'">'
                        .html($assignedRoom['name']).' <button>担当解除</button></form>';
                }
            }
            $body.='</li>';
        }
        $body.='</ul><h2>管理者を追加</h2><form method="post" action="?route=admin-create">'.self::csrf()
            .'<label>管理者ID<input name="login_id" required minlength="3" maxlength="64"></label>'
            .'<label>認証文字列（12文字以上）<input name="secret" type="password" required minlength="12"></label>'
            .'<label>権限<select name="role"><option value="room">ルーム管理者</option><option value="system">システム管理者</option></select></label>'
            .'<button>管理者を作成</button></form><p><a href="?route=home">戻る</a></p>';
        page('管理者管理',$body);
    }

    private static function admin(PDO $db): ?array
    {
        if (!isset($_SESSION['admin_id'])) return null;
        $stmt=$db->prepare('SELECT id,login_id,role FROM administrators WHERE id=? AND disabled_at IS NULL');
        $stmt->execute([$_SESSION['admin_id']]);
        return $stmt->fetch()?:null;
    }

    private static function csrf(): string
    {
        return '<input type="hidden" name="csrf" value="'.html(csrfToken()).'">';
    }
}
