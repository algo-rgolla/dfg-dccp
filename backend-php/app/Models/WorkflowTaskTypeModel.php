<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class WorkflowTaskTypeModel
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function listActive(): array
    {
        $sql = "SELECT TaskTypeID, Name
                FROM dbo.tblWorkflowTaskTypes
                WHERE IsActive = 1
                ORDER BY Name ASC";
        $stmt = $this->conn->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['TaskTypeID']] = $r['Name'];
        }
        return $out;
    }

     /** ✅ Find a single type name by ID */
    public function findNameById(int $id): ?string
    {
        try {
            $sql = "SELECT Name FROM dbo.tblWorkflowTaskTypes WHERE TaskTypeID = :id";
            $st  = $this->conn->prepare($sql);
            $st->bindValue(':id', $id, PDO::PARAM_INT);
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row['Name'] ?? null;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }
}
