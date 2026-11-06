<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/Migrator.php';
require dirname(__DIR__) . '/src/FormDefinition.php';
require dirname(__DIR__) . '/src/ChatService.php';

use PostComment\Database;
use PostComment\Migrator;
use PostComment\FormDefinition;
use PostComment\ChatService;

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
    if ($applied !== ['001_initial']) {
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
    echo "Smoke tests passed\n";
} finally {
    $db = null;
    @unlink($path);
    @unlink($path . '-journal');
    @rmdir($dir);
}
