<?php
declare(strict_types=1);

// --- Load environment from .env first ---
require_once __DIR__ . '/../shared/env.php';
loadEnv(__DIR__ . '/../.env');

require_once __DIR__ . '/../shared/SessionHelper.php';
require_once __DIR__ . '/../shared/lang.php'; // provides \App\Shared\Lang and maybe __t()

// --- Session configuration (env-driven) ---

$cookiePath  = getenv('APP_COOKIE_PATH')  ?: '/';
$useHttps    = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_cookies', '1');
ini_set('session.use_only_cookies', '1');
session_cache_limiter('nocache');


session_set_cookie_params([
  'lifetime' => 0,
  'path'     => $cookiePath,
  'domain'   => '',
  'secure'   => $useHttps,
  'httponly' => true,
  'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
  //session_start();
}

// --- Namespaced session bucket (cbmsv21.*) ---
$prefix = getenv('APP_SESSION_PREFIX') ?: 'cbmsv21';
if (!isset($_SESSION[$prefix]) || !is_array($_SESSION[$prefix])) {
  $_SESSION[$prefix] = [];
}

// --- DB + logger (includes env override of APP_DEBUG flags) ---
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../shared/logger.php';
if (isset($conn) && $conn instanceof \PDO) {
    try {
        app_log_set_conn($conn);
    } catch (\Throwable $e) {
        app_log('app_log_set_conn failed', ['error' => $e->getMessage()], 'error');
        throw $e; // ✅ bubble up to global handler
    }
}

// --- Ensure translation helper exists even if not defined in lang.php ---
if (!function_exists('__t')) {
    function __t(string $key, array $replacements = []): string {
        return \App\Shared\Lang::t($key, $replacements);
    }
}

// --- Language: set from SystemSettings default if not already in session ---
try {
    if (isset($conn) && $conn instanceof \PDO) {
        require_once __DIR__ . '/../app/Models/SystemSettingsModel.php';
        $settings = new \App\Models\SystemSettingsModel($conn);

        // If session has no language yet, seed from DB default (fallback 'en')
        if (!\App\Shared\SessionHelper::has('lang')) {
            $defaultLang = (string)$settings->get('DEFAULT_LANGUAGE', 'en');
            \App\Shared\Lang::setActiveLang($defaultLang);
        }
    }
} catch (\Throwable $e) {
    app_log('Language default lookup failed', ['error' => $e->getMessage()], 'error');
    throw $e; // ✅ bubble up
}

// --- Call Lang::init() only if it exists (compat safe) ---
if (method_exists(\App\Shared\Lang::class, 'init')) {
    \App\Shared\Lang::init();
}

// --- Session idle + absolute timeout enforcement ---
if (isset($conn) && $conn instanceof \PDO) {
    try {
        require_once __DIR__ . '/../app/Models/SystemSettingsModel.php';
        $settings = new \App\Models\SystemSettingsModel($conn);

        $idleLimit = (int)$settings->get('SESSION_IDLE_LIMIT', '900');   // seconds (default 15 min)
        $absLimit  = (int)$settings->get('SESSION_TIMEOUT_MIN', '60');   // minutes (default 60)

        $now     = time();
        $loginAt = (int)(\App\Shared\SessionHelper::get('auth.login_time') ?? 0);
        $lastAct = (int)(\App\Shared\SessionHelper::get('auth.last_activity') ?? 0);

        $expired = false;

        if ($loginAt > 0 && $absLimit > 0 && ($now - $loginAt) > ($absLimit * 60)) {
            $expired = true;
        }
        if (!$expired && $idleLimit > 0 && $lastAct > 0 && ($now - $lastAct) > $idleLimit) {
            $expired = true;
        }

        if ($expired) {
            // Clear namespaced state then rebuild session
            \App\Shared\SessionHelper::forget('auth');
            $_SESSION[$prefix] = [];
            session_destroy();
            //session_start();

            \App\Shared\SessionHelper::set('flash.message', [
                'type' => 'warning',
                'text' => __t('session_expired') // ensure key exists in lang/en.php
            ]);

            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        // Update last activity if logged in
        if (\App\Shared\SessionHelper::get('auth.user_id')) {
            \App\Shared\SessionHelper::set('auth.last_activity', $now);
        }
    } catch (\Throwable $e) {
        app_log('Idle/timeout check failed', ['error' => $e->getMessage()], 'error');
        throw $e; // ✅ bubble up
    }
}
