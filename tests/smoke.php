<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/Migrator.php';
require dirname(__DIR__) . '/src/FormDefinition.php';

use PostComment\Database;
use PostComment\Migrator;
use PostComment\FormDefinition;

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
    echo "Smoke tests passed\n";
} finally {
    $db = null;
    @unlink($path);
    @unlink($path . '-journal');
    @rmdir($dir);
}
