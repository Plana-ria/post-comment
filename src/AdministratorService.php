<?php
declare(strict_types=1);

namespace PostComment;

use PDO;
use RuntimeException;
use Throwable;

/** System administrator lifecycle with last-admin lockout protection. */
final class AdministratorService
{
    public function __construct(private PDO $db) {}

    public function change(string $actorId, string $targetId, string $operation): void
    {
        if (!in_array($operation,['disable','enable','make_system','make_room'],true)) {
            throw new RuntimeException('Invalid administrator operation.');
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $actor=$this->db->prepare("SELECT role FROM administrators WHERE id=? AND disabled_at IS NULL");
            $actor->execute([$actorId]);
            if ($actor->fetchColumn() !== 'system') throw new RuntimeException('System administrator required.');
            $get=$this->db->prepare('SELECT role, disabled_at FROM administrators WHERE id=?');
            $get->execute([$targetId]);
            $target=$get->fetch();
            if (!$target) throw new RuntimeException('Administrator does not exist.');
            if ($actorId === $targetId && in_array($operation,['disable','make_room'],true)) {
                throw new RuntimeException('Cannot remove your own system administration access.');
            }
            if ($target['role'] === 'system'
                && $target['disabled_at'] === null
                && in_array($operation,['disable','make_room'],true)) {
                $count=(int)$this->db->query("SELECT COUNT(*) FROM administrators WHERE role='system' AND disabled_at IS NULL")->fetchColumn();
                if ($count <= 1) throw new RuntimeException('At least one active system administrator is required.');
            }
            $stmt=match($operation) {
                'disable' => $this->db->prepare("UPDATE administrators SET disabled_at=?,auth_version=auth_version+1 WHERE id=? AND disabled_at IS NULL"),
                'enable' => $this->db->prepare("UPDATE administrators SET disabled_at=NULL,auth_version=auth_version+1 WHERE id=? AND disabled_at IS NOT NULL"),
                'make_system' => $this->db->prepare("UPDATE administrators SET role='system',auth_version=auth_version+1 WHERE id=? AND role<>'system'"),
                'make_room' => $this->db->prepare("UPDATE administrators SET role='room',auth_version=auth_version+1 WHERE id=? AND role<>'room'"),
            };
            $params=$operation==='disable' ? [gmdate('Y-m-d\TH:i:s\Z'),$targetId] : [$targetId];
            $stmt->execute($params);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('No administrator change was necessary.');
            $audit=$this->db->prepare('INSERT INTO audit_events(actor_id,action,target_type,target_id,created_at) VALUES(?,?,?,?,?)');
            $audit->execute([$actorId,'admin_'.$operation,'administrator',$targetId,gmdate('Y-m-d\TH:i:s\Z')]);
            $this->db->exec('COMMIT');
        } catch (Throwable $e) {
            try { $this->db->exec('ROLLBACK'); } catch (Throwable $rollbackError) {}
            throw $e;
        }
    }
}
