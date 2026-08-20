<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Rbac;
use App\Models\AuditModel;
use App\Shared\SessionHelper;

require_once __DIR__ . '/../../shared/csrf.php';

final class ApplicationAdminController extends BaseController
{
    private array $applicantProfileCache = [];

    protected array $acl = [
        '*' => ['auth' => true],
    ];

    public function index(): void
    {
        $this->ensureAdminAccess();

        $search = trim((string)($_GET['q'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));

        $sql = "
            SELECT TOP 250
                a.ApplicationID,
                a.UserID,
                a.EmployeeID,
                a.ApplicationTypeID,
                a.Status,
                a.CurrentStepKey,
                a.StartedAt,
                a.LastSavedAt,
                a.Locked,
                at.ApplicationTypeName,
                at.ApplicationTypeKey,
                u.Username,
                u.DisplayName,
                u.Email,
                u.FirstName,
                u.LastName,
                s.DataJson,
                s.LastSavedAt AS PayloadSavedAt
            FROM dbo.tblApplications a
            LEFT JOIN dbo.tblApplicationTypes at
              ON at.ApplicationTypeID = a.ApplicationTypeID
            LEFT JOIN dbo.tblUsers u
              ON u.UserID = a.UserID
            LEFT JOIN dbo.tblApplicationSteps s
              ON s.ApplicationID = a.ApplicationID
             AND s.StepKey = 'application'
            WHERE 1 = 1
        ";

        $params = [];

        if ($status !== '') {
            $sql .= " AND a.Status = :status";
            $params['status'] = $status;
        }

        if ($search !== '') {
            $sql .= "
                AND (
                    CAST(a.ApplicationID AS NVARCHAR(50)) = :search_exact
                    OR CAST(a.ApplicationID AS NVARCHAR(50)) LIKE :search_like_id
                    OR ISNULL(CAST(a.EmployeeID AS NVARCHAR(50)), '') LIKE :search_like_employee
                    OR ISNULL(u.Username, '') LIKE :search_like_username
                    OR ISNULL(u.FirstName, '') LIKE :search_like_firstname
                    OR ISNULL(u.LastName, '') LIKE :search_like_lastname
                    OR ISNULL(at.ApplicationTypeName, '') LIKE :search_like_type_name
                    OR ISNULL(at.ApplicationTypeKey, '') LIKE :search_like_type_key
                )
            ";
            $params['search_exact'] = $search;
            $searchLike = '%' . $search . '%';
            $params['search_like_id'] = $searchLike;
            $params['search_like_employee'] = $searchLike;
            $params['search_like_username'] = $searchLike;
            $params['search_like_firstname'] = $searchLike;
            $params['search_like_lastname'] = $searchLike;
            $params['search_like_type_name'] = $searchLike;
            $params['search_like_type_key'] = $searchLike;
        }

        $sql .= " ORDER BY a.ApplicationID DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $rows = $this->enrichApplicationListRows($rows);

        $this->render('admin/ApplicationList', [
            'title' => 'Applications',
            'rows' => $rows,
            'search' => $search,
            'statusFilter' => $status,
        ]);
    }

    public function view(): void
    {
        $this->ensureAdminAccess();

        $applicationId = (int)($_GET['id'] ?? 0);
        if ($applicationId <= 0) {
            http_response_code(400);
            echo 'Missing application id';
            return;
        }

        $stmt = $this->db->prepare("
            SELECT
                a.ApplicationID,
                a.UserID,
                a.EmployeeID,
                a.ApplicationTypeID,
                a.Status,
                a.CurrentStepKey,
                a.StartedAt,
                a.LastSavedAt,
                a.Locked,
                at.ApplicationTypeName,
                at.ApplicationTypeKey,
                u.Username,
                u.FirstName,
                u.LastName
            FROM dbo.tblApplications a
            LEFT JOIN dbo.tblApplicationTypes at
              ON at.ApplicationTypeID = a.ApplicationTypeID
            LEFT JOIN dbo.tblUsers u
              ON u.UserID = a.UserID
            WHERE a.ApplicationID = :id
        ");
        $stmt->execute(['id' => $applicationId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        if (!$row) {
            http_response_code(404);
            echo 'Application not found';
            return;
        }

        $payloadStmt = $this->db->prepare("
            SELECT DataJson, LastSavedAt, UpdatedBy
            FROM dbo.tblApplicationSteps
            WHERE ApplicationID = :id
              AND StepKey = 'application'
        ");
        $payloadStmt->execute(['id' => $applicationId]);
        $payloadRow = $payloadStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $payloadJson = (string)($payloadRow['DataJson'] ?? '');
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $stepStmt = $this->db->prepare("
            SELECT StepKey, IsComplete, CompletedAt, LastSavedAt, UpdatedBy
            FROM dbo.tblApplicationSteps
            WHERE ApplicationID = :id
            ORDER BY StepKey ASC
        ");
        $stepStmt->execute(['id' => $applicationId]);
        $steps = $stepStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $historyStmt = $this->db->prepare("
            SELECT TOP 20
                AuditID,
                EventTime,
                Username,
                Action,
                Details
            FROM dbo.tblAuditLog
            WHERE Entity = 'Application'
              AND EntityKey = :id
            ORDER BY AuditID DESC
        ");
        $historyStmt->execute(['id' => (string)$applicationId]);
        $history = $historyStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        usort($history, function (array $a, array $b): int {
            $aStatus = $this->isStatusHistoryEntry($a) ? 1 : 0;
            $bStatus = $this->isStatusHistoryEntry($b) ? 1 : 0;
            if ($aStatus !== $bStatus) {
                return $bStatus <=> $aStatus;
            }
            return ((int)($b['AuditID'] ?? 0)) <=> ((int)($a['AuditID'] ?? 0));
        });

        $this->render('admin/ApplicationView', [
            'title' => 'Application Details',
            'row' => $row,
            'payload' => $payload,
            'payloadJson' => $payloadJson,
            'payloadMeta' => $payloadRow,
            'steps' => $steps,
            'history' => $history,
        ]);
    }

    public function updateStatus(): void
    {
        $this->ensureAdminAccess();

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo 'Method not allowed';
            return;
        }

        if (!csrf_check($_POST['_csrf'] ?? '')) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Security check failed. Please try again.']);
            header('Location: index.php?route=admin/applications');
            exit;
        }

        $applicationId = (int)($_POST['application_id'] ?? 0);
        $targetStatus = trim((string)($_POST['target_status'] ?? ''));
        $statusReason = trim((string)($_POST['status_reason'] ?? ''));

        if ($applicationId <= 0) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Missing application id.']);
            header('Location: index.php?route=admin/applications');
            exit;
        }

        $allowed = ['Draft', 'InProgress', 'CardIssued'];
        if (!in_array($targetStatus, $allowed, true)) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Only Draft, InProgress, or CardIssued can be set by an administrator.']);
            header('Location: index.php?route=admin/applications-view&id=' . urlencode((string)$applicationId));
            exit;
        }
        if ($statusReason === '') {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Reason is required.']);
            header('Location: index.php?route=admin/applications-view&id=' . urlencode((string)$applicationId));
            exit;
        }

        $stmt = $this->db->prepare("
            SELECT ApplicationID, Status, Locked
            FROM dbo.tblApplications
            WHERE ApplicationID = :id
        ");
        $stmt->execute(['id' => $applicationId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        if (!$row) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Application not found.']);
            header('Location: index.php?route=admin/applications');
            exit;
        }

        $currentStatus = (string)($row['Status'] ?? '');

        $currentStatusKey = strtolower(trim($currentStatus));
        $targetStatusKey = strtolower(trim($targetStatus));
        if ($targetStatusKey === 'cardissued' && !in_array($currentStatusKey, ['senttobank', 'sent_to_bank', 'approved', 'cardissued', 'card_issued'], true)) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'CardIssued can only be set after the application has been approved or sent to the bank.']);
            header('Location: index.php?route=admin/applications-view&id=' . urlencode((string)$applicationId));
            exit;
        }

        [$stepKey, $locked] = $this->mapStatusState($targetStatus);

        $upd = $this->db->prepare("
            UPDATE dbo.tblApplications
            SET Status = :status,
                CurrentStepKey = :step_key,
                Locked = :locked,
                LastSavedAt = SYSUTCDATETIME()
            WHERE ApplicationID = :id
        ");
        $upd->execute([
            'status' => $targetStatus,
            'step_key' => $stepKey,
            'locked' => $locked,
            'id' => $applicationId,
        ]);

        $auditAction = in_array($targetStatusKey, ['draft', 'inprogress'], true) ? 'REOPEN' : 'STATUS_UPDATE';
        $this->audit($auditAction, 'Application', (string)$applicationId, [
            'status_before' => $currentStatus,
            'status_after' => $targetStatus,
            'status_reason' => $statusReason,
            'locked_before' => (int)($row['Locked'] ?? 0),
            'locked_after' => $locked,
            'step_after' => $stepKey,
        ]);

        SessionHelper::set('flash.message', [
            'type' => 'success',
            'text' => $targetStatusKey === 'cardissued'
                ? 'Application marked as CardIssued. The user will now see the application as fully completed.'
                : 'Application status updated to ' . $targetStatus . '. The applicant can now reopen and edit the application.',
        ]);
        header('Location: index.php?route=admin/applications-view&id=' . urlencode((string)$applicationId));
        exit;
    }

    public function resetApproval(): void
    {
        $this->ensureAdminAccess();

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo 'Method not allowed';
            return;
        }

        if (!csrf_check($_POST['_csrf'] ?? '')) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Security check failed. Please try again.']);
            header('Location: index.php?route=admin/applications');
            exit;
        }

        $applicationId = (int)($_POST['application_id'] ?? 0);
        $resetReason = trim((string)($_POST['reset_reason'] ?? ''));
        if ($applicationId <= 0) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Missing application id.']);
            header('Location: index.php?route=admin/applications');
            exit;
        }
        if ($resetReason === '') {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Reason is required.']);
            header('Location: index.php?route=admin/applications-view&id=' . urlencode((string)$applicationId));
            exit;
        }

        $stmt = $this->db->prepare("
            SELECT
                a.ApplicationID,
                a.ApplicationTypeID,
                a.Status,
                at.ApplicationTypeKey
            FROM dbo.tblApplications a
            LEFT JOIN dbo.tblApplicationTypes at
              ON at.ApplicationTypeID = a.ApplicationTypeID
            WHERE a.ApplicationID = :id
        ");
        $stmt->execute(['id' => $applicationId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        if (!$row) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Application not found.']);
            header('Location: index.php?route=admin/applications');
            exit;
        }

        $applicationTypeKey = strtolower(trim((string)($row['ApplicationTypeKey'] ?? '')));
        $adminUserId = (int)(SessionHelper::get('auth.user_id') ?? 0);

        try {
            if (str_contains($applicationTypeKey, 'limit_change')) {
                $controller = new CardsController();
                $result = $controller->adminResetApprovalProcess($applicationId, $adminUserId);
            } elseif ($applicationTypeKey === 'dpc' || (int)($row['ApplicationTypeID'] ?? 0) === 1) {
                $controller = new ApplicationsController();
                $result = $controller->adminResetApprovalProcess($applicationId, $adminUserId);
            } else {
                throw new \RuntimeException('Approval reset is only available for workflow-based approval applications.');
            }

            $this->audit('RESET_APPROVAL_PROCESS', 'Application', (string)$applicationId, [
                'status_before' => (string)($row['Status'] ?? ''),
                'status_after' => 'ToBeApproved',
                'reset_reason' => $resetReason,
                'route' => 'admin/applications-reset-approval',
                'result' => $result,
            ]);

            $recipientSummary = '';
            if (!empty($result['recipient_emails']) && is_array($result['recipient_emails'])) {
                $recipientSummary = ' Notification sent to: ' . implode(', ', $result['recipient_emails']) . '.';
            }

            SessionHelper::set('flash.message', [
                'type' => 'success',
                'text' => 'Approval process reset. The workflow payload was rebuilt and the approval email was resent.' . $recipientSummary,
            ]);
        } catch (\Throwable $e) {
            SessionHelper::set('flash.message', [
                'type' => 'danger',
                'text' => 'Could not reset approval process: ' . $e->getMessage(),
            ]);
        }

        header('Location: index.php?route=admin/applications-view&id=' . urlencode((string)$applicationId));
        exit;
    }

    public function delete(): void
    {
        $this->ensureAdminAccess();

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo 'Method not allowed';
            return;
        }

        if (!csrf_check($_POST['_csrf'] ?? '')) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Security check failed. Please try again.']);
            header('Location: index.php?route=admin/applications');
            exit;
        }

        $applicationId = (int)($_POST['application_id'] ?? 0);
        if ($applicationId <= 0) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Missing application id.']);
            header('Location: index.php?route=admin/applications');
            exit;
        }

