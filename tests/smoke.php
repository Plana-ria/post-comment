<?php
declare(strict_types=1);
require dirname(__DIR__) . '/public/bootstrap.php';
$_SERVER['DOCUMENT_ROOT']=dirname(__DIR__) . '/public';
if (postCommentRoot() !== realpath(dirname(__DIR__))) throw new RuntimeException('Bootstrap root discovery failed');
$_SERVER['DOCUMENT_ROOT']=dirname(__DIR__);
try { postCommentRoot(); throw new RuntimeException('Unsafe document root allowed'); } catch (RuntimeException $e) { if ($e->getMessage()==='Unsafe document root allowed') throw $e; }
$_SERVER['DOCUMENT_ROOT']=dirname(__DIR__) . '/public';
require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/Migrator.php';
require dirname(__DIR__) . '/src/FormDefinition.php';
require dirname(__DIR__) . '/src/ChatService.php';
require dirname(__DIR__) . '/src/CsvExport.php';
require dirname(__DIR__) . '/src/RoomForms.php';
require dirname(__DIR__) . '/src/LegacyRoomCatalog.php';
require dirname(__DIR__) . '/src/SignedPackage.php';
require dirname(__DIR__) . '/src/AdministratorService.php';
require dirname(__DIR__) . '/src/AuthThrottle.php';
require dirname(__DIR__) . '/src/BackupService.php';
require dirname(__DIR__) . '/src/RestoreService.php';
require dirname(__DIR__) . '/src/LegacyCsvImporter.php';
require dirname(__DIR__) . '/src/EmergencyRecovery.php';
require dirname(__DIR__) . '/src/ReleaseChecker.php';

