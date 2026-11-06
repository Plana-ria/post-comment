<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/Migrator.php';
require dirname(__DIR__) . '/src/FormDefinition.php';
require dirname(__DIR__) . '/src/ChatService.php';
require dirname(__DIR__) . '/src/RoomForms.php';
require dirname(__DIR__) . '/src/AuthThrottle.php';

use PostComment\Database;
use PostComment\Migrator;
use PostComment\FormDefinition;
use PostComment\ChatService;
use PostComment\RoomForms;
use PostComment\AuthThrottle;

$dir = sys_get_temp_dir() . '/post-comment-test-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0700) && !is_dir($dir)) {
    throw new RuntimeException('Cannot create test directory');
}
$path = $dir . '/db.sqlite';
try {
    $db = Database::connect(['storage' => ['database_path' => $path]]);
    $folder = dirname(__DIR__) . '/database/migrations';
    if (!Migrator::pending($db, $folder)) {
        throw new RuntimeException('Initial migration unexpectedly absent');
    }
    $applied = Migrator::migrate($db, $folder);
    if ($applied !== ['001_initial', '002_auth_throttling']) {
        throw new RuntimeException('Migration failed');
    }
    if (Migrator::pending($db, $folder)) {
        throw new RuntimeException('Migration still pending');
    }
    if (Migrator::migrate($db, $folder) !== []) {
        throw new RuntimeException('Migration not idempotent');
    }
    $foreignKeys = (int) $db->query('PRAGMA foreign_keys')->fetchColumn();
    if ($foreignKeys !== 1) {
        throw new RuntimeException('Foreign key enforcement disabled');
    }
    FormDefinition::validate(FormDefinition::defaults());
    $bad = FormDefinition::defaults();
    $bad['fields'][4]['required'] = false;
    try {
        FormDefinition::validate($bad);
        throw new RuntimeException('Invalid form was accepted');
    } catch (InvalidArgumentException $e) {
        // Expected.
    }

    $service = new ChatService($db);
    $adminId = ChatService::id();
    $db->prepare("INSERT INTO administrators(id, login_id, secret_hash, role) VALUES(?, 'admin', 'test-only', 'system')")->execute([$adminId]);
    $authenticatedRoom = $service->createRoom('Test Lecture', 'test-lecture', 'required', $adminId);
    $anonymousRoom = $service->createRoom('Guest Session', 'guest-session', 'none', $adminId);
    $room = $service->roomById($authenticatedRoom);
    if (!$room || $room['auth_mode'] !== 'required' || count($service->form($room)['fields']) !== 5) {
        throw new RuntimeException('Room/form initialization failed');
    }
    $user = $service->registerUser();
    if (!$service->authenticateUser($user['login_id'], $user['code'])) {
        throw new RuntimeException('Login code authentication failed');
    }
    $input = ['last_name'=>'Yamada','first_name'=>'Taro','affiliation'=>'University','email'=>'','comment'=>'Hello'];
    $service->submit($room, $service->form($room), $input, $user['id'], null);
    $own = $service->ownComments($authenticatedRoom, $user['id'], null);
    if (count($own) !== 1 || array_keys($own[0]) !== ['created_at','comment_text'] || $own[0]['comment_text'] !== 'Hello') {
        throw new RuntimeException('Participant export contract failed');
    }
    $code = $service->resetCode($user['id'], $adminId);
    if ($service->authenticateUser($user['login_id'], $user['code']) || !$service->authenticateUser($user['login_id'], $code)) {
        throw new RuntimeException('Code reset failed');
    }
    $guestRoom = $service->roomById($anonymousRoom);
    $anon = $service->acceptAnonymous($anonymousRoom, null);
    if ($service->anonymousId($anonymousRoom, $anon['token']) !== $anon['id']) {
        throw new RuntimeException('Anonymous session verification failed');
    }
    $service->submit($guestRoom, $service->form($guestRoom), $input, null, $anon['id']);
    if (count($service->ownComments($anonymousRoom, null, $anon['id'])) !== 1) {
        throw new RuntimeException('Anonymous export failed');
    }
    if (count($service->adminComments($anonymousRoom, 0, null)) !== 1) {
        throw new RuntimeException('Admin room export failed');
    }

    $roomAdminId = ChatService::id();
    $db->prepare("INSERT INTO administrators(id,login_id,secret_hash,role) VALUES(?,'manager','test-only','room')")->execute([$roomAdminId]);
    if ($service->isManager($roomAdminId,$authenticatedRoom,false)) {
        throw new RuntimeException('Unassigned manager unexpectedly authorized');
    }
    $db->prepare("INSERT INTO admin_room_roles(admin_id,room_id,role) VALUES(?,?,'manager')")
        ->execute([$roomAdminId,$authenticatedRoom]);
    if (!$service->isManager($roomAdminId,$authenticatedRoom,false)
        || $service->isManager($roomAdminId,$anonymousRoom,false)) {
        throw new RuntimeException('Manager room isolation failed');
    }
    if (count($service->roomsForAdmin($roomAdminId,false)) !== 1) {
        throw new RuntimeException('Room list exposes unauthorized room');
    }

    $forms = new RoomForms($db);
    $original = $service->form($room);
    $previousVersion = $room['active_form_version_id'];
    $new = $original;
    $new['title'] = '授業への質問';
    $new['fields'][4]['label'] = '質問内容';
    $new['fields'][] = ['key'=>'category','type'=>'select','label'=>'分類','required'=>true,'options'=>['質問','感想']];
    $version = $forms->publish($authenticatedRoom,$new,$adminId);
    if ($version !== 2 || count($forms->versions($authenticatedRoom)) !== 2) {
        throw new RuntimeException('Form version publication failed');
    }
    $room = $service->roomById($authenticatedRoom);
    $input['category'] = '質問';
    $service->submit($room,$service->form($room),$input,$user['id'],null);
    $past=$db->prepare('SELECT form_version_id FROM comments WHERE room_id=? ORDER BY id');
    $past->execute([$authenticatedRoom]);
    $linked=$past->fetchAll(PDO::FETCH_COLUMN);
    if (count($linked)!==2 || $linked[0]!==$previousVersion || $linked[1]!==$room['active_form_version_id']) {
        throw new RuntimeException('Historical form versions were not preserved');
    }
    $editor = [
        'title'=>'アンケート','description'=>'テスト','submit_label'=>'送信',
        'fields'=>[
            ['key'=>'message','type'=>'textarea','label'=>'感想','required'=>'1','comment_body'=>'1'],
            ['key'=>'kind','type'=>'select','label'=>'種別','options'=>"質問\n感想"],
        ],
    ];
    $parsed=RoomForms::fromEditor($editor);
    if ($parsed['fields'][0]['role']!=='comment_body' || $parsed['fields'][1]['options']!==['質問','感想']) {
        throw new RuntimeException('Form editor serialization failed');
    }
    try {
        RoomForms::fromEditor(['title'=>'X','description'=>'','submit_label'=>'送信','fields'=>[
            ['key'=>'bad','type'=>'text','label'=>'Missing comment body']
        ]]);
        throw new RuntimeException('Form missing required body accepted');
    } catch (InvalidArgumentException $e) {}
    $limiter = new AuthThrottle($db);
    for ($i=0;$i<6;$i++) $limiter->failure('participant','test-account',6,900);
    if ($limiter->allow('participant','test-account')) {
        throw new RuntimeException('Rate limit did not block repeated failures');
    }
    $limiter->success('participant','test-account');
    if (!$limiter->allow('participant','test-account')) {
        throw new RuntimeException('Rate limit was not cleared on success');
    }
    echo "Smoke tests passed\n";
} finally {
    $db = null;
    @unlink($path);
    @unlink($path . '-journal');
    @rmdir($dir);
}
