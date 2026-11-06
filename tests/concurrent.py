#!/usr/bin/env python3
"""Run concurrent PHP processes against the actual SQLite ChatService."""
import concurrent.futures
import sqlite3
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INCLUDES = r"""
require $argv[1] . '/src/Database.php';
require $argv[1] . '/src/Migrator.php';
require $argv[1] . '/src/FormDefinition.php';
require $argv[1] . '/src/ChatService.php';
$db = \PostComment\Database::connect(['storage'=>['database_path'=>$argv[2]]]);
"""
SETUP = INCLUDES + r"""
\PostComment\Migrator::migrate($db,$argv[1].'/database/migrations');
$id=\PostComment\ChatService::id();
$db->prepare("INSERT INTO administrators(id,login_id,secret_hash,role) VALUES(?,'owner','test','system')")
    ->execute([$id]);
$service=new \PostComment\ChatService($db);
$service->createRoom('Parallel Room','parallel-room','none',$id,'guest');
"""
WORKER = INCLUDES + r"""
$service=new \PostComment\ChatService($db);
$room=$service->roomBySlug('parallel-room');
$form=$service->form($room);
$anon=$service->acceptAnonymous($room['id'],null);
for ($i=0;$i<12;$i++) {
    $service->submit($room,$form,[
        'name'=>'Worker '.$argv[3],
        'comment'=>'Worker '.$argv[3].' comment '.$i
    ],null,$anon['id']);
}
"""

def run_php(script, database, worker=None):
    cmd = ["php", "-r", script, "--", str(ROOT), str(database)]
    if worker is not None:
        cmd.append(str(worker))
    result = subprocess.run(cmd, text=True, capture_output=True, timeout=40)
    if result.returncode:
        raise RuntimeError(
            f"Concurrent PHP failed ({result.returncode}): "
            f"{result.stdout[-500:]} {result.stderr[-1500:]}"
        )

with tempfile.TemporaryDirectory(prefix="pc-concurrency-") as tmp:
    db_path = Path(tmp) / "comments.sqlite"
    run_php(SETUP, db_path)
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as executor:
        futures = [executor.submit(run_php, WORKER, db_path, i) for i in range(8)]
        for future in futures:
            future.result()
    with sqlite3.connect(db_path) as connection:
        count, distinct = connection.execute(
            "SELECT COUNT(*),COUNT(DISTINCT comment_text) FROM comments"
        ).fetchone()
        errors = connection.execute("PRAGMA integrity_check").fetchone()[0]
        foreign = connection.execute("PRAGMA foreign_key_check").fetchall()
    if count != 96 or distinct != 96 or errors != "ok" or foreign:
        raise AssertionError(
            f"Parallel submissions lost/duplicated data: {count}, "
            f"distinct={distinct}, integrity={errors}, foreign={foreign}"
        )
print("Concurrent SQLite submissions passed (8 workers × 12 posts)")
