<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\SystemSettingsModel;
use App\Shared\SessionHelper;
use App\Services\ApprovalInboxService;
use App\Services\MailService;
use App\Services\EmailTemplateService;

require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../shared/windows_login.php';

final class OnboardingController extends BaseController
{
    protected array $acl = [
        '*'      => ['auth' => false], // onboarding is pre-auth
        'start'  => ['auth' => false],
        'save'   => ['auth' => false],
    ];

    public function __construct()
    {
        parent::__construct();
    }

public function start(): void
{
    SessionHelper::ensureSession();
    csrf_token();
    $activationEnabled = $this->isNewUserActivationEnabled();

    $windowsLogin = \get_windows_login();
    if ($windowsLogin === null) {
        $this->flashError('Windows login not detected. Ensure IIS Windows Authentication is enabled.');
        $this->render('auth/Onboarding', [
            'title'        => 'Link your account',
            'windowsLogin' => '',
            'prefillEmail' => '',
            'activationEnabled' => $activationEnabled,
        ]);
        return;
    }

    require __DIR__ . '/../../config/db.php';
    $user = \find_user_by_windows_login($conn, $windowsLogin);

    // If user exists and is activated, sign in and go straight to landing page.
    if ($user && (int)($user['IsActivated'] ?? 0) === 1 && (int)($user['IsActive'] ?? 1) === 1) {
        $this->completeLogin((int)($user['UserID'] ?? 0), $conn);
        header('Location: index.php?route=' . $this->postOnboardingLoginRoute());
        exit;
    }

    // If user exists but pending, show activation hint.
    if ($user && (int)($user['IsActivated'] ?? 0) === 0) {
        // Preserve any flash set by save() (e.g., activation URL in test mode).
        if (!SessionHelper::has('flash.message')) {
            $this->flashSuccess('Account linked! Please check your email to activate.');
        }
    }

    $prefillEmail = $this->guessEmailFromWindowsLogin($windowsLogin);

    $this->render('auth/Onboarding', [
        'title'        => 'Link your account',
        'windowsLogin' => $windowsLogin,
        'prefillEmail' => $prefillEmail,
        'activationEnabled' => $activationEnabled,
    ]);
}
  
public function save(): void
{
    SessionHelper::ensureSession();

    if ((!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST')) {
        http_response_code(405);
        echo __t('method_not_allowed');
        return;
    }

    $csrfInput = isset($_POST['_csrf']) ? $_POST['_csrf'] : '';
    if (!csrf_check($csrfInput)) {
        $this->flashError(__t('security_check_failed'));
        header('Location: index.php?route=onboarding/start');
        exit;
    }

    $windowsLogin = get_windows_login();
    if ($windowsLogin === null) {
        $this->flashError('Windows login not detected. Ensure IIS Windows Authentication is enabled.');
        header('Location: index.php?route=onboarding/start');
        exit;
    }

    $employeeId = trim((string)(isset($_POST['EmployeeID']) ? $_POST['EmployeeID'] : ''));
    $email      = trim((string)(isset($_POST['Email']) ? $_POST['Email'] : ''));

    if ($employeeId === '' || $email === '') {
        $this->flashError('EmployeeID and Email are required.');
        header('Location: index.php?route=onboarding/start');
        exit;
    }

    require __DIR__ . '/../../config/db.php';

    if (!$this->isNewUserActivationEnabled()) {
        $this->flashError('New user activation is currently disabled. Existing activated users can still log in.');
        header('Location: index.php?route=onboarding/start');
        exit;
    }

    if (!($capsConn instanceof \PDO)) {
        app_log('Onboarding CAPS connection unavailable', [], 'error');
        $this->flashError('The corporate directory is temporarily unavailable. Please try again or contact support.');
        header('Location: index.php?route=onboarding/start');
        exit;
    }

    $dir = $this->findDirectoryMatch($capsConn, $employeeId, $email);
    if (!$dir) {
        $this->flashError('We could not match that EmployeeID + Email in the corporate directory. Please check and try again.');
        header('Location: index.php?route=onboarding/start');
        exit;
    }

    $userId = $this->upsertUser($conn, $windowsLogin, $employeeId, $email, $dir);
    $this->ensureDefaultRole($conn, $userId);

    // Generate token
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $stmt = $conn->prepare("
        INSERT INTO dbo.tblActivationTokens 
            (UserID, Token, Email, ExpiresAt)
        VALUES (:userId, :token, :email, :expires)
    ");
    $stmt->execute([
        'userId'  => $userId,
        'token'   => $token,
        'email'   => $email,
        'expires' => $expires
    ]);

    // Build link
    $baseUrl = $this->resolvePortalBaseUrl($conn);
    $activationLink = $baseUrl . '/index.php?route=auth/activate&token=' . urlencode($token);
    $activationEmailLink = preg_replace('#^https?://#i', '', $activationLink) ?: $activationLink;

    // Send email
    $dearName = !empty($dir['Firstname']) ? $dir['Firstname'] : 'User';
    $templateService = new EmailTemplateService($conn);
    $rendered = $templateService->renderTemplate('activation_account', [
        '{{dear_name}}' => htmlspecialchars((string)$dearName, ENT_QUOTES, 'UTF-8'),
        '{{windows_login}}' => htmlspecialchars((string)$windowsLogin, ENT_QUOTES, 'UTF-8'),
        '{{activation_link}}' => htmlspecialchars((string)$activationEmailLink, ENT_QUOTES, 'UTF-8'),
    ]);

    $showActivationLink = $this->shouldShowActivationLinkInsteadOfEmail();
    $emailSent = false;
    if (!$showActivationLink) {
        try {
            $mail = new MailService($conn);
            $emailSent = $mail->sendEmail($email, $rendered['subject'], $rendered['body']);
            if ($emailSent) {
                app_log("Activation email sent", ['userId' => $userId, 'email' => $email], 'info');
            } else {
                app_log("Activation email failed", [
                    'userId' => $userId,
                    'email' => $email,
                    'error' => $mail->getLastError(),
                ], 'error');
            }
        } catch (\Throwable $e) {
            $emailSent = false;
            app_log("Activation email failed", [
                'userId' => $userId,
                'email' => $email,
                'error' => $e->getMessage(),
            ], 'error');
        }
    }

    $msg = $showActivationLink
        ? 'Account linked successfully. Use this activation URL to complete activation: ' . $activationLink
        : (
            $emailSent
                ? 'Account linked successfully. Please check your email to confirm activation and log in.'
                : 'Account linked successfully, but the activation email could not be sent. Please contact Defence Credit Cards. defence.creditcards@defence.gov.au'
        );
    if (!$showActivationLink && \envBool('ONBOARDING_SHOW_ACTIVATION_URL', false)) {
        $msg .= ' Activation URL: ' . $activationLink;
    }
    $this->flashSuccess($msg);

    // Stay on onboarding screen to show the message
    $redirectUrl = 'index.php?route=onboarding/start';
    if (!headers_sent($sentFile, $sentLine)) {
        header('Location: ' . $redirectUrl, true, 302);
        exit;
    }

    app_log('Onboarding redirect headers already sent', [
        'file' => $sentFile,
        'line' => $sentLine,
        'redirect' => $redirectUrl,
    ], 'error');

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url='
        . htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8')
        . '"><title>Redirecting...</title></head><body><script>window.location.replace('
        . json_encode($redirectUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)
        . ');</script><p>Redirecting...</p></body></html>';
    exit;
}

    private function getWindowsLogin(): ?string {
    return 'andrew.bull3';
}

    private function resolvePortalBaseUrl(\PDO $conn): string
    {
        $settings = new SystemSettingsModel($conn);
        $configured = trim((string)($settings->get('APP_URL') ?? ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
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

        return $scheme . '://' . $host . $scriptDir;
    }

    private function guessEmailFromWindowsLogin(string $windowsLogin): string
    {
        // You can change the domain here if needed
        return $windowsLogin . '@company.gov.au';
    }

    private function findUserByWindowsLogin(\PDO $conn, string $windowsLogin): ?array
{
    $st = $conn->prepare("
        SELECT TOP 1 UserID, IsActivated
        FROM dbo.tblUsers
        WHERE WindowsLogin = :w
          AND ISNULL(IsActive, 1) = 1
    ");
    $st->execute(['w' => $windowsLogin]);
    return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
}

    private function findDirectoryMatch(\PDO $capsConn, string $employeeId, string $email): ?array
    {
        $st = $capsConn->prepare("
            SELECT TOP 1
                EmployeeID,
                Email_Address,
                Firstname,
                Surname,
                TelephoneNumber,
                MobileNumber,
                DepartmentName,
                DivisionName,
                BranchName,
                JobTitle = ActualRankLvl,
                Active
            FROM dbo.tblCAPSCDMCPortal
            WHERE EmployeeID = :emp
              AND LOWER(LTRIM(RTRIM(Email_Address))) = LOWER(LTRIM(RTRIM(:email)))
        ");
        $st->execute([
            'emp'   => $employeeId,
            'email' => $email,
        ]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function upsertUser(\PDO $conn, string $windowsLogin, string $employeeId, string $email, array $dir): int
{
    $existing = $this->findUserByWindowsLogin($conn, $windowsLogin);

    $first = trim((string)($dir['Firstname'] ?? ''));
    $last  = trim((string)($dir['Surname'] ?? ''));
    $display = trim($first . ' ' . $last);

    if ($existing) {
        // Update existing
        $st = $conn->prepare("
            UPDATE dbo.tblUsers
            SET EmployeeID   = :emp,
                Email        = :email,
                FirstName    = :first,
                LastName     = :last,
                DisplayName  = :display,
                Phone        = :phone,
                Department   = :dept,
                JobTitle     = :job,
                UpdatedAt    = SYSUTCDATETIME()
            WHERE UserID = :id
        ");

        $st->execute([
            'emp'     => $employeeId,
            'email'   => $email,
            'first'   => $first,
            'last'    => $last,
            'display' => $display,
            'phone'   => (string)($dir['TelephoneNumber'] ?? ''),
            'dept'    => (string)($dir['DepartmentName'] ?? ''),
            'job'     => (string)($dir['JobTitle'] ?? ''),
            'id'      => (int)$existing['UserID'],
        ]);

        return (int)$existing['UserID'];
    }

    // Create new
    $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

    // Use OUTPUT clause for sqlsrv (more reliable than SCOPE_IDENTITY with fetch)
   $sql = "
        INSERT INTO dbo.tblUsers
            (Username, WindowsLogin, PasswordHash, Email, IsActive, CreatedAt,
            FirstName, LastName, DisplayName, Phone, Department, JobTitle, EmployeeID,
            FailedLoginCount, ForcePasswordReset, MustChangePassword, LoginCount, IsActivated)
        OUTPUT INSERTED.UserID
        VALUES
            (:username, :windows, :phash, :email, 1, SYSUTCDATETIME(),
            :first, :last, :display, :phone, :dept, :job, :emp,
            0, 0, 0, 0, 0);  -- IsActivated = 0
    ";

    $st = $conn->prepare($sql);

    $st->execute([
        'username' => $windowsLogin,
        'windows'  => $windowsLogin,
        'phash'    => $passwordHash,
        'email'    => $email,
        'first'    => $first,
        'last'     => $last,
        'display'  => $display,
        'phone'   => (string)($dir['TelephoneNumber'] ?? ''),
        'dept'    => (string)($dir['DepartmentName'] ?? ''),
        'job'     => (string)($dir['JobTitle'] ?? ''),
        'emp'     => $employeeId,
    ]);

    // Fetch the OUTPUT value
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if ($row && isset($row['UserID'])) {
        return (int)$row['UserID'];
    }

    // Fallback: query last inserted ID (if OUTPUT fails)
    $lastIdStmt = $conn->query("SELECT SCOPE_IDENTITY() AS NewID");
    $lastIdRow = $lastIdStmt->fetch(\PDO::FETCH_ASSOC);
    return (int)($lastIdRow['NewID'] ?? 0);
}

    private function ensureDefaultRole(\PDO $conn, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $roleName = trim((string)\envStr('ONBOARDING_DEFAULT_ROLE', ''));
        if ($roleName === '') {
            return;
        }

        try {
            $st = $conn->prepare("
                SELECT TOP 1 RoleID
                FROM dbo.tblRoles
                WHERE RoleName = :name
                  AND (Active = 1 OR Active IS NULL)
            ");
            $st->execute(['name' => $roleName]);
            $roleId = (int)($st->fetchColumn() ?: 0);

            if ($roleId <= 0) {
                app_log('Onboarding default role not found', ['role' => $roleName], 'warn');
                return;
            }

            // Prefer insert with DateAssigned when column exists.
            try {
                $st = $conn->prepare("
                    IF NOT EXISTS (
                        SELECT 1 FROM dbo.tblUserRoles WHERE UserID = :uid_check AND RoleID = :rid_check
                    )
                    INSERT INTO dbo.tblUserRoles (UserID, RoleID, DateAssigned)
                    VALUES (:uid_insert, :rid_insert, SYSUTCDATETIME())
                ");
                $st->execute([
                    'uid_check' => $userId,
                    'rid_check' => $roleId,
                    'uid_insert' => $userId,
                    'rid_insert' => $roleId,
                ]);
            } catch (\Throwable $e) {
                // Fallback for schemas without DateAssigned.
                $st = $conn->prepare("
                    IF NOT EXISTS (
                        SELECT 1 FROM dbo.tblUserRoles WHERE UserID = :uid_check AND RoleID = :rid_check
                    )
                    INSERT INTO dbo.tblUserRoles (UserID, RoleID)
                    VALUES (:uid_insert, :rid_insert)
                ");
                $st->execute([
                    'uid_check' => $userId,
                    'rid_check' => $roleId,
                    'uid_insert' => $userId,
                    'rid_insert' => $roleId,
                ]);
            }
        } catch (\Throwable $e) {
            app_log('Onboarding default role assignment failed', [
                'userId' => $userId,
                'role' => $roleName,
                'error' => $e->getMessage(),
            ], 'error');
        }
    }

    private function completeLogin(int $userId, \PDO $conn): void
    {
        // Pull user for session fields
        $st = $conn->prepare("
            SELECT UserID, Username, FirstName, LastName, DisplayName, EmployeeID
            FROM dbo.tblUsers
            WHERE UserID = :id
        ");
        $st->execute(['id' => $userId]);
        $user = $st->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            $this->flashError('Unable to load user after onboarding.');
            header('Location: index.php?route=onboarding/start');
            exit;
        }

        session_regenerate_id(true);
        csrf_regenerate();

        SessionHelper::set('auth.user_id', (int)$user['UserID']);
        SessionHelper::set('auth.username', (string)$user['Username']);

        // IMPORTANT: EmployeeID is varchar(20) in CAPS and nvarchar in CCPortal — keep it as string
        SessionHelper::set('auth.employee_id', (string)($user['EmployeeID'] ?? ''));
        $this->setCapsEmployeeSession((string)($user['EmployeeID'] ?? ''));

        $first = trim((string)($user['FirstName'] ?? ''));
        $last  = trim((string)($user['LastName'] ?? ''));
        $display = trim((string)($user['DisplayName'] ?? ''));
        if ($display === '') $display = trim($first . ' ' . $last);

        SessionHelper::set('auth.first_name', $first);
        SessionHelper::set('auth.last_name', $last);
        SessionHelper::set('auth.display_name', $display !== '' ? $display : (string)$user['Username']);

        SessionHelper::set('auth.login_time', time());
        SessionHelper::set('auth.last_activity', time());
        SessionHelper::set('auth.just_logged_in', true);
        $this->prepareLoginAgreement($conn);

        // Load RBAC like your current login does
        $rbac = new \App\Core\Rbac($conn);
        $rbac->loadForUser((int)$user['UserID']);
        SessionHelper::set('auth.roles', $rbac->roles());
        SessionHelper::set('auth.perms', $rbac->perms());
    }

    private function prepareLoginAgreement(\PDO $conn): void
    {
        try {
            $settings = new SystemSettingsModel($conn);
            $enabled = strtolower(trim((string)($settings->get('LOGIN_AGREEMENT_ENABLED') ?? '0')));
            $text = trim((string)($settings->get('LOGIN_AGREEMENT_TEXT') ?? ''));

            if (!in_array($enabled, ['1', 'true', 'yes', 'on'], true) || $text === '') {
                SessionHelper::forget('auth.login_agreement_pending');
                SessionHelper::forget('auth.login_agreement_accepted');
                SessionHelper::forget('auth.login_agreement_accepted_at');
                SessionHelper::forget('auth.login_agreement_hash');
                SessionHelper::forget('auth.login_agreement_error');
                return;
            }

            SessionHelper::set('auth.login_agreement_pending', true);
            SessionHelper::set('auth.login_agreement_accepted', false);
            SessionHelper::forget('auth.login_agreement_accepted_at');
            SessionHelper::set('auth.login_agreement_hash', sha1($text));
        } catch (\Throwable $e) {
            app_log('Onboarding login agreement setup failed', ['error' => $e->getMessage()], 'warn');
        }
    }

    private function postOnboardingLoginRoute(): string
    {
        if (SessionHelper::get('auth.login_agreement_pending', false)) {
            return 'auth/login-agreement';
        }

        $route = trim((string)(SessionHelper::get('auth.intended_route') ?? ''));
        SessionHelper::forget('auth.intended_route');

        if ($route !== '' && preg_match('/^(applications\/approve&id=\d+|cards\/limit-change-approve&id=\d+|applications\/my-dpc-approvals|cards\/my-limit-change-approvals)$/', $route) === 1) {
            return $route;
        }

        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId > 0 && $this->db instanceof \PDO) {
            try {
                $service = new ApprovalInboxService($this->db);
                $pendingRoute = $service->resolvePostLoginRoute($userId);
                if ($pendingRoute !== null) {
                    return $pendingRoute;
                }
            } catch (\Throwable $e) {
                app_log('Onboarding pending approval redirect resolution failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ], 'warn');
            }
        }

        return 'home/index';
    }

    private function setCapsEmployeeSession(string $employeeId): void
    {
        SessionHelper::set('auth.employee_group', '');
        SessionHelper::set('auth.employee_rank', '');
        SessionHelper::set('auth.employee_type', '');

        SessionHelper::forget('EmployeeGroup');
        SessionHelper::forget('EmployeeRank');
        SessionHelper::forget('EmployeeType');

        if ($employeeId === '') {
            return;
        }

        global $capsConn;
        if (!($capsConn instanceof \PDO)) {
            return;
        }

        try {
            $stmt = $capsConn->prepare("
                SELECT TOP 1
                    GroupName,
                    ActualRankLvl,
                    EmployeeType
                FROM dbo.tblCAPSCDMCPortal
                WHERE EmployeeID = :emp
            ");
            $stmt->execute(['emp' => $employeeId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

            SessionHelper::set('auth.employee_group', (string)($row['GroupName'] ?? ''));
            SessionHelper::set('auth.employee_rank', (string)($row['ActualRankLvl'] ?? ''));
            SessionHelper::set('auth.employee_type', $this->normalizeEmployeeType((string)($row['EmployeeType'] ?? '')));
        } catch (\Throwable $e) {
            app_log('CAPS employee lookup failed (onboarding)', ['error' => $e->getMessage()], 'error');
        }
    }

    private function normalizeEmployeeType(string $employeeType): string
    {
        $employeeType = trim($employeeType);
        if ($employeeType === '') {
            return '';
        }

        $allowed = $this->loadConfiguredEmployeeTypes();
        return in_array(strtoupper($employeeType), $allowed, true) ? $employeeType : 'Defence';
    }

    private function loadConfiguredEmployeeTypes(): array
    {
        $raw = 'ASA,ASD,ANNPSR';
        try {
            if ($this->db instanceof \PDO) {
                $settings = new SystemSettingsModel($this->db);
                $raw = trim((string)($settings->get('EMPLOYEE_TYPE_DIRECT_MATCHES') ?? $raw));
            }
        } catch (\Throwable $e) {
            app_log('loadConfiguredEmployeeTypes failed (onboarding)', ['error' => $e->getMessage()], 'warn');
        }

        $parts = preg_split('/[\s,;|]+/', strtoupper($raw)) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn(string $v): bool => $v !== ''));
        return $parts ?: ['ASA', 'ASD', 'ANNPSR'];
    }

}
