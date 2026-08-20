<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

class DataObjectWorkflowStatusModel
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        // If your connection doesn't already set this:
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getStatus(int $fy, int $ver, string $code): ?string
    {
        $sql = "SELECT TOP 1 Status
                FROM dbo.tblDataObjectWorkflowStatus
                WHERE FiscalYearID = :fy AND VersionID = :ver AND DataObjectCode = :code
                ORDER BY DateUpdated DESC, WorkflowStatusID DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':fy',   $fy,   PDO::PARAM_INT);
        $stmt->bindValue(':ver',  $ver,  PDO::PARAM_INT);
        $stmt->bindValue(':code', $code, PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['Status'] ?? null;
    }

    public function setStatus(int $fy, int $ver, string $code, string $status, int $userId): void
{
    $this->db->beginTransaction();
    try {
        // --- Get all descendants (including self)
        $sqlTree = "SELECT DescendantCode 
                      FROM dbo.tblDataObjectTree
                     WHERE FiscalYearID = :fy
                       AND AncestorCode = :code";
        $treeStmt = $this->db->prepare($sqlTree);
        $treeStmt->bindValue(':fy', $fy, PDO::PARAM_INT);
        $treeStmt->bindValue(':code', $code, PDO::PARAM_STR);
        $treeStmt->execute();

        $codes = $treeStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$codes) {
            $codes = [$code]; // fallback → at least update the one passed in
        }

        // --- Upsert each code in workflow status table
        $sqlUpdate = "UPDATE dbo.tblDataObjectWorkflowStatus
                         SET Status = :status,
                             UpdatedBy = :user,
                             DateUpdated = SYSUTCDATETIME()
                       WHERE FiscalYearID = :fy
                         AND VersionID = :ver
                         AND DataObjectCode = :code";
        $stmtU = $this->db->prepare($sqlUpdate);

        $sqlInsert = "INSERT INTO dbo.tblDataObjectWorkflowStatus
                        (FiscalYearID, VersionID, DataObjectCode, Status, UpdatedBy, DateUpdated)
                      VALUES (:fy, :ver, :code, :status, :user, SYSUTCDATETIME())";
        $stmtI = $this->db->prepare($sqlInsert);

        foreach ($codes as $c) {
            // Try update
            $stmtU->execute([
                ':status' => $status,
                ':user'   => $userId,
                ':fy'     => $fy,
                ':ver'    => $ver,
                ':code'   => $c,
            ]);

            if ($stmtU->rowCount() === 0) {
                // If no row updated → insert
                $stmtI->execute([
                    ':fy'     => $fy,
                    ':ver'    => $ver,
                    ':code'   => $c,
                    ':status' => $status,
                    ':user'   => $userId,
                ]);
            }
        }

        $this->db->commit();
    } catch (\Throwable $e) {
        $this->db->rollBack();
        throw $e;
    }
}

}
