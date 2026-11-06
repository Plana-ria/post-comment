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
        $hash = hash('sha256', $identity);
        $this->db->prepare('INSERT OR IGNORE INTO auth_attempts(scope,identity_hash,window_start,attempts,blocked_until)
            VALUES (?,?,?,0,0)')->execute([$scope,$hash,$now]);
        $stmt = $this->db->prepare('UPDATE auth_attempts SET
            attempts = CASE WHEN window_start <= ? THEN 1 ELSE attempts+1 END,
            blocked_until = CASE
                WHEN window_start <= ? THEN 0
                WHEN attempts+1 >= ? THEN ? ELSE blocked_until END,
            window_start = CASE WHEN window_start <= ? THEN ? ELSE window_start END
            WHERE scope=? AND identity_hash=?');
        $stmt->execute([$now-$period,$now-$period,$max,$now+$period,$now-$period,$now,$scope,$hash]);
        // Lock on the threshold even for a newly inserted row and guard
        // against different SQLite expression evaluation order.
        $this->db->prepare('UPDATE auth_attempts SET blocked_until=? WHERE scope=? AND identity_hash=?
            AND attempts >= ? AND blocked_until < ?')
            ->execute([$now+$period,$scope,$hash,$max,$now]);
        // Shared hosting has no required cron scheduler. Opportunistically
        // remove expired attempts to prevent unbounded SQLite table growth.
        if (random_int(1,128) === 1) $this->prune();
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
