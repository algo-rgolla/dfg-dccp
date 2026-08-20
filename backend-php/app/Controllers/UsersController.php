<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Shared\SessionHelper;
use App\Models\UserModel;
use App\Models\AuditModel;
use App\Models\RoleModel;
use App\Models\UserRoleModel;
use App\Models\SystemSettingsModel;
use App\Services\EmailTemplateService;
use App\Services\MailService;

require_once __DIR__ . '/../../shared/csrf.php';

final class UsersController extends BaseController
{
    protected array $acl = [
        '*'      => ['auth' => true, 'permsAny' => ['USERS_ADMIN']],
        'list'   => ['auth' => true, 'permsAny' => ['USERS_VIEW','USERS_ADMIN']],
        'edit'   => ['auth' => true, 'permsAny' => ['USERS_EDIT','USERS_ADMIN']],
        'save'   => ['auth' => true, 'permsAny' => ['USERS_EDIT','USERS_ADMIN']],
        'resetActivation' => ['auth' => true, 'permsAny' => ['USERS_ADMIN']],
        'unlock' => ['auth' => true, 'permsAny' => ['USERS_ADMIN']],
        'saveRoles'      => ['auth' => true, 'permsAny' => ['USERS_EDIT','USERS_ADMIN']],
        'exportPdf'      => ['auth' => true, 'permsAny' => ['USERS_VIEW','USERS_ADMIN']],
        'exportUserPdf'  => ['auth' => true, 'permsAny' => ['USERS_VIEW','USERS_ADMIN']],
        'upload'         => ['auth' => true, 'permsAny' => ['USERS_ADMIN']],
        'uploadProcess'  => ['auth' => true, 'permsAny' => ['USERS_ADMIN']],
    ];

    public function __construct()
    {
        parent::__construct();
    }

    /** Show list of users with filters + pagination */
    public function list(): void
{
    require __DIR__ . '/../../config/db.php';
    $model = new UserModel($conn);

    $q          = trim((string)($_GET['q'] ?? ''));
    $department = trim((string)($_GET['department'] ?? ''));
    $status     = ($_GET['status'] ?? '') !== '' ? (string)$_GET['status'] : '';
    $departments = $model->listDepartments();

    $filters = [
        'q'          => $q,
        'department' => $department,
        'status'     => $status,
    ];
    \App\Shared\SessionHelper::set('users.filters', $filters);

    $perPage     = 25;
    $currentPage = max(1, (int)($_GET['page'] ?? 1));
    $hasSearchFilter = mb_strlen($q) >= 2;
    $hasDepartmentFilter = $department !== '';
    $canRunListing = $hasSearchFilter || $hasDepartmentFilter;
    $listMessage = null;
    $totalCount = 0;
    $users = [];
    $totalPages = 0;

    if (!$canRunListing) {
        $listMessage = $q !== ''
            ? 'Enter at least 2 characters in search, or select a department, before loading users.'
            : 'Search by name, username, or email, or select a department, before loading users.';
    } else {
        $totalCount = $model->countFiltered($q, $status, $department);
        $totalPages = max(1, (int)ceil($totalCount / $perPage));
        $currentPage = min($currentPage, $totalPages);

    // ✅ calculate correct offset
        $offset = ($currentPage - 1) * $perPage;

        $users = $model->listFiltered($q, $status, $department, $offset, $perPage);
    }

    $flash = SessionHelper::get('flash.message', null);

    $this->render('users/UsersList', [
        'title'         => __t('menu_users'),
        'users'         => $users,
        'filters'       => $filters,
        'departments'   => $departments,
        'canRunListing' => $canRunListing,
        'listMessage'   => $listMessage,
        'perPage'       => $perPage,
        'currentPage'   => $currentPage,
        'totalPages'    => $totalPages,
        'totalCount'    => $totalCount,
        'flash'         => $flash,
    ]);

    if ($flash !== null) {
        SessionHelper::forget('flash.message');
    }
}


