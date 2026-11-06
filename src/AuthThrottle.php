<?php
declare(strict_types=1);

namespace PostComment;

use PDO;

/**
 * SQLite-backed bounded login throttling. Every failure updates an atomic row.
 * Identity hashes prevent the database from retaining raw IDs/IPs.
 */
final class AuthThrottle
{
    public function __construct(private PDO $db) {}

    public function allow(string $scope, string $identity): bool
    {
        $stmt = $this->db->prepare('SELECT blocked_until FROM auth_attempts WHERE scope=? AND identity_hash=?');
        $stmt->execute([$scope, hash('sha256', $identity)]);
        $blocked = $stmt->fetchColumn();
        return $blocked === false || (int)$blocked <= time();
    }

    public function failure(string $scope, string $identity, int $max=6, int $period=900): void
    {
        $now = time();
        $this->db->prepare('INSERT INTO auth_attempts(scope,identity_hash,window_start,attempts,blocked_until)
            VALUES (?,?,?,1,0)
            ON CONFLICT(scope,identity_hash) DO UPDATE SET
                window_start = CASE WHEN auth_attempts.window_start < ? THEN excluded.window_start ELSE auth_attempts.window_start END,
                attempts = CASE WHEN auth_attempts.window_start < ? THEN 1 ELSE auth_attempts.attempts+1 END,
                blocked_until = CASE
                    WHEN auth_attempts.window_start < ? THEN 0
                    WHEN auth_attempts.attempts+1 >= ? THEN ? ELSE auth_attempts.blocked_until END')
            ->execute([$scope,hash('sha256',$identity),$now,$now-$period,$now-$period,$now-$period,$max,$now+$period]);
    }

    public function success(string $scope, string $identity): void
    {
        $this->db->prepare('DELETE FROM auth_attempts WHERE scope=? AND identity_hash=?')
            ->execute([$scope,hash('sha256',$identity)]);
    }

    public function prune(): void
    {
        $this->db->prepare('DELETE FROM auth_attempts WHERE window_start < ? AND blocked_until < ?')
            ->execute([time()-86400,time()]);
    }
}
