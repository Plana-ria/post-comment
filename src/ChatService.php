<?php
declare(strict_types=1);

namespace PostComment;

use PDO;
use RuntimeException;

/**
 * Database operations behind the web controller. Every query is parameterized.
 * User codes are generated randomly and shown only at registration/reset.
 */
final class ChatService
{
    public function __construct(private PDO $db) {}

    public static function id(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function code(): string
    {
        return strtoupper(implode('-', str_split(bin2hex(random_bytes(16)), 8)));
    }

    public function roomsForAdmin(string $adminId, bool $system): array
    {
        if ($system) {
            return $this->db->query('SELECT * FROM rooms ORDER BY created_at DESC')->fetchAll();
        }
        $stmt = $this->db->prepare('SELECT r.* FROM rooms r JOIN admin_room_roles ar ON ar.room_id=r.id WHERE ar.admin_id=? ORDER BY r.created_at DESC');
        $stmt->execute([$adminId]);
        return $stmt->fetchAll();
    }

    public function isManager(string $adminId, string $roomId, bool $system): bool
    {
        if ($system) return true;
        $stmt = $this->db->prepare('SELECT 1 FROM admin_room_roles WHERE admin_id=? AND room_id=?');
        $stmt->execute([$adminId, $roomId]);
        return (bool) $stmt->fetchColumn();
    }

    public function createRoom(string $name, string $slug, string $mode, string $creatorId): string
    {
        if (trim($name) === '' || mb_strlen($name) > 160) {
            throw new RuntimeException('Room name must be 1–160 characters.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $slug)) {
            throw new RuntimeException('Room slug must use lowercase letters, digits, and hyphens.');
        }
        if (!in_array($mode, ['required','none'], true)) {
            throw new RuntimeException('Invalid authentication mode.');
        }
        $id = self::id();
        $formId = self::id();
        $definition = FormDefinition::defaults();
        FormDefinition::validate($definition);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('INSERT INTO rooms(id, slug, name, auth_mode, active_form_version_id, created_at) VALUES(?,?,?,?,?,?)');
            $stmt->execute([$id, $slug, $name, $mode, $formId, $now]);
            $stmt = $this->db->prepare('INSERT INTO form_versions(id, room_id, version_no, definition_json, published_at) VALUES(?,?,?,?,?)');
            $stmt->execute([$formId, $id, 1, json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $now]);
            $stmt = $this->db->prepare("INSERT INTO admin_room_roles(admin_id,room_id,role) VALUES(?,?,'manager')");
            $stmt->execute([$creatorId, $id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $id;
    }

    public function roomBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM rooms WHERE slug=?');
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }

    public function roomById(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM rooms WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function form(array $room): array
    {
        $stmt = $this->db->prepare('SELECT definition_json FROM form_versions WHERE id=? AND room_id=?');
        $stmt->execute([$room['active_form_version_id'], $room['id']]);
        $raw = $stmt->fetchColumn();
        if ($raw === false) throw new RuntimeException('Room form unavailable.');
        $form = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        FormDefinition::validate($form);
        return $form;
    }

    public function registerUser(): array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $login = strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
            $code = self::code();
            $id = self::id();
            try {
                $stmt = $this->db->prepare('INSERT INTO users(id,login_id,code_hash,created_at) VALUES(?,?,?,?)');
                $stmt->execute([$id,$login,password_hash($code, PASSWORD_DEFAULT),gmdate('Y-m-d\TH:i:s\Z')]);
                return ['id'=>$id,'login_id'=>$login,'code'=>$code];
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
            }
        }
        throw new RuntimeException('Could not allocate user ID.');
    }

    public function authenticateUser(string $login, string $code): ?array
    {
        $stmt = $this->db->prepare('SELECT id, login_id, code_hash FROM users WHERE login_id=? AND disabled_at IS NULL');
        $stmt->execute([$login]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($code, $user['code_hash'])) return null;
        return ['id'=>$user['id'],'login_id'=>$user['login_id']];
    }

    public function activeUser(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id,login_id FROM users WHERE id=? AND disabled_at IS NULL');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function resetCode(string $userId, string $adminId): string
    {
        $code = self::code();
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('UPDATE users SET code_hash=?, code_version=code_version+1 WHERE id=? AND disabled_at IS NULL');
            $stmt->execute([password_hash($code, PASSWORD_DEFAULT),$userId]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('User not found.');
            $this->db->prepare('UPDATE user_sessions SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL')
                ->execute([gmdate('Y-m-d\TH:i:s\Z'),$userId]);
            $this->db->prepare("INSERT INTO audit_events(actor_id,action,target_type,target_id,created_at) VALUES(?,'user_code_reset','user',?,?)")
                ->execute([$adminId,$userId,gmdate('Y-m-d\TH:i:s\Z')]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $code;
    }

    public function usersInRoom(string $roomId): array
    {
        $stmt = $this->db->prepare('SELECT u.id,u.login_id,COUNT(c.id) AS posts FROM room_participants p JOIN users u ON u.id=p.user_id LEFT JOIN comments c ON c.room_id=p.room_id AND c.user_id=u.id WHERE p.room_id=? GROUP BY u.id,u.login_id ORDER BY u.login_id');
        $stmt->execute([$roomId]);
        return $stmt->fetchAll();
    }

    public function acceptAnonymous(string $roomId, ?string $cookieToken, int $hours=24): array
    {
        if ($cookieToken !== null && preg_match('/^[a-f0-9]{64}$/', $cookieToken)) {
            $stmt = $this->db->prepare('SELECT id FROM anonymous_sessions WHERE room_id=? AND token_hash=? AND revoked_at IS NULL AND expires_at>?');
            $stmt->execute([$roomId,hash('sha256',$cookieToken),gmdate('Y-m-d\TH:i:s\Z')]);
            $id = $stmt->fetchColumn();
            if ($id) return ['id'=>$id,'token'=>null];
        }
        $id = self::id();
        $token = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare('INSERT INTO anonymous_sessions(id,room_id,token_hash,expires_at) VALUES(?,?,?,?)');
        $stmt->execute([$id,$roomId,hash('sha256',$token),gmdate('Y-m-d\TH:i:s\Z',time()+$hours*3600)]);
        return ['id'=>$id,'token'=>$token];
    }

    public function anonymousId(string $roomId, ?string $token): ?string
    {
        if ($token === null || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $stmt=$this->db->prepare('SELECT id FROM anonymous_sessions WHERE room_id=? AND token_hash=? AND revoked_at IS NULL AND expires_at>?');
        $stmt->execute([$roomId,hash('sha256',$token),gmdate('Y-m-d\TH:i:s\Z')]);
        return $stmt->fetchColumn() ?: null;
    }

    public function submit(array $room, array $form, array $input, ?string $userId, ?string $anonymousId): void
    {
        if ($room['status'] !== 'active') throw new RuntimeException('Room is closed.');
        if ($room['auth_mode'] === 'required' && !$userId) throw new RuntimeException('Login required.');
        if ($room['auth_mode'] === 'none' && !$anonymousId) throw new RuntimeException('Anonymous session required.');
        $values = [];
        $comment = null;
        foreach ($form['fields'] as $field) {
            $key=$field['key'];
            $value=$input[$key] ?? '';
            if (!is_string($value)) throw new RuntimeException('Invalid field value.');
            $value=trim($value);
            if (mb_strlen($value) > ($key==='comment' || ($field['role']??'')==='comment_body' ? 10000 : 1000)) {
                throw new RuntimeException('Field too long.');
            }
            if (!empty($field['required']) && $value==='') throw new RuntimeException('Required field is missing: '.$field['label']);
            if ($field['type']==='email' && $value!=='' && !filter_var($value,FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email address.');
            }
            if (in_array($field['type'],['select','radio'],true) && $value!=='' && !in_array($value,$field['options']??[],true)) {
                throw new RuntimeException('Invalid option.');
            }
            $values[$key]=$value;
            if (($field['role']??'')==='comment_body') $comment=$value;
        }
        if ($comment === null || $comment==='') throw new RuntimeException('Comment body required.');
        $this->db->beginTransaction();
        try {
            if ($userId !== null) {
                $this->db->prepare('INSERT OR IGNORE INTO room_participants(room_id,user_id,first_joined_at) VALUES(?,?,?)')
                    ->execute([$room['id'],$userId,gmdate('Y-m-d\TH:i:s\Z')]);
            }
            $stmt=$this->db->prepare('INSERT INTO comments(room_id,user_id,anonymous_session_id,form_version_id,comment_text,submitted_values_json,created_at) VALUES(?,?,?,?,?,?,?)');
            $stmt->execute([$room['id'],$userId,$anonymousId,$room['active_form_version_id'],$comment,json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),gmdate('Y-m-d\TH:i:s\Z')]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function ownComments(string $roomId, ?string $userId, ?string $anonymousId): array
    {
        if ($userId !== null) {
            $stmt=$this->db->prepare('SELECT created_at,comment_text FROM comments WHERE room_id=? AND user_id=? ORDER BY id');
            $stmt->execute([$roomId,$userId]);
        } elseif ($anonymousId !== null) {
            $stmt=$this->db->prepare('SELECT created_at,comment_text FROM comments WHERE room_id=? AND anonymous_session_id=? ORDER BY id');
            $stmt->execute([$roomId,$anonymousId]);
        } else {
            return [];
        }
        return $stmt->fetchAll();
    }

    public function adminComments(string $roomId, int $after=0): array
    {
        $stmt=$this->db->prepare('SELECT c.id,c.created_at,c.comment_text,c.submitted_values_json,u.login_id FROM comments c LEFT JOIN users u ON u.id=c.user_id WHERE c.room_id=? AND c.id>? ORDER BY c.id ASC LIMIT 500');
        $stmt->execute([$roomId,$after]);
        return $stmt->fetchAll();
    }
}
