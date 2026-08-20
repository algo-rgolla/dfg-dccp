<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class LimitChangeReasonModel
{
    private PDO $conn;
    private string $lastError = '';
    private const ALLOWED_APPLICATION_TYPE_KEYS = [
        'dpc_limit_change',
        'dtc_limit_change',
        'dpc_transaction_limit',
        'lodge_limit_change',
    ];

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
        $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function listAll(?int $applicationTypeId = null, ?string $active = null): array
    {
        $sql = "
            SELECT
                r.ReasonID,
                r.ApplicationTypeID,
                at.ApplicationTypeKey,
                at.ApplicationTypeName,
                r.ReasonLabel,
                r.SortOrder,
                r.IsActive,
                r.CreatedAt,
                r.UpdatedAt
            FROM dbo.tblLimitChangeReasons r
            INNER JOIN dbo.tblApplicationTypes at
                ON at.ApplicationTypeID = r.ApplicationTypeID
            WHERE 1 = 1
        ";
        $params = [];

        if ($applicationTypeId !== null && $applicationTypeId > 0) {
            $sql .= " AND r.ApplicationTypeID = :application_type_id";
            $params['application_type_id'] = $applicationTypeId;
        }

        if ($active !== null && $active !== '') {
            $sql .= " AND r.IsActive = :is_active";
            $params['is_active'] = $active === '1' ? 1 : 0;
        }

        $sql .= " ORDER BY at.ApplicationTypeName ASC, r.SortOrder ASC, r.ReasonLabel ASC";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function listActiveByApplicationType(int $applicationTypeId): array
    {
        try {
            $stmt = $this->conn->prepare("
                SELECT
                    ReasonID,
                    ApplicationTypeID,
                    ReasonLabel,
                    SortOrder
                FROM dbo.tblLimitChangeReasons
                WHERE ApplicationTypeID = :application_type_id
                  AND IsActive = 1
                ORDER BY SortOrder ASC, ReasonLabel ASC
            ");
            $stmt->execute(['application_type_id' => $applicationTypeId]);
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
                FROM dbo.tblLimitChangeReasons
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
                INSERT INTO dbo.tblLimitChangeReasons
                    (ApplicationTypeID, ReasonLabel, SortOrder, IsActive, UpdatedAt)
                VALUES
                    (:application_type_id, :reason_label, :sort_order, :is_active, SYSUTCDATETIME());
                SELECT CAST(SCOPE_IDENTITY() AS int);
            ");
            $stmt->execute([
                'application_type_id' => $data['ApplicationTypeID'],
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
                UPDATE dbo.tblLimitChangeReasons
                SET ApplicationTypeID = :application_type_id,
                    ReasonLabel = :reason_label,
                    SortOrder = :sort_order,
                    IsActive = :is_active,
                    UpdatedAt = SYSUTCDATETIME()
                WHERE ReasonID = :id
            ");
            $ok = $stmt->execute([
                'application_type_id' => $data['ApplicationTypeID'],
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
                DELETE FROM dbo.tblLimitChangeReasons
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

    public function listApplicationTypeOptions(): array
    {
        try {
            $placeholders = [];
            $params = [];
            foreach (self::ALLOWED_APPLICATION_TYPE_KEYS as $index => $key) {
                $param = ':key_' . $index;
                $placeholders[] = $param;
                $params[$param] = $key;
            }

            $stmt = $this->conn->prepare("
                SELECT
                    ApplicationTypeID,
                    ApplicationTypeKey,
                    ApplicationTypeName
                FROM dbo.tblApplicationTypes
                WHERE IsActive = 1
                  AND LOWER(ApplicationTypeKey) IN (" . implode(', ', $placeholders) . ")
                ORDER BY ApplicationTypeName ASC
            ");
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }
}
