<?php
declare(strict_types=1);

namespace App\Controllers;

require_once __DIR__ . '/../../shared/env.php';

final class HealthController extends BaseController
{

    protected array $acl = [
        // Deny everything by default
        '*'     => ['auth' => true, 'permsAny' => ['SYSADMIN']],  

        // Allow unauthenticated probes to /health/ping
        'ping'  => [],

        // Allow authenticated users with HEALTH_VIEW or SYSADMIN to view UI
        'index' => ['auth' => true, 'permsAny' => ['HEALTH_VIEW','SYSADMIN']],
    ];

    public function __construct()
    {
        parent::__construct(); // ✅ enforce auth + ACL checks
    }
    /**
     * Lightweight JSON health check for probes.
     * 200 when OK, 503 when DB degraded.
     */
    public function ping(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');

        $nowUtc = gmdate('Y-m-d\TH:i:s\Z');
        $appEnv = getenv('APP_ENV') ?: 'local';
        $appVer = getenv('APP_VERSION') ?: 'dev';

        $dbOk  = null;
        $dbErr = null;

        try {
            global $conn;
            if ($conn instanceof \PDO) {
                $st   = $conn->query('SELECT 1');
                $dbOk = ($st && $st->fetchColumn() == 1);
            } else {
                $dbOk  = false;
                $dbErr = 'No PDO connection';
            }
        } catch (\Throwable $e) {
            $dbOk  = false;
            $dbErr = $e->getMessage();
        }

        $debug = (strtolower((string)getenv('APP_DEBUG')) === 'true');

        $payload = [
            'status' => $dbOk ? 'ok' : 'degraded',
            'time'   => $nowUtc,
            'env'    => $appEnv,
            'version'=> $appVer,
            'checks' => [
                'db' => [
                    'ok'    => $dbOk,
                    'error' => ($appEnv === 'local' || $debug) ? $dbErr : null,
                ],
            ],
        ];

        http_response_code($dbOk ? 200 : 503);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    /**
     * UI page for Health Check (with detailed diagnostics).
     */
    public function index(): void
    {
        global $conn;

        $env     = getenv('APP_ENV') ?: 'local';
        $version = getenv('APP_VERSION') ?: 'dev';

        // --- DB health + driver
        $dbOk = false; $dbErr = null; $driver = '';
        try {
            if ($conn instanceof \PDO) {
                $driver = (string)($conn->getAttribute(\PDO::ATTR_DRIVER_NAME) ?: '');
                $st = $conn->query("SELECT 1");
                $dbOk = ($st && $st->fetchColumn() == 1);
            } else {
                $dbErr = 'DB connection not available';
            }
        } catch (\Throwable $e) {
            $dbOk  = false;
            $dbErr = $e->getMessage();
        }

        // --- Extra lightweight checks
        $checks = [];

        // 1) PHP extensions
        $required = [];
        if ($driver === 'sqlsrv') { $required[] = 'pdo_sqlsrv'; }
        elseif ($driver === 'mysql') { $required[] = 'pdo_mysql'; }
        $required = array_unique(array_merge($required, ['mbstring','openssl','json']));
        $missing  = array_values(array_filter($required, fn($ext) => !extension_loaded($ext)));
        $checks[] = [
            'id'      => 'php_extensions',
            'label'   => 'PHP extensions',
            'ok'      => empty($missing),
            'message' => empty($missing) ? 'All required extensions loaded' : ('Missing: ' . implode(', ', $missing)),
            'meta'    => ['driver' => $driver, 'required' => $required],
        ];

        // 2) Log directory writable
        $defaultLog = __DIR__ . '/../../logs/app.log';
        $logFile    = function_exists('envStr') ? envStr('APP_LOG_PATH', $defaultLog) : $defaultLog;
        $logDir     = dirname($logFile);
        $logOk      = is_dir($logDir) && is_writable($logDir);
        $checks[] = [
            'id'      => 'log_writable',
            'label'   => 'Log directory writable',
            'ok'      => $logOk,
            'message' => $logOk ? $logDir : "Not writable: {$logDir}",
            'meta'    => ['path' => $logDir],
        ];

        // 3) Session round-trip
        try {
            \App\Shared\SessionHelper::set('health.tmp', time());
            $val = \App\Shared\SessionHelper::get('health.tmp');
            \App\Shared\SessionHelper::forget('health.tmp');
            $sessOk = !empty($val);
        } catch (\Throwable $e) {
            $sessOk = false;
        }
        $checks[] = [
            'id'      => 'session_roundtrip',
            'label'   => 'Session read/write',
            'ok'      => $sessOk,
            'message' => $sessOk ? 'OK' : 'Set/get failed',
        ];

        // 4) Disk space
        $diskPath  = is_dir($logDir) ? $logDir : sys_get_temp_dir();
        $freeRaw   = @disk_free_space($diskPath);
        $totalRaw  = @disk_total_space($diskPath);
        $freeBytes  = is_numeric($freeRaw)  ? (float)$freeRaw  : null;
        $totalBytes = is_numeric($totalRaw) ? (float)$totalRaw : null;
        $minFree    = 200 * 1024 * 1024; // 200 MB
        $diskOk     = ($freeBytes !== null) && ($freeBytes >= $minFree);
        $freeMB     = $freeBytes  !== null ? round($freeBytes  / 1024 / 1024) : null;
        $totalMB    = $totalBytes !== null ? round($totalBytes / 1024 / 1024) : null;
        $usedPct    = ($freeBytes !== null && $totalBytes !== null && $totalBytes > 0)
                        ? round((1 - ($freeBytes / $totalBytes)) * 100, 1)
                        : null;
        $checks[] = [
            'id'      => 'disk_free',
            'label'   => 'Disk free space',
            'ok'      => $diskOk,
            'message' => ($freeMB !== null && $totalMB !== null)
                        ? "{$freeMB} MB free of {$totalMB} MB" . ($usedPct !== null ? " ({$usedPct}% used)" : '')
                        : 'Unknown',
            'meta'    => [
                'path'            => $diskPath,
                'free_mb'         => $freeMB,
                'total_mb'        => $totalMB,
                'used_percent'    => $usedPct,
                'min_required_mb' => $minFree / 1024 / 1024,
            ],
        ];

        // 5) DB latency
        $dbLatencyMs = null; $dbLatencyOk = false;
        try {
            if ($conn instanceof \PDO) {
                $t0 = microtime(true);
                $conn->query("SELECT 1");
                $dbLatencyMs = round((microtime(true) - $t0) * 1000, 2);
                $dbLatencyOk = true;
            }
        } catch (\Throwable $e) { $dbLatencyOk = false; }
        $checks[] = [
            'id'      => 'db_latency',
            'label'   => 'Database latency',
            'ok'      => $dbLatencyOk,
            'message' => $dbLatencyOk ? ($dbLatencyMs . ' ms') : 'Query failed',
        ];

        // 6) DB vs App time drift
        $driftSec = null; $driftOk = true;
        try {
            if ($conn instanceof \PDO) {
                if ($driver === 'sqlsrv') {
                    $st  = $conn->query("SELECT CONVERT(varchar(33), SYSUTCDATETIME(), 126) AS dbUtc");
                    $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
                    $dbUtcStr = $row['dbUtc'] ?? null;
                } elseif ($driver === 'mysql') {
                    $st  = $conn->query("SELECT DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%dT%H:%i:%sZ') AS dbUtc");
                    $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
                    $dbUtcStr = $row['dbUtc'] ?? null;
                } else {
                    $dbUtcStr = null;
                }
                if ($dbUtcStr) {
                    $dbTs  = strtotime($dbUtcStr);
                    $phpTs = time();
                    $driftSec = abs($phpTs - $dbTs);
                    $driftOk  = ($driftSec <= 5);
                }
            }
        } catch (\Throwable $e) { $driftOk = false; }
        $checks[] = [
            'id'      => 'time_drift',
            'label'   => 'DB vs App time drift',
            'ok'      => $driftOk,
            'message' => is_null($driftSec) ? 'Unknown' : ($driftSec . ' sec'),
        ];

        // 7) SMTP reachability
        $host = null; $port = 0;
        $smtpOk = null; $smtpMsg = 'Skipped';
        try {
            $host = getenv('ERROR_EMAIL_HOST') ?: null;
            $port = (int)(getenv('ERROR_EMAIL_PORT') ?: 0);
            if ((!$host || !$port) && $conn instanceof \PDO) {
                require_once __DIR__ . '/../Models/SystemSettingsModel.php';
                $ss = new \App\Models\SystemSettingsModel($conn);
                $host = $host ?: $ss->get('ERROR_EMAIL_HOST');
                $port = $port ?: (int)$ss->get('ERROR_EMAIL_PORT');
            }
            if ($host && $port) {
                $errno = 0; $errstr = '';
                $sock = @fsockopen($host, $port, $errno, $errstr, 2.0);
                if ($sock) {
                    stream_set_timeout($sock, 2);
                    $banner = fgets($sock, 256);
                    fclose($sock);
                    $smtpOk  = true;
                    $smtpMsg = $banner ? trim($banner) : 'Connected';
                } else {
                    $smtpOk  = false;
                    $smtpMsg = "Connect failed: {$errno} {$errstr}";
                }
            }
        } catch (\Throwable $e) {
            $smtpOk = false; $smtpMsg = $e->getMessage();
        }
        $checks[] = [
            'id'      => 'smtp_socket',
            'label'   => 'SMTP reachability',
            'ok'      => ($smtpOk === null) ? true : (bool)$smtpOk, // treat "skipped" as OK
            'message' => $smtpMsg,
            'meta'    => ['host' => $host, 'port' => $port],
        ];

        // 8) Debug mode sanity
        $prodWarn = null;
        try {
            $appEnv = getenv('APP_ENV') ?: 'local';
            $appDbg = strtolower((string)(getenv('APP_DEBUG') ?: 'false'));
            if (in_array($appEnv, ['prod','production'], true)
                && in_array($appDbg, ['1','true','yes','on'], true)) {
                $prodWarn = 'APP_DEBUG is ON in production';
            }
        } catch (\Throwable $e) { /* ignore */ }
        $checks[] = [
            'id'      => 'debug_sanity',
            'label'   => 'Debug mode sanity',
            'ok'      => $prodWarn ? false : true,
            'message' => $prodWarn ?: 'OK',
        ];

        // Build payload for the view
        $data = [
            'status'      => $dbOk ? 'ok' : 'degraded',
            'time'        => date('Y-m-d H:i:s'),
            'env'         => $env,
            'version'     => $version,
            'rid'         => function_exists('cbms_request_id') ? cbms_request_id() : null,
            'checks'      => ['db' => ['ok' => $dbOk, 'error' => $dbErr]],
            'extraChecks' => $checks,
        ];

       $this->render('health/HealthView', [
            'title' => __t('health_check'),
            'data'  => $data,
        ]);

    }
}
