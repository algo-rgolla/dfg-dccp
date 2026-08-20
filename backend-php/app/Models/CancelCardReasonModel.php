<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class CancelCardReasonModel
{
    private PDO $conn;
    private string $lastError = '';

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
        $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function listAll(?string $active = null): array
    {
        $sql = "
            SELECT
                ReasonID,
                ReasonLabel,
                SortOrder,
                IsActive,
                CreatedAt,
                UpdatedAt
            FROM dbo.tblCancelCardReasons
            WHERE 1 = 1
        ";
        $params = [];

        if ($active !== null && $active !== '') {
            $sql .= " AND IsActive = :is_active";
            $params['is_active'] = $active === '1' ? 1 : 0;
        }

        $sql .= " ORDER BY SortOrder ASC, ReasonLabel ASC";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function listActive(): array
    {
        try {
            $stmt = $this->conn->query("
                SELECT
                    ReasonID,
                    ReasonLabel,
                    SortOrder
                FROM dbo.tblCancelCardReasons
                WHERE IsActive = 1
                ORDER BY SortOrder ASC, ReasonLabel ASC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function find(int $id): ?array
    {
        try {
            $stmt = $this->conn->prepare("
                SELECT *
                FROM dbo.tblCancelCardReasons
                WHERE ReasonID = :id
            ");
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    public function create(array $data): int
    {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO dbo.tblCancelCardReasons
                    (ReasonLabel, SortOrder, IsActive, UpdatedAt)
                VALUES
                    (:reason_label, :sort_order, :is_active, SYSUTCDATETIME());
                SELECT CAST(SCOPE_IDENTITY() AS int);
            ");
            $stmt->execute([
                'reason_label' => $data['ReasonLabel'],
                'sort_order' => $data['SortOrder'],
                'is_active' => $data['IsActive'],
            ]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return 0;
        }
    }

    public function update(int $id, array $data): bool
    {
        try {
            $stmt = $this->conn->prepare("
                UPDATE dbo.tblCancelCardReasons
                SET ReasonLabel = :reason_label,
                    SortOrder = :sort_order,
                    IsActive = :is_active,
                    UpdatedAt = SYSUTCDATETIME()
                WHERE ReasonID = :id
            ");
            $ok = $stmt->execute([
                'reason_label' => $data['ReasonLabel'],
                'sort_order' => $data['SortOrder'],
                'is_active' => $data['IsActive'],
                'id' => $id,
            ]);
            if ($ok && $stmt->rowCount() === 0) {
                $this->lastError = 'No rows updated. ReasonID may not exist.';
                return false;
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function delete(int $id): bool
    {
        try {
            $stmt = $this->conn->prepare("
                DELETE FROM dbo.tblCancelCardReasons
                WHERE ReasonID = :id
            ");
            $ok = $stmt->execute(['id' => $id]);
            if ($ok && $stmt->rowCount() === 0) {
                $this->lastError = 'No rows deleted. ReasonID may not exist.';
                return false;
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }
}