use PostComment\Database;
use PostComment\Migrator;
use PostComment\FormDefinition;
use PostComment\ChatService;
use PostComment\CsvExport;
use PostComment\RoomForms;
use PostComment\LegacyRoomCatalog;
use PostComment\SignedPackage;
use PostComment\AdministratorService;
use PostComment\AuthThrottle;
use PostComment\BackupService;
use PostComment\RestoreService;
use PostComment\LegacyCsvImporter;
use PostComment\EmergencyRecovery;
use PostComment\ReleaseChecker;

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
    if ($applied !== ['001_initial', '002_auth_throttling', '003_admin_auth_revision', '004_legacy_import_unique']) {
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
    $service->joinRoom($authenticatedRoom,$user['id']);
    if (count($service->usersInRoom($authenticatedRoom))!==1) {
        throw new RuntimeException('Joined but not posted user missing');
    }
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
    $service->updateRoom($authenticatedRoom,'Renamed room','closed');
    if ($service->roomById($authenticatedRoom)['status']!=='closed') {
        throw new RuntimeException('Room closure not stored');
    }
    $service->updateRoom($authenticatedRoom,'Renamed room','active');
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

    $adminService = new AdministratorService($db);
    try {
        $adminService->change($adminId,$adminId,'disable');
        throw new RuntimeException('Self-disable unexpectedly succeeded');
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(),'unexpectedly succeeded')) throw $e;
    }
    $adminService->change($adminId,$roomAdminId,'disable');
    $disabled=$db->prepare('SELECT disabled_at FROM administrators WHERE id=?');
    $disabled->execute([$roomAdminId]);
    if (!$disabled->fetchColumn()) throw new RuntimeException('Admin disable failed');
    $adminService->change($adminId,$roomAdminId,'enable');
    $adminService->change($adminId,$roomAdminId,'make_system');
    $promoted=$db->prepare('SELECT role FROM administrators WHERE id=?');
    $promoted->execute([$roomAdminId]);
    if ($promoted->fetchColumn() !== 'system') throw new RuntimeException('Role promotion failed');
    $adminService->change($adminId,$roomAdminId,'make_room');
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
    try {
        $forms->publish($authenticatedRoom,$new,$adminId,$previousVersion);
        throw new RuntimeException('Stale form submission unexpectedly succeeded');
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(),'unexpectedly succeeded')) throw $e;
    }
    $stream = fopen('php://temp','w+b');
    $exportCount = CsvExport::admin($db,$authenticatedRoom,$stream);
    rewind($stream);
    $csvBytes = stream_get_contents($stream);
    fclose($stream);
    if ($exportCount !== 2 || !str_contains($csvBytes,'category')
        || !str_contains($csvBytes,'University')) {
        throw new RuntimeException('Full historical admin export missing fields');
    }
    $stream = fopen('php://temp','w+b');
    CsvExport::own($db,$authenticatedRoom,$user['id'],null,$stream);
    rewind($stream);
    $ownCsv = stream_get_contents($stream);
    fclose($stream);
    if (!str_starts_with($ownCsv,"\xEF\xBB\xBFtime,comment")
        || str_contains($ownCsv,'University')
        || str_contains($ownCsv,'last_name')) {
        throw new RuntimeException('Personal data leaked into participant CSV');
    }
    $editor = [
        'title'=>'アンケート','description'=>'テスト','submit_label'=>'送信',
        'fields'=>[
            ['key'=>'message','type'=>'textarea','label'=>'感想','required'=>'1','comment_body'=>'1'],
            ['key'=>'kind','type'=>'select','label'=>'種別','options'=>"質問\n感想",'description'=>'区分を選択','placeholder'=>'選択してください'],
        ],
    ];
    $parsed=RoomForms::fromEditor($editor);
    if ($parsed['fields'][1]['description']!=='区分を選択' || $parsed['fields'][1]['placeholder']!=='選択してください') throw new RuntimeException('Form display settings lost');
    if ($parsed['fields'][0]['role']!=='comment_body' || $parsed['fields'][1]['options']!==['質問','感想']) {
        throw new RuntimeException('Form editor serialization failed');
    }
    try {
        RoomForms::fromEditor(['title'=>'X','description'=>'','submit_label'=>'送信','fields'=>[
            ['key'=>'bad','type'=>'text','label'=>'Missing comment body']
        ]]);
        throw new RuntimeException('Form missing required body accepted');
    } catch (InvalidArgumentException $e) {}
    if (count(LegacyRoomCatalog::entries()) !== 18) throw new RuntimeException('Missing legacy room definitions');
    $imported=LegacyRoomCatalog::import($service,new RoomForms($db),$adminId,['4','18']);
    if ($imported['created']!==2) throw new RuntimeException('Legacy room templates could not be created');
    $again=LegacyRoomCatalog::import($service,new RoomForms($db),$adminId,['4','18']);
    if ($again['skipped']!==2) throw new RuntimeException('Legacy room import is not idempotent');
    $expo=$service->roomBySlug('legacy-room-18');
    $expoForm=$service->form($expo);
    if ($expo['auth_mode']!=='none' || $expoForm['fields'][2]['label']!=='所属') {
        throw new RuntimeException('EXPO room original form was not preserved');
    }
    $guestTemplateRoom=$service->createRoom('Imported guest','old-guest','none',$adminId,'guest');
    $guestForm=$service->form($service->roomById($guestTemplateRoom));
    if (count($guestForm['fields']) !== 2 || $guestForm['fields'][0]['key'] !== 'name') {
        throw new RuntimeException('Guest form template differs from legacy guest');
    }
    $csvPath=$dir.'/old-chat.csv';
    file_put_contents($csvPath,"\xEF\xBB\xBFtime,last_name,first_name,affiliation,mail,messenge\n2026/11/01 12:00:00,Yamada,Taro,University,,Historical post\n");
    $importer=new LegacyCsvImporter($db);
    $first=$importer->import($authenticatedRoom,$csvPath);
    $again=$importer->import($authenticatedRoom,$csvPath);
    if ($first['imported']!==1 || $again['duplicate']!==1) {
        throw new RuntimeException('Legacy CSV must be imported idempotently');
    }
    $oldUser=$db->query("SELECT user_id,anonymous_session_id FROM comments WHERE legacy_source_id IS NOT NULL")->fetch();
    if ($oldUser['user_id']!==null || $oldUser['anonymous_session_id']!==null) {
        throw new RuntimeException('Legacy import must not link to new user ID');
    }
    file_put_contents($csvPath,"time,name,messenge\n2026/11/02 12:00:00,Visitor,Guest comment\n");
    $guestImported=$importer->import($guestTemplateRoom,$csvPath);
    if ($guestImported['imported']!==1 || !$guestImported['guest']) {
        throw new RuntimeException('Guest CSV import failed');
    }
    unlink($csvPath);

    $limiter = new AuthThrottle($db);
    for ($i=0;$i<6;$i++) $limiter->failure('participant','test-account',6,900);
    if ($limiter->allow('participant','test-account')) {
        throw new RuntimeException('Rate limit did not block repeated failures');
    }
    $limiter->success('participant','test-account');
    if (!$limiter->allow('participant','test-account')) {
        throw new RuntimeException('Rate limit was not cleared on success');
    }
    $adminVersion=(int)$db->query("SELECT auth_version FROM administrators WHERE login_id='admin'")->fetchColumn();
    if ($adminVersion !== 1) throw new RuntimeException('Administrator auth revision migration failed');
    $code=(new EmergencyRecovery($db))->regenerate('admin');
    $reset=$db->query("SELECT secret_hash,auth_version FROM administrators WHERE login_id='admin'")->fetch();
    if ((int)$reset['auth_version']!==2 || !password_verify($code,$reset['secret_hash'])) {
        throw new RuntimeException('Emergency administrator reset failed');
    }
    $backupDir=$dir.'/backups';
    if (!mkdir($backupDir,0700)) throw new RuntimeException('Could not create backup directory');
    $backup=(new BackupService($db))->create($backupDir);
    BackupService::validateImport($backup);
    $copy=new PDO('sqlite:'.$backup,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if ($copy->query('SELECT COUNT(*) FROM comments')->fetchColumn() !== $db->query('SELECT COUNT(*) FROM comments')->fetchColumn()) {
        throw new RuntimeException('Backup does not contain current comments');
    }
    $copy=null;
    $active=$dir.'/restored-active.sqlite';
    $staged=$dir.'/restore-staged.sqlite';
    if (!copy($backup,$active) || !copy($backup,$staged)) throw new RuntimeException('Could not prepare restore test');
    $original=new PDO('sqlite:'.$active);
    $original->exec('DELETE FROM comments');
    $original=null;
    $previous=(new RestoreService())->restore($active,$staged,$backupDir,$dir.'/maintenance.lock');
    $restored=new PDO('sqlite:'.$active);
    if ((int)$restored->query('SELECT COUNT(*) FROM comments')->fetchColumn() < 2) {
        throw new RuntimeException('Browser restore backend lost comments');
    }
    $restored=null;
    unlink($active);
    unlink($previous);
    unlink($backup);
    unlink($dir.'/maintenance.lock');
    rmdir($backupDir);
    $fakeRelease=[
        'tag_name'=>'v1.2.3','draft'=>false,'prerelease'=>false,
        'assets'=>[[
            'name'=>'post-comment-portable.zip',
            'browser_download_url'=>'https://github.com/Plana-ria/post-comment/releases/download/v1.2.3/post-comment-portable.zip',
            'digest'=>'sha256:'.str_repeat('a',64),
        ]],
    ];
    if ((ReleaseChecker::fromApi($fakeRelease)['version']??null)!=='1.2.3') {
        throw new RuntimeException('Release parsing failed');
    }
    $fakeRelease['assets'][0]['browser_download_url']='https://attacker.invalid/release.zip';
    if (ReleaseChecker::fromApi($fakeRelease)!==null) {
        throw new RuntimeException('Untrusted release URL accepted');
    }
    if (extension_loaded('zip') && extension_loaded('sodium')) {
        $fixture=$dir.'/valid.zip';
        $signature=$dir.'/valid.sig';
        $zip=new ZipArchive();
        if ($zip->open($fixture,ZipArchive::CREATE)!==true) throw new RuntimeException('Test ZIP unavailable');
        foreach ([
            'post-comment-private/VERSION'=>"1.2.3\n",
            'post-comment-private/src/Database.php'=>'<?php',
            'post-comment-private/src/Migrator.php'=>'<?php',
            'public/index.php'=>'<?php',
            'public/bootstrap.php'=>'<?php',
        ] as $filename=>$data) $zip->addFromString($filename,$data);
        $zip->close();
        $pair=sodium_crypto_sign_keypair();
        $public=bin2hex(sodium_crypto_sign_publickey($pair));
        $private=sodium_crypto_sign_secretkey($pair);
        file_put_contents($signature,base64_encode(sodium_crypto_sign_detached(hash_file('sha256',$fixture,true),$private)));
        $info=SignedPackage::inspect($fixture,$signature,$public,'1.0.0');
        if ($info['version']!=='1.2.3' || count($info['entries'])!==5) throw new RuntimeException('Signed package inspection failed');
        file_put_contents($signature,base64_encode(random_bytes(64)));
        try {
            SignedPackage::inspect($fixture,$signature,$public,'1.0.0');
            throw new RuntimeException('Invalid signature accepted');
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(),'accepted')) throw $e;
        }
        @unlink($fixture);
        @unlink($signature);
    }
    echo "Smoke tests passed\n";
} finally {
    $db = null;
    @unlink($path);
    @unlink($path . '-journal');
    @rmdir($dir);
}