    /** Edit existing user or show blank form */
    public function edit(): void
    {
        require __DIR__ . '/../../config/db.php';

        $id = (int)($_GET['id'] ?? 0);

        $userModel     = new UserModel($conn);
        $roleModel     = new RoleModel($conn);
        $userRoleModel = new UserRoleModel($conn);

        $user = $id > 0 ? $userModel->find($id) : null;

        // ✅ Get all roles in system
        $roles = $roleModel->listAll();

        // ✅ Flatten user roles to an array of RoleIDs only
        $userRoles = $id > 0
            ? array_column($userRoleModel->listByUser($id), 'RoleID')
            : [];
        $activationPreview = $id > 0 ? $this->loadActivationPreview($conn, $id) : null;

        $flash = SessionHelper::get('flash.message', null);

        $this->render('users/UserForm', [
            'title'     => $id > 0 ? __t('edit_user') : __t('create_user'),
            'user'      => $user,
            'roles'     => $roles,
            'userRoles' => $userRoles,
            'activationPreview' => $activationPreview,
            'flash'     => $flash,
        ]);

        if ($flash !== null) {
            SessionHelper::forget('flash.message');
        }
    }

    /** Save user changes (create or update) */
    public function save(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        require __DIR__ . '/../../config/db.php';
        $model = new UserModel($conn);
        $audit = new AuditModel($conn);

        $id         = (int)($_POST['UserID'] ?? 0);
        $username   = trim((string)($_POST['Username'] ?? ''));
        $employeeId = trim((string)($_POST['EmployeeID'] ?? ''));
        $email      = trim((string)($_POST['Email'] ?? ''));
        $firstName  = trim((string)($_POST['FirstName'] ?? ''));
        $lastName   = trim((string)($_POST['LastName'] ?? ''));
        $display    = trim((string)($_POST['DisplayName'] ?? ''));
        $phone      = trim((string)($_POST['Phone'] ?? ''));
        $department = trim((string)($_POST['Department'] ?? ''));
        $jobTitle   = trim((string)($_POST['JobTitle'] ?? ''));
        $notes      = trim((string)($_POST['Notes'] ?? ''));
        $isActive   = isset($_POST['IsActive']) ? 1 : 0;
        $isActivated = isset($_POST['IsActivated']) ? 1 : 0;
        $forceReset = isset($_POST['ForcePasswordReset']) ? 1 : 0;
        $mustChange = isset($_POST['MustChangePassword']) ? 1 : 0;

        $data = [
            'EmployeeID'         => $employeeId !== '' ? $employeeId : null,
            'Username'           => $username,
            'Email'              => $email,
            'FirstName'          => $firstName,
            'LastName'           => $lastName,
            'DisplayName'        => $display,
            'Phone'              => $phone,
            'Department'         => $department,
            'JobTitle'           => $jobTitle,
            'Notes'              => $notes,
            'IsActive'           => $isActive,
            'IsActivated'        => $isActivated,
            'ForcePasswordReset' => $forceReset,
            'MustChangePassword' => $mustChange,
            'UpdatedBy'          => (int)SessionHelper::get('auth.user_id', 0),
            'UpdatedAt'          => gmdate('Y-m-d H:i:s'),
        ];

        try {
            if ($id > 0) {
                $model->update($id, $data);

                if ($isActive === 0) {
                    $st = $conn->prepare("
                        UPDATE dbo.tblUserSessions
                        SET IsActive = 0,
                            ForceLogout = 1,
                            LogoutTime = COALESCE(LogoutTime, SYSUTCDATETIME())
                        WHERE UserID = :uid
                          AND IsActive = 1
                    ");
                    $st->execute(['uid' => $id]);
                }

                $this->flashSuccess(__t('user_updated', ['user' => $username]));

                $audit->insert([
                    'UserID'       => SessionHelper::get('auth.user_id'),
                    'Username'     => SessionHelper::get('auth.username', 'guest'),
                    'Action'       => 'UPDATE',
                    'Entity'       => 'User',
                    'EntityKey'    => (string)$id,
                    'IPAddress'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'Details'      => json_encode(['EmployeeID' => $employeeId, 'Username' => $username, 'Email' => $email, 'Active' => $isActive, 'Activated' => $isActivated]),
                    'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                    'VersionID'    => SessionHelper::get('VersionID'),
                ]);

            } else {
                $newId = $model->create($data);
                $this->flashSuccess(__t('user_created', ['user' => $username]));

                $audit->insert([
                    'UserID'       => SessionHelper::get('auth.user_id'),
                    'Username'     => SessionHelper::get('auth.username', 'guest'),
                    'Action'       => 'CREATE',
                    'Entity'       => 'User',
                    'EntityKey'    => (string)$newId,
                    'IPAddress'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'Details'      => json_encode(['EmployeeID' => $employeeId, 'Username' => $username, 'Email' => $email, 'Active' => $isActive, 'Activated' => $isActivated]),
                    'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                    'VersionID'    => SessionHelper::get('VersionID'),
                ]);
            }
        } catch (\Throwable $e) {
            $this->flashError(__t('user_save_failed') . ': ' . $e->getMessage());
        }

        header('Location: index.php?route=users/list');
        exit;
    }

    /** Unlock user (clear locks + reset counters) */
    public function unlock(): void
    {
        require __DIR__ . '/../../config/db.php';
        $model = new UserModel($conn);
        $audit = new AuditModel($conn);

        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            $this->flashError(__t('invalid_user'));
            header('Location: index.php?route=users/list');
            exit;
        }

        try {
            $count = $model->unlock($id);
            $user  = $model->find($id);

            $this->flashSuccess(__t('lock_reset_success', [
                'user'  => $user['Username'] ?? (string)$id,
                'count' => (string)$count,
            ]));

            $audit->insert([
                'UserID'       => SessionHelper::get('auth.user_id'),
                'Username'     => SessionHelper::get('auth.username', 'guest'),
                'Action'       => 'UNLOCK',
                'Entity'       => 'User',
                'EntityKey'    => (string)$id,
                'IPAddress'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'Details'      => json_encode(['Message' => "Login lock reset for user " . ($user['Username'] ?? $id), 'Count' => $count]),
                'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                'VersionID'    => SessionHelper::get('VersionID'),
            ]);

        } catch (\Throwable $e) {
            $this->flashError(__t('lock_reset_fail', ['msg' => $e->getMessage()]));
        }

        header('Location: index.php?route=users/list');
        exit;
    }

