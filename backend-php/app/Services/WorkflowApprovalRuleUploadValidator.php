<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class WorkflowApprovalRuleUploadValidator
{
    private PDO $conn;
    private array $errors = [];
    /** @var array<int,array<int,string>> */
    private array $rowErrors = [];
    /** @var array<string,int> */
    private array $applicationTypeIdByKey = [];
    /** @var array<string,bool> */
    private array $seenImportKeys = [];

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
        $this->loadApplicationTypes();
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getRowErrors(int $rowNum): array
    {
        return $this->rowErrors[$rowNum] ?? [];
    }

    public function expectedHeaders(): array
    {
        return [
            'ApplicationTypeKey',
            'EmployeeGroup',
            'ApprovalStage',
            'MinLimit',
            'MaxLimit',
            'RequiredApproverType',
            'RequiredRank',
            'IsActive',
        ];
    }

    public function validateRow(array $row, int $rowNum): ?array
    {
        $errors = [];

        $applicationTypeKey = strtolower(trim((string)($row['ApplicationTypeKey'] ?? '')));
        $employeeGroup = trim((string)($row['EmployeeGroup'] ?? ''));
        $approvalStageRaw = trim((string)($row['ApprovalStage'] ?? '1'));
        $minLimitRaw = trim((string)($row['MinLimit'] ?? ''));
        $maxLimitRaw = trim((string)($row['MaxLimit'] ?? ''));
        $requiredApproverType = strtoupper(trim((string)($row['RequiredApproverType'] ?? '')));
        $requiredRank = trim((string)($row['RequiredRank'] ?? ''));
        $isActiveRaw = trim((string)($row['IsActive'] ?? '1'));

        if ($applicationTypeKey === '') {
            $errors[] = "Row $rowNum: ApplicationTypeKey is required.";
        }
        $applicationTypeId = $this->applicationTypeIdByKey[$applicationTypeKey] ?? 0;
        if ($applicationTypeKey !== '' && $applicationTypeId <= 0) {
            $errors[] = "Row $rowNum: ApplicationTypeKey '{$applicationTypeKey}' was not found.";
        }

        if ($approvalStageRaw === '' || !ctype_digit($approvalStageRaw) || (int)$approvalStageRaw <= 0) {
            $errors[] = "Row $rowNum: ApprovalStage must be a whole number greater than 0.";
        }
        $approvalStage = ctype_digit($approvalStageRaw) ? (int)$approvalStageRaw : 0;

        if ($minLimitRaw === '' || !is_numeric($minLimitRaw)) {
            $errors[] = "Row $rowNum: MinLimit must be a number.";
        }
        $minLimit = is_numeric($minLimitRaw) ? (float)$minLimitRaw : 0.0;
        if ($minLimit < 0) {
            $errors[] = "Row $rowNum: MinLimit must be zero or greater.";
        }

        $maxLimit = null;
        if ($maxLimitRaw !== '') {
            if (!is_numeric($maxLimitRaw)) {
                $errors[] = "Row $rowNum: MaxLimit must be blank or a number.";
            } else {
                $maxLimit = (float)$maxLimitRaw;
                if ($maxLimit < $minLimit) {
                    $errors[] = "Row $rowNum: MaxLimit must be greater than or equal to MinLimit.";
                }
            }
        }

        if ($requiredApproverType === '') {
            $errors[] = "Row $rowNum: RequiredApproverType is required.";
        }

        $isActive = $this->parseBoolish($isActiveRaw);
        if ($isActive === null) {
            $errors[] = "Row $rowNum: IsActive must be 1/0, true/false, yes/no, or active/inactive.";
        }

        if ($errors) {
            $this->errors = array_merge($this->errors, $errors);
            $this->rowErrors[$rowNum] = $errors;
            return null;
        }

        $importKey = strtolower(implode('|', [
            (string)$applicationTypeId,
            strtolower($employeeGroup),
            (string)$approvalStage,
            number_format($minLimit, 2, '.', ''),
            $maxLimit === null ? 'null' : number_format($maxLimit, 2, '.', ''),
        ]));
        if (isset($this->seenImportKeys[$importKey])) {
            $message = "Row $rowNum: Duplicate import key detected in the spreadsheet for the same application type, employee group, approval stage, and limit band.";
            $this->errors[] = $message;
            $this->rowErrors[$rowNum] = [$message];
            return null;
        }
        $this->seenImportKeys[$importKey] = true;

        return [
            'ApplicationTypeID' => $applicationTypeId,
            'EmployeeGroup' => $employeeGroup,
            'ApprovalStage' => $approvalStage,
            'MinLimit' => $minLimit,
            'MaxLimit' => $maxLimit,
            'RequiredApproverType' => $requiredApproverType,
            'RequiredRank' => $requiredRank,
            'IsActive' => $isActive,
        ];
    }

    private function loadApplicationTypes(): void
    {
        $stmt = $this->conn->query("
            SELECT ApplicationTypeID, ApplicationTypeKey
            FROM dbo.tblApplicationTypes
            WHERE LTRIM(RTRIM(ISNULL(ApplicationTypeKey, ''))) <> ''
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $key = strtolower(trim((string)($row['ApplicationTypeKey'] ?? '')));
            $id = (int)($row['ApplicationTypeID'] ?? 0);
            if ($key !== '' && $id > 0) {
                $this->applicationTypeIdByKey[$key] = $id;
            }
        }
    }

    private function parseBoolish(string $value): ?int
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || in_array($normalized, ['1', 'true', 'yes', 'y', 'active'], true)) {
            return 1;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'n', 'inactive'], true)) {
            return 0;
        }
        return null;
    }
}
