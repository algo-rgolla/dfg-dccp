<?php
declare(strict_types=1);

/**
 * config/db.php
 *
 * Provides TWO PDO connections:
 *   - $conn      → CCPortal (primary)
 *   - $capsConn  → CAPS (secondary)
 *
 * Uses .env keys:
 *   # Primary (CCPortal)
 *   DB_DRIVER=sqlsrv|mysql
 *   DB_HOST / DB_SERVER
 *   DB_NAME / DB_DATABASE
 *   DB_USER / DB_USERNAME
 *   DB_PASS / DB_PASSWORD
 *   DB_PORT (optional)
 *   DB_ENCRYPT=true|false
 *   DB_TRUST_CERT=true|false
 *   DB_LOGIN_TIMEOUT=5
 *
 *   # Secondary (CAPS) — SAME pattern but prefixed with CAPS_
 *   CAPS_DB_DRIVER=sqlsrv|mysql      (optional; defaults to DB_DRIVER)
 *   CAPS_DB_HOST / CAPS_DB_SERVER
 *   CAPS_DB_NAME / CAPS_DB_DATABASE
 *   CAPS_DB_USER / CAPS_DB_USERNAME
 *   CAPS_DB_PASS / CAPS_DB_PASSWORD
 *   CAPS_DB_PORT (optional)
 *   CAPS_DB_ENCRYPT=true|false
 *   CAPS_DB_TRUST_CERT=true|false
 *   CAPS_DB_LOGIN_TIMEOUT=5
 *
 * Notes:
 * - This file is safe to include multiple times.
 * - Exposes $GLOBALS['conn'] and $GLOBALS['capsConn'] for legacy code.
 */

require_once __DIR__ . '/../shared/env.php';
require_once __DIR__ . '/../shared/logger.php';

// Only load .env once
if (!isset($GLOBALS['__env_loaded__'])) {
    loadEnv(__DIR__ . '/../.env');
    $GLOBALS['__env_loaded__'] = true;
}

$APP_ENV = envStr('APP_ENV', 'local');

/**
 * Build a PDO connection from env values.
 * $prefix = '' (primary) or 'CAPS_' (secondary)
 */
if (!function_exists('makePdo')) {
    function makePdo(string $prefix, string $appEnv): PDO
    {
        // driver: allow per-connection override, fallback to primary DB_DRIVER
        $driver = strtolower((string) envStr($prefix . 'DB_DRIVER', envStr('DB_DRIVER', 'sqlsrv')));

        // common keys with fallbacks (DB_HOST->DB_SERVER, DB_NAME->DB_DATABASE, etc.)
        $host = envStr($prefix . 'DB_HOST', envStr($prefix . 'DB_SERVER', envStr('DB_HOST', envStr('DB_SERVER', 'localhost'))));
        $name = envStr($prefix . 'DB_NAME', envStr($prefix . 'DB_DATABASE', envStr('DB_NAME', envStr('DB_DATABASE', ''))));
        $user = envStr($prefix . 'DB_USER', envStr($prefix . 'DB_USERNAME', envStr('DB_USER', envStr('DB_USERNAME', ''))));
        $pass = envStr($prefix . 'DB_PASS', envStr($prefix . 'DB_PASSWORD', envStr('DB_PASS', envStr('DB_PASSWORD', ''))));
        $port = envStr($prefix . 'DB_PORT', envStr('DB_PORT','')); // optional

        // sqlsrv options
        $encrypt   = envBool($prefix . 'DB_ENCRYPT', envBool('DB_ENCRYPT', true));
        $trustCert = envBool($prefix . 'DB_TRUST_CERT', envBool('DB_TRUST_CERT', true));
        $timeout   = (int) (envStr($prefix . 'DB_LOGIN_TIMEOUT', envStr('DB_LOGIN_TIMEOUT', '5')) ?? '5');

        if ($name === '') {
            throw new RuntimeException("Missing database name for prefix '{$prefix}'. Set {$prefix}DB_DATABASE or {$prefix}DB_NAME.");
        }

        if ($driver === 'mysql') {
            $portPart = $port ? ";port={$port}" : '';
            $dsn = "mysql:host={$host}{$portPart};dbname={$name};charset=utf8mb4";

            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        // default: sqlsrv
        $server = $host . ($port ? ',' . $port : '');

        $params = [
            "Server={$server}",
            "Database={$name}",
            "LoginTimeout={$timeout}",
            "Encrypt=" . ($encrypt ? 'yes' : 'no'),
            "TrustServerCertificate=" . ($trustCert ? 'yes' : 'no'),
        ];
        $dsn = 'sqlsrv:' . implode(';', $params);

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        // Set UTF-8 for pdo_sqlsrv if available
        if (defined('PDO::SQLSRV_ATTR_ENCODING') && defined('PDO::SQLSRV_ENCODING_UTF8')) {
            $options[PDO::SQLSRV_ATTR_ENCODING] = PDO::SQLSRV_ENCODING_UTF8;
        }

        // Empty user => Windows integrated auth (pdo_sqlsrv expects null credentials for that)
        return new PDO($dsn, $user !== '' ? $user : null, $user !== '' ? $pass : null, $options);
    }
}

// Create primary connection once per request
try {
    if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof PDO)) {
        $GLOBALS['conn'] = makePdo('', $APP_ENV);
    }
    $conn = $GLOBALS['conn'];
} catch (Throwable $e) {
    if (function_exists('app_log')) {
        app_log('DB: primary connect failed', [
            'error' => $e->getMessage(),
        ], 'error');
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');

    if ($APP_ENV === 'local') {
        echo "Database connection failed.\n", $e->getMessage(), "\n";
    } else {
        echo "Database connection failed.";
    }
    exit;
}

// Create CAPS secondary connection once per request, but do not kill the whole app if it fails.
try {
    if (!isset($GLOBALS['capsConn']) || !($GLOBALS['capsConn'] instanceof PDO)) {
        $GLOBALS['capsConn'] = makePdo('CAPS_', $APP_ENV);
    }
    $capsConn = $GLOBALS['capsConn'];
} catch (Throwable $e) {
    $GLOBALS['capsConn'] = null;
    $capsConn = null;

    if (function_exists('app_log')) {
        app_log('DB: CAPS connect failed', [
            'error' => $e->getMessage(),
        ], 'error');
    }
}
