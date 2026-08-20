<?php
declare(strict_types=1);

namespace App\Models;

final class DataObjectCodeAccessModel
{
    private \PDO $pdo;
    private string $lastError = '';

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    // Get all ACTIVE access (with user/code names)
    public function getAll(int $fy): array
    {
        $sql = "
            SELECT 
                a.UserID, 
                u.Username, 
                u.Email,
                u.FirstName + ' ' + u.LastName AS FullName,
                a.DataObjectCode, 
                c.DataObjectName,
                a.AccessLevel, 
                a.AssignedBy, 
                ab.Username AS AssignedByName,
                a.AssignedAt, 
                a.Revoked, 
                a.RevokedBy, 
                rb.Username AS RevokedByName,
                a.RevokedAt
            FROM tblDataObjectCodeAccess a
            JOIN tblUsers u ON a.UserID = u.UserID
            JOIN tblDataObjectCodes c ON a.DataObjectCode = c.DataObjectCode 
                                      AND a.FiscalYearID = c.FiscalYearID
            LEFT JOIN tblUsers ab ON a.AssignedBy = ab.UserID
            LEFT JOIN tblUsers rb ON a.RevokedBy = rb.UserID
            WHERE a.FiscalYearID = :fy AND a.Revoked = 0
            ORDER BY a.AssignedAt DESC
        ";
        try {
            $st = $this->pdo->prepare($sql);
            $st->execute([':fy' => $fy]);
            return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    // Get all users
    public function getUsers(): array
    {
        try {
            $st = $this->pdo->query("
                SELECT UserID, Username, Email, 
                    FirstName + ' ' + LastName AS FullName 
                FROM tblUsers 
                WHERE IsActive = 1 
                ORDER BY Username
            ");
            return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    // Get codes for fiscal year
   public function getCodes(int $fy): array
{
    $sql = "
        WITH CodeHierarchy AS (
            SELECT 
                DataObjectCode,
                DataObjectName,
                DataObjectCodeParent,
                0 AS Level
            FROM tblDataObjectCodes
            WHERE (DataObjectCodeParent IS NULL OR DataObjectCodeParent = '')

            UNION ALL

            SELECT 
                c.DataObjectCode,
                c.DataObjectName,
                c.DataObjectCodeParent,
                h.Level + 1
            FROM tblDataObjectCodes c
            INNER JOIN CodeHierarchy h 
                ON c.DataObjectCodeParent = h.DataObjectCode
        )
        SELECT 
            DataObjectCode,
            DataObjectName,
            Level
        FROM CodeHierarchy
        ORDER BY Level, DataObjectCode
        OPTION (MAXRECURSION 0)
    ";
    try {
        $st = $this->pdo->prepare($sql);
        $st->execute(); // NO PARAMETERS
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $this->lastError = $e->getMessage();
        return [];
    }
}
    // Grant access (UPSERT with MERGE)
    public function grant(
        int $userId, 
        int $fy, 
        string $code, 
        string $level, 
        int $assignedBy,
        bool $includeChildren = true  // NEW
    ): bool {
        // ... existing validation ...

        $sql = "
            MERGE INTO tblDataObjectCodeAccess AS target
            USING (SELECT ? AS UserID, ? AS FiscalYearID, ? AS DataObjectCode) AS source
            ON target.UserID = source.UserID 
            AND target.FiscalYearID = source.FiscalYearID 
            AND target.DataObjectCode = source.DataObjectCode
            WHEN MATCHED THEN
                UPDATE SET 
                    AccessLevel = ?,
                    AssignedBy = ?,
                    AssignedAt = SYSUTCDATETIME(),
                    IncludeChildren = ?,
                    Revoked = 0,
                    RevokedBy = NULL,
                    RevokedAt = NULL
            WHEN NOT MATCHED THEN
                INSERT (UserID, FiscalYearID, DataObjectCode, AccessLevel, AssignedBy, AssignedAt, IncludeChildren)
                VALUES (?, ?, ?, ?, ?, SYSUTCDATETIME(), ?);
        ";
        $st = $this->pdo->prepare($sql);
        return $st->execute([
            $userId, $fy, $code,
            $level, $assignedBy, $includeChildren ? 1 : 0,
            $userId, $fy, $code, $level, $assignedBy, $includeChildren ? 1 : 0
        ]);
    }

    // Revoke access
    public function revoke(int $userId, int $fy, string $code, int $revokedBy): bool
{
    try {
        $sql = "
            UPDATE tblDataObjectCodeAccess
            SET Revoked = 1, 
                RevokedBy = ?, 
                RevokedAt = SYSUTCDATETIME()
            WHERE UserID = ? 
              AND FiscalYearID = ? 
              AND DataObjectCode = ?
        ";
        $st = $this->pdo->prepare($sql);
        return $st->execute([$revokedBy, $userId, $fy, $code]);
    } catch (\Throwable $e) {
        $this->lastError = $e->getMessage();
        return false;
    }
}

    // Get cascade codes (for hierarchy)
    public function getCascadeCodes(int $fy, string $parentCode): array
    {
        $sql = "
            WITH CodeTree AS (
                SELECT DataObjectCode
                FROM tblDataObjectCodes
                WHERE FiscalYearID = :fy AND DataObjectCode = :parent

                UNION ALL

                SELECT c.DataObjectCode
                FROM tblDataObjectCodes c
                INNER JOIN CodeTree t ON c.DataObjectCodeParent = t.DataObjectCode
                WHERE c.FiscalYearID = :fy
            )
            SELECT DataObjectCode FROM CodeTree
        ";
        try {
            $st = $this->pdo->prepare($sql);
            $st->execute([':fy' => $fy, ':parent' => $parentCode]);
            return $st->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

// Get user by ID
public function getUserById(int $userId): ?array
{
    $sql = "SELECT UserID, Username, Email, FirstName, LastName FROM tblUsers WHERE UserID = :uid";
    try {
        $st = $this->pdo->prepare($sql);
        $st->execute([':uid' => $userId]);
        $user = $st->fetch(\PDO::FETCH_ASSOC);
        if ($user) {
            $user['FullName'] = trim(($user['FirstName'] ?? '') . ' ' . ($user['LastName'] ?? ''));
        }
        return $user ?: null;
    } catch (\Throwable $e) {
        $this->lastError = $e->getMessage();
        return null;
    }
}

// Get direct access only
public function getDirectAccess(int $userId, int $fy): array
{
    $sql = "
        SELECT a.DataObjectCode, c.DataObjectName, a.AccessLevel
        FROM tblDataObjectCodeAccess a
        JOIN tblDataObjectCodes c ON a.DataObjectCode = c.DataObjectCode AND a.FiscalYearID = c.FiscalYearID
        WHERE a.UserID = :uid AND a.FiscalYearID = :fy AND a.Revoked = 0
        ORDER BY a.DataObjectCode
    ";
    try {
        $st = $this->pdo->prepare($sql);
        $st->execute([':uid' => $userId, ':fy' => $fy]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $this->lastError = $e->getMessage();
        return [];
    }
}

// Get accessible codes WITH level
public function getUserAccessibleCodesWithLevel(int $userId, int $fy): array
{
    $sql = "
       WITH UserDirectAccess AS (
    SELECT DataObjectCode, IncludeChildren
    FROM tblDataObjectCodeAccess
    WHERE UserID = @uid AND FiscalYearID = @fy AND Revoked = 0
),
CodeHierarchy AS (
    SELECT 
        doc.DataObjectCode,
        doc.DataObjectCodeParent,
        0 AS HierarchyLevel
    FROM tblDataObjectCodes doc
    INNER JOIN UserDirectAccess uda 
        ON doc.DataObjectCode = uda.DataObjectCode
    WHERE doc.FiscalYearID = @fy

    UNION ALL

    SELECT 
        c.DataObjectCode,
        c.DataObjectCodeParent,
        h.HierarchyLevel + 1
    FROM tblDataObjectCodes c
    INNER JOIN CodeHierarchy h 
        ON c.DataObjectCodeParent = h.DataObjectCode
    INNER JOIN UserDirectAccess uda 
        ON h.DataObjectCode = uda.DataObjectCode
    WHERE c.FiscalYearID = @fy
      AND uda.IncludeChildren = 1
)";
    try {
        $st = $this->pdo->prepare($sql);
        $st->execute([$userId, $fy, $fy, $fy, $fy, $fy]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $this->lastError = $e->getMessage();
        return [];
    }
}

public function getUserAccessibleCodes(int $userId, int $fy): array
{
    $sql = "
        WITH UserDirectAccess AS (
            SELECT DataObjectCode
            FROM tblDataObjectCodeAccess
            WHERE UserID = @uid AND FiscalYearID = @fy AND Revoked = 0
        ),
        CodeHierarchy AS (
            SELECT DataObjectCode
            FROM tblDataObjectCodes
            WHERE DataObjectCode IN (SELECT DataObjectCode FROM UserDirectAccess)
              AND FiscalYearID = @fy

            UNION ALL

            SELECT c.DataObjectCode
            FROM tblDataObjectCodes c
            INNER JOIN CodeHierarchy h ON c.DataObjectCodeParent = h.DataObjectCode
            WHERE c.FiscalYearID = @fy
        )
        SELECT DISTINCT DataObjectCode
        FROM CodeHierarchy
    ";
    try {
        $st = $this->pdo->prepare($sql);
        $st->execute(['@uid' => $userId, '@fy' => $fy]);
        return $st->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    } catch (\Throwable $e) {
        $this->lastError = $e->getMessage();
        return [];
    }
}
}