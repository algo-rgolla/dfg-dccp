<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class WorkflowApprovalRuleModel
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

    public function listAll(?int $applicationTypeId = null, ?string $employeeGroup = null, ?string $approverType = null, ?string $active = null): array
    {
        $sql = "
            SELECT
                r.RuleID,
                r.ApplicationTypeID,
                at.ApplicationTypeKey,
                at.ApplicationTypeName,
                r.EmployeeGroup,
                r.ApprovalStage,
                r.MinLimit,
                r.MaxLimit,
                r.RequiredApproverType,
                r.RequiredRank,
                r.IsActive,
                r.CreatedAt,
                r.UpdatedAt
            FROM dbo.tblWorkflowApprovalRules r
            LEFT JOIN dbo.tblApplicationTypes at
              ON at.ApplicationTypeID = r.ApplicationTypeID
            WHERE 1 = 1
        ";
        $params = [];

        if ($applicationTypeId !== null && $applicationTypeId > 0) {
            $sql .= " AND r.ApplicationTypeID = :applicationTypeId";
            $params['applicationTypeId'] = $applicationTypeId;
        }

        if ($employeeGroup !== null && trim($employeeGroup) !== '') {
            $sql .= " AND ISNULL(r.EmployeeGroup, '') = :employeeGroup";
            $params['employeeGroup'] = trim($employeeGroup);
        }

        if ($approverType !== null && trim($approverType) !== '') {
            $sql .= " AND r.RequiredApproverType = :approverType";
            $params['approverType'] = trim($approverType);
        }

        if ($active !== null && $active !== '') {
            $sql .= " AND r.IsActive = :isActive";
            $params['isActive'] = $active === '1' ? 1 : 0;
        }

        $sql .= "
            ORDER BY
                r.IsActive DESC,
                r.ApplicationTypeID ASC,
                ISNULL(r.EmployeeGroup, '') ASC,
                r.ApprovalStage ASC,
                r.MinLimit ASC,
                ISNULL(r.MaxLimit, 999999999999.99) ASC,
                r.RequiredApproverType ASC,
                r.RuleID DESC
        ";

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
                FROM dbo.tblWorkflowApprovalRules
                WHERE RuleID = :id
            ");
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    public function listApplicationTypes(): array
    {
        try {
            $stmt = $this->conn->query("
                SELECT ApplicationTypeID, ApplicationTypeName, ApplicationTypeKey
                FROM dbo.tblApplicationTypes
                ORDER BY ApplicationTypeName ASC, ApplicationTypeID ASC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
                FROM dbo.tblWorkflowApprovalRules
                WHERE LTRIM(RTRIM(ISNULL(EmployeeGroup, ''))) <> ''
                ORDER BY EmployeeGroup
            ");
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function listDistinctApproverTypes(): array
    {
        try {
            $stmt = $this->conn->query("
                SELECT DISTINCT RequiredApproverType
                FROM dbo.tblWorkflowApprovalRules
                WHERE LTRIM(RTRIM(ISNULL(RequiredApproverType, ''))) <> ''
                ORDER BY RequiredApproverType
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
                INSERT INTO dbo.tblWorkflowApprovalRules
                    (ApplicationTypeID, EmployeeGroup, ApprovalStage, MinLimit, MaxLimit, RequiredApproverType, RequiredRank, IsActive, UpdatedAt)
                    OUTPUT INSERTED.RuleID
                VALUES
                    (:applicationTypeId, :employeeGroup, :approvalStage, :minLimit, :maxLimit, :requiredApproverType, :requiredRank, :isActive, SYSUTCDATETIME());
            ");
            $stmt->execute([
                'applicationTypeId' => $data['ApplicationTypeID'],
                'employeeGroup' => $data['EmployeeGroup'] !== '' ? $data['EmployeeGroup'] : null,
                'approvalStage' => $data['ApprovalStage'],
                'minLimit' => $data['MinLimit'],
                'maxLimit' => $data['MaxLimit'],
                'requiredApproverType' => $data['RequiredApproverType'],
                'requiredRank' => $data['RequiredRank'] !== '' ? $data['RequiredRank'] : null,
                'isActive' => $data['IsActive'],
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
                UPDATE dbo.tblWorkflowApprovalRules
                SET ApplicationTypeID = :applicationTypeId,
                    EmployeeGroup = :employeeGroup,
                    ApprovalStage = :approvalStage,
                    MinLimit = :minLimit,
                    MaxLimit = :maxLimit,
                    RequiredApproverType = :requiredApproverType,
                    RequiredRank = :requiredRank,
                    IsActive = :isActive,
                    UpdatedAt = SYSUTCDATETIME()
                WHERE RuleID = :id
            ");
            $ok = $stmt->execute([
                'applicationTypeId' => $data['ApplicationTypeID'],
                'employeeGroup' => $data['EmployeeGroup'] !== '' ? $data['EmployeeGroup'] : null,
                'approvalStage' => $data['ApprovalStage'],
                'minLimit' => $data['MinLimit'],
                'maxLimit' => $data['MaxLimit'],
                'requiredApproverType' => $data['RequiredApproverType'],
                'requiredRank' => $data['RequiredRank'] !== '' ? $data['RequiredRank'] : null,
                'isActive' => $data['IsActive'],
                'id' => $id,
            ]);
            if ($ok && $stmt->rowCount() === 0) {
                $this->lastError = 'No rows updated. RuleID may not exist.';
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
                DELETE FROM dbo.tblWorkflowApprovalRules
                WHERE RuleID = :id
            ");
            $ok = $stmt->execute(['id' => $id]);
            if ($ok && $stmt->rowCount() === 0) {
                $this->lastError = 'No rows deleted. RuleID may not exist.';
                return false;
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function findImportMatch(int $applicationTypeId, string $employeeGroup, int $approvalStage, float $minLimit, ?float $maxLimit): ?array
    {
        try {
            $stmt = $this->conn->prepare("
                SELECT TOP 1 *
                FROM dbo.tblWorkflowApprovalRules
                WHERE ApplicationTypeID = :applicationTypeId
                  AND ISNULL(EmployeeGroup, '') = :employeeGroup
                  AND ApprovalStage = :approvalStage
                  AND MinLimit = :minLimit
                  AND (
                        (MaxLimit IS NULL AND :maxLimit IS NULL)
                        OR MaxLimit = :maxLimit
                      )
                ORDER BY RuleID ASC
            ");
            $stmt->bindValue(':applicationTypeId', $applicationTypeId, PDO::PARAM_INT);
            $stmt->bindValue(':employeeGroup', $employeeGroup);
            $stmt->bindValue(':approvalStage', $approvalStage, PDO::PARAM_INT);
            $stmt->bindValue(':minLimit', $minLimit);
            if ($maxLimit === null) {
                $stmt->bindValue(':maxLimit', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':maxLimit', $maxLimit);
            }
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    public function findOverlaps(int $applicationTypeId, string $employeeGroup, int $approvalStage, float $minLimit, ?float $maxLimit, ?int $excludeRuleId = null): array
    {
        $candidateMax = $maxLimit ?? 999999999999.99;
        try {
            $sql = "
                SELECT
                    RuleID,
                    ApplicationTypeID,
                    EmployeeGroup,
                    ApprovalStage,
                    MinLimit,
                    MaxLimit,
                    RequiredApproverType,
                    RequiredRank,
                    IsActive
                FROM dbo.tblWorkflowApprovalRules
                WHERE ApplicationTypeID = :applicationTypeId
                  AND ISNULL(EmployeeGroup, '') = :employeeGroup
                  AND ApprovalStage = :approvalStage
            ";
            $params = [
                'applicationTypeId' => $applicationTypeId,
                'employeeGroup' => $employeeGroup,
                'approvalStage' => $approvalStage,
            ];
            if ($excludeRuleId !== null && $excludeRuleId > 0) {
                $sql .= " AND RuleID <> :excludeRuleId";
                $params['excludeRuleId'] = $excludeRuleId;
            }
            $sql .= "
                  AND MinLimit <= :candidateMax
                  AND ISNULL(MaxLimit, 999999999999.99) >= :candidateMin
                ORDER BY MinLimit ASC, ISNULL(MaxLimit, 999999999999.99) ASC, RuleID ASC
            ";
            $params['candidateMax'] = $candidateMax;
            $params['candidateMin'] = $minLimit;

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }
}