        $stmt = $this->db->prepare("
            SELECT ApplicationID, Status, Locked
            FROM dbo.tblApplications
            WHERE ApplicationID = :id
        ");
        $stmt->execute(['id' => $applicationId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        if (!$row) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Application not found.']);
            header('Location: index.php?route=admin/applications');
            exit;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                DELETE FROM dbo.tblApplicationSteps
                WHERE ApplicationID = :id
            ");
            $stmt->execute(['id' => $applicationId]);

            $stmt = $this->db->prepare("
                DELETE FROM dbo.tblApplications
                WHERE ApplicationID = :id
            ");
            $stmt->execute(['id' => $applicationId]);

            $this->db->commit();

            $this->audit('DELETE', 'Application', (string)$applicationId, [
                'status_before' => (string)($row['Status'] ?? ''),
                'route' => 'admin/applications-delete',
            ]);

            SessionHelper::set('flash.message', ['type' => 'success', 'text' => 'Application deleted.']);
        } catch (\Throwable $e) {
            if ($this->db instanceof \PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Could not delete application.']);
        }

        header('Location: index.php?route=admin/applications');
        exit;
    }

    private function ensureAdminAccess(): void
    {
        $rbac = new Rbac($this->db);
        $roles = array_map('strtolower', (array)SessionHelper::get('auth.roles', []));
        if ($rbac->canAny(['ADMIN_ALL', 'SYSADMIN']) || in_array('admin', $roles, true)) {
            return;
        }

        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    private function audit(string $action, string $entity, string $entityKey, array $details = []): void
    {
        try {
            $audit = new AuditModel($this->db);
            $audit->insert([
                'UserID' => (int)(SessionHelper::get('auth.user_id') ?? 0),
                'Username' => (string)(SessionHelper::get('auth.username') ?? ''),
                'Action' => $action,
                'Entity' => $entity,
                'EntityKey' => $entityKey,
                'Details' => $details,
                'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                'VersionID' => SessionHelper::get('VersionID'),
            ]);
        } catch (\Throwable $e) {
        }
    }

    private function isStatusHistoryEntry(array $row): bool
    {
        $action = strtoupper(trim((string)($row['Action'] ?? '')));
        if (in_array($action, ['REOPEN', 'SUBMIT', 'SAVE_DRAFT', 'STATUS_UPDATE'], true)) {
            return true;
        }

        $raw = (string)($row['Details'] ?? '');
        if ($raw === '') {
            return false;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return false;
        }
        return array_key_exists('status_before', $data) || array_key_exists('status_after', $data);
    }

    private function mapStatusState(string $status): array
    {
        $statusKey = strtolower(trim($status));
        $stepKey = match ($statusKey) {
            'draft', 'inprogress' => 'inprogress',
            'submitted' => 'submitted',
            'tobeapproved', 'awaitingapproval' => 'tobeapproved',
            'approved' => 'approved',
            'rejected' => 'rejected',
            'senttobank', 'sent_to_bank' => 'senttobank',
            'cardissued', 'card_issued' => 'cardissued',
            default => 'inprogress',
        };

        $locked = in_array($statusKey, ['draft', 'inprogress'], true) ? 0 : 1;
        return [$stepKey, $locked];
    }

    private function enrichApplicationListRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $payload = json_decode((string)($row['DataJson'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];

            $submitterName = trim((string)($row['DisplayName'] ?? ''));
            if ($submitterName === '') {
                $submitterName = trim((string)($row['FirstName'] ?? '') . ' ' . (string)($row['LastName'] ?? ''));
            }
            if ($submitterName === '') {
                $submitterName = trim((string)($row['Username'] ?? ''));
            }

            $applicantEmployeeId = trim((string)($payload['target_employee_id'] ?? ($row['EmployeeID'] ?? '')));
            $applicantProfile = $this->loadApplicantProfileByEmployeeId($applicantEmployeeId);

            if ($applicantProfile['name'] === '' && $applicantEmployeeId !== '' && strcasecmp($applicantEmployeeId, trim((string)($row['EmployeeID'] ?? ''))) === 0) {
                $applicantProfile['name'] = $submitterName;
            }
            if ($applicantProfile['email'] === '' && $applicantEmployeeId !== '' && strcasecmp($applicantEmployeeId, trim((string)($row['EmployeeID'] ?? ''))) === 0) {
                $applicantProfile['email'] = trim((string)($row['Email'] ?? ''));
            }

            $row['ApplicantEmployeeID'] = $applicantEmployeeId;
            $row['ApplicantName'] = $applicantProfile['name'] !== '' ? $applicantProfile['name'] : ($applicantEmployeeId !== '' ? $applicantEmployeeId : '');
            $row['ApplicantEmail'] = $applicantProfile['email'];
            $row['SubmitterName'] = $submitterName;
            $row['SubmitterEmail'] = trim((string)($row['Email'] ?? ''));
            $row['IsOnBehalf'] = $applicantEmployeeId !== ''
                && strcasecmp($applicantEmployeeId, trim((string)($row['EmployeeID'] ?? ''))) !== 0;

            $out[] = $row;
        }

        return $out;
    }

    private function loadApplicantProfileByEmployeeId(string $employeeId): array
    {
        $employeeId = trim($employeeId);
        if ($employeeId === '') {
            return ['name' => '', 'email' => ''];
        }

        if (isset($this->applicantProfileCache[$employeeId])) {
            return $this->applicantProfileCache[$employeeId];
        }

        $profile = ['name' => '', 'email' => ''];

        try {
            $stmt = $this->db->prepare("
                SELECT TOP 1
                    LTRIM(RTRIM(ISNULL(NULLIF(DisplayName, ''), NULLIF(Username, '')))) AS DisplayName,
                    LTRIM(RTRIM(ISNULL(Email, ''))) AS Email
                FROM dbo.tblUsers
                WHERE LTRIM(RTRIM(ISNULL(EmployeeID, ''))) = :employee_id
                ORDER BY UserID DESC
            ");
            $stmt->execute(['employee_id' => $employeeId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $profile['name'] = trim((string)($row['DisplayName'] ?? ''));
            $profile['email'] = trim((string)($row['Email'] ?? ''));
        } catch (\Throwable $e) {
            // Ignore and fall through to CAPS lookup.
        }

        if ($profile['name'] === '' || $profile['email'] === '') {
            global $capsConn;
            if ($capsConn instanceof \PDO) {
                try {
                    $stmt = $capsConn->prepare("
                        SELECT TOP 1
                            NULLIF(LTRIM(RTRIM(Firstname)), '') AS FirstName,
                            NULLIF(LTRIM(RTRIM(Surname)), '') AS Surname,
                            NULLIF(LTRIM(RTRIM(Email_Address)), '') AS EmailAddress
                        FROM dbo.tblCAPSCDMCPortal
                        WHERE NULLIF(LTRIM(RTRIM(EmployeeID)), '') = :employee_id
                    ");
                    $stmt->execute(['employee_id' => $employeeId]);
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
                    if ($profile['name'] === '') {
                        $firstName = trim((string)($row['FirstName'] ?? ''));
                        $surname = trim((string)($row['Surname'] ?? ''));
                        $profile['name'] = trim($surname !== '' || $firstName !== '' ? ($surname . ($surname !== '' && $firstName !== '' ? ', ' : '') . $firstName) : '');
                    }
                    if ($profile['email'] === '') {
                        $profile['email'] = trim((string)($row['EmailAddress'] ?? ''));
                    }
                } catch (\Throwable $e) {
                    // Keep fallback values.
                }
            }
        }

        $this->applicantProfileCache[$employeeId] = $profile;
        return $profile;
    }
}
