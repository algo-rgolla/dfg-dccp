<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class UserSessionModel
{
    public function __construct(private PDO $db) {}

    private function debugEnabled(): bool
    {
        $val = getenv('APP_DEBUG');
        if ($val === false) {
            return false;
        }
        return in_array(strtolower(trim((string)$val)), ['1', 'true', 'yes', 'on'], true);
    }

    private function debugLog(string $message): void
    {
        if ($this->debugEnabled()) {
            error_log($message);
        }
    }

    public function create(string $sid, int $userId, string $username, string $ip, string $ua, ?int $idleSeconds = null): void
    {
        $idleSeconds = $idleSeconds ?? 3600; // Default to 1 hour
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $idleSeconds);
        $sql = "INSERT INTO dbo.tblUserSessions
                (SessionID, UserID, Username, IP, UserAgent, LoginTime, LastActivity, ExpiresAt, IsActive, ForceLogout)
                VALUES (:sid, :uid, :username, :ip, :ua, SYSUTCDATETIME(), SYSUTCDATETIME(), :expiresAt, 1, 0)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'sid' => $sid,
            'uid' => $userId,
            'username' => $username,
            'ip' => $ip,
            'ua' => $ua,
            'expiresAt' => $expiresAt
        ]);
        $this->debugLog("[SESSION DEBUG] UserSessionModel: Created session record for session_id={$sid}, user_id={$userId}, expiresAt={$expiresAt}");
    }

    public function ensure(string $sid, ?int $userId, ?string $username, string $ip, string $ua, ?int $idleSeconds): void
    {
        $exists = $this->find($sid);
        if ($exists) {
            $this->touch($sid, $idleSeconds);
            return;
        }
        $this->create($sid, (int)($userId ?? 0), (string)($username ?? 'guest'), $ip, $ua, $idleSeconds);
    }

    public function find(string $sid): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM dbo.tblUserSessions WHERE SessionID = :sid");
        $stmt->execute(['sid' => $sid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->debugLog("[SESSION DEBUG] UserSessionModel: Find session_id={$sid}, result=" . json_encode($row));
        return $row ?: null;
    }

    public function touch(string $sid, ?int $idleSeconds = null): void
    {
        $idleSeconds = $idleSeconds ?? 3600; // Default to 1 hour
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $idleSeconds);
        $sql = "UPDATE dbo.tblUserSessions
                SET LastActivity = SYSUTCDATETIME(),
                    ExpiresAt = :expiresAt
                WHERE SessionID = :sid AND IsActive = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['expiresAt' => $expiresAt, 'sid' => $sid]);
        $this->debugLog("[SESSION DEBUG] UserSessionModel: Touched session record for session_id={$sid}, expiresAt={$expiresAt}");
    }

    public function logout(string $sid): void
    {
        $stmt = $this->db->prepare("
            UPDATE dbo.tblUserSessions
            SET IsActive = 0, LogoutTime = SYSUTCDATETIME(), ForceLogout = 1
            WHERE SessionID = :sid
        ");
        $stmt->execute(['sid' => $sid]);
        $this->debugLog("[SESSION DEBUG] UserSessionModel: Logged out session_id={$sid}");
    }

    public function listActive(int $limit = 200): array
    {
        $sql = "SELECT TOP {$limit} * FROM dbo.vUserSessionsActive ORDER BY LastActivity DESC";
        $stmt = $this->db->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $this->debugLog("[SESSION DEBUG] UserSessionModel: List active sessions, count=" . count($rows));
        return $rows;
    }
}