    public function resetActivation(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        if (!csrf_check($_POST['_csrf'] ?? '')) {
            $this->flashError(__t('security_check_failed'));
            header('Location: index.php?route=users/list');
            exit;
        }

        require __DIR__ . '/../../config/db.php';
        $audit = new AuditModel($conn);

        $id = (int)($_POST['UserID'] ?? 0);
        if ($id <= 0) {
            $this->flashError(__t('invalid_user'));
            header('Location: index.php?route=users/list');
            exit;
        }

        $st = $conn->prepare("
            SELECT TOP 1 UserID, Username, WindowsLogin, Email, FirstName, DisplayName
            FROM dbo.tblUsers
            WHERE UserID = :id
        ");
        $st->execute(['id' => $id]);
        $user = $st->fetch(\PDO::FETCH_ASSOC) ?: null;

        if (!$user) {
            $this->flashError('User not found.');
            header('Location: index.php?route=users/list');
            exit;
        }

        $email = trim((string)($user['Email'] ?? ''));
        $showActivationLink = $this->shouldShowActivationLinkInsteadOfEmail();
        if ($email === '' && !$showActivationLink) {
            $this->flashError('This user does not have an email address, so an activation email cannot be sent.');
            header('Location: index.php?route=users/edit&id=' . $id . '#edit');
            exit;
        }

        $conn->beginTransaction();
        try {
            $st = $conn->prepare("
                UPDATE dbo.tblUsers
                SET IsActivated = 0,
                    UpdatedAt = SYSUTCDATETIME(),
                    UpdatedBy = :updatedBy
                WHERE UserID = :id
            ");
            $st->execute([
                'id' => $id,
                'updatedBy' => (int)SessionHelper::get('auth.user_id', 0),
            ]);

            $st = $conn->prepare("
                UPDATE dbo.tblActivationTokens
                SET IsUsed = 1,
                    UsedAt = COALESCE(UsedAt, SYSUTCDATETIME())
                WHERE UserID = :id
                  AND ISNULL(IsUsed, 0) = 0
            ");
            $st->execute(['id' => $id]);

            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $st = $conn->prepare("
                INSERT INTO dbo.tblActivationTokens
                    (UserID, Token, Email, ExpiresAt)
                VALUES (:userId, :token, :email, :expires)
            ");
            $st->execute([
                'userId' => $id,
                'token' => $token,
                'email' => $email,
                'expires' => $expires,
            ]);

            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $this->flashError('Activation reset failed: ' . $e->getMessage());
            header('Location: index.php?route=users/edit&id=' . $id . '#edit');
            exit;
        }

        $activationLink = $this->resolvePortalBaseUrl($conn) . '/index.php?route=auth/activate&token=' . urlencode($token);
        $activationEmailLink = preg_replace('#^https?://#i', '', $activationLink) ?: $activationLink;
        $dearName = trim((string)($user['FirstName'] ?? '')) !== ''
            ? trim((string)$user['FirstName'])
            : (trim((string)($user['DisplayName'] ?? '')) !== '' ? trim((string)$user['DisplayName']) : 'User');
        $windowsLogin = trim((string)($user['WindowsLogin'] ?? '')) !== ''
            ? trim((string)$user['WindowsLogin'])
            : trim((string)($user['Username'] ?? ''));

        $templateService = new EmailTemplateService($conn);
        $rendered = $templateService->renderTemplate('activation_account', [
            '{{dear_name}}' => htmlspecialchars($dearName, ENT_QUOTES, 'UTF-8'),
            '{{windows_login}}' => htmlspecialchars($windowsLogin, ENT_QUOTES, 'UTF-8'),
            '{{activation_link}}' => htmlspecialchars((string)$activationEmailLink, ENT_QUOTES, 'UTF-8'),
        ]);

        $emailSent = false;
        if (!$showActivationLink) {
            try {
                $mail = new MailService($conn);
                $emailSent = $mail->sendEmail($email, $rendered['subject'], $rendered['body']);
            } catch (\Throwable $e) {
                $emailSent = false;
            }
        }

        $msg = $showActivationLink
            ? 'Activation has been reset. Use this activation URL: ' . $activationLink
            : (
                $emailSent
                    ? 'Activation has been reset and a new activation email has been sent.'
                    : 'Activation was reset, but the activation email could not be sent.'
            );
        if (!$showActivationLink && \envBool('ONBOARDING_SHOW_ACTIVATION_URL', false)) {
            $msg .= ' Activation URL: ' . $activationLink;
        }

        if ($showActivationLink || $emailSent) {
            $this->flashSuccess($msg);
        } else {
            $this->flashError($msg);
        }

        $audit->insert([
            'UserID'       => SessionHelper::get('auth.user_id'),
            'Username'     => SessionHelper::get('auth.username', 'guest'),
            'Action'       => 'RESET_ACTIVATION',
            'Entity'       => 'User',
            'EntityKey'    => (string)$id,
            'IPAddress'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'Details'      => json_encode([
                'TargetUserID' => $id,
                'TargetUsername' => (string)($user['Username'] ?? ''),
                'Email' => $email,
                'ShowActivationLink' => $showActivationLink,
                'EmailSent' => $emailSent,
            ]),
            'FiscalYearID' => SessionHelper::get('FiscalYearID'),
            'VersionID'    => SessionHelper::get('VersionID'),
        ]);

        header('Location: index.php?route=users/edit&id=' . $id . '#edit');
        exit;
    }

    private function loadActivationPreview(\PDO $conn, int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $st = $conn->prepare("
            SELECT TOP 1 Token, Email, ExpiresAt, IsUsed, UsedAt
            FROM dbo.tblActivationTokens
            WHERE UserID = :id
            ORDER BY
                CASE WHEN ISNULL(IsUsed, 0) = 0 THEN 0 ELSE 1 END,
                ExpiresAt DESC,
                UsedAt DESC,
                Token DESC
        ");
        $st->execute(['id' => $userId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }

        $token = trim((string)($row['Token'] ?? ''));
        $link = $token !== ''
            ? $this->resolvePortalBaseUrl($conn) . '/index.php?route=auth/activate&token=' . urlencode($token)
            : '';

        return [
            'Token' => $token,
            'Email' => trim((string)($row['Email'] ?? '')),
            'ExpiresAt' => trim((string)($row['ExpiresAt'] ?? '')),
            'IsUsed' => (int)($row['IsUsed'] ?? 0),
            'UsedAt' => trim((string)($row['UsedAt'] ?? '')),
            'ActivationLink' => $link,
        ];
    }

    /** Save assigned roles */
    public function saveRoles(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        require __DIR__ . '/../../config/db.php';
        $userRoleModel = new UserRoleModel($conn);
        $audit         = new AuditModel($conn);

        $userId  = (int)($_POST['UserID'] ?? 0);
        $roleIds = array_map('intval', $_POST['RoleIDs'] ?? []);

        if ($userId <= 0) {
            $this->flashError(__t('invalid_user'));
            header('Location: index.php?route=users/list');
            exit;
        }

        try {
            $userRoleModel->setRoles($userId, $roleIds);
            $this->flashSuccess(__t('roles_updated_successfully'));

            $audit->insert([
                'UserID'       => SessionHelper::get('auth.user_id'),
                'Username'     => SessionHelper::get('auth.username', 'guest'),
                'Action'       => 'UPDATE_ROLES',
                'Entity'       => 'User',
                'EntityKey'    => (string)$userId,
                'IPAddress'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'Details'      => ['RoleIDs' => $roleIds],
                'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                'VersionID'    => SessionHelper::get('VersionID'),
            ]);
        } catch (\Throwable $e) {
            $this->flashError(__t('roles_update_failed') . ': ' . $e->getMessage());
        }

        header('Location: index.php?route=users/edit&id=' . $userId . '#roles');
        exit;
    }

public function exportPdf(): void
{
    @set_time_limit(120);

    if (!isset($conn) || !($conn instanceof \PDO)) {
        require __DIR__ . '/../../config/db.php';
    }

    $model   = new \App\Models\UserModel($conn);
    $filters = \App\Shared\SessionHelper::get('users.filters', [
        'q'          => '',
        'department' => '',
        'status'     => ''
    ]);
    $q = trim((string)($filters['q'] ?? ''));
    $department = trim((string)($filters['department'] ?? ''));
    if (mb_strlen($q) < 2 && $department === '') {
        $this->flashError('Search by name, username, or email, or select a department, before exporting users.');
        header('Location: index.php?route=users/list');
        exit;
    }

    // ✅ Fetch rows using same filters as list()
        $users = $model->listAllFiltered(
        $q,
        (string)($filters['status'] ?? ''),
        $department
    );

    // ✅ Prepare meta line (shows active filters in report)
    $meta = [];
    if ($filters['q'] !== '')         $meta['Search']     = $filters['q'];
    if ($filters['department'] !== '') $meta['Department'] = $filters['department'];
    if ($filters['status'] !== '')     $meta['Status']     = $filters['status'] === '1' ? 'Enabled' : 'Disabled';

    // ✅ Call modular PdfReport
    \App\Shared\PdfReport::render([
        'title'    => 'Users',
        'filename' => 'Users.pdf',
        'columns'  => [
            ['label' => 'ID',           'key' => 'UserID'],
            ['label' => 'Username',     'key' => 'Username'],
            ['label' => 'Display Name', 'key' => 'DisplayName'],
            ['label' => 'Email',        'key' => 'Email'],
            ['label' => 'Department',   'key' => 'Department'],
            ['label' => 'Job',          'key' => 'JobTitle'],
            ['label' => 'Status',       'key' => 'IsActive'],
            ['label' => 'Last Login',   'key' => 'LastLoginAt'],
            ['label' => 'Failed',       'key' => 'FailedLoginCount'],
        ],
        'rows'     => array_map(function ($u) {
            // Normalise row data so PdfReport doesn’t need to know logic
            $u['IsActive'] = ((int)($u['IsActive'] ?? 0) === 1) ? 'Enabled' : 'Disabled';
            return $u;
        }, $users),
        'meta'     => $meta,
    ]);
}

public function exportUserPdf(): void
{
    @set_time_limit(120);
    require __DIR__ . '/../../config/db.php';

    $userModel     = new \App\Models\UserModel($conn);
    $roleModel     = new \App\Models\RoleModel($conn);
    $userRoleModel = new \App\Models\UserRoleModel($conn);

    $id   = (int)($_GET['id'] ?? 0);
    $user = $id > 0 ? $userModel->find($id) : null;
    if (!$user) {
        http_response_code(404);
        echo __t('user_not_found'); // translated
        return;
    }

    $roles     = $roleModel->listAll();
    $userRoles = array_column($userRoleModel->listByUser($id), 'RoleID');

    $esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    // --- Section: Edit Info ---
    $editHtml = "<table class='table table-bordered align-middle'>
        <tbody>
          <tr><th style='width:20%'>" . __t('username_label') . "</th><td style='width:80%'>{$esc($user['Username'] ?? '')}</td></tr>
          <tr><th>" . __t('email') . "</th><td>{$esc($user['Email'] ?? '')}</td></tr>
          <tr><th>" . __t('first_name') . "</th><td>{$esc($user['FirstName'] ?? '')}</td></tr>
          <tr><th>" . __t('last_name') . "</th><td>{$esc($user['LastName'] ?? '')}</td></tr>
          <tr><th>" . __t('display_name') . "</th><td>{$esc($user['DisplayName'] ?? '')}</td></tr>
          <tr><th>" . __t('phone') . "</th><td>{$esc($user['Phone'] ?? '')}</td></tr>
          <tr><th>" . __t('department') . "</th><td>{$esc($user['Department'] ?? '')}</td></tr>
          <tr><th>" . __t('job_title') . "</th><td>{$esc($user['JobTitle'] ?? '')}</td></tr>
          <tr><th>" . __t('status') . "</th><td>" . ((int)($user['IsActive'] ?? 0) === 1 ? __t('enabled') : __t('disabled')) . "</td></tr>
        </tbody>
      </table>";

    // --- Section: Details ---
    $detailsHtml = "<table class='table table-bordered align-middle'>
        <tbody>
          <tr><th style='width:20%'>" . __t('user_id') . "</th><td style='width:80%'>{$esc((string)$user['UserID'])}</td></tr>
          <tr><th>" . __t('last_login') . "</th><td>{$esc($user['LastLoginAt'] ?? '—')}</td></tr>
          <tr><th>" . __t('last_login_ip') . "</th><td>{$esc($user['LastLoginIP'] ?? '—')}</td></tr>
          <tr><th>" . __t('login_count') . "</th><td>{$esc((string)($user['LoginCount'] ?? 0))}</td></tr>
          <tr><th>" . __t('failed_logins') . "</th><td>{$esc((string)($user['FailedLoginCount'] ?? 0))}</td></tr>
          <tr><th>" . __t('last_failed_login') . "</th><td>{$esc($user['LastFailedLoginAt'] ?? '—')}</td></tr>
          <tr><th>" . __t('created_at') . "</th><td>{$esc($user['CreatedAt'] ?? '—')}</td></tr>
          <tr><th>" . __t('created_by') . "</th><td>{$esc((string)($user['CreatedBy'] ?? '—'))}</td></tr>
          <tr><th>" . __t('updated_at') . "</th><td>{$esc($user['UpdatedAt'] ?? '—')}</td></tr>
          <tr><th>" . __t('updated_by') . "</th><td>{$esc((string)($user['UpdatedBy'] ?? '—'))}</td></tr>
        </tbody>
      </table>";

    // --- Section: Assigned Roles ---
    $assignedRoleIds = array_map('intval', $userRoles);
    $rows = '';

    foreach ($roles as $r) {
        $rid      = (int)$r['RoleID'];
        $assigned = in_array($rid, $assignedRoleIds, true) ? __t('yes') : __t('no');
        $rows .= "<tr><td>{$esc((string)$r['RoleName'])}</td><td>{$assigned}</td></tr>";
    }

    if ($rows === '') {
        $rows = "<tr><td colspan='2'>" . __t('no_roles_found') . "</td></tr>";
    }

    $rolesHtml = "<table class='table table-bordered align-middle'>
        <thead><tr><th style='width:20%'>" . __t('role') . "</th><th style='width:80%'>" . __t('assigned') . "</th></tr></thead>
        <tbody>{$rows}</tbody>
      </table>";

    // --- Sections for PdfReport ---
    $sections = [
        ['title' => __t('user_details'), 'html' => $editHtml],
        ['title' => __t('user_meta_data'), 'html' => $detailsHtml],
        ['title' => __t('assign_roles'), 'html' => $rolesHtml],
    ];

    // --- Call PdfReport ---
    \App\Shared\PdfReport::render([
        'title'    => __t('user_report') . ': ' . $esc($user['Username']),
        'filename' => 'User_' . $user['UserID'] . '.pdf',
        'mode'     => 'sections',
        'sections' => $sections,
        'meta'     => [
            __t('user_id')      => $user['UserID'],
            __t('generated_by') => SessionHelper::get('auth.username', 'system'),
            __t('generated_on') => date('Y-m-d H:i'),
        ],
    ]);
}

public function upload(): void
{
    $this->render('users/UsersUpload', [
        'title' => __t('upload_users'),
    ]);
}


public function uploadProcess(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo __t('method_not_allowed');
        return;
    }

    require __DIR__ . '/../../config/db.php';
    $validator = new \App\Services\UserUploadValidator($conn);
    $userModel = new \App\Models\UserModel($conn);

    if (!isset($_FILES['uploadFile']) || $_FILES['uploadFile']['error'] !== UPLOAD_ERR_OK) {
        $this->flashError("File upload failed.");
        header("Location: index.php?route=users/upload");
        exit;
    }

    $filePath = $_FILES['uploadFile']['tmp_name'];

    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);

        // ✅ Always target the "Users" sheet (admins may save with "Instructions" active)
        $sheet = $spreadsheet->getSheetByName('Users') ?? $spreadsheet->getActiveSheet();

        // ✅ Use numeric indexes (0,1,2,...) not A,B,C
        $rows = $sheet->toArray(null, true, true, false);

        if (empty($rows)) {
            throw new \RuntimeException("Empty worksheet.");
        }

        // Header normalisation
        $headerRow = array_map(static fn($v) => trim((string)$v), $rows[0] ?? []);
        $expected  = [
            'Username','Email','FirstName','LastName','DisplayName',
            'Phone','Department','JobTitle','IsActive','RoleID',
            'Notes','ForcePasswordReset','MustChangePassword','Password'
        ];

        // ✅ Robust header check (order must match, case-insensitive, trimmed)
        foreach ($expected as $i => $col) {
            $given = $headerRow[$i] ?? '';
            if (strcasecmp($given, $col) !== 0) {
                throw new \RuntimeException(
                    "Header mismatch at column " . ($i + 1) . ": expected '{$col}', found '{$given}'"
                );
            }
        }

        $imported = 0;

        // Iterate data rows (start at row index 1)
        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r];

            // Skip fully empty rows
            if (!array_filter($row, static fn($v) => (string)$v !== '')) {
                continue;
            }

            // Map by index → assoc by expected header labels
            $assoc = [];
            foreach ($expected as $i => $label) {
                $assoc[$label] = isset($row[$i]) ? (string)$row[$i] : '';
            }

            $valid = $validator->validateRow($assoc, $r + 1);
            if ($valid === null) {
                continue; // validator recorded errors
            }

            // Password handling
            if (($valid['Password'] ?? '') !== '') {
                $valid['PasswordHash'] = password_hash($valid['Password'], PASSWORD_BCRYPT);
            } else {
                $valid['PasswordHash'] = password_hash('ChangeMe123!', PASSWORD_BCRYPT);
                $valid['ForcePasswordReset'] = 1;
            }
            unset($valid['Password']);

            $valid['CreatedBy'] = (int)\App\Shared\SessionHelper::get('auth.user_id', 0);
            $valid['UpdatedBy'] = (int)\App\Shared\SessionHelper::get('auth.user_id', 0);

            if ($userModel->create($valid)) {
                $imported++;
            } else {
                // Record DB error against this row
                $err = $userModel->getLastError() ?: 'Unknown error';
                // You can accumulate this into the validator, or flash directly:
                // $validator->addError("Line " . ($r + 1) . ": DB insert failed - " . $err);
                // Alternatively, accumulate and show once at the end
                $errors[] = "Line " . ($r + 1) . ": DB insert failed - " . $err;
            }
        }

        $allErrors = $validator->getErrors() ?? [];
        if (!empty($errors ?? [])) {
            $allErrors = array_merge($allErrors, $errors);
        }

        if ($allErrors) {
            $this->flashError("Upload completed with errors:<br>" . implode("<br>", $allErrors));
        } else {
            $this->flashSuccess("Successfully imported {$imported} users.");
        }

    } catch (\Throwable $e) {
        $this->flashError("Upload failed: " . $e->getMessage());
    }

    header("Location: index.php?route=users/list");
    exit;
}

public function exportExcel(): void
{
    require __DIR__ . '/../../config/db.php';
    $usersModel = new \App\Models\UserModel($conn);

    // Filters from query string
    $q          = trim((string)($_GET['q'] ?? ''));
    $department = trim((string)($_GET['department'] ?? ''));
    $status     = ($_GET['status'] ?? '') !== '' ? (string)$_GET['status'] : '';

    if (mb_strlen($q) < 2 && $department === '') {
        $this->flashError('Search by name, username, or email, or select a department, before exporting users.');
        header('Location: index.php?route=users/list');
        exit;
    }

    // Get filtered list (no pagination)
    $users = $usersModel->listAllFiltered($q, $status, $department);

    // Excel
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Headers
    $headers = ['UserID','Username','DisplayName','Email','Department','JobTitle','IsActive','LastLoginAt','FailedLoginCount'];
    $colLetter = 'A';
    foreach ($headers as $h) {
        $sheet->setCellValue($colLetter . '1', $h);
        $sheet->getStyle($colLetter . '1')->getFont()->setBold(true);
        $sheet->getStyle($colLetter . '1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFD3D3D3'); // light gray
        $colLetter++;
    }

    // ✅ Auto-size columns after populating data
    foreach (range('A', $colLetter) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Freeze header row (row 1)
    $sheet->freezePane('A2');

    // Data
    $rowNum = 2;
    foreach ($users as $u) {
        $colLetter = 'A';
        foreach ($headers as $h) {
            $val = $u[$h] ?? '';
            // Format IsActive → Enabled/Disabled
            if ($h === 'IsActive') {
                $val = ((int)$val === 1) ? 'Enabled' : 'Disabled';
            }
            $sheet->setCellValue($colLetter . $rowNum, $val);
            $colLetter++;
        }
        $rowNum++;
    }

    // Auto-size
    foreach (range('A', $colLetter) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Output
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="users.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

private function resolvePortalBaseUrl(\PDO $conn): string
{
    $settings = new SystemSettingsModel($conn);
    $configured = trim((string)($settings->get('APP_URL') ?? ''));
    if ($configured !== '') {
        return (string)preg_replace('#/backend-php/public/?$#i', '', rtrim($configured, '/'));
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));

    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = trim((string)dirname($scriptName));
    if ($scriptDir === '.' || $scriptDir === '\\' || $scriptDir === '/') {
        $scriptDir = '';
    } else {
        $scriptDir = '/' . trim(str_replace('\\', '/', $scriptDir), '/');
    }
    $scriptDir = (string)preg_replace('#/backend-php/public$#i', '', $scriptDir);

    return $scheme . '://' . $host . $scriptDir;
}


}
