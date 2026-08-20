<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Shared\SessionHelper;
use App\Models\SystemSettingsModel;
use App\Models\FiscalContextModel;
use App\Core\Rbac;

require_once __DIR__ . '/../../shared/logger.php';
require_once __DIR__ . '/../../shared/lang.php';

abstract class BaseController
{
    protected ?\PDO $db = null;
    protected array $acl = [];
    protected bool $requiresContext = false;

    protected function currentServerTimestamp(): string
    {
        return date('Y-m-d H:i:s');
    }

   public function __construct()
{
    SessionHelper::ensureSession();

    global $conn;
    $this->db = $conn ?? null;

    $route = trim($_GET['route'] ?? '', '/');  // trim to be safe

    // Define public routes that skip ALL auth/ACL checks
    $isPublicRoute = str_starts_with($route, 'auth/login')
                  || str_starts_with($route, 'auth/logout')
                  || str_starts_with($route, 'auth/force')
                  || str_starts_with($route, 'auth/refresh')
                  || str_starts_with($route, 'onboarding/')
                  || $route === 'auth/activate';

    $isIframe = !empty($_GET['iframe']);

    // Skip everything for public routes
    if ($isPublicRoute) {
        app_log("Public route detected - skipping auth checks", ['route' => $route], 'debug');
        return;
    }

    // Only run auth checks for non-public routes
    if (!($route === 'home/index' && SessionHelper::get('auth.just_logged_in'))) {
        if ($this->db instanceof \PDO && !$isIframe) {
            $this->enforceEmployeeIdentityConsistency($route);
        }

        if ($this->db instanceof \PDO && !$isIframe) {
            $uid = (int)SessionHelper::get('auth.user_id', 0);
            if ($uid > 0) {
                app_log("[SESSION DEBUG] Calling enforceActiveSession", ['user_id' => $uid, 'session_id' => session_id(), 'route' => $route], 'debug');
                SessionHelper::enforceActiveSession($this->db);
            }
        }

        $this->enforceAcl();

        $userId = (int)SessionHelper::get('auth.user_id', 0);
        if ($userId > 0) {
            $this->enforceLoginAgreement($route);
        }
        if ($this->requiresContext && $userId > 0) {
            $this->ensureContext();
        }

        if ($userId > 0) {
            $this->sessionHeartbeat();
        }

        $this->autoMaintenance();
    }

    if ($route === 'home/index' && SessionHelper::get('auth.just_logged_in')) {
        SessionHelper::forget('auth.just_logged_in');
        session_write_close();
    }
}

    protected function enforceLoginAgreement(string $route): void
    {
        if (!SessionHelper::get('auth.login_agreement_pending', false)) {
            return;
        }

        if (!$this->isLoginAgreementEnabled()) {
            SessionHelper::forget('auth.login_agreement_pending');
            SessionHelper::forget('auth.login_agreement_accepted');
            SessionHelper::forget('auth.login_agreement_accepted_at');
            SessionHelper::forget('auth.login_agreement_hash');
            return;
        }

        if (in_array($route, ['auth/login-agreement', 'auth/login-agreement-accept', 'auth/logout'], true)) {
            return;
        }

        session_write_close();
        header('Location: index.php?route=auth/login-agreement');
        exit;
    }

    protected function enforceEmployeeIdentityConsistency(string $route): void
    {
        $userId = (int) SessionHelper::get('auth.user_id', 0);
        if ($userId <= 0 || !($this->db instanceof \PDO)) {
            return;
        }

        $sessionEmployeeId = trim((string) SessionHelper::get('auth.employee_id', ''));
        if ($sessionEmployeeId === '') {
            return;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT EmployeeID
                FROM dbo.tblUsers
                WHERE UserID = :uid
            ");
            $stmt->execute(['uid' => $userId]);
            $currentEmployeeId = trim((string)($stmt->fetchColumn() ?? ''));
        } catch (\Throwable $e) {
            app_log('Employee identity consistency check failed', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'route' => $route,
                'session_id' => session_id(),
            ], 'warn');
            return;
        }

