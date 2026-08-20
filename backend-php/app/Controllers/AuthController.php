<?php
declare(strict_types=1);

namespace App\Controllers;

    use App\Shared\SessionHelper;
    use App\Services\ApprovalInboxService;
    use App\Services\MailService;
    use App\Models\AuditModel;

    require_once __DIR__ . '/../../shared/csrf.php';
    require_once __DIR__ . '/../../shared/login_throttle_db.php';
    require_once __DIR__ . '/../../shared/windows_login.php'; // Windows login helper

    final class AuthController extends BaseController
    {
        protected array $acl = [
            '*'            => ['auth' => true],
            'loginForm'    => ['auth' => false],
            'loginFormTrace' => ['auth' => false],
            'login'        => ['auth' => false], // forms only
            'loginAgreement' => ['auth' => false],
            'loginAgreementAccept' => ['auth' => false],
            'logout'       => ['auth' => true],
            'account'      => ['auth' => true],
            'refreshAccess'=> ['auth' => true],
            'sso'          => ['auth' => false], // allow SSO entry without being logged in
        ];

        public function __construct()
        {
            parent::__construct();
        }

        /**
         * Optional SSO entry point — redirects to the main login form handler
         */
        public function sso(): void
        {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

    public function account(): void
    {
        require __DIR__ . '/../../config/db.php';

        $currentUserId = (int)SessionHelper::get('auth.user_id', 0);
        if ($currentUserId <= 0) {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $requestedUserId = (int)($_GET['UserID'] ?? 0);
        $canViewOthers = \App\Core\Rbac::canAny(['USERS_VIEW', 'USERS_ADMIN', 'ADMIN_ALL', 'SYSADMIN']);
        $targetUserId = ($requestedUserId > 0 && $canViewOthers) ? $requestedUserId : $currentUserId;
        $isIframe = !empty($_GET['iframe']);

        $user = $this->loadUserBasic($conn, $targetUserId);
        if (!$user) {
            $this->flashError('User not found.');
            header('Location: index.php?route=home/index');
            exit;
        }

        $roles = $this->fetchRolesForUser($conn, $targetUserId);
        $perms = $this->fetchPermsForUser($conn, $targetUserId);
        $employeeId = (string)($user['EmployeeID'] ?? '');
        $employeeGroup = $targetUserId === $currentUserId
            ? (string)SessionHelper::get('auth.employee_group', '')
            : $this->loadCapsEmployeeGroup($employeeId);
        $employeeType = $targetUserId === $currentUserId
            ? (string)SessionHelper::get('auth.employee_type', '')
            : $this->loadCapsEmployeeType($employeeId);

        $vars = [
            'title' => __t('account_access'),
            'userId' => $targetUserId,
            'username' => (string)($user['Username'] ?? ('user_' . $targetUserId)),
            'employeeId' => $employeeId,
            'employeeGroup' => $employeeGroup,
            'employeeType' => $employeeType,
            'roles' => $roles,
            'perms' => $perms,
            'refreshedAt' => gmdate('Y-m-d H:i:s') . ' UTC',
        ];

        if ($isIframe) {
            $this->renderPartial('auth/RefreshAccessView', $vars);
            return;
        }
        $this->render('auth/RefreshAccessView', $vars);
    }

    public function refreshAccess(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        require __DIR__ . '/../../config/db.php';
        if (!csrf_check($_POST['_csrf'] ?? '')) {
            $this->flashError(__t('security_check_failed'));
            header('Location: index.php?route=home/index');
            exit;
        }

        $currentUserId = (int)SessionHelper::get('auth.user_id', 0);
        if ($currentUserId <= 0) {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $requestedUserId = (int)($_GET['UserID'] ?? 0);
        $canManageOthers = \App\Core\Rbac::canAny(['USERS_ADMIN', 'ADMIN_ALL', 'SYSADMIN']);
        $targetUserId = ($requestedUserId > 0 && $canManageOthers) ? $requestedUserId : $currentUserId;
        $isIframe = !empty($_GET['iframe']);

        $user = $this->loadUserBasic($conn, $targetUserId);
        if (!$user) {
            $this->flashError('User not found.');
            header('Location: index.php?route=home/index');
            exit;
        }

        $rolesBefore = $this->fetchRolesForUser($conn, $targetUserId);
        $permsBefore = $this->fetchPermsForUser($conn, $targetUserId);

        // For current session user, also refresh live session RBAC.
        if ($targetUserId === $currentUserId) {
            $rbac = new \App\Core\Rbac($conn);
            $rbac->loadForUser($targetUserId);
            SessionHelper::set('auth.roles', $rbac->roles());
            SessionHelper::set('auth.perms', $rbac->perms());
        }

        $rolesAfter = $this->fetchRolesForUser($conn, $targetUserId);
        $permsAfter = $this->fetchPermsForUser($conn, $targetUserId);

        $vars = [
            'title' => __t('access_refreshed'),
            'userId' => $targetUserId,
            'username' => (string)($user['Username'] ?? ('user_' . $targetUserId)),
            'employeeId' => (string)($user['EmployeeID'] ?? ''),
            'employeeGroup' => $targetUserId === $currentUserId
                ? (string)SessionHelper::get('auth.employee_group', '')
                : $this->loadCapsEmployeeGroup((string)($user['EmployeeID'] ?? '')),
            'employeeType' => $targetUserId === $currentUserId
                ? (string)SessionHelper::get('auth.employee_type', '')
                : $this->loadCapsEmployeeType((string)($user['EmployeeID'] ?? '')),
            'rolesBefore' => $rolesBefore,
            'permsBefore' => $permsBefore,
            'rolesAfter' => $rolesAfter,
            'permsAfter' => $permsAfter,
            'refreshedAt' => gmdate('Y-m-d H:i:s') . ' UTC',
            'dbName' => $this->loadDbName($conn),
            'backUrl' => 'index.php?route=auth/account&UserID=' . urlencode((string)$targetUserId) . ($isIframe ? '&iframe=1' : ''),
        ];

        $this->auditLog(
            'REFRESH_ACCESS',
            'Auth',
            (string)$targetUserId,
            [
                'operation' => 'refreshAccess',
                'requested_user_id' => $requestedUserId,
                'target_user_id' => $targetUserId,
            ]
        );

        if ($isIframe) {
            $this->renderPartial('auth/RefreshAccessResult', $vars);
            return;
        }
        $this->render('auth/RefreshAccessResult', $vars);
    }

    public function loginForm(): void
{
    SessionHelper::ensureSession();
    csrf_token();
    $reason = (string)($_GET['reason'] ?? '');

    if ($reason === 'forced') {
        SessionHelper::set('flash.message', [
            'type' => 'warning',
            'text' => 'Your session was terminated by an administrator. Please log in again.'
        ]);
        session_write_close();
        header('Location: index.php?route=auth/loginForm&shown=1');
        exit;
    }

    if (($_GET['shown'] ?? '') === '1') {
        session_write_close();
        header('Location: index.php?route=auth/loginForm');
        exit;
    }

    // Already logged in → go home
    if (SessionHelper::get('auth.user_id')) {
        session_write_close();
        header('Location: index.php?route=' . $this->postLoginRoute());
        exit;
    }

    require __DIR__ . '/../../config/db.php';
    require_once __DIR__ . '/../Models/SystemSettingsModel.php';
    $settingsModel = new \App\Models\SystemSettingsModel($conn);
    $authMode = strtolower((string)$settingsModel->get('LOGIN_AUTH_MODE', 'forms'));

    app_log("Auth mode loaded from DB: '$authMode'", [], 'debug');

        if ($authMode === 'windows') {
            if ($reason === 'disabled') {
                $this->render('auth/login', [
                    'title' => __t('login'),
                    'authMode' => $authMode,
                ]);
                return;
            }

            $winLogin = \get_windows_login();  // shared global function

            if ($winLogin === null) {
                $this->flashError('Windows login not detected. Ensure IIS Windows Authentication is enabled.');
            header('Location: index.php?route=onboarding/start');
            exit;
        }

        $user = \find_user_by_windows_login($conn, $winLogin);  // shared global function

        if ($user) {
            if ((int)($user['IsActive'] ?? 1) === 0) {
                $this->flashError('Your account has been disabled. Please contact an administrator.');
                header('Location: index.php?route=auth/loginForm&reason=disabled');
                exit;
            }

            if ((int)($user['IsActivated'] ?? 0) === 0) {
                $this->flashError('Your account is pending activation. Please check your email for the activation link.');
                header('Location: index.php?route=onboarding/start');
                exit;
            }

            // Activated → auto-login
            $this->ssoSignInFromRow($conn, $user);  // ← use your existing method
            header('Location: index.php?route=' . $this->postLoginRoute());
            exit;
        }

        // No user → onboarding
        SessionHelper::set('auth.pending_windows_login', $winLogin);
        session_write_close();
        header('Location: index.php?route=onboarding/start');
        exit;
    }

    // Forms mode
    $this->render('auth/login', [
        'title' => __t('login'),
        'authMode' => $authMode,
    ]);
}

    public function loginFormTrace(): void
    {
        SessionHelper::ensureSession();

        $startedAt = microtime(true);
        $marks = [];
        $mark = static function (string $label) use (&$marks, $startedAt): void {
            $marks[] = [
                'label' => $label,
                'ms' => (int)round((microtime(true) - $startedAt) * 1000),
            ];
        };

        header('Content-Type: text/plain; charset=UTF-8');

        try {
            $mark('method start');

            csrf_token();
            $mark('csrf_token');

            $reason = (string)($_GET['reason'] ?? '');
            $mark('read query params');

            $loggedInUserId = (int)SessionHelper::get('auth.user_id', 0);
            $mark('SessionHelper::get(auth.user_id)');

            require __DIR__ . '/../../config/db.php';
            $mark('require config/db.php');

            $hasPrimaryDb = isset($conn) && ($conn instanceof \PDO);
            $hasCapsDb = isset($capsConn) && ($capsConn instanceof \PDO);
            $mark('db availability flags');

            require_once __DIR__ . '/../Models/SystemSettingsModel.php';
            $mark('require SystemSettingsModel');

            $authMode = '(not loaded)';
            if ($hasPrimaryDb) {
                $settingsModel = new \App\Models\SystemSettingsModel($conn);
                $mark('new SystemSettingsModel');

                $authMode = strtolower((string)$settingsModel->get('LOGIN_AUTH_MODE', 'forms'));
                $mark('SystemSettingsModel->get(LOGIN_AUTH_MODE)');
            }

            echo "loginForm route trace\n";
            echo 'Time: ' . date('Y-m-d H:i:s') . "\n\n";
            echo 'Reason: ' . $reason . "\n";
            echo 'Auth user id: ' . $loggedInUserId . "\n";
            echo 'Primary DB available: ' . ($hasPrimaryDb ? 'yes' : 'no') . "\n";
            echo 'CAPS DB available: ' . ($hasCapsDb ? 'yes' : 'no') . "\n";
            echo 'Auth mode: ' . $authMode . "\n\n";
            echo "Timing marks\n";
            foreach ($marks as $item) {
                echo str_pad((string)$item['ms'], 6, ' ', STR_PAD_LEFT) . " ms  " . $item['label'] . "\n";
            }
            exit;
        } catch (\Throwable $e) {
            echo "FAILED\n";
            echo 'Error: ' . $e->getMessage() . "\n\n";
            echo "Timing marks\n";
            foreach ($marks as $item) {
                echo str_pad((string)$item['ms'], 6, ' ', STR_PAD_LEFT) . " ms  " . $item['label'] . "\n";
            }
            exit;
        }
    }

    public function login(): void
    {
        SessionHelper::ensureSession();
        app_log('[SESSION DEBUG] login() start SID=' . session_id());

        require __DIR__ . '/../../config/db.php';
        require_once __DIR__ . '/../Models/SystemSettingsModel.php';
        $settingsModel = new \App\Models\SystemSettingsModel($conn);
        $authMode = strtolower($settingsModel->get('LOGIN_AUTH_MODE', 'forms'));

        if ($authMode === 'windows') {
            $this->flashError(__t('method_not_allowed'));
            session_write_close();
            header('Location: index.php?route=auth/loginForm');
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        if (!csrf_check(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
            $this->flashError(__t('security_check_failed'));
            session_write_close();
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $ipRaw = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $ip = trim(explode(',', $ipRaw)[0]);

        if ($username === '' || $password === '') {
            $this->flashError(__t('username_password_required'));
            session_write_close();
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $uKey = mb_strtolower($username, 'UTF-8');
        $cfg = lt_get_config($conn);
        $maxAttempts = (int)$cfg['maxAttempts'];
        $decaySeconds = (int)$cfg['decaySeconds'];
        $lockSeconds = (int)$cfg['lockSeconds'];
        $permanentLock = (bool)$cfg['permanentLockout'];

        $pre = lt_precheck($conn, $uKey, $ip);
        if (!empty($pre['locked'])) {
            if (($pre['retry_after'] ?? 0) === -1) {
                $this->flashError(__t('account_locked_permanent'));
            } else {
                $minutes = max(1, (int)ceil(((int)$pre['retry_after']) / 60));
                if (!headers_sent() && isset($pre['retry_after'])) {
                    header('Retry-After: ' . (int)$pre['retry_after']);
                }
                $this->flashError(__t('too_many_attempts', ['minutes' => (string)$minutes]));
            }
            session_write_close();
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $user = null;
        if (!($conn instanceof \PDO)) {
            $this->flashError('Database connection is unavailable.');
            session_write_close();
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $st = $conn->prepare("
            SELECT UserID, Username, PasswordHash, IsActive, FirstName, LastName, EmployeeID, IsActivated
            FROM dbo.tblUsers
            WHERE Username = :u
        ");
        $st->execute(['u' => $username]);
        $user = $st->fetch(\PDO::FETCH_ASSOC) ?: null;

        $invalid =
            (!$user) ||
            ((int)($user['IsActive'] ?? 1) === 0) ||
            ((int)($user['IsActivated'] ?? 0) === 0) ||  // ← NEW: block if not activated
            (!password_verify($password, (string)($user['PasswordHash'] ?? '')));

        if ($invalid) {
            // Special message for pending activation
            if ($user && (int)($user['IsActivated'] ?? 0) === 0) {
                $this->flashError('Your account is pending activation. Please check your email for the activation link.');
            } else {
                $res = lt_fail($conn, $uKey, $ip, $maxAttempts, $decaySeconds, $lockSeconds, $permanentLock);
                if ($user && isset($user['UserID'])) {
                    $st = $conn->prepare("
                        UPDATE dbo.tblUsers
                        SET FailedLoginCount = ISNULL(FailedLoginCount,0) + 1,
                            LastFailedLoginAt = SYSUTCDATETIME()
                        WHERE UserID = :id
                    ");
                    $st->execute(['id' => (int)$user['UserID']]);
                }

                if (!empty($res['locked'])) {
                    if (($res['retry_after'] ?? 0) === -1) {
                        $this->flashError(__t('account_locked_permanent'));
                    } else {
                        $minutes = max(1, (int)ceil(((int)($res['retry_after'] ?? 60)) / 60));
                        if (!headers_sent() && isset($res['retry_after'])) {
                            header('Retry-After: ' . (int)$res['retry_after']);
                        }
                        $this->flashError(__t('too_many_attempts', ['minutes' => (string)$minutes]));
                    }
                } else {
                    $remaining = (int)($res['remaining'] ?? 0);
                    $msg = __t('invalid_login');
                    if ($remaining > 0) {
                        $msg .= ' ' . __t('remaining_attempts', ['count' => (string)$remaining]);
                    }
                    $this->flashError($msg);
                }
            }

            $this->auditLog(
                'DENIED',
                'Auth',
                $username !== '' ? $username : null,
                [
                    'operation' => 'login',
                    'reason' => 'invalid_credentials_or_inactive',
                    'username' => $username,
                ]
            );

            session_write_close();
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        lt_success($conn, $uKey, $ip);

        session_regenerate_id(true);
        $newSessionId = session_id();
        csrf_regenerate();

        SessionHelper::set('auth.user_id', (int)$user['UserID']);
        SessionHelper::set('auth.username', (string)$user['Username']);
        SessionHelper::set('auth.employee_id', (string)($user['EmployeeID'] ?? ''));
        SessionHelper::set('portalcards.filters.employeeId', (string)($user['EmployeeID'] ?? ''));
        $this->setCapsEmployeeSession((string)($user['EmployeeID'] ?? ''));

        $first = trim((string)($user['FirstName'] ?? ''));
        $last  = trim((string)($user['LastName'] ?? ''));
        $display = trim($first . ' ' . $last);

        SessionHelper::set('auth.first_name', $first);
        SessionHelper::set('auth.last_name', $last);
        SessionHelper::set('auth.display_name', $display !== '' ? $display : (string)$user['Username']);

        SessionHelper::set('auth.login_time', time());
        SessionHelper::set('auth.last_activity', time());
        SessionHelper::set('auth.just_logged_in', true);
        // Clear stale auth-required flash set before redirect to login.
        SessionHelper::forget('flash.message');
        $this->prepareLoginAgreement($conn);

        $rbac = new \App\Core\Rbac($conn);
        $rbac->loadForUser((int)$user['UserID']);
        $roles = $rbac->roles();
        $perms = $rbac->perms();
        SessionHelper::set('auth.roles', $roles);
        SessionHelper::set('auth.perms', $rbac->perms());

        $this->ensureContext();

        try {
            require_once __DIR__ . '/../Models/UserSessionModel.php';
            $sessionModel = new \App\Models\UserSessionModel($conn);
            $sessionModel->ensure(
                $newSessionId,
                (int)$user['UserID'],
                (string)$user['Username'],
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                1200
            );
        } catch (\Throwable $e) {
            app_log('UserSessionModel.ensure failed', ['error' => $e->getMessage(), 'session_id' => $newSessionId], 'error');
        }

        $this->auditLog(
            'LOGIN',
            'Auth',
            (string)$user['UserID'],
            [
                'operation' => 'login',
                'username' => (string)$user['Username'],
            ]
        );

        session_write_close();
        header('Location: index.php?route=' . $this->postLoginRoute());
        exit;
    }

    public function loginAgreement(): void
    {
        SessionHelper::ensureSession();

        $userId = (int)SessionHelper::get('auth.user_id', 0);
        if ($userId <= 0) {
            session_write_close();
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        require __DIR__ . '/../../config/db.php';
        $agreementText = $this->loadLoginAgreementText($conn);
        if ($agreementText === '') {
            $this->clearLoginAgreementSession();
            session_write_close();
            header('Location: index.php?route=' . $this->postLoginRoute());
            exit;
        }

        $privacyError = trim((string)(SessionHelper::pull('auth.login_agreement_error', '') ?? ''));

        $this->render('auth/LoginAgreement', [
            'title' => 'Privacy Notice',
            'heading' => 'Privacy Notice',
            'agreementText' => $agreementText,
            'privacyError' => $privacyError,
            '_csrf' => csrf_token(),
        ]);
    }

    public function loginAgreementAccept(): void
    {
        SessionHelper::ensureSession();

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        $userId = (int)SessionHelper::get('auth.user_id', 0);
        if ($userId <= 0) {
            session_write_close();
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        require __DIR__ . '/../../config/db.php';
        if (!csrf_check($_POST['_csrf'] ?? '')) {
            $this->flashError(__t('security_check_failed'));
            session_write_close();
            header('Location: index.php?route=auth/login-agreement');
            exit;
        }

        $agreementText = $this->loadLoginAgreementText($conn);
        if ($agreementText === '') {
            $this->clearLoginAgreementSession();
            session_write_close();
            header('Location: index.php?route=home/index');
            exit;
        }

        if ((string)($_POST['agree_login_agreement'] ?? '') !== '1') {
            SessionHelper::set('auth.login_agreement_error', 'Please tick the checkbox to confirm you have read and agree to the Privacy Notice before entering the portal.');
            session_write_close();
            header('Location: index.php?route=auth/login-agreement');
            exit;
        }

        SessionHelper::set('auth.login_agreement_pending', false);
        SessionHelper::set('auth.login_agreement_accepted', true);
        SessionHelper::set('auth.login_agreement_accepted_at', gmdate('Y-m-d H:i:s'));
        SessionHelper::set('auth.login_agreement_hash', sha1($agreementText));

        $this->auditLog(
            'LOGIN_AGREEMENT_ACCEPTED',
            'Auth',
            (string)$userId,
            [
                'operation' => 'loginAgreementAccept',
                'agreement_hash' => sha1($agreementText),
            ]
        );

        session_write_close();
        header('Location: index.php?route=' . $this->postLoginRoute());
        exit;
    }

        public function logout(): void
        {
            $closeAfterLogout = ((string)($_GET['close'] ?? '') === '1');
            $actorUserId = (int)SessionHelper::get('auth.user_id', 0);
            $actorUsername = (string)SessionHelper::get('auth.username', '');
            app_log('AuthController.logout called', [
                'reason' => (string)($_GET['reason'] ?? ''),
                'route' => (string)($_GET['route'] ?? ''),
                'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
                'referer' => (string)($_SERVER['HTTP_REFERER'] ?? ''),
                'session_id' => session_id(),
                'user_id' => (int)SessionHelper::get('auth.user_id', 0),
                'username' => (string)SessionHelper::get('auth.username', ''),
            ], 'info');
            $this->auditLog(
                'LOGOUT',
                'Auth',
                $actorUserId > 0 ? (string)$actorUserId : null,
                [
                    'operation' => 'logout',
                    'username' => $actorUsername,
                    'reason' => (string)($_GET['reason'] ?? ''),
                ]
            );

            // Clear all session data safely
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION = [];  // Clear session array
                session_destroy();  // Destroy session
            }

            // Optional: extra cleanup via your helper if needed
            SessionHelper::forget('auth.user_id');
            SessionHelper::forget('auth.username');
            SessionHelper::forget('auth.roles');
            SessionHelper::forget('auth.perms');
            // Add any other keys you store

            // Flash success message
            $this->flashSuccess('You have been logged out successfully.');

            if ($closeAfterLogout) {
                $this->render('auth/LogoutClose', [
                    'title' => 'Exit Portal',
                ]);
                exit;
            }

            // Redirect to login
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        // Helpers ───────────────────────────────────────────────────────

        private function normalizeWindowsLogin(string $raw): string
        {
            $s = trim($raw);
            if ($s === '') return '';

            if (str_contains($s, '\\')) {
                $parts = explode('\\', $s);
                $s = end($parts) ?: $s;
            }

            if (str_contains($s, '@')) {
                $s = explode('@', $s)[0];
            }

            return mb_strtolower(trim($s), 'UTF-8');
        }

    public function activate(): void
    {
        $token = trim((string)($_GET['token'] ?? ''));

        error_log("activate() called with token: " . substr($token, 0, 8) . "...");

        if (!$this->isNewUserActivationEnabled()) {
            $this->auditLog('DENIED', 'Auth', null, ['operation' => 'activate', 'reason' => 'activation_disabled']);
            $this->flashError('New user activation is currently disabled. Existing activated users can still log in.');
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        if ($token === '') {
            $this->auditLog('DENIED', 'Auth', null, ['operation' => 'activate', 'reason' => 'missing_token']);
            $this->flashError('Invalid or missing activation token.');
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        require __DIR__ . '/../../config/db.php';

        $stmt = $conn->prepare("
            SELECT UserID, IsUsed, ExpiresAt
            FROM dbo.tblActivationTokens
            WHERE Token = :token
        ");
        $stmt->execute(['token' => $token]);
        $tokenRow = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tokenRow || $tokenRow['IsUsed'] || strtotime($tokenRow['ExpiresAt']) < time()) {
            $this->auditLog('DENIED', 'Auth', null, ['operation' => 'activate', 'reason' => 'invalid_or_expired_token']);
            $this->flashError('This activation link is invalid, expired, or already used.');
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        error_log("activate() token lookup result: " . ($tokenRow ? 'found' : 'NOT FOUND'));

        // Mark token as used
        $stmt = $conn->prepare("
            UPDATE dbo.tblActivationTokens
            SET IsUsed = 1, UsedAt = SYSUTCDATETIME()
            WHERE Token = :token
        ");
        $stmt->execute(['token' => $token]);

        // Activate the user account (THIS LINE WAS MISSING)
        $stmt = $conn->prepare("
            UPDATE dbo.tblUsers
            SET IsActivated = 1
            WHERE UserID = :id
        ");
        $stmt->execute(['id' => $tokenRow['UserID']]);

        // Load user and log in
        $userStmt = $conn->prepare("
            SELECT * FROM dbo.tblUsers WHERE UserID = :id
        ");
        $userStmt->execute(['id' => $tokenRow['UserID']]);
        $user = $userStmt->fetch(\PDO::FETCH_ASSOC);

        if ($user) {
            $this->ensureUserHasDefaultRole($conn, (int)($user['UserID'] ?? 0));
            $this->ssoSignInFromRow($conn, $user);
            $this->auditLog(
                'ACTIVATE',
                'Auth',
                (string)($user['UserID'] ?? ''),
                [
                    'operation' => 'activate',
                    'username' => (string)($user['Username'] ?? ''),
                ]
            );
            $this->flashSuccess('Account Activated Successfully');
            header('Location: index.php?route=' . $this->postLoginRoute());
        } else {
            $this->flashError('User account not found.');
            header('Location: index.php?route=auth/loginForm');
        }
        exit;
    }

        private function ssoSignInFromRow($conn, array $user): void
        {
            if ((int)($user['IsActive'] ?? 1) === 0) {
                throw new \RuntimeException('Cannot sign in a disabled user.');
            }

            SessionHelper::ensureSession();
            session_regenerate_id(true);
            $newSessionId = session_id();
            csrf_regenerate();

            SessionHelper::set('auth.user_id', (int)($user['UserID'] ?? 0));
            SessionHelper::set('auth.username', (string)($user['Username'] ?? ''));
            SessionHelper::set('auth.employee_id', (string)($user['EmployeeID'] ?? ''));
            SessionHelper::set('portalcards.filters.employeeId', (string)($user['EmployeeID'] ?? ''));
            $this->setCapsEmployeeSession((string)($user['EmployeeID'] ?? ''));

            $first = trim((string)($user['FirstName'] ?? ''));
            $last  = trim((string)($user['LastName'] ?? ''));
            $display = trim($first . ' ' . $last);

            SessionHelper::set('auth.first_name', $first);
            SessionHelper::set('auth.last_name', $last);
            SessionHelper::set('auth.display_name', $display !== '' ? $display : (string)($user['Username'] ?? ''));

            SessionHelper::set('auth.login_time', time());
            SessionHelper::set('auth.last_activity', time());
            SessionHelper::set('auth.just_logged_in', true);
            // Clear stale auth-required flash set before redirect to login.
            SessionHelper::forget('flash.message');
            $this->prepareLoginAgreement($conn);

            $rbac = new \App\Core\Rbac($conn);
            $rbac->loadForUser((int)($user['UserID'] ?? 0));
            SessionHelper::set('auth.roles', $rbac->roles());
            SessionHelper::set('auth.perms', $rbac->perms());

            $this->ensureContext();

            try {
                require_once __DIR__ . '/../Models/UserSessionModel.php';
                $sessionModel = new \App\Models\UserSessionModel($conn);
                $sessionModel->ensure(
                    $newSessionId,
                    (int)($user['UserID'] ?? 0),
                    (string)($user['Username'] ?? ''),
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    $_SERVER['HTTP_USER_AGENT'] ?? '',
                    1200
                );
            } catch (\Throwable $e) {
                app_log('UserSessionModel.ensure failed (SSO)', [
                    'error'      => $e->getMessage(),
                    'session_id' => $newSessionId
                ], 'error');
            }
        }

        private function postLoginRoute(): string
        {
            if (SessionHelper::get('auth.login_agreement_pending', false)) {
                return 'auth/login-agreement';
            }

            $intendedRoute = $this->consumeIntendedRoute();
            if ($intendedRoute !== null) {
                return $intendedRoute;
            }

            $pendingApprovalRoute = $this->resolvePendingApprovalRoute();
            if ($pendingApprovalRoute !== null) {
                return $pendingApprovalRoute;
            }

            return 'home/index';
        }

        private function resolvePendingApprovalRoute(): ?string
        {
            $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
            if ($userId <= 0 || !($this->db instanceof \PDO)) {
                return null;
            }

            try {
                $service = new ApprovalInboxService($this->db);
                return $service->resolvePostLoginRoute($userId);
            } catch (\Throwable $e) {
                app_log('Pending approval redirect resolution failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ], 'warn');
                return null;
            }
        }

        private function consumeIntendedRoute(): ?string
        {
            $route = trim((string)(SessionHelper::get('auth.intended_route') ?? ''));
            SessionHelper::forget('auth.intended_route');

            if ($route === '') {
                return null;
            }

            return $this->isSafeIntendedRoute($route) ? $route : null;
        }

        private function isSafeIntendedRoute(string $route): bool
        {
            if ($route === '' || str_contains($route, '://') || str_starts_with($route, '/')) {
                return false;
            }

            return preg_match('/^(applications\/approve&id=\d+|cards\/limit-change-approve&id=\d+|applications\/my-dpc-approvals|cards\/my-limit-change-approvals)$/', $route) === 1;
        }

        private function prepareLoginAgreement(\PDO $conn): void
        {
            $agreementText = $this->loadLoginAgreementText($conn);
            if ($agreementText === '') {
                $this->clearLoginAgreementSession();
                return;
            }

        SessionHelper::set('auth.login_agreement_pending', true);
        SessionHelper::set('auth.login_agreement_accepted', false);
        SessionHelper::forget('auth.login_agreement_accepted_at');
        SessionHelper::set('auth.login_agreement_hash', sha1($agreementText));
        SessionHelper::forget('auth.just_logged_in');
    }

        private function clearLoginAgreementSession(): void
        {
            SessionHelper::forget('auth.login_agreement_pending');
            SessionHelper::forget('auth.login_agreement_accepted');
            SessionHelper::forget('auth.login_agreement_accepted_at');
            SessionHelper::forget('auth.login_agreement_hash');
            SessionHelper::forget('auth.login_agreement_error');
        }

        private function loadLoginAgreementText(\PDO $conn): string
        {
            $settingsModel = new \App\Models\SystemSettingsModel($conn);
            $enabled = strtolower(trim((string)($settingsModel->get('LOGIN_AGREEMENT_ENABLED') ?? '0')));
            if (!in_array($enabled, ['1', 'true', 'yes', 'on'], true)) {
                return '';
            }

            return trim((string)($settingsModel->get('LOGIN_AGREEMENT_TEXT') ?? ''));
        }

        private function setCapsEmployeeSession(string $employeeId): void
        {
            // Default empty values so consumers can rely on keys existing
            SessionHelper::set('auth.employee_group', '');
            SessionHelper::set('auth.employee_rank', '');
            SessionHelper::set('auth.employee_type', '');

            // Clear any legacy top-level keys if they exist
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
                app_log('CAPS employee lookup failed', ['error' => $e->getMessage()], 'error');
            }
        }

        private function ensureUserHasDefaultRole(\PDO $conn, int $userId): void
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
                    return;
                }

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
                app_log('Activation default role assignment failed', [
                    'userId' => $userId,
                    'role' => $roleName,
                    'error' => $e->getMessage(),
                ], 'error');
            }
        }

        private function loadUserBasic(\PDO $conn, int $userId): ?array
        {
            if ($userId <= 0) return null;
            $st = $conn->prepare("SELECT TOP 1 UserID, Username, EmployeeID FROM dbo.tblUsers WHERE UserID = :id");
            $st->execute(['id' => $userId]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        }

        private function loadCapsEmployeeGroup(string $employeeId): string
        {
            $employeeId = trim($employeeId);
            if ($employeeId === '') {
                return '';
            }

            global $capsConn;
            if (!($capsConn instanceof \PDO)) {
                return '';
            }

            try {
                $st = $capsConn->prepare("
                    SELECT TOP 1 GroupName
                    FROM dbo.tblCAPSCDMCPortal
                    WHERE EmployeeID = :emp
                ");
                $st->execute(['emp' => $employeeId]);
                return trim((string)($st->fetchColumn() ?: ''));
            } catch (\Throwable $e) {
                app_log('loadCapsEmployeeGroup failed', ['employeeId' => $employeeId, 'error' => $e->getMessage()], 'error');
                return '';
            }
        }

        private function loadCapsEmployeeType(string $employeeId): string
        {
            $employeeId = trim($employeeId);
            if ($employeeId === '') {
                return '';
            }

            global $capsConn;
            if (!($capsConn instanceof \PDO)) {
                return '';
            }

            try {
                $st = $capsConn->prepare("
                    SELECT TOP 1 EmployeeType
                    FROM dbo.tblCAPSCDMCPortal
                    WHERE EmployeeID = :emp
                ");
                $st->execute(['emp' => $employeeId]);
                return $this->normalizeEmployeeType((string)($st->fetchColumn() ?: ''));
            } catch (\Throwable $e) {
                app_log('loadCapsEmployeeType failed', ['employeeId' => $employeeId, 'error' => $e->getMessage()], 'error');
                return '';
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
                    $settings = new \App\Models\SystemSettingsModel($this->db);
                    $raw = trim((string)($settings->get('EMPLOYEE_TYPE_DIRECT_MATCHES') ?? $raw));
                }
            } catch (\Throwable $e) {
                app_log('loadConfiguredEmployeeTypes failed', ['error' => $e->getMessage()], 'warn');
            }

            $parts = preg_split('/[\s,;|]+/', strtoupper($raw)) ?: [];
            $parts = array_values(array_filter(array_map('trim', $parts), static fn(string $v): bool => $v !== ''));
            return $parts ?: ['ASA', 'ASD', 'ANNPSR'];
        }

        private function fetchRolesForUser(\PDO $conn, int $userId): array
        {
            if ($userId <= 0) return [];
            $sql = "
                SELECT r.RoleName
                FROM dbo.tblUserRoles ur
                JOIN dbo.tblRoles r ON r.RoleID = ur.RoleID
                WHERE ur.UserID = :uid
                  AND (r.Active = 1 OR r.Active IS NULL)
                ORDER BY r.RoleName
            ";
            $st = $conn->prepare($sql);
            $st->execute(['uid' => $userId]);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            return array_values(array_unique(array_map(static fn($r) => trim((string)($r['RoleName'] ?? '')), $rows)));
        }

        private function fetchPermsForUser(\PDO $conn, int $userId): array
        {
            if ($userId <= 0) return [];
            $sql = "
                SELECT DISTINCT p.PermissionCode
                FROM dbo.tblUserRoles ur
                JOIN dbo.tblRolePermissions rp ON rp.RoleID = ur.RoleID
                JOIN dbo.tblPermissions p ON p.PermissionID = rp.PermissionID
                WHERE ur.UserID = :uid
                  AND (p.Active = 1 OR p.Active IS NULL)
                ORDER BY p.PermissionCode
            ";
            $st = $conn->prepare($sql);
            $st->execute(['uid' => $userId]);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            return array_values(array_unique(array_map(static fn($r) => strtoupper(trim((string)($r['PermissionCode'] ?? ''))), $rows)));
        }

        private function loadDbName(\PDO $conn): string
        {
            try {
                $v = $conn->query("SELECT DB_NAME()")->fetchColumn();
                return trim((string)$v) !== '' ? (string)$v : '(unknown)';
            } catch (\Throwable $e) {
                return '(unknown)';
            }
        }

        private function auditLog(string $action, string $entity, ?string $entityKey = null, array $details = []): void
        {
            try {
                require __DIR__ . '/../../config/db.php';
                if (!($conn instanceof \PDO)) {
                    return;
                }
                $audit = new AuditModel($conn);
                $audit->insert([
                    'UserID'       => SessionHelper::get('auth.user_id'),
                    'Username'     => SessionHelper::get('auth.username', 'guest'),
                    'Action'       => $action,
                    'Entity'       => $entity,
                    'EntityKey'    => $entityKey,
                    'IPAddress'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'Details'      => $details,
                    ...$this->resolvedAuditContext(),
                ]);
            } catch (\Throwable $e) {
                app_log('AuthController.auditLog failed', ['error' => $e->getMessage()], 'error');
            }
        }

        private function resolvedAuditContext(): array
        {
            $fy = (int)(SessionHelper::get('FiscalYearID') ?? 0);
            $ver = (int)(SessionHelper::get('VersionID') ?? 0);

            if ($fy <= 0 || $ver <= 0) {
                try {
                    $this->ensureContext();
                } catch (\Throwable $e) {
                    app_log('AuthController.resolvedAuditContext ensureContext failed', ['error' => $e->getMessage()], 'warn');
                }

                $fy = (int)(SessionHelper::get('FiscalYearID') ?? 0);
                $ver = (int)(SessionHelper::get('VersionID') ?? 0);
            }

            return [
                'FiscalYearID' => $fy > 0 ? $fy : null,
                'VersionID'    => $ver > 0 ? $ver : null,
            ];
        }

        
    }
