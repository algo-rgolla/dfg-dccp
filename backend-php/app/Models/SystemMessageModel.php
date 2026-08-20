<?php
declare(strict_types=1);

namespace App\Models;

use PDO;
use App\Services\AudienceService;
use App\Models\EmailQueueModel;
use App\Services\MailService;
use App\Shared\SessionHelper;

class SystemMessageModel
{
    public function __construct(private PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getById(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM dbo.tblSystemMessage WHERE MessageID = :id");
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listAll(): array
    {
        $st = $this->db->query("
            SELECT
                MessageID,
                Title,
                Severity,
                Status,
                IsGlobal,
                RequireAck,
                ScopeGroupName,
                DeliveryStartUTC,
                DeliveryEndUTC,
                CreatedAtUTC,
                UpdatedAtUTC
            FROM dbo.tblSystemMessage
            ORDER BY CreatedAtUTC DESC, MessageID DESC
        ");
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Normalise severity: accepts int or strings like info/success/warning/danger */
    private function normalizeSeverity(mixed $sev): int
    {
        if (is_numeric($sev)) return (int)$sev;
        $map = ['info'=>1,'warning'=>2,'danger'=>3,'success'=>4];
        $key = strtolower((string)$sev);
        return $map[$key] ?? 1;
    }

    public function create(array $data, array $codes, array $userIds, array $roles): int
    {
        $this->db->beginTransaction();
        try {
            // Map incoming keys -> DB columns
            $title           = (string)($data['Title'] ?? '');
            $bodyHtml        = (string)($data['BodyHtml'] ?? $data['Body'] ?? '');
            $severity        = $this->normalizeSeverity($data['Severity'] ?? 1);
            $isGlobal        = (int)($data['IsGlobal'] ?? $data['AudienceGlobal'] ?? 0);
            $descTarget      = (int)($data['DescendantTarget'] ?? $data['IncludeDescendants'] ?? 0);
            $scopeGroupName  = trim((string)($data['ScopeGroupName'] ?? ''));
            $requireAck      = (int)($data['RequireAck'] ?? $data['RequiresAck'] ?? 0);
            $startUtc        = (string)($data['DeliveryStartUTC'] ?? $data['StartAt'] ?? gmdate('Y-m-d H:i:s'));
            $endUtc          = $data['DeliveryEndUTC'] ?? $data['EndAt'] ?? null;
            $status          = (string)($data['Status'] ?? 'draft'); // draft|published|archived
            $emailAlso       = (int)($data['EmailAlso'] ?? $data['SendEmail'] ?? 0);
            $emailSubject    = $data['EmailSubject'] ?? null;
            $emailBodyHtml   = $data['EmailBodyHtml'] ?? $bodyHtml;
            $createdBy       = isset($data['CreatedBy']) ? (int)$data['CreatedBy'] : null;

            // Insert (SQL Server): use OUTPUT to get the new ID in one round-trip
            $sql = "INSERT INTO dbo.tblSystemMessage
                    (Title, BodyHtml, Severity, IsGlobal, DescendantTarget,
                     ScopeGroupName, RequireAck,
                     DeliveryStartUTC, DeliveryEndUTC,
                     Status, EmailAlso, EmailSubject, EmailBodyHtml,
                     CreatedBy, CreatedAtUTC)
                    OUTPUT INSERTED.MessageID
                    VALUES
                    (:t, :bodyHtml, :sev, :isGlobal, :descTgt,
                     :scopeGroupName, :reqAck,
                     :startUtc, :endUtc,
                     :status, :emailAlso, :emailSubj, :emailBody,
                     :createdBy, SYSUTCDATETIME())";

            $st = $this->db->prepare($sql);
            $st->bindValue(':t',         $title);
            $st->bindValue(':bodyHtml',  $bodyHtml);
            $st->bindValue(':sev',       $severity, PDO::PARAM_INT);
            $st->bindValue(':isGlobal',  $isGlobal, PDO::PARAM_INT);
            $st->bindValue(':descTgt',   $descTarget, PDO::PARAM_INT);
            $scopeGroupName === '' ? $st->bindValue(':scopeGroupName', null, PDO::PARAM_NULL) : $st->bindValue(':scopeGroupName', $scopeGroupName);
            $st->bindValue(':reqAck',    $requireAck, PDO::PARAM_INT);
            $st->bindValue(':startUtc',  $startUtc);
            $endUtc === null ? $st->bindValue(':endUtc', null, PDO::PARAM_NULL) : $st->bindValue(':endUtc', (string)$endUtc);
            $st->bindValue(':status',    $status);
            $st->bindValue(':emailAlso', $emailAlso, PDO::PARAM_INT);
            $emailSubject === null ? $st->bindValue(':emailSubj', null, PDO::PARAM_NULL) : $st->bindValue(':emailSubj', (string)$emailSubject);
            $st->bindValue(':emailBody', $emailBodyHtml);
            $createdBy === null ? $st->bindValue(':createdBy', null, PDO::PARAM_NULL) : $st->bindValue(':createdBy', $createdBy, PDO::PARAM_INT);
            $st->execute();
            $id = (int)$st->fetchColumn();

            // Mapping tables
            if (!empty($codes)) {
                $ins = $this->db->prepare("INSERT INTO dbo.tblSystemMessageDataObject (MessageID, DataObjectCode) VALUES (:id,:c)");
                foreach ($codes as $c) {
                    $ins->bindValue(':id', $id, PDO::PARAM_INT);
                    $ins->bindValue(':c',  (string)$c);
                    $ins->execute();
                }
            }
            if (!empty($userIds)) {
                $inu = $this->db->prepare("INSERT INTO dbo.tblSystemMessageUser (MessageID, UserID) VALUES (:id,:u)");
                foreach ($userIds as $u) {
                    $inu->bindValue(':id', $id, PDO::PARAM_INT);
                    $inu->bindValue(':u',  (int)$u, PDO::PARAM_INT);
                    $inu->execute();
                }
            }
            if (!empty($roles)) {
                $inr = $this->db->prepare("INSERT INTO dbo.tblSystemMessageRole (MessageID, RoleName) VALUES (:id,:r)");
                foreach ($roles as $r) {
                    $inr->bindValue(':id', $id, PDO::PARAM_INT);
                    $inr->bindValue(':r',  (string)$r);
                    $inr->execute();
                }
            }

            $this->logEvent($id, $createdBy, 'created', null);
            $this->db->commit();

            if ($status === 'published' && $emailAlso === 1) {
                $this->scheduleEmails($id);
            }

            return $id;

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function updateMessage(int $messageId, array $data, array $codes, array $userIds, array $roles): void
    {
        $this->db->beginTransaction();
        try {
            $title           = (string)($data['Title'] ?? '');
            $bodyHtml        = (string)($data['BodyHtml'] ?? $data['Body'] ?? '');
            $severity        = $this->normalizeSeverity($data['Severity'] ?? 1);
            $isGlobal        = (int)($data['IsGlobal'] ?? $data['AudienceGlobal'] ?? 0);
            $descTarget      = (int)($data['DescendantTarget'] ?? $data['IncludeDescendants'] ?? 0);
            $scopeGroupName  = trim((string)($data['ScopeGroupName'] ?? ''));
            $requireAck      = (int)($data['RequireAck'] ?? $data['RequiresAck'] ?? 0);
            $startUtc        = (string)($data['DeliveryStartUTC'] ?? $data['StartAt'] ?? gmdate('Y-m-d H:i:s'));
            $endUtc          = $data['DeliveryEndUTC'] ?? $data['EndAt'] ?? null;
            $status          = (string)($data['Status'] ?? 'draft');
            $emailAlso       = (int)($data['EmailAlso'] ?? $data['SendEmail'] ?? 0);
            $emailSubject    = $data['EmailSubject'] ?? null;
            $emailBodyHtml   = $data['EmailBodyHtml'] ?? $bodyHtml;
            $updatedBy       = isset($data['UpdatedBy']) ? (int)$data['UpdatedBy'] : null;

            $sql = "UPDATE dbo.tblSystemMessage
                    SET Title = :t,
                        BodyHtml = :bodyHtml,
                        Severity = :sev,
                        IsGlobal = :isGlobal,
                        DescendantTarget = :descTgt,
                        ScopeGroupName = :scopeGroupName,
                        RequireAck = :reqAck,
                        DeliveryStartUTC = :startUtc,
                        DeliveryEndUTC = :endUtc,
                        Status = :status,
                        EmailAlso = :emailAlso,
                        EmailSubject = :emailSubj,
                        EmailBodyHtml = :emailBody,
                        UpdatedBy = :updatedBy,
                        UpdatedAtUTC = SYSUTCDATETIME()
                    WHERE MessageID = :id";

            $st = $this->db->prepare($sql);
            $st->bindValue(':t',         $title);
            $st->bindValue(':bodyHtml',  $bodyHtml);
            $st->bindValue(':sev',       $severity, PDO::PARAM_INT);
            $st->bindValue(':isGlobal',  $isGlobal, PDO::PARAM_INT);
            $st->bindValue(':descTgt',   $descTarget, PDO::PARAM_INT);
            $scopeGroupName === '' ? $st->bindValue(':scopeGroupName', null, PDO::PARAM_NULL) : $st->bindValue(':scopeGroupName', $scopeGroupName);
            $st->bindValue(':reqAck',    $requireAck, PDO::PARAM_INT);
            $st->bindValue(':startUtc',  $startUtc);
            $endUtc === null ? $st->bindValue(':endUtc', null, PDO::PARAM_NULL) : $st->bindValue(':endUtc', (string)$endUtc);
            $st->bindValue(':status',    $status);
            $st->bindValue(':emailAlso', $emailAlso, PDO::PARAM_INT);
            $emailSubject === null ? $st->bindValue(':emailSubj', null, PDO::PARAM_NULL) : $st->bindValue(':emailSubj', (string)$emailSubject);
            $st->bindValue(':emailBody', $emailBodyHtml);
            $updatedBy === null ? $st->bindValue(':updatedBy', null, PDO::PARAM_NULL) : $st->bindValue(':updatedBy', $updatedBy, PDO::PARAM_INT);
            $st->bindValue(':id', $messageId, PDO::PARAM_INT);
            $st->execute();

            $this->replaceAudienceMappings($messageId, $codes, $userIds, $roles);
            $this->logEvent($messageId, $updatedBy, 'updated', null);
            $this->db->commit();

            if ($status === 'published' && $emailAlso === 1) {
                $this->scheduleEmails($messageId);
            }
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function updateStatus(int $messageId, string $status, int $byUserId): void
    {
        $st = $this->db->prepare(
            "UPDATE dbo.tblSystemMessage
             SET Status = :s, UpdatedBy = :u, UpdatedAtUTC = SYSUTCDATETIME()
             WHERE MessageID = :id"
        );
        $st->bindValue(':s',  $status);
        $st->bindValue(':u',  $byUserId, PDO::PARAM_INT);
        $st->bindValue(':id', $messageId, PDO::PARAM_INT);
        $st->execute();

        $this->logEvent($messageId, $byUserId, $status === 'published' ? 'published' : 'archived', null);

        if ($status === 'published') {
            $row = $this->getById($messageId);
            if (!empty($row['EmailAlso'])) {
                $this->scheduleEmails($messageId);
            }
        }
    }

public function getActiveForUser(?int $userId, ?string $scopeCode, ?string $currentGroupName, ?int $currentFy = null): array
{
    $nowSql = "SYSUTCDATETIME()";
    $params = [];
    $joins  = [];
    $wheres = [
        "m.Status = 'published'",
        "m.DeliveryStartUTC <= {$nowSql}",
        "(m.DeliveryEndUTC IS NULL OR m.DeliveryEndUTC >= {$nowSql})",
    ];

    if ($userId) {
    // Show if it doesn't require ack, OR (requires ack AND user has NOT acked)
        $wheres[] = "(m.RequireAck = 0 OR NOT EXISTS (
                        SELECT 1
                        FROM dbo.tblSystemMessageAck a
                        WHERE a.MessageID = m.MessageID AND a.UserID = :uid
                    ))";
        $params[':uid'] = $userId;
    } else {
        // No user context => only show non-ack messages
        $wheres[] = "m.RequireAck = 0";
    }

    // Scope (CAPS GroupName)
    $currentGroupName = trim((string)($currentGroupName ?? ''));
    if ($currentGroupName !== '') {
        $wheres[] = "(m.ScopeGroupName IS NULL OR LTRIM(RTRIM(m.ScopeGroupName)) = '' OR LTRIM(RTRIM(m.ScopeGroupName)) = :group_scope)";
        $params[':group_scope'] = $currentGroupName;
    } else {
        $wheres[] = "(m.ScopeGroupName IS NULL OR LTRIM(RTRIM(m.ScopeGroupName)) = '')";
    }

    // Audience: global OR user OR role OR by DataObjectCode (with descendant targeting)
    $aud = [];
    $aud[] = "m.IsGlobal = 1";

    if ($userId) {
        // Specific users
        $aud[] = "EXISTS (
                    SELECT 1
                    FROM dbo.tblSystemMessageUser mu
                    WHERE mu.MessageID = m.MessageID
                      AND mu.UserID    = :uid_mu
                  )";
        $params[':uid_mu'] = (int)$userId;

        // Roles (JOIN through tblRoles)
        $aud[] = "EXISTS (
                    SELECT 1
                    FROM dbo.tblSystemMessageRole mr
                    JOIN dbo.tblUserRoles ur ON ur.UserID = :uid_roles
                    JOIN dbo.tblRoles r      ON r.RoleID  = ur.RoleID
                    WHERE mr.MessageID = m.MessageID
                      AND r.RoleName   = mr.RoleName
                  )";
        $params[':uid_roles'] = (int)$userId;
    }

    if ($scopeCode && $currentFy) {
        $aud[] = "EXISTS (
                    SELECT 1
                    FROM dbo.tblSystemMessageDataObject md
                    WHERE md.MessageID = m.MessageID
                      AND (
                           (m.DescendantTarget = 1 AND EXISTS (
                               SELECT 1
                               FROM dbo.tblDataObjectTree t
                               WHERE t.FiscalYearID   = :cfy_tree
                                 AND t.AncestorCode   = md.DataObjectCode
                                 AND t.DescendantCode = :scope_tree
                           ))
                           OR
                           (m.DescendantTarget = 0 AND md.DataObjectCode = :scope_exact)
                      )
                  )";
        $params[':cfy_tree']    = (int)$currentFy;
        $params[':scope_tree']  = $scopeCode;
        $params[':scope_exact'] = $scopeCode;
    }

    $wheres[] = '(' . implode(' OR ', $aud) . ')';

    $sql = "SELECT TOP 50 m.*
            FROM dbo.tblSystemMessage m
            " . implode("\n", $joins) . "
            WHERE " . implode(' AND ', $wheres) . "
            ORDER BY m.DeliveryStartUTC DESC, m.MessageID DESC";

    $st = $this->db->prepare($sql);
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

public function acknowledge(int $messageId, int $userId, ?string $ip = null): bool
{
    // Idempotent insert using positional placeholders (ODBC-safe).
    // Uses AckAtUTC; if your table has AckAt instead, uncomment the second SQL.
    $sql = "
        IF NOT EXISTS (
            SELECT 1
            FROM dbo.tblSystemMessageAck
            WHERE MessageID = ? AND UserID = ?
        )
        BEGIN
            INSERT INTO dbo.tblSystemMessageAck (MessageID, UserID, AckAt, AckFromIP)
            VALUES (?, ?, SYSUTCDATETIME(), ?)
        END
    ";

    // If your column is AckAt (not AckAtUTC), use this instead:
    // $sql = "
    //     IF NOT EXISTS (
    //         SELECT 1
    //         FROM dbo.tblSystemMessageAck
    //         WHERE MessageID = ? AND UserID = ?
    //     )
    //     BEGIN
    //         INSERT INTO dbo.tblSystemMessageAck (MessageID, UserID, AckAt, AckFromIP)
    //         VALUES (?, ?, SYSUTCDATETIME(), ?)
    //     END
    // ";

    $st = $this->db->prepare($sql);

    // Order matters with positional placeholders:
    // 1: EXISTS (MessageID)
    // 2: EXISTS (UserID)
    // 3: INSERT (MessageID)
    // 4: INSERT (UserID)
    // 5: INSERT (AckFromIP)
    $st->bindValue(1, $messageId, \PDO::PARAM_INT);
    $st->bindValue(2, $userId,    \PDO::PARAM_INT);
    $st->bindValue(3, $messageId, \PDO::PARAM_INT);
    $st->bindValue(4, $userId,    \PDO::PARAM_INT);
    $st->bindValue(5, $ip);

    $st->execute();

    $this->logEvent($messageId, $userId, 'acknowledged', $ip ?? null);
    return true;
}

private function replaceAudienceMappings(int $messageId, array $codes, array $userIds, array $roles): void
{
    $delCode = $this->db->prepare("DELETE FROM dbo.tblSystemMessageDataObject WHERE MessageID = :id");
    $delUser = $this->db->prepare("DELETE FROM dbo.tblSystemMessageUser WHERE MessageID = :id");
    $delRole = $this->db->prepare("DELETE FROM dbo.tblSystemMessageRole WHERE MessageID = :id");
    $delCode->bindValue(':id', $messageId, PDO::PARAM_INT);
    $delUser->bindValue(':id', $messageId, PDO::PARAM_INT);
    $delRole->bindValue(':id', $messageId, PDO::PARAM_INT);
    $delCode->execute();
    $delUser->execute();
    $delRole->execute();

    if (!empty($codes)) {
        $ins = $this->db->prepare("INSERT INTO dbo.tblSystemMessageDataObject (MessageID, DataObjectCode) VALUES (:id,:c)");
        foreach ($codes as $c) {
            $ins->bindValue(':id', $messageId, PDO::PARAM_INT);
            $ins->bindValue(':c', (string)$c);
            $ins->execute();
        }
    }

    if (!empty($userIds)) {
        $ins = $this->db->prepare("INSERT INTO dbo.tblSystemMessageUser (MessageID, UserID) VALUES (:id,:u)");
        foreach ($userIds as $u) {
            $ins->bindValue(':id', $messageId, PDO::PARAM_INT);
            $ins->bindValue(':u', (int)$u, PDO::PARAM_INT);
            $ins->execute();
        }
    }

    if (!empty($roles)) {
        $ins = $this->db->prepare("INSERT INTO dbo.tblSystemMessageRole (MessageID, RoleName) VALUES (:id,:r)");
        foreach ($roles as $r) {
            $ins->bindValue(':id', $messageId, PDO::PARAM_INT);
            $ins->bindValue(':r', (string)$r);
            $ins->execute();
        }
    }
}

// Minimal event logger for sys messages.
// Relies on tblSystemMessageEvent(EventAtUTC) having a DEFAULT SYSUTCDATETIME().
private function logEvent(int $messageId, ?int $userId, string $type, ?string $detail): void
{
    $sql = "INSERT INTO dbo.tblSystemMessageEvent (MessageID, UserID, EventType, Detail)
            VALUES (?, ?, ?, ?)";
    $st = $this->db->prepare($sql);
    $st->bindValue(1, $messageId, \PDO::PARAM_INT);
    if ($userId === null) {
        $st->bindValue(2, null, \PDO::PARAM_NULL);
    } else {
        $st->bindValue(2, $userId, \PDO::PARAM_INT);
    }
    $st->bindValue(3, $type);
    $st->bindValue(4, $detail);
    $st->execute();
}

private function scheduleEmails(int $messageId): void
{
    $row = $this->getById($messageId);
    if (!$row || empty($row['EmailAlso'])) {
        return;
    }

    $codes = $this->fetchMessageCodes($messageId);
    $userIds = $this->fetchMessageUsers($messageId);
    $roles = $this->fetchMessageRoles($messageId);

    $audience = new AudienceService($this->db);
    $scopeFy = (int) SessionHelper::get('context.FiscalYearID', 0);
    $resolvedUserIds = $audience->resolveUserIds(
        !empty($row['IsGlobal']),
        $codes,
        !empty($row['DescendantTarget']),
        $userIds,
        $roles,
        $scopeFy > 0 ? $scopeFy : null
    );

    $emails = $audience->resolveEmails($resolvedUserIds);
    if (!$emails) {
        return;
    }

    $subject = trim((string)($row['EmailSubject'] ?? ''));
    if ($subject === '') {
        $subject = (string)($row['Title'] ?? 'System Message');
    }

    $html = (string)($row['EmailBodyHtml'] ?? $row['BodyHtml'] ?? '');
    $when = (string)($row['DeliveryStartUTC'] ?? gmdate('Y-m-d H:i:s'));

    $batch = [];
    foreach ($emails as $email) {
        $batch[] = [
            'to' => (string)$email,
            'subject' => $subject,
            'html' => $html,
            'text' => strip_tags($html),
            'when' => $when,
            'MessageID' => $messageId,
        ];
    }

    $queue = new EmailQueueModel($this->db, new MailService($this->db));
    $queue->enqueueBatch($batch);
}

private function fetchMessageCodes(int $messageId): array
{
    $st = $this->db->prepare("SELECT DataObjectCode FROM dbo.tblSystemMessageDataObject WHERE MessageID = :id");
    $st->bindValue(':id', $messageId, PDO::PARAM_INT);
    $st->execute();
    return array_column($st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'DataObjectCode');
}

private function fetchMessageUsers(int $messageId): array
{
    $st = $this->db->prepare("SELECT UserID FROM dbo.tblSystemMessageUser WHERE MessageID = :id");
    $st->bindValue(':id', $messageId, PDO::PARAM_INT);
    $st->execute();
    return array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'UserID'));
}

private function fetchMessageRoles(int $messageId): array
{
    $st = $this->db->prepare("SELECT RoleName FROM dbo.tblSystemMessageRole WHERE MessageID = :id");
    $st->bindValue(':id', $messageId, PDO::PARAM_INT);
    $st->execute();
    return array_column($st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'RoleName');
}

}
