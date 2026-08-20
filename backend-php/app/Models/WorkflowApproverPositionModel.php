<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class WorkflowApproverPositionModel
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

    public function listAll(?string $approverType = null, ?string $employeeGroup = null, ?string $active = null): array
    {
        $sql = "
            SELECT
                ApproverPositionID,
                ApproverType,
                EmployeeGroup,
                PositionNumber,
                Email,
                DisplayName,
                IsActive,
                CreatedAt,
                UpdatedAt
            FROM dbo.tblWorkflowApproverPositions
            WHERE 1 = 1
        ";
        $params = [];

        if ($approverType !== null && trim($approverType) !== '') {
            $sql .= " AND ApproverType = :approverType";
            $params['approverType'] = trim($approverType);
        }

        if ($employeeGroup !== null && trim($employeeGroup) !== '') {
            $sql .= " AND ISNULL(EmployeeGroup, '') = :employeeGroup";
            $params['employeeGroup'] = trim($employeeGroup);
        }

        if ($active !== null && $active !== '') {
            $sql .= " AND IsActive = :isActive";
            $params['isActive'] = $active === '1' ? 1 : 0;
        }

        $sql .= " ORDER BY IsActive DESC, ApproverType ASC, EmployeeGroup ASC, PositionNumber ASC, ApproverPositionID DESC";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
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
                FROM dbo.tblWorkflowApproverPositions
                WHERE ApproverPositionID = :id
            ");
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    public function listDistinctApproverTypes(): array
    {
        try {
            $stmt = $this->conn->query("
                SELECT DISTINCT ApproverType
                FROM dbo.tblWorkflowApproverPositions
                WHERE LTRIM(RTRIM(ISNULL(ApproverType, ''))) <> ''
                ORDER BY ApproverType
            ");
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function listDistinctEmployeeGroups(): array
    {
        try {
            $stmt = $this->conn->query("
                SELECT DISTINCT EmployeeGroup
                FROM dbo.tblWorkflowApproverPositions
                WHERE LTRIM(RTRIM(ISNULL(EmployeeGroup, ''))) <> ''
                ORDER BY EmployeeGroup
            ");
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function create(array $data): int
    {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO dbo.tblWorkflowApproverPositions
                    (ApproverType, EmployeeGroup, PositionNumber, Email, DisplayName, IsActive, UpdatedAt)
                OUTPUT INSERTED.ApproverPositionID
                VALUES
                    (:approver_type, :employee_group, :position_number, :email, :display_name, :is_active, SYSUTCDATETIME());
            ");
            $stmt->execute([
                'approver_type' => $data['ApproverType'],
                'employee_group' => $data['EmployeeGroup'] !== '' ? $data['EmployeeGroup'] : null,
                'position_number' => $data['PositionNumber'],
                'email' => $data['Email'] !== '' ? $data['Email'] : null,
                'display_name' => $data['DisplayName'] !== '' ? $data['DisplayName'] : null,
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
                UPDATE dbo.tblWorkflowApproverPositions
                SET ApproverType = :approver_type,
                    EmployeeGroup = :employee_group,
                    PositionNumber = :position_number,
                    Email = :email,
                    DisplayName = :display_name,
                    IsActive = :is_active,
                    UpdatedAt = SYSUTCDATETIME()
                WHERE ApproverPositionID = :id
            ");
            $ok = $stmt->execute([
                'approver_type' => $data['ApproverType'],
                'employee_group' => $data['EmployeeGroup'] !== '' ? $data['EmployeeGroup'] : null,
                'position_number' => $data['PositionNumber'],
                'email' => $data['Email'] !== '' ? $data['Email'] : null,
                'display_name' => $data['DisplayName'] !== '' ? $data['DisplayName'] : null,
                'is_active' => $data['IsActive'],
                'id' => $id,
            ]);
            if ($ok && $stmt->rowCount() === 0) {
                $this->lastError = 'No rows updated. ApproverPositionID may not exist.';
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
                DELETE FROM dbo.tblWorkflowApproverPositions
                WHERE ApproverPositionID = :id
            ");
            $ok = $stmt->execute(['id' => $id]);
            if ($ok && $stmt->rowCount() === 0) {
                $this->lastError = 'No rows deleted. ApproverPositionID may not exist.';
                return false;
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }
}
