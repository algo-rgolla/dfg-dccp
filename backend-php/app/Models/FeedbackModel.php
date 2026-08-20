<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class FeedbackModel
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function findByEmployeeId(string $employeeId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT TOP 1 FeedbackID, EmployeeID, Stars, Comments, UpdatedBy, DateUpdated
            FROM dbo.tblPORTALFeedback
            WHERE EmployeeID = :employee_id
            ORDER BY FeedbackID DESC
        ");

        $stmt->execute([':employee_id' => $employeeId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function saveForEmployee(string $employeeId, int $stars, string $comments, ?int $updatedBy): bool
    {
        $existing = $this->findByEmployeeId($employeeId);

        if ($existing !== null) {
            $sql = "UPDATE dbo.tblPORTALFeedback
                    SET Stars = :stars,
                        Comments = :comments,
                        UpdatedBy = :updated_by,
                        DateUpdated = SYSUTCDATETIME()
                    WHERE FeedbackID = :feedback_id";

            $stmt = $this->conn->prepare($sql);

            return $stmt->execute([
                ':feedback_id' => (int)$existing['FeedbackID'],
                ':stars' => $stars,
                ':comments' => $comments,
                ':updated_by' => ($updatedBy ?? 0) > 0 ? $updatedBy : null,
            ]);
        }

        $sql = "INSERT INTO dbo.tblPORTALFeedback
                    (EmployeeID, Stars, Comments, UpdatedBy, DateUpdated)
                VALUES
                    (:employee_id, :stars, :comments, :updated_by, SYSUTCDATETIME())";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':employee_id' => $employeeId,
            ':stars' => $stars,
            ':comments' => $comments,
            ':updated_by' => ($updatedBy ?? 0) > 0 ? $updatedBy : null,
        ]);
    }

    public function getSummary(): array
    {
        $stmt = $this->conn->query("
            SELECT
                COUNT(*) AS TotalFeedback,
                COUNT(DISTINCT NULLIF(LTRIM(RTRIM(EmployeeID)), '')) AS TotalEmployees,
                CAST(AVG(CASE WHEN Stars BETWEEN 1 AND 5 THEN CAST(Stars AS DECIMAL(10,2)) END) AS DECIMAL(10,2)) AS AverageStars,
                MIN(DateUpdated) AS FirstUpdated,
                MAX(DateUpdated) AS LastUpdated
            FROM dbo.tblPORTALFeedback
        ");

        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        return is_array($row) ? $row : [];
    }

    public function getStarBreakdown(): array
    {
        $stmt = $this->conn->query("
            SELECT
                Stars,
                COUNT(*) AS FeedbackCount
            FROM dbo.tblPORTALFeedback
            WHERE Stars BETWEEN 1 AND 5
            GROUP BY Stars
            ORDER BY Stars DESC
        ");

        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        return is_array($rows) ? $rows : [];
    }

    public function listRecent(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        $stmt = $this->conn->query("
            SELECT TOP {$limit}
                f.FeedbackID,
                f.EmployeeID,
                f.Stars,
                f.Comments,
                f.UpdatedBy,
                f.DateUpdated,
                u.Username AS UpdatedByUsername
            FROM dbo.tblPORTALFeedback f
            LEFT JOIN dbo.tblUsers u
              ON u.UserID = f.UpdatedBy
            ORDER BY f.DateUpdated DESC, f.FeedbackID DESC
        ");

        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        return is_array($rows) ? $rows : [];
    }
}
