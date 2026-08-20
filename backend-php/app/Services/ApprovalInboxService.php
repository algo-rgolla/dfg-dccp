<?php
declare(strict_types=1);

namespace App\Services;

final class ApprovalInboxService
{
    private const DPC_PENDING_STATUSES = ['submitted', 'tobeapproved', 'awaitingapproval'];
    private const LIMIT_CHANGE_PENDING_STATUSES = ['submitted', 'tobeapproved', 'awaitingapproval'];

    public function __construct(private \PDO $db)
    {
    }

    public function listMyDpcApprovals(int $userId): array
    {
        [$userEmail, $userEmployeeId] = $this->loadUserApprovalIdentity($userId);
        if ($userId <= 0 || $userEmail === '') {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT
                a.ApplicationID,
                a.UserID,
                a.EmployeeID,
                a.Status,
                a.SubmittedAt,
                a.LastSavedAt,
                at.ApplicationTypeKey,
                at.ApplicationTypeName,
                ISNULL(NULLIF(u.DisplayName, ''), NULLIF(u.Username, '')) AS RequestorName,
                ISNULL(u.Email, '') AS RequestorEmail,
                s.DataJson
            FROM dbo.tblApplications a
            INNER JOIN dbo.tblApplicationTypes at
                ON at.ApplicationTypeID = a.ApplicationTypeID
            LEFT JOIN dbo.tblUsers u
                ON u.UserID = a.UserID
            LEFT JOIN dbo.tblApplicationSteps s
                ON s.ApplicationID = a.ApplicationID
               AND s.StepKey = 'application'
            WHERE LOWER(ISNULL(at.ApplicationTypeKey, '')) = 'dpc'
              AND LOWER(LTRIM(RTRIM(ISNULL(a.Status, '')))) IN ('submitted', 'tobeapproved', 'awaitingapproval')
            ORDER BY ISNULL(a.SubmittedAt, a.LastSavedAt) DESC, a.ApplicationID DESC
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $approvalRows = [];
        foreach ($rows as $row) {
            $status = strtolower(trim((string)($row['Status'] ?? '')));
            if (!in_array($status, self::DPC_PENDING_STATUSES, true)) {
                continue;
            }

            $payload = $this->decodePayload((string)($row['DataJson'] ?? ''));
            $supervisorEmail = $this->normalizeEmail($payload['supervisor_email'] ?? '');
            if ($supervisorEmail === '' || !hash_equals($supervisorEmail, $userEmail)) {
                continue;
            }

            if ($this->isSelfApprovalRequest(
                $userId,
                $userEmployeeId,
                (int)($row['UserID'] ?? 0),
                (string)($row['EmployeeID'] ?? '')
            )) {
                continue;
            }

            $approvalRows[] = [
                'ApplicationID' => (int)($row['ApplicationID'] ?? 0),
                'Status' => (string)($row['Status'] ?? ''),
                'ApplicationTypeName' => (string)($row['ApplicationTypeName'] ?? ''),
                'ApplicationTypeKey' => (string)($row['ApplicationTypeKey'] ?? ''),
                'RequestorUserID' => (int)($row['UserID'] ?? 0),
                'RequestorEmployeeID' => (string)($row['EmployeeID'] ?? ''),
                'RequestorName' => (string)($row['RequestorName'] ?? ''),
                'RequestorEmail' => (string)($row['RequestorEmail'] ?? ''),
                'SubmittedAt' => (string)($row['SubmittedAt'] ?? ''),
                'LastSavedAt' => (string)($row['LastSavedAt'] ?? ''),
                'SupervisorName' => (string)($payload['supervisor_name'] ?? ''),
                'SupervisorEmployeeID' => (string)($payload['supervisor_employee_id'] ?? ''),
                'SupervisorEmail' => (string)($payload['supervisor_email'] ?? ''),
                'ApplicantEmail' => (string)($payload['email'] ?? ''),
                'Company' => (string)($payload['company'] ?? ''),
                'CostCentre' => (string)($payload['cost_centre'] ?? ''),
                'Branding' => (string)($payload['branding'] ?? ''),
                'ApprovedByUserID' => (int)($payload['approved_by_user_id'] ?? 0),
                'ApprovedAt' => (string)($payload['approved_at'] ?? ''),
                'RejectedByUserID' => (int)($payload['rejected_by_user_id'] ?? 0),
                'RejectedAt' => (string)($payload['rejected_at'] ?? ''),
                'RejectReason' => (string)($payload['reject_reason'] ?? ''),
            ];
        }

        return $approvalRows;
    }

    public function listMyLimitChangeApprovals(int $userId): array
    {
        [$userEmail, $userEmployeeId] = $this->loadUserApprovalIdentity($userId);
        if ($userId <= 0 || $userEmail === '') {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT
                a.ApplicationID,
                a.UserID,
                a.EmployeeID,
                a.Status,
                a.SubmittedAt,
                a.LastSavedAt,
                at.ApplicationTypeKey,
                at.ApplicationTypeName,
                ISNULL(NULLIF(u.DisplayName, ''), NULLIF(u.Username, '')) AS RequestorName,
                ISNULL(u.Email, '') AS RequestorEmail,
                s.DataJson
            FROM dbo.tblApplications a
            INNER JOIN dbo.tblApplicationTypes at
                ON at.ApplicationTypeID = a.ApplicationTypeID
            LEFT JOIN dbo.tblUsers u
                ON u.UserID = a.UserID
            LEFT JOIN dbo.tblApplicationSteps s
                ON s.ApplicationID = a.ApplicationID
               AND s.StepKey = 'application'
            WHERE LOWER(ISNULL(at.ApplicationTypeKey, '')) LIKE :typeKey
              AND LOWER(LTRIM(RTRIM(ISNULL(a.Status, '')))) IN ('submitted', 'tobeapproved', 'awaitingapproval')
            ORDER BY ISNULL(a.SubmittedAt, a.LastSavedAt) DESC, a.ApplicationID DESC
        ");
        $stmt->execute(['typeKey' => '%limit_change']);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $approvalRows = [];
        foreach ($rows as $row) {
            $status = strtolower(trim((string)($row['Status'] ?? '')));
            if (!in_array($status, self::LIMIT_CHANGE_PENDING_STATUSES, true)) {
                continue;
            }

            $payload = $this->decodePayload((string)($row['DataJson'] ?? ''));
            if (!$this->isCurrentUserAssignedLimitChangeApprover($userEmail, $payload)) {
                continue;
            }

            if ($this->isSelfApprovalRequest(
                $userId,
                $userEmployeeId,
                (int)($row['UserID'] ?? 0),
                (string)($row['EmployeeID'] ?? ''),
                (string)($payload['target_employee_id'] ?? '')
            )) {
                continue;
            }

            $approvalRows[] = [
                'ApplicationID' => (int)($row['ApplicationID'] ?? 0),
                'Status' => (string)($row['Status'] ?? ''),
                'ApplicationTypeName' => (string)($row['ApplicationTypeName'] ?? ''),
                'ApplicationTypeKey' => (string)($row['ApplicationTypeKey'] ?? ''),
                'RequestorUserID' => (int)($row['UserID'] ?? 0),
                'RequestorEmployeeID' => (string)($row['EmployeeID'] ?? ''),
                'RequestorName' => (string)($row['RequestorName'] ?? ''),
                'RequestorEmail' => (string)($row['RequestorEmail'] ?? ''),
                'ApplicantEmployeeID' => $this->resolveApplicantEmployeeId(
                    (string)($row['EmployeeID'] ?? ''),
                    $payload
                ),
                'ApplicantName' => $this->resolveApplicantDisplayName(
                    (string)($row['RequestorName'] ?? ''),
                    (string)($row['EmployeeID'] ?? ''),
                    $payload
                ),
                'ApplicantEmail' => $this->resolveApplicantEmail(
                    (string)($row['RequestorEmail'] ?? ''),
                    (string)($row['EmployeeID'] ?? ''),
                    $payload
                ),
                'IsOnBehalf' => trim((string)($payload['on_behalf'] ?? '')) === '1',
                'SubmittedAt' => (string)($row['SubmittedAt'] ?? ''),
                'LastSavedAt' => (string)($row['LastSavedAt'] ?? ''),
                'CardID' => (int)($payload['card_id'] ?? 0),
                'CardType' => (string)($payload['card_type_sub'] ?? ($payload['card_type'] ?? '')),
                'CreditLimitNew' => (string)($payload['credit_limit_new'] ?? ''),
                'TransactionLimitNew' => $this->resolveTransactionLimitDisplayValue($payload),
                'SelectedApprover' => (string)($payload['approver'] ?? ''),
                'ApproverType' => (string)($payload['selected_approver_type'] ?? ''),
                'ApprovedByUserID' => (int)($payload['approved_by_user_id'] ?? 0),
                'ApprovedAt' => (string)($payload['approved_at'] ?? ''),
                'RejectedByUserID' => (int)($payload['rejected_by_user_id'] ?? 0),
                'RejectedAt' => (string)($payload['rejected_at'] ?? ''),
                'RejectReason' => (string)($payload['reject_reason'] ?? ''),
                'ForwardTo' => (string)($payload['forward_to'] ?? ''),
            ];
        }

        return $approvalRows;
    }

    public function getPendingApprovalSummary(int $userId): array
    {
        $dpcRows = $this->listMyDpcApprovals($userId);
        $limitChangeRows = $this->listMyLimitChangeApprovals($userId);

        return [
            'dpcCount' => count($dpcRows),
            'limitChangeCount' => count($limitChangeRows),
            'totalCount' => count($dpcRows) + count($limitChangeRows),
            'dpcListRoute' => 'applications/my-dpc-approvals',
            'limitChangeListRoute' => 'cards/my-limit-change-approvals',
            'dpcRows' => $dpcRows,
            'limitChangeRows' => $limitChangeRows,
        ];
    }

    public function resolvePostLoginRoute(int $userId): ?string
    {
        $summary = $this->getPendingApprovalSummary($userId);
        $total = (int)($summary['totalCount'] ?? 0);
        $dpcCount = (int)($summary['dpcCount'] ?? 0);
        $limitChangeCount = (int)($summary['limitChangeCount'] ?? 0);

        if ($total <= 0) {
            return null;
        }

        if ($total === 1) {
            if ($dpcCount === 1) {
                $row = $summary['dpcRows'][0] ?? null;
                if (is_array($row) && (int)($row['ApplicationID'] ?? 0) > 0) {
                    return 'applications/approve&id=' . (int)$row['ApplicationID'];
                }
            }

            if ($limitChangeCount === 1) {
                $row = $summary['limitChangeRows'][0] ?? null;
                if (is_array($row) && (int)($row['ApplicationID'] ?? 0) > 0) {
                    return 'cards/limit-change-approve&id=' . (int)$row['ApplicationID'];
                }
            }
        }

        if ($dpcCount > 0 && $limitChangeCount === 0) {
            return 'applications/my-dpc-approvals';
        }

        if ($limitChangeCount > 0 && $dpcCount === 0) {
            return 'cards/my-limit-change-approvals';
        }

        return null;
    }

    private function loadUserApprovalIdentity(int $userId): array
    {
        if ($userId <= 0) {
            return ['', ''];
        }

        $stmt = $this->db->prepare("
            SELECT TOP 1 Email, EmployeeID
            FROM dbo.tblUsers
            WHERE UserID = :userId
        ");
        $stmt->execute(['userId' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            $this->normalizeEmail($row['Email'] ?? ''),
            strtolower(trim((string)($row['EmployeeID'] ?? ''))),
        ];
    }

    private function isSelfApprovalRequest(
        int $currentUserId,
        string $currentEmployeeId,
        int $requestorUserId,
        string $requestorEmployeeId,
        string $targetEmployeeId = ''
    ): bool {
        if ($currentUserId > 0 && $requestorUserId > 0 && $currentUserId === $requestorUserId) {
            return true;
        }

        if ($currentEmployeeId === '') {
            return false;
        }

        foreach ([$requestorEmployeeId, $targetEmployeeId] as $candidate) {
            $candidateId = strtolower(trim($candidate));
            if ($candidateId !== '' && hash_equals($candidateId, $currentEmployeeId)) {
                return true;
            }
        }

        return false;
    }

    private function isCurrentUserAssignedLimitChangeApprover(string $userEmail, array $payload): bool
    {
        if ($userEmail === '') {
            return false;
        }

        foreach ($this->collectCurrentLimitChangeApproverEmails($payload) as $candidateEmail) {
            if ($candidateEmail !== '' && hash_equals($candidateEmail, $userEmail)) {
                return true;
            }
        }

        return false;
    }

    private function collectCurrentLimitChangeApproverEmails(array $payload): array
    {
        $emails = [];
        $employeeGroup = trim((string)($payload['employee_group'] ?? ''));
        $stages = $this->normalizeStoredApprovalStages($payload);
        $currentStageNumber = $this->resolveCurrentApprovalStageNumber($payload, $stages);
        $currentStage = $this->findStoredApprovalStage($stages, $currentStageNumber);

        if ($currentStage !== null) {
            foreach (($currentStage['candidate_approvers'] ?? []) as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $this->appendEmailCandidate($emails, $candidate['email'] ?? '');
            }

            $this->appendSelectionEmail($emails, (string)($currentStage['approver'] ?? ''), $employeeGroup);
        }

        $this->appendEmailCandidate($emails, $payload['approver_email'] ?? '');
        $this->appendSelectionEmail($emails, (string)($payload['approver'] ?? ''), $employeeGroup);
        $this->appendSelectionEmail($emails, (string)($payload['forward_to'] ?? ''), $employeeGroup);

        return array_values(array_unique(array_filter($emails, static fn(string $email): bool => $email !== '')));
    }

    private function appendSelectionEmail(array &$emails, string $selection, string $employeeGroup): void
    {
        $parsed = $this->parseApproverSelection($selection);
        $this->appendEmailCandidate($emails, $parsed['email'] ?? '');
        if (($parsed['email'] ?? '') === '' && trim((string)($parsed['type'] ?? '')) !== '') {
            $contact = $this->resolveApproverContact(
                (string)($parsed['type'] ?? ''),
                (string)($parsed['position'] ?? ''),
                $employeeGroup
            );
            $this->appendEmailCandidate($emails, $contact['email'] ?? '');
        }
    }

    private function appendEmailCandidate(array &$emails, mixed $value): void
    {
        $email = $this->normalizeEmail($value);
        if ($email !== '') {
            $emails[] = $email;
        }
    }

    private function parseApproverSelection(string $selected): array
    {
        $selected = trim($selected);
        if ($selected === '') {
            return ['type' => '', 'position' => null, 'email' => ''];
        }

        $parts = explode('|', $selected, 2);
        $type = strtoupper(trim((string)($parts[0] ?? '')));
        $position = isset($parts[1]) ? trim((string)$parts[1]) : null;
        if ($position === '') {
            $position = null;
        }

        return [
            'type' => $type,
            'position' => $position,
            'email' => $type === 'EMAIL' ? $this->normalizeEmail($parts[1] ?? '') : '',
        ];
    }

    private function resolveApproverContact(string $approverType, string $position, string $employeeGroup): array
    {
        $approverType = strtoupper(trim($approverType));
        $position = trim($position);
        $employeeGroup = trim($employeeGroup);

        if ($approverType === 'EMAIL') {
            return [
                'email' => $this->normalizeEmail($position),
                'display_name' => $this->normalizeEmail($position),
                'position' => '',
            ];
        }

        if ($approverType === 'SES' && strcasecmp($employeeGroup, 'Defence') === 0) {
            foreach ($this->loadSesApproversFromView($employeeGroup) as $row) {
                $pos = trim((string)($row['PositionNumber'] ?? ''));
                if ($position !== '' && $pos !== '' && $pos !== $position) {
                    continue;
                }

                return [
                    'email' => trim((string)($row['Email'] ?? '')),
                    'display_name' => trim((string)($row['DisplayName'] ?? '')),
                    'position' => $pos,
                ];
            }
        }

        $stmt = $this->db->prepare("
            SELECT TOP 1 Email, DisplayName, PositionNumber
            FROM dbo.tblWorkflowApproverPositions
            WHERE IsActive = 1
              AND UPPER(ApproverType) = :atype
              AND (
                    LOWER(ISNULL(EmployeeGroup, '')) = LOWER(:grp_match)
                    OR EmployeeGroup IS NULL
                  )
              AND (:pos_match = '' OR PositionNumber = :pos_filter)
            ORDER BY
              CASE WHEN LOWER(ISNULL(EmployeeGroup, '')) = LOWER(:grp_order) THEN 0 ELSE 1 END,
              ApproverPositionID ASC
        ");
        $stmt->execute([
            'atype' => $approverType,
            'grp_match' => $employeeGroup,
            'grp_order' => $employeeGroup,
            'pos_match' => $position,
            'pos_filter' => $position,
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'email' => trim((string)($row['Email'] ?? '')),
            'display_name' => trim((string)($row['DisplayName'] ?? '')),
            'position' => trim((string)($row['PositionNumber'] ?? $position)),
        ];
    }

    private function normalizeStoredApprovalStages(array $payload): array
    {
        $stages = $payload['approval_stages'] ?? null;
        if (!is_array($stages)) {
            return [];
        }

        $out = [];
        foreach ($stages as $stage) {
            if (!is_array($stage)) {
                continue;
            }

            $out[] = [
                'stage' => max(1, (int)($stage['stage'] ?? 1)),
                'candidate_approvers' => is_array($stage['candidate_approvers'] ?? null)
                    ? array_values($stage['candidate_approvers'])
                    : [],
                'approver' => trim((string)($stage['approver'] ?? '')),
            ];
        }

        usort($out, static fn(array $a, array $b): int => ((int)$a['stage']) <=> ((int)$b['stage']));
        return $out;
    }

    private function resolveCurrentApprovalStageNumber(array $payload, array $stages): int
    {
        $current = max(1, (int)($payload['approval_current_stage'] ?? 1));
        if ($stages === []) {
            return $current;
        }

        foreach ($stages as $stage) {
            if ((int)($stage['stage'] ?? 0) === $current) {
                return $current;
            }
        }

        return (int)($stages[0]['stage'] ?? 1);
    }

    private function findStoredApprovalStage(array $stages, int $stageNumber): ?array
    {
        foreach ($stages as $stage) {
            if ((int)($stage['stage'] ?? 0) === $stageNumber) {
                return $stage;
            }
        }

        return null;
    }

    private function resolveTransactionLimitDisplayValue(array $payload): string
    {
        $keys = [
            'transaction_limit_new_display',
            'transaction_limit_new',
            'transaction_limit',
        ];

        foreach ($keys as $key) {
            $value = trim((string)($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveApplicantEmployeeId(string $requestorEmployeeId, array $payload): string
    {
        $targetEmployeeId = trim((string)($payload['target_employee_id'] ?? ''));
        return $targetEmployeeId !== '' ? $targetEmployeeId : trim($requestorEmployeeId);
    }

    private function resolveApplicantDisplayName(string $requestorName, string $requestorEmployeeId, array $payload): string
    {
        $applicantEmployeeId = $this->resolveApplicantEmployeeId($requestorEmployeeId, $payload);
        $requestorEmployeeId = trim($requestorEmployeeId);
        if ($applicantEmployeeId === '' || strcasecmp($applicantEmployeeId, $requestorEmployeeId) === 0) {
            return trim($requestorName);
        }

        $displayName = $this->loadUserDisplayNameByEmployeeId($applicantEmployeeId);
        if ($displayName !== '') {
            return $displayName;
        }

        return '';
    }

    private function resolveApplicantEmail(string $requestorEmail, string $requestorEmployeeId, array $payload): string
    {
        $applicantEmployeeId = $this->resolveApplicantEmployeeId($requestorEmployeeId, $payload);
        $requestorEmployeeId = trim($requestorEmployeeId);
        if ($applicantEmployeeId === '' || strcasecmp($applicantEmployeeId, $requestorEmployeeId) === 0) {
            return trim($requestorEmail);
        }

        $email = $this->loadUserEmailByEmployeeId($applicantEmployeeId);
        if ($email !== '') {
            return $email;
        }

        return '';
    }

    private function decodePayload(string $json): array
    {
        $payload = json_decode($json, true);
        return is_array($payload) ? $payload : [];
    }

    private function loadSesApproversFromView(string $employeeGroup): array
    {
        $employeeGroup = trim($employeeGroup);
        if (strcasecmp($employeeGroup, 'Defence') !== 0) {
            return [];
        }

        $rows = $this->loadAllSesApproversFromView();
        if ($rows === []) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $group = trim((string)($row['EmployeeGroup'] ?? ''));
            if ($group !== '' && strcasecmp($group, $employeeGroup) !== 0) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    private function loadAllSesApproversFromView(): array
    {
        global $capsConn;
        if (!($capsConn instanceof \PDO)) {
            return [];
        }

        $exists = $capsConn->prepare("
            SELECT 1
            WHERE OBJECT_ID('dbo.vwWorkflowDefenceSesApprovers', 'V') IS NOT NULL
        ");
        $exists->execute();
        if (!(bool)$exists->fetchColumn()) {
            return [];
        }

        $stmt = $capsConn->prepare("
            SELECT *
            FROM dbo.vwWorkflowDefenceSesApprovers
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $position = $this->firstNonEmpty($row, ['PositionNumber', 'PositionNo', 'Position', 'position_number']);
            if ($position === '') {
                continue;
            }

            $group = $this->firstNonEmpty($row, ['EmployeeGroup', 'GroupName', 'employee_group', 'group_name']);
            $display = $this->firstNonEmpty($row, ['DisplayName', 'ApproverName', 'Name', 'FullName', 'UserName', 'Username']);
            $email = $this->firstNonEmpty($row, ['Email', 'EmailAddress', 'ApproverEmail', 'email']);
            if ($display === '' && $email !== '') {
                $display = trim((string)strtok($email, '@'));
            }

            $out[] = [
                'EmployeeGroup' => $group,
                'PositionNumber' => $position,
                'DisplayName' => $display,
                'Email' => $email,
            ];
        }

        return $out;
    }

    private function firstNonEmpty(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $value = trim((string)$row[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function normalizeEmail(mixed $value): string
    {
        return strtolower(trim((string)$value));
    }

    private function loadUserDisplayNameByEmployeeId(string $employeeId): string
    {
        $employeeId = trim($employeeId);
        if ($employeeId === '') {
            return '';
        }

        $stmt = $this->db->prepare("
            SELECT TOP 1
                LTRIM(RTRIM(ISNULL(NULLIF(DisplayName, ''), NULLIF(Username, '')))) AS DisplayName
            FROM dbo.tblUsers
            WHERE LTRIM(RTRIM(ISNULL(EmployeeID, ''))) = :employeeId
            ORDER BY UserID DESC
        ");
        $stmt->execute(['employeeId' => $employeeId]);
        return trim((string)($stmt->fetchColumn() ?? ''));
    }

    private function loadUserEmailByEmployeeId(string $employeeId): string
    {
        $employeeId = trim($employeeId);
        if ($employeeId === '') {
            return '';
        }

        $stmt = $this->db->prepare("
            SELECT TOP 1 LTRIM(RTRIM(ISNULL(Email, '')))
            FROM dbo.tblUsers
            WHERE LTRIM(RTRIM(ISNULL(EmployeeID, ''))) = :employeeId
            ORDER BY UserID DESC
        ");
        $stmt->execute(['employeeId' => $employeeId]);
        return trim((string)($stmt->fetchColumn() ?? ''));
    }
}
