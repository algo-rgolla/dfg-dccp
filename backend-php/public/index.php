<?php
declare(strict_types=1);

ob_start(); // Start buffering early to catch any stray output

$__reqStart = microtime(true);

// Request ID
require_once __DIR__ . '/../shared/request_id.php';
if (function_exists('cbms_request_id')) {
    $rid = cbms_request_id();
    if (!headers_sent()) {
        header('X-Request-ID: ' . $rid);
    }
}

// Security headers
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');

    header(
        "Content-Security-Policy-Report-Only: "
        . "default-src 'self'; "
        . "img-src 'self' data:; "
        . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
        . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
        . "font-src 'self' data:; "
        . "connect-src 'self'"
    );
}

// Tiny translated error renderer (404/405)
if (!function_exists('_et')) {
    function _et(string $key, string $fallback): string {
        return function_exists('__t') ? __t($key) : $fallback;
    }
}

function renderHttpError(int $code, string $titleKey, string $messageKey, ?string $detail = null): void {
    http_response_code($code);

    $title   = _et($titleKey, $code === 404 ? 'Not Found' : 'Method Not Allowed');
    $message = _et($messageKey, $code === 404 ? 'Page not found.' : 'Method not allowed.');

    $rid = function_exists('cbms_request_id') ? cbms_request_id() : null;

    echo "<!doctype html>
<html lang='en'>
<head><meta charset='utf-8'><title>Error</title></head>
<body style='font-family:sans-serif;padding:2rem;max-width:640px;margin:auto;'>
  <h2>" . htmlspecialchars($title) . "</h2>
  <p>" . htmlspecialchars($message) . "</p>";
    if ($detail) echo "<p><small>" . htmlspecialchars($detail) . "</small></p>";
    if ($rid) echo "<p><small>Reference ID: " . htmlspecialchars($rid) . "</small></p>";
    echo "  <a href='index.php?route=home/index'>Back to Home</a>
</body>
</html>";
    exit;
}

// Composer autoloader
$rootVendor = __DIR__ . '/../../vendor/autoload.php';
$localVendor = __DIR__ . '/../vendor/autoload.php';

if (file_exists($rootVendor)) {
    require_once $rootVendor;
} elseif (file_exists($localVendor)) {
    require_once $localVendor;
}

// Fallback autoloader for App\ classes when Composer class maps are stale on a server.
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $path = __DIR__ . '/../app/' . $relative . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

// Env + global handlers & bootstrap
require_once __DIR__ . '/../shared/env.php';

$isDebug = function_exists('envFlag') ? envFlag('APP_DEBUG', false) : false;
ini_set('display_errors', $isDebug ? '1' : '0');
ini_set('display_startup_errors', $isDebug ? '1' : '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../shared/error_handler.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../shared/logger.php';

// Shutdown: log timing & flush buffer
register_shutdown_function(function () use ($__reqStart) {
    $durationMs = round((microtime(true) - $__reqStart) * 1000, 2);
    $status = http_response_code() ?: 200;

    if (!headers_sent()) {
        header('X-Response-Time: ' . $durationMs . ' ms');
    }

    if (function_exists('app_log')) {
        app_log('Request complete', [
            'status' => $status,
            'time_ms' => $durationMs,
            'memory_peak_kb' => round(memory_get_peak_usage(true) / 1024),
        ], $durationMs >= 1000 ? 'warn' : 'debug');
    }

    // Force flush if anything is left in buffer
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
});

// Routing
$routes = require __DIR__ . '/../config/routes.php';
$route = trim((string)($_GET['route'] ?? ''), '/');
$map = $routes[$route] ?? ($routes[''] ?? null);

if ($map === null || !str_contains($map, '@')) {
    if (function_exists('app_log')) {
        app_log('Route not found', ['route' => $route], 'error');
    }
    renderHttpError(404, 'not_found', 'page_not_found');
}

[$ctrl, $action] = explode('@', $map, 2);
$fqcn = 'App\\Controllers\\' . $ctrl;

if (!class_exists($fqcn)) {
    if (function_exists('app_log')) {
        app_log('Controller not found', ['route' => $route, 'controller' => $fqcn], 'error');
    }
    renderHttpError(500, 'server_error', 'controller_not_found', $fqcn);
}

$controller = new $fqcn();

if (!method_exists($controller, $action)) {
    if (function_exists('app_log')) {
        app_log('Action not found', ['route' => $route, 'controller' => $fqcn, 'action' => $action], 'error');
    }
    renderHttpError(500, 'server_error', 'action_not_found', $action);
}

$controller->$action();

// Ensure buffer is flushed after successful execution
if (ob_get_length() > 0) {
    ob_end_flush();
}
