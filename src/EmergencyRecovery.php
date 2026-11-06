<?php
declare(strict_types=1);

namespace PostComment;

use PDO;
use RuntimeException;

/**
 * No backdoor or fixed master secret: requires a one-use proof file placed
 * outside the document root by a person with filesystem write access.
 */
final class EmergencyRecovery
{
    public function __construct(private PDO $db) {}

    public function regenerate(string $loginId): string
    {
        $stmt=$this->db->prepare("SELECT id FROM administrators WHERE login_id=? AND role='system' AND disabled_at IS NULL");
        $stmt->execute([$loginId]);
        $id=$stmt->fetchColumn();
        if (!$id) throw new RuntimeException('指定されたシステム管理者は存在しません。');
        $newCode=strtoupper(implode('-',str_split(bin2hex(random_bytes(24)),12)));
        $this->db->beginTransaction();
        try {
            $stmt=$this->db->prepare('UPDATE administrators SET secret_hash=?, auth_version=auth_version+1 WHERE id=?');
            $stmt->execute([password_hash($newCode,PASSWORD_DEFAULT),$id]);
            $stmt=$this->db->prepare("INSERT INTO audit_events(actor_id,action,target_type,target_id,created_at) VALUES(NULL,'emergency_admin_reset','administrator',?,?)");
            $stmt->execute([$id,gmdate('Y-m-d\TH:i:s\Z')]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $newCode;
    }
}
