<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

class AudienceService
{
    public function __construct(private PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Resolve audience UserIDs based on message targeting.
     *
     * @param bool        $global
     * @param array       $codes                List of DataObjectCodes
     * @param bool        $includeDescendants   If true, expand codes using tblDataObjectTree (Depth>=0)
     * @param array       $userIds              Explicit user IDs
     * @param array       $roles                Role names
     * @param int|null    $scopeFy              FiscalYear filter for code targeting (null = ignore code targeting)
     * @return int[]                           Unique UserIDs
     */
    public function resolveUserIds(
        bool $global,
        array $codes,
        bool $includeDescendants,
        array $userIds,
        array $roles,
        ?int $scopeFy
    ): array {
        $ids = [];

        // 1) Global audience
        if ($global) {
            $q = $this->db->query("SELECT UserID FROM dbo.tblUsers WHERE IsActive = 1");
            $ids = array_merge($ids, array_map('intval', array_column($q->fetchAll(PDO::FETCH_ASSOC), 'UserID')));
        }

        // 2) Specific users
        if (!empty($userIds)) {
            $ids = array_merge($ids, array_map('intval', $userIds));
        }

        // 3) Roles (assuming dbo.tblUserRoles(UserID, RoleName))
        if (!empty($roles)) {
            $place = implode(',', array_fill(0, count($roles), '?'));
            $sql = "SELECT DISTINCT ur.UserID
                    FROM dbo.tblUserRoles ur
                    JOIN dbo.tblUsers u ON u.UserID = ur.UserID AND u.IsActive = 1
                    WHERE ur.RoleName IN ($place)";
            $st = $this->db->prepare($sql);
            foreach ($roles as $i => $r) { $st->bindValue($i+1, $r); }
            try {
                $st->execute();
                $ids = array_merge($ids, array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'UserID')));
            } catch (\Throwable $e) {
                // If you don't have tblUserRoles, skip silently or log
            }
        }

        // 4) DataObject codes (requires FY)
        if ($scopeFy && !empty($codes)) {
            $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));
            if ($codes) {
                if ($includeDescendants) {
                    // Expand via tree
                    $place = implode(',', array_fill(0, count($codes), '?'));
                    $sql = "WITH CTE_Codes AS (
                              SELECT DISTINCT DescendantCode AS Code
                              FROM dbo.tblDataObjectTree
                              WHERE FiscalYearID = ? AND AncestorCode IN ($place) AND Depth >= 0
                            )
                            SELECT DISTINCT a.UserID
                            FROM dbo.tblDataObjectAccess a
                            JOIN CTE_Codes c ON c.Code = a.DataObjectCode
                            JOIN dbo.tblUsers u ON u.UserID = a.UserID AND u.IsActive = 1
                            WHERE a.FiscalYearID = ?";
                    $st = $this->db->prepare($sql);
                    $idx = 1;
                    $st->bindValue($idx++, $scopeFy, PDO::PARAM_INT);
                    foreach ($codes as $c) { $st->bindValue($idx++, $c); }
                    $st->bindValue($idx++, $scopeFy, PDO::PARAM_INT);
                } else {
                    $place = implode(',', array_fill(0, count($codes), '?'));
                    $sql = "SELECT DISTINCT a.UserID
                            FROM dbo.tblDataObjectAccess a
                            JOIN dbo.tblUsers u ON u.UserID = a.UserID AND u.IsActive = 1
                            WHERE a.FiscalYearID = ? AND a.DataObjectCode IN ($place)";
                    $st = $this->db->prepare($sql);
                    $idx = 1;
                    $st->bindValue($idx++, $scopeFy, PDO::PARAM_INT);
                    foreach ($codes as $c) { $st->bindValue($idx++, $c); }
                }

                try {
                    $st->execute();
                    $ids = array_merge($ids, array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'UserID')));
                } catch (\Throwable $e) {
                    // log or ignore
                }
            }
        }

        // Unique & normalized
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        return $ids;
    }

    /**
     * Resolve emails for given UserIDs.
     * Skips users without emails or inactive.
     */
    public function resolveEmails(array $userIds): array
    {
        if (!$userIds) return [];
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $chunks = array_chunk($userIds, 900); // stay under SQL parameter limits
        $emails = [];

        foreach ($chunks as $chunk) {
            $place = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "SELECT Email FROM dbo.tblUsers WHERE IsActive = 1 AND Email IS NOT NULL AND LEN(Email) > 3 AND UserID IN ($place)";
            $st  = $this->db->prepare($sql);
            foreach ($chunk as $i => $id) { $st->bindValue($i+1, $id, PDO::PARAM_INT); }
            $st->execute();
            $emails = array_merge($emails, array_column($st->fetchAll(PDO::FETCH_ASSOC), 'Email'));
        }
        // Deduplicate (case-insensitive)
        $emails = array_values(array_unique(array_map('strtolower', $emails)));
        return $emails;
    }
}