        if ($currentEmployeeId === '' || strcasecmp($currentEmployeeId, $sessionEmployeeId) === 0) {
            return;
        }

        app_log('Employee identity changed - forcing fresh login', [
            'user_id' => $userId,
            'route' => $route,
            'session_employee_id' => $sessionEmployeeId,
            'current_employee_id' => $currentEmployeeId,
            'session_id' => session_id(),
        ], 'warn');

        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        SessionHelper::ensureSession();
        SessionHelper::set('flash.message', [
            'type' => 'warning',
            'text' => 'Employee identity changed for this user. Please log in again to start a fresh session.',
        ]);
        session_write_close();
        header('Location: index.php?route=auth/loginForm');
        exit;
    }

    protected function isLoginAgreementEnabled(): bool
    {
        if (!($this->db instanceof \PDO)) {
            return false;
        }

        try {
            $settings = new SystemSettingsModel($this->db);
            $enabled = strtolower(trim((string)($settings->get('LOGIN_AGREEMENT_ENABLED') ?? '0')));
            $text = trim((string)($settings->get('LOGIN_AGREEMENT_TEXT') ?? ''));
            return in_array($enabled, ['1', 'true', 'yes', 'on'], true) && $text !== '';
        } catch (\Throwable $e) {
            app_log('Failed to evaluate login agreement setting', ['error' => $e->getMessage()], 'warn');
            return false;
        }
    }

    protected function isCapsWriteEnabled(): bool
    {
        return $this->getBoolSystemSetting('CAPS_WRITES_ENABLED', true);
    }

    protected function isNewUserActivationEnabled(): bool
    {
        return $this->getBoolSystemSetting('NEW_USER_ACTIVATION_ENABLED', true);
    }

    protected function shouldShowActivationLinkInsteadOfEmail(): bool
    {
        return $this->getBoolSystemSetting('SHOW_ACTIVATION_LINK_INSTEAD_OF_EMAIL', false);
    }

    protected function getBoolSystemSetting(string $settingKey, bool $default = false): bool
    {
        if (!($this->db instanceof \PDO)) {
            return $default;
        }

        try {
            $settings = new SystemSettingsModel($this->db);
            $raw = strtolower(trim((string)($settings->get($settingKey) ?? '')));
            if ($raw === '') {
                return $default;
            }

            return in_array($raw, ['1', 'true', 'yes', 'on'], true);
        } catch (\Throwable $e) {
            app_log('Failed to evaluate boolean system setting', [
                'setting_key' => $settingKey,
                'error' => $e->getMessage(),
            ], 'warn');
            return $default;
        }
    }

    protected function deleteCapsLimitDetailsPortalByPortalId(int $portalApplicationId): void
    {
        if ($portalApplicationId <= 0) {
            return;
        }

        global $capsConn;
        if (!($capsConn instanceof \PDO)) {
            return;
        }

        $existsStmt = $capsConn->query("SELECT OBJECT_ID('dbo.tblCAPSLimitDetailsPortal', 'U')");
        $tableObjectId = $existsStmt ? $existsStmt->fetchColumn() : false;
        if (empty($tableObjectId)) {
            return;
        }

        $stmt = $capsConn->prepare("
            DELETE FROM dbo.tblCAPSLimitDetailsPortal
            WHERE PortalID = :portal_id
        ");
        $stmt->execute(['portal_id' => $portalApplicationId]);
    }

    protected function enforceAcl(): void
    {
        $route = $_GET['route'] ?? '';
        $segments = explode('/', $route);
        $action = $segments[1] ?? 'index';

        $rules = $this->acl[$action] ?? ($this->acl['*'] ?? []);
        $requiresAuth = $rules['auth'] ?? true;

        $userId = (int) SessionHelper::get('auth.user_id', 0);
        app_log("enforceAcl", [
            'route' => $route,
            'action' => $action,
            'requiresAuth' => $requiresAuth,
            'userId' => $userId,
            'permsAny' => $rules['permsAny'] ?? [],
            'permsAll' => $rules['permsAll'] ?? [],
            'session_id' => session_id()
        ], 'debug');

        if ($requiresAuth && $userId <= 0) {
            app_log("enforceAcl: No user ID, redirecting to login", ['route' => $route, 'session_id' => session_id()], 'info');
            $this->flashError(__t('please_login'));
            session_write_close();
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        if ($userId > 0) {
            $now = time();
            try {
                global $conn;
                $settings = new SystemSettingsModel($conn);
                $idleLimitSec = (int) $settings->get('SESSION_IDLE_LIMIT', '1200');
                $absoluteMin = (int) $settings->get('SESSION_TIMEOUT_MIN', '600');
            } catch (\Throwable $e) {
                $idleLimitSec = 1200;
                $absoluteMin = 600;
                app_log("enforceAcl: Failed to load settings", ['error' => $e->getMessage(), 'session_id' => session_id()], 'warn');
            }

            $absoluteLimitSec = $absoluteMin * 60;
            $loginTime = (int) SessionHelper::get('auth.login_time', 0);
            $lastAct = (int) SessionHelper::get('auth.last_activity', 0);

            if ($loginTime > 0) {
                if (($now - $lastAct) > $idleLimitSec) {
                    app_log("enforceAcl: Session idle timeout", ['userId' => $userId, 'lastAct' => $lastAct, 'now' => $now, 'session_id' => session_id()], 'info');
                    header('Location: index.php?route=auth/logout&reason=idle');
                    exit;
                }
                if (($now - $loginTime) > $absoluteLimitSec) {
                    app_log("enforceAcl: Session absolute timeout", ['userId' => $userId, 'loginTime' => $loginTime, 'now' => $now, 'session_id' => session_id()], 'info');
                    header('Location: index.php?route=auth/logout&reason=absolute');
                    exit;
                }
            }

            SessionHelper::set('auth.last_activity', $now);
            session_write_close();
        }

        if ($userId > 0) {
            $rbac = new Rbac($GLOBALS['conn'] ?? null);
            $isSuperUser = $rbac->canAny(['ADMIN_ALL', 'SYSADMIN']);
            if (!$isSuperUser && !empty($rules['permsAny']) && !$rbac->canAny($rules['permsAny'])) {
                $detail = 'Missing one of: ' . implode(', ', $rules['permsAny']);
                app_log("enforceAcl: Permission denied", ['userId' => $userId, 'permsAny' => $rules['permsAny'], 'session_id' => session_id()], 'warn');
                $this->denyAccess($detail);
            }
            if (!$isSuperUser && !empty($rules['permsAll']) && !$rbac->canAll($rules['permsAll'])) {
                $detail = 'Missing all of: ' . implode(', ', $rules['permsAll']);
                app_log("enforceAcl: Permission denied", ['userId' => $userId, 'permsAll' => $rules['permsAll'], 'session_id' => session_id()], 'warn');
                $this->denyAccess($detail);
            }
        }
    }

    protected function context(): array
    {
        return [
            'FiscalYearID' => (int) (SessionHelper::get('FiscalYearID') ?? 0),
            'VersionID' => (int) (SessionHelper::get('VersionID') ?? 0),
        ];
    }

    protected function ensureContext(): void
    {
        $fy = (int) (SessionHelper::get('FiscalYearID') ?? 0);
        $ver = (int) (SessionHelper::get('VersionID') ?? 0);

        if ($fy > 0 && $ver > 0 && $this->isValidContext($fy, $ver)) {
            app_log('Fiscal context valid', ['fy' => $fy, 'ver' => $ver, 'session_id' => session_id()], 'debug');
            return;
        }

        global $conn;
        require_once __DIR__ . '/../Models/FiscalContextModel.php';
        require_once __DIR__ . '/../Models/SystemSettingsModel.php';

        $fc = new FiscalContextModel($conn);
        $ss = new SystemSettingsModel($conn);

        $defFy = (int) ($ss->get('DEFAULT_FISCAL_YEAR') ?? 0);
        $defVer = (int) ($ss->get('DEFAULT_VERSION') ?? 0);
        if ($defFy > 0 && $defVer > 0 && $this->isValidPair($fc, $defFy, $defVer)) {
            SessionHelper::set('FiscalYearID', $defFy);
            SessionHelper::set('VersionID', $defVer);
            app_log('Fiscal context set from system defaults', ['fy' => $defFy, 'ver' => $defVer, 'session_id' => session_id()], 'info');
            session_write_close();
            return;
        }

        $years = $fc->listFiscalYears();
        if (!empty($years)) {
            $bestFy = (int) $years[0]['FiscalYearID'];
            $vList = $fc->listVersions($bestFy);
            if (!empty($vList)) {
                $bestVer = (int) $vList[0]['VersionID'];
                SessionHelper::set('FiscalYearID', $bestFy);
                SessionHelper::set('VersionID', $bestVer);
                app_log('Fiscal context set from latest active FY/Version', ['fy' => $bestFy, 'ver' => $bestVer, 'session_id' => session_id()], 'info');
                session_write_close();
                return;
            }
        }

        app_log('No valid fiscal context available, setting defaults', ['session_id' => session_id()], 'warn');
        SessionHelper::set('FiscalYearID', 0);
        SessionHelper::set('VersionID', 0);
        session_write_close();
    }

    protected function isValidContext(int $fy, int $ver): bool
    {
        global $conn;
        require_once __DIR__ . '/../Models/FiscalContextModel.php';
        $fc = new FiscalContextModel($conn);
        return $this->isValidPair($fc, $fy, $ver);
    }

    private function isValidPair(FiscalContextModel $fc, int $fy, int $ver): bool
    {
        if ($fy <= 0 || $ver <= 0) {
            return false;
        }
        $versions = $fc->listVersions($fy);
        foreach ($versions as $row) {
            if ((int) $row['VersionID'] === $ver) {
                return true;
            }
        }
        return false;
    }

    protected function render(string $view, array $vars = []): void
{
    $start = microtime(true);
    $viewFile = __DIR__ . '/../Views/' . $view . '.php';
    $layout = __DIR__ . '/../Views/layouts/main.php';

    if (!is_file($viewFile)) {
        http_response_code(500);
        echo 'View not found: ' . htmlspecialchars($viewFile, ENT_QUOTES, 'UTF-8');
        app_log("Render failed: View not found", ['view' => $viewFile, 'session_id' => session_id()], 'error');
        return;
    }

    $flash = \App\Shared\SessionHelper::get('flash.message', null);
    $vars = [
        'flash' => $flash,
        '_ctx' => $this->context(),
        'userId' => (int) SessionHelper::get('auth.user_id', 0),
        'username' => (string) SessionHelper::get('auth.username', ''),
        'employee_group' => (string) SessionHelper::get('auth.employee_group', ''),
        'employee_rank' => (string) SessionHelper::get('auth.employee_rank', ''),
        'employee_type' => (string) SessionHelper::get('auth.employee_type', ''),
        'sessionRoles' => SessionHelper::get('auth.roles', []),
        'perms' => SessionHelper::get('auth.perms', [])
    ] + $vars;

    if ($flash !== null) {
        register_shutdown_function(function (): void {
            \App\Shared\SessionHelper::forget('flash.message');
            if (isset($_SESSION['flash']['message'])) {
                unset($_SESSION['flash']['message']);
            }
        });
    }

    ob_start();
    extract($vars, EXTR_OVERWRITE);
    require $viewFile;
    $content = ob_get_clean();

    // ──────────────────────────────────────────────────────────────
    // Skip full layout for onboarding routes (standalone/clean look)
    // ──────────────────────────────────────────────────────────────
    $route = trim($_GET['route'] ?? '', '/');  // normalize
    if (str_starts_with($route, 'onboarding/') || $route === 'onboarding') {
        echo $content;
        // Flash cleanup
        if ($flash !== null) {
            \App\Shared\SessionHelper::forget('flash.message');
            if (isset($_SESSION['flash']['message'])) {
                unset($_SESSION['flash']['message']);
            }
        }
        return;
    }

    // Normal layout flow for all other pages
    if ($view !== 'auth/login' && $content !== '') {
        if (is_file($layout)) {
            require $layout;
        } else {
            echo $content;
        }
    } else {
        echo $content;
    }

    if ($flash !== null) {
        \App\Shared\SessionHelper::forget('flash.message');
        if (isset($_SESSION['flash']['message'])) {
            unset($_SESSION['flash']['message']);
        }
    }

    $durationMs = round((microtime(true) - $start) * 1000, 2);
    $threshold = 500;
    try {
        global $conn;
        if ($conn instanceof \PDO) {
            $settings = new SystemSettingsModel($conn);
            $threshold = (int) $settings->get('SLOW_REQUEST_THRESHOLD_MS', (string) $threshold);
        }
    } catch (\Throwable $e) { }

    $level = ($durationMs >= $threshold) ? 'warn' : 'debug';
    app_log('Render complete', [
        'controller' => static::class,
        'action' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'unknown',
        'view' => $view,
        'time_ms' => $durationMs,
        'threshold' => $threshold,
        'route' => $route,
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'session_id' => session_id(),
        'memory_mb' => round(memory_get_usage(true) / 1048576, 2),
        'fy' => (int) (SessionHelper::get('FiscalYearID') ?? 0),
        'ver' => (int) (SessionHelper::get('VersionID') ?? 0),
    ], $level);
}
    protected function renderPartial(string $view, array $params = []): void
    {
        $viewFile = __DIR__ . '/../Views/' . $view . '.php';
        if (!is_file($viewFile)) {
            http_response_code(500);
            echo 'View not found: ' . htmlspecialchars($viewFile, ENT_QUOTES, 'UTF-8');
            app_log("RenderPartial failed: View not found", ['view' => $viewFile, 'session_id' => session_id()], 'error');
            return;
        }

        $flash = \App\Shared\SessionHelper::get('flash.message', null);
        $params = [
            'flash' => $flash,
            '_ctx' => $this->context(),
            'userId' => (int) SessionHelper::get('auth.user_id', 0),
            'username' => (string) SessionHelper::get('auth.username', ''),
            'sessionRoles' => SessionHelper::get('auth.roles', []),
            'perms' => SessionHelper::get('auth.perms', [])
        ] + $params;

        ob_start();
        extract($params, EXTR_OVERWRITE);
        require $viewFile;
        echo ob_get_clean();

        if ($flash !== null) {
            \App\Shared\SessionHelper::forget('flash.message');
            if (isset($_SESSION['flash']['message'])) {
                unset($_SESSION['flash']['message']);
            }
        }
    }

    protected function denyAccess(string $detail = ''): void
    {
        $msg = __t('access_denied');
        if ($detail !== '') {
            $msg .= ' (' . $detail . ')';
        }

        app_log("denyAccess triggered", ['detail' => $detail, 'route' => $_GET['route'] ?? '', 'session_id' => session_id()], 'warn');
        $this->flashError($msg);
        session_write_close();
        // Keep the user logged in and send them to a safe page with the error message.
        header('Location: index.php?route=home/index');
        exit;
    }

    protected function flash(string $type, string $keyOrText, array $replacements = []): void
    {
        $text = __t($keyOrText, $replacements);
        SessionHelper::set('flash.message', ['type' => $type, 'text' => $text]);
        session_write_close();
    }

    protected function flashSuccess(string $keyOrText, array $replacements = []): void
    {
        $this->flash('success', $keyOrText, $replacements);
    }

    protected function flashError(string $keyOrText, array $replacements = []): void
    {
        $this->flash('danger', $keyOrText, $replacements);
    }

    protected function flashInfo(string $keyOrText, array $replacements = []): void
    {
        $this->flash('info', $keyOrText, $replacements);
    }

    protected function sessionHeartbeat(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            \App\Shared\SessionHelper::ensureSession();
        }

        $sid = session_id();
        if (!$sid) return;

        $userId = (int) \App\Shared\SessionHelper::get('auth.user_id', 0);
        $username = (string) \App\Shared\SessionHelper::get('auth.username', '');
        if ($userId <= 0 || $username === '') {
            app_log('sessionHeartbeat: No valid user session', ['session_id' => $sid], 'warn');
            return;
        }

        $cache = $_SESSION['cbmsv21']['settings_cache'] ?? [];
        $now = time();

        if (empty($cache['fetched_at']) || ($now - $cache['fetched_at']) > 300) {
            try {
                global $conn;
                $settings = new \App\Models\SystemSettingsModel($conn);
                $cache['idle'] = (int) $settings->get('SESSION_IDLE_LIMIT', '1200');
                $cache['heartbeat'] = (int) $settings->get('SESSION_HEARTBEAT_THROTTLE_SEC', '30');
                $cache['fetched_at'] = $now;
                $_SESSION['cbmsv21']['settings_cache'] = $cache;
                app_log('sessionHeartbeat: cached settings', ['idle' => $cache['idle'], 'heartbeat' => $cache['heartbeat'], 'session_id' => $sid], 'debug');
            } catch (\Throwable $e) {
                $cache['idle'] = 1200;
                $cache['heartbeat'] = 30;
                app_log('sessionHeartbeat: Failed to load settings', ['error' => $e->getMessage(), 'session_id' => $sid], 'warn');
            }
        }

        $IDLE_TIMEOUT_SEC = $cache['idle'] ?? 1200;
        $HEARTBEAT_THROTTLE_SEC = $cache['heartbeat'] ?? 30;

        $nextTouch = (int) ($_SESSION['cbmsv21']['session']['next_touch_ts'] ?? 0);
        if ($now < $nextTouch) return;

        try {
            global $conn;
            require_once __DIR__ . '/../Models/UserSessionModel.php';
            $model = new \App\Models\UserSessionModel($conn);

            $model->ensure(
                $sid,
                $userId,
                $username,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $IDLE_TIMEOUT_SEC
            );

            $model->touch($sid, $IDLE_TIMEOUT_SEC);

            $_SESSION['cbmsv21']['session']['next_touch_ts'] = $now + $HEARTBEAT_THROTTLE_SEC;
            app_log('sessionHeartbeat successful', ['userId' => $userId, 'sid' => $sid], 'debug');
        } catch (\Throwable $e) {
            app_log('sessionHeartbeat failed', ['error' => $e->getMessage(), 'userId' => $userId, 'sid' => $sid], 'error');
        }

        session_write_close();
    }

    protected function autoMaintenance(): void
    {
        $userId = (int) (\App\Shared\SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            return;
        }

        $now = time();
        $last = (int) ($_SESSION['cbmsv21']['maintenance']['last_run'] ?? 0);
        if ($now - $last < 86400) {
            return;
        }

        $_SESSION['cbmsv21']['maintenance']['last_run'] = $now;

        try {
            global $conn;
            require_once __DIR__ . '/../Models/SystemSettingsModel.php';
            require_once __DIR__ . '/../Models/UserSessionModel.php';

            static $cachedRetentionDays = null;

            if ($cachedRetentionDays === null) {
                $settings = new \App\Models\SystemSettingsModel($conn);
                $cachedRetentionDays = (int) $settings->get('SESSION_RETENTION_DAYS', '30');
                app_log('autoMaintenance: cached SESSION_RETENTION_DAYS', ['value' => $cachedRetentionDays, 'session_id' => session_id()], 'debug');
            }

            $sql = "EXEC dbo.usp_UserSessions_Purge @RetainDays = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$cachedRetentionDays]);

            app_log('autoMaintenance: purge ok', [
                'time' => gmdate('Y-m-d H:i:s') . ' UTC',
                'retentionDays' => $cachedRetentionDays,
                'rows_deleted' => $stmt->rowCount() ?: null,
                'session_id' => session_id()
            ], 'info');
        } catch (\Throwable $e) {
            app_log('autoMaintenance failed', ['error' => $e->getMessage(), 'session_id' => session_id()], 'warn');
        }
    }
}
