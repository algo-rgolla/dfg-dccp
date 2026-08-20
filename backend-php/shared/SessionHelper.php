<?php
declare(strict_types=1);

namespace App\Shared;

final class SessionHelper
{
    private static function debugEnabled(): bool
    {
        $val = getenv('APP_DEBUG');
        if ($val === false) {
            return false;
        }
        return in_array(strtolower(trim((string)$val)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function debugLog(string $message): void
    {
        if (self::debugEnabled()) {
            error_log($message);
        }
    }

    private static function root(): string
    {
        return getenv('APP_SESSION_PREFIX') ?: 'ccportal';
    }

    private static function &ref(array &$arr, array $segments)
    {
        $cur =& $arr;
        foreach ($segments as $seg) {
            if (!is_array($cur)) {
                $cur = [];
            }
            if (!array_key_exists($seg, $cur) || !is_array($cur[$seg])) {
                $cur[$seg] = [];
            }
            $cur =& $cur[$seg];
        }
        return $cur;
    }

  public static function ensureSession(): void
{
    if (session_status() === \PHP_SESSION_ACTIVE) {
        return;
    }

    $sessionName = getenv('APP_SESSION_NAME') ?: 'CCPORTALSESSID';
    session_name($sessionName);

    $cookiePath = getenv('APP_COOKIE_PATH') ?: '/';

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $cookiePath,
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    $root = self::root(); // APP_SESSION_PREFIX (ccportal)
    $_SESSION[$root] ??= [];

    self::debugLog("[SESSION DEBUG] Session started, name={$sessionName}, root={$root}, SID=" . session_id());
}


    public static function get(string $key, $default = null)
    {
        self::ensureSession();
        $root  = self::root();
        $parts = explode('.', $key);
        $cur   = $_SESSION[$root] ?? [];

        foreach ($parts as $seg) {
            if (!isset($cur[$seg])) {
                return $default;
            }
            $cur = $cur[$seg];
        }
        return $cur;
    }

    public static function set(string $key, $value): void
    {
        self::ensureSession();

        if ($key === 'flash.message') {
            $trace  = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
            $caller = $trace[1]['function'] ?? 'unknown';
            $file   = $trace[0]['file'] ?? '(no file)';
            $line   = $trace[0]['line'] ?? 0;
            self::debugLog("[FLASH DEBUG] flash.message set by {$caller} in {$file}:{$line}");
        }

        $root  = self::root();
        $_SESSION[$root] ??= [];

        $parts = explode('.', $key);
        $last  = array_pop($parts);

        $bucket =& $_SESSION[$root];
        $parent =& self::ref($bucket, $parts);
        $parent[$last] = $value;

        self::debugLog("[SESSION DEBUG] Set session key {$key} to " . json_encode($value));
    }


    public static function forget(string $key): void
    {
        self::ensureSession();
        $root  = self::root();
        $_SESSION[$root] ??= [];
        $parts = explode('.', $key);
        $last  = array_pop($parts);
        $cur   =& $_SESSION[$root];

        foreach ($parts as $seg) {
            if (!isset($cur[$seg]) || !is_array($cur[$seg])) {
                return;
            }
            $cur =& $cur[$seg];
        }
        unset($cur[$last]);
        self::debugLog("[SESSION DEBUG] Forgot session key {$key}");
    }

    public static function pull(string $key, $default = null)
    {
        $val = self::get($key, $default);
        self::forget($key);
        return $val;
    }

      public static function has(string $key): bool
    {
        self::ensureSession();
        $root  = self::root();
        $parts = explode('.', $key);
        $last  = array_pop($parts);
        $cur   = $_SESSION[$root] ?? [];

        foreach ($parts as $seg) {
            if (!isset($cur[$seg]) || !is_array($cur[$seg])) {
                return false;
            }
            $cur = $cur[$seg];
        }

        return array_key_exists($last, $cur);
    }

    public static function enforceActiveSession(\PDO $db): void
    {
        self::ensureSession();
        $root      = self::root();
        $userId    = (int)($_SESSION[$root]['auth']['user_id'] ?? 0);
        $sessionId = session_id();
        $route     = $_GET['route'] ?? '';
        self::debugLog("[SESSION DEBUG] enforceActiveSession: session_id={$sessionId}, user_id={$userId}, route={$route}");

        if ($userId <= 0 || !$sessionId) {
            self::debugLog("[SESSION DEBUG] enforceActiveSession: No user ID or session ID, skipping");
            return;
        }

        $now = time();
        $nextCheck = $_SESSION[$root]['session']['next_active_check'] ?? 0;
        $forceCheck = false;

        // Force check for critical routes
        $criticalRoutes = ['sessions/list', 'users/list'];
        if (in_array($route, $criticalRoutes, true) || ($_SESSION[$root]['session']['force_check'] ?? false)) {
            $forceCheck = true;
            self::debugLog("[SESSION DEBUG] enforceActiveSession: Forcing check for route={$route} or force_check flag");
        }

        try {
            $stmt = $db->prepare("
                SELECT
                    s.IsActive,
                    s.ExpiresAt,
                    s.ForceLogout,
                    ISNULL(u.IsActive, 1) AS UserIsActive
                FROM dbo.tblUserSessions s
                LEFT JOIN dbo.tblUsers u
                    ON u.UserID = s.UserID
                WHERE s.SessionID = :sid AND s.UserID = :uid
            ");
            $stmt->execute(['sid' => $sessionId, 'uid' => $userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            self::debugLog("[SESSION DEBUG] enforceActiveSession: Query result=" . json_encode($row));

            if ($row && (int)($row['ForceLogout'] ?? 0) === 1) {
                $forceCheck = true;
                self::debugLog("[SESSION DEBUG] enforceActiveSession: ForceLogout flag detected, bypassing throttle");
            }

            if (!$forceCheck && $now < $nextCheck) {
                self::debugLog("[SESSION DEBUG] enforceActiveSession: Throttled, next check at " . ($nextCheck - $now) . "s");
                return;
            }
            $_SESSION[$root]['session']['next_active_check'] = $now + 60;

            if (!$row) {
                self::debugLog("[SESSION DEBUG] enforceActiveSession: No session found, recreating");
                try {
                    require_once __DIR__ . '/../app/Models/UserSessionModel.php';
                    $model = new \App\Models\UserSessionModel($db);
                    $model->ensure(
                        $sessionId,
                        $userId,
                        (string)($_SESSION[$root]['auth']['username'] ?? 'unknown'),
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        $_SERVER['HTTP_USER_AGENT'] ?? '',
                        3600
                    );
                    self::debugLog("[SESSION DEBUG] enforceActiveSession: Recreated session record for user_id={$userId}, session_id={$sessionId}");
                    return;
                } catch (\Throwable $e) {
                    error_log("[SESSION ERROR] enforceActiveSession: Failed to recreate session: " . $e->getMessage());
                    return; // Prevent session destruction
                }
            }

            $expiresAt = $row['ExpiresAt'] ?? null;
            $isExpired = $expiresAt ? (strtotime($expiresAt . ' +11 hours') < $now) : false;
            if (
                (int)$row['IsActive'] === 0
                || (int)($row['UserIsActive'] ?? 1) === 0
                || $isExpired
                || (int)($row['ForceLogout'] ?? 0) === 1
            ) {
                self::debugLog("[SESSION DEBUG] enforceActiveSession failed: row=" . json_encode($row) . ", isExpired=" . ($isExpired ? 'true' : 'false'));
                session_unset();
                session_destroy();
                setcookie(session_name(), '', [
                    'expires'  => time() - 3600,
                    'path'     => '/',
                    'secure'   => !empty($_SERVER['HTTPS']),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
                self::debugLog("[SESSION DEBUG] Session destroyed: user_id={$userId}, session_id={$sessionId}, route={$route}");
                if ((int)($row['ForceLogout'] ?? 0) === 1) {
                    $stmt = $db->prepare("
                        UPDATE dbo.tblUserSessions
                        SET ForceLogout = 0
                        WHERE SessionID = :sid
                    ");
                    $stmt->execute(['sid' => $sessionId]);
                    self::debugLog("[SESSION DEBUG] enforceActiveSession: Cleared ForceLogout flag");
                }
                self::flashError(__t('session_terminated_by_admin'));
                header('Location: index.php?route=auth/loginForm&reason=forced');
                exit;
            }

            self::debugLog("[SESSION DEBUG] enforceActiveSession: Session valid, user_id={$userId}, session_id={$sessionId}, route={$route}");
        } catch (\Throwable $e) {
            error_log("[SESSION ERROR] enforceActiveSession: Query failed: " . $e->getMessage() . ", route={$route}");
            try {
                require_once __DIR__ . '/../app/Models/UserSessionModel.php';
                $model = new \App\Models\UserSessionModel($db);
                $model->ensure(
                    $sessionId,
                    $userId,
                    (string)($_SESSION[$root]['auth']['username'] ?? 'unknown'),
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    $_SERVER['HTTP_USER_AGENT'] ?? '',
                    3600
                );
                self::debugLog("[SESSION DEBUG] enforceActiveSession: Recreated session record on query failure for user_id={$userId}, session_id={$sessionId}");
                return;
            } catch (\Throwable $e2) {
                error_log("[SESSION ERROR] enforceActiveSession: Failed to recreate session: " . $e2->getMessage() . ", route={$route}");
                return; // Prevent session destruction
            }
        }
    }

    private static function flashError(string $message): void
    {
        self::set('flash.message', ['type' => 'danger', 'text' => $message]);
    }

  

}

