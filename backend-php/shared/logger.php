<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/request_id.php';

use App\Shared\SessionHelper;
use App\Models\SystemSettingsModel;
use App\Services\MailService;

/**
 * Logs a message with context and level.
 * Null-safe: forces $context to [] if null or invalid.
 */
function app_log(string $message, mixed $context = [], string $level = 'info'): void
{
    global $conn;
    static $settingsCache = null;

    // Null-safe guard: prevent fatal "array offset on null"
    if ($context === null || !is_array($context)) {
        error_log("app_log WARNING: context was null or not array - forced to []");
        $context = [];
    }

    $logDir = __DIR__ . '/../logs';
    $today = date('Y-m-d');
    $defaultPath = "$logDir/app-$today.log";
    $logFile = envStr('APP_LOG_PATH', $defaultPath);

    // Cache settings
    if ($settingsCache === null && $conn instanceof \PDO) {
        try {
            require_once __DIR__ . '/../app/Models/SystemSettingsModel.php';
            $ss = new \App\Models\SystemSettingsModel($conn);
            $settingsCache = [
                'retentionDays'     => (int)$ss->get('APP_LOG_RETENTION_DAYS', '30'),
                'emailEnabled'      => filter_var($ss->get('ERROR_EMAIL_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
                'emailTo'           => $ss->get('ERROR_EMAIL_TO', envStr('ERROR_EMAIL_TO', '')),
                'emailFrom'         => $ss->get('ERROR_EMAIL_FROM', envStr('ERROR_EMAIL_FROM', 'noreply@cbmsv2.local')),
                'slowAlertsEnabled' => filter_var($ss->get('SLOW_REQUEST_ALERTS_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
                'slowThreshold'     => (int)$ss->get('SLOW_REQUEST_THRESHOLD_MS', '500'),
                'lastFetched'       => time()
            ];
        } catch (\Throwable $e) {
            $settingsCache = [
                'retentionDays'     => 30,
                'emailEnabled'      => envFlag('ERROR_EMAIL_ENABLED', false),
                'emailTo'           => envStr('ERROR_EMAIL_TO', ''),
                'emailFrom'         => envStr('ERROR_EMAIL_FROM', 'noreply@cbmsv2.local'),
                'slowAlertsEnabled' => false,
                'slowThreshold'     => 500,
                'lastFetched'       => time()
            ];
        }
    } elseif ($conn instanceof \PDO && (time() - $settingsCache['lastFetched']) > 300) {
        // Refresh cache every 5 minutes
        try {
            $ss = new \App\Models\SystemSettingsModel($conn);
            $settingsCache['retentionDays']     = (int)$ss->get('APP_LOG_RETENTION_DAYS', '30');
            $settingsCache['emailEnabled']      = filter_var($ss->get('ERROR_EMAIL_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
            $settingsCache['emailTo']           = $ss->get('ERROR_EMAIL_TO', envStr('ERROR_EMAIL_TO', ''));
            $settingsCache['emailFrom']         = $ss->get('ERROR_EMAIL_FROM', envStr('ERROR_EMAIL_FROM', 'noreply@cbmsv2.local'));
            $settingsCache['slowAlertsEnabled'] = filter_var($ss->get('SLOW_REQUEST_ALERTS_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
            $settingsCache['slowThreshold']     = (int)$ss->get('SLOW_REQUEST_THRESHOLD_MS', '500');
            $settingsCache['lastFetched']       = time();
        } catch (\Throwable $e) {}
    }

    $retentionDays = $settingsCache['retentionDays'] ?? 30;
    $cutoff = new \DateTimeImmutable("-$retentionDays days", new \DateTimeZone('UTC'));
    foreach (glob($logDir . "/app-*.log") as $file) {
        if (preg_match('/app-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m)) {
            $fileDate = \DateTimeImmutable::createFromFormat('Y-m-d', $m[1], new \DateTimeZone('UTC'));
            if ($fileDate && $fileDate < $cutoff) {
                @unlink($file);
            }
        }
    }

    $debugEnabled = envFlag('APP_DEBUG', false);
    $infoEnabled  = envFlag('APP_DEBUG_LOG_ENABLED', false);
    ['key' => $levelLower, 'label' => $levelLabel] = normalize_app_log_level($level);

    if ($levelLower === 'debug' && (!$debugEnabled || !$infoEnabled)) return;
    if ($levelLower === 'info' && !$infoEnabled) return;

    $meta = [
        'FiscalYearID' => SessionHelper::get('FiscalYearID') ?? null,
        'VersionID'    => SessionHelper::get('VersionID') ?? null,
        'UserID'       => SessionHelper::get('auth.user_id') ?? null,
        'Username'     => SessionHelper::get('auth.username') ?? null,
    ];
    $context = array_merge($meta, $context);

    $reqId = function_exists('cbms_request_id') ? cbms_request_id() : null;
    $context['RequestID'] = $reqId;

    $context += [
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'uri'    => $_SERVER['REQUEST_URI'] ?? null,
        'route'  => $_GET['route'] ?? null,
        'ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    $msgJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $plainLine = sprintf("[%s] [%s] %s %s\n", date('Y-m-d H:i:s'), $levelLabel, $message, $msgJson);

    try {
        file_put_contents($logFile, $plainLine, FILE_APPEND | LOCK_EX);
    } catch (\Throwable $e) {
        error_log('[app_log fallback] failed to write log file: ' . $e->getMessage());
        error_log(trim($plainLine));
    }

    if (in_array($levelLower, ['error', 'critical'], true)) {
        switch ($levelLower) {
            case 'critical': $ansiLevel = "\033[35mCRITICAL\033[0m"; break;
            case 'error': $ansiLevel = "\033[31mERROR\033[0m"; break;
            default: $ansiLevel = $levelLabel;
        }
        error_log(sprintf("[%s] [%s] %s %s", date('Y-m-d H:i:s'), $ansiLevel, $message, $msgJson));
    }

    static $lastEmailTime = 0;
    static $emailQueue = [];

    try {
        if ($conn instanceof \PDO) {
            $emailEnabled      = $settingsCache['emailEnabled'] ?? false;
            $emailTo           = $settingsCache['emailTo'] ?? '';
            $emailFrom         = $settingsCache['emailFrom'] ?? 'noreply@cbmsv2.local';
            $slowAlertsEnabled = $settingsCache['slowAlertsEnabled'] ?? false;
            $slowThreshold     = $settingsCache['slowThreshold'] ?? 500;

            $shouldQueue = false;
            $subject     = '';
            $body        = '';

            $ridTag = $context['RequestID'] ? " [RID: {$context['RequestID']}]" : '';

            if (in_array($levelLower, ['error', 'critical'], true) && $emailEnabled) {
                $shouldQueue = true;
                $subject = sprintf('[CBMS %s]%s %s', $levelLabel, $ridTag, $message);
                $body = buildLogEmailBody($message, $context, $level);
            } elseif ($levelLower === 'warn' && $slowAlertsEnabled) {
                $timeMs = (float)($context['time_ms'] ?? 0);
                if ($timeMs >= $slowThreshold) {
                    $shouldQueue = true;
                    $subject = "[CBMS Slow Request]{$ridTag} {$message} took {$timeMs} ms";
                    $body = buildLogEmailBody($message, $context, $level);
                }
            }

            if ($shouldQueue && $emailTo !== '') {
                $emailQueue[] = ['to' => $emailTo, 'subject' => $subject, 'body' => $body, 'from' => $emailFrom];
            }

            // Send queued emails every 60 seconds
            if ($emailQueue && (time() - $lastEmailTime) >= 60) {
                $mailer = new MailService($conn);
                foreach ($emailQueue as $email) {
                    try {
                        $mailer->sendEmail($email['to'], $email['subject'], $email['body'], $email['from']);
                        try {
                            file_put_contents($logFile, sprintf("[%s] [INFO] Email sent: %s\n", date('Y-m-d H:i:s'), $email['subject']), FILE_APPEND | LOCK_EX);
                        } catch (\Throwable $writeErr) {
                            error_log('[app_log fallback] failed to write email success log: ' . $writeErr->getMessage());
                        }
                    } catch (\Throwable $e) {
                        try {
                            file_put_contents($logFile, sprintf("[%s] [ERROR] Email failed: %s\n", date('Y-m-d H:i:s'), $e->getMessage()), FILE_APPEND | LOCK_EX);
                        } catch (\Throwable $writeErr) {
                            error_log('[app_log fallback] failed to write email failure log: ' . $writeErr->getMessage());
                            error_log('[app_log email failure] ' . $e->getMessage());
                        }
                    }
                }
                $emailQueue = [];
                $lastEmailTime = time();
            }
        }
    } catch (\Throwable $e) {
        try {
            file_put_contents($logFile, sprintf("[%s] [ERROR] app_log email setup failed: %s\n", date('Y-m-d H:i:s'), $e->getMessage()), FILE_APPEND | LOCK_EX);
        } catch (\Throwable $writeErr) {
            error_log('[app_log fallback] failed to write email setup error log: ' . $writeErr->getMessage());
            error_log('[app_log email setup failure] ' . $e->getMessage());
        }
    }
}

function envFlag(string $key, bool $default = false): bool
{
    $val = strtolower(trim((string)(envStr($key, $default ? '1' : '0'))));
    return in_array($val, ['1','true','yes','on'], true);
}

function buildLogEmailBody(string $message, array $context, string $level): string
{
    $time = date('Y-m-d H:i:s');
    $fy   = $context['FiscalYearID'] ?? '(n/a)';
    $ver  = $context['VersionID'] ?? '(n/a)';
    $uid  = $context['UserID'] ?? '(n/a)';
    $user = $context['Username'] ?? '(n/a)';
    $rid  = $context['RequestID'] ?? '(n/a)';
    $method = $context['method'] ?? '(n/a)';
    $uri    = $context['uri'] ?? '(n/a)';
    $route  = $context['route'] ?? '(n/a)';
    $ip     = $context['ip'] ?? '(n/a)';

    $body = "<h3>CBMS " . htmlspecialchars($level, ENT_QUOTES, 'UTF-8') . " Alert</h3>";
    $body .= "<p><strong>Time:</strong> {$time}</p>";
    $body .= "<p><strong>User:</strong> " . htmlspecialchars((string)$user, ENT_QUOTES, 'UTF-8') . " (ID={$uid})</p>";
    $body .= "<p><strong>Fiscal Context:</strong> FY={$fy}, Version={$ver}</p>";
    $body .= "<p><strong>Request ID:</strong> " . htmlspecialchars((string)$rid, ENT_QUOTES, 'UTF-8') . "</p>";
    $body .= "<p><strong>Request:</strong> " . htmlspecialchars("$method $uri (route=$route, ip=$ip)", ENT_QUOTES, 'UTF-8') . "</p>";
    $body .= "<p><strong>Message:</strong> " . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</p>";
    $body .= "<pre>" . htmlspecialchars(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') . "</pre>";
    return $body;
}

function app_log_set_conn($conn): void
{
    try {
        $st = $conn->query("
            SELECT SettingKey, SettingValue
            FROM dbo.tblSystemSettings
            WHERE SettingKey IN ('APP_DEBUG','APP_DEBUG_LOG_ENABLED')
        ");
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['SettingKey'])) {
                putenv($row['SettingKey'].'='.$row['SettingValue']);
            }
        }
    } catch (\Throwable $e) {
        error_log("app_log_set_conn failed: " . $e->getMessage());
    }
}

function normalize_app_log_level(string $level): array
{
    $key = strtolower(trim($level));
    $map = [
        'debug' => ['key' => 'debug', 'label' => 'DEBUG'],
        'info' => ['key' => 'info', 'label' => 'INFO'],
        'warn' => ['key' => 'warn', 'label' => 'WARN'],
        'warning' => ['key' => 'warn', 'label' => 'WARN'],
        'error' => ['key' => 'error', 'label' => 'ERROR'],
        'critical' => ['key' => 'critical', 'label' => 'CRITICAL'],
    ];

    return $map[$key] ?? ['key' => 'info', 'label' => 'INFO'];
}
