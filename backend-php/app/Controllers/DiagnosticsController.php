<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\SystemSettingsModel;
use App\Services\MailService;
use App\Shared\SessionHelper;

require_once __DIR__ . '/../../shared/logger.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../shared/error_handler.php'; // for db_query()

class DiagnosticsController extends BaseController
{
      protected array $acl = [
        // Deny everything unless explicitly listed
        '*'                => ['auth' => true, 'permsAny' => ['SYSADMIN']],

        // Allow viewing diagnostics summary if user has DIAG_VIEW or SYSADMIN
        'index'            => ['auth' => true, 'permsAny' => ['DIAG_VIEW','SYSADMIN']],

        // Require SYSADMIN for mail + dangerous test routes
        'sendTestEmail'    => ['auth' => true, 'permsAny' => ['SYSADMIN']],
        'forceDbError'     => ['auth' => true, 'permsAny' => ['SYSADMIN']],
        'throwException'   => ['auth' => true, 'permsAny' => ['SYSADMIN']],
        'fatalError'       => ['auth' => true, 'permsAny' => ['SYSADMIN']],
    ];

    public function __construct()
    {
        parent::__construct(); // ✅ enforce auth + ACL checks
    }
    
    public function index(): void
    {
        global $conn, $capsConn;

        $results = [
            'db_portal' => false,
            'db_caps'   => false,
            'settings' => [],
            'log_test' => false,
            'log_file' => '',
            'mail_test'=> null,
        ];

        $results['db_portal'] = $this->checkDatabaseConnection($conn, 'CCPortal');
        $results['db_caps'] = $this->checkDatabaseConnection($capsConn, 'CAPS');
        $mailTest = SessionHelper::get('diagnostics.mail_test_result');
        SessionHelper::forget('diagnostics.mail_test_result');
        if (is_array($mailTest)) {
            $results['mail_test'] = $mailTest;
        }

        // Settings check
        try {
            $settings = new SystemSettingsModel($conn);
            $results['settings'] = [
                'APP_DEBUG'                   => getenv('APP_DEBUG'),
                'APP_DEBUG_LOG_ENABLED'       => getenv('APP_DEBUG_LOG_ENABLED'),
                'SLOW_REQUEST_THRESHOLD_MS'   => $settings->get('SLOW_REQUEST_THRESHOLD_MS', '(not set)'),
                'SLOW_REQUEST_ALERTS_ENABLED' => $settings->get('SLOW_REQUEST_ALERTS_ENABLED', '(not set)'),
                'ERROR_EMAIL_ENABLED'         => $settings->get('ERROR_EMAIL_ENABLED', '(not set)'),
                'ERROR_EMAIL_TO'              => $settings->get('ERROR_EMAIL_TO', '(not set)'),
            ];
        } catch (\Throwable $e) {
            $results['settings'] = ['error' => $e->getMessage()];
        }

        // Log test (INFO only, no ERROR spam)
        try {
            app_log('Diagnostics test INFO', ['controller'=>'Diagnostics'], 'info');
            $results['log_test'] = true;
            $results['log_file'] = $this->resolveAppLogPath();
        } catch (\Throwable $e) {
            $results['log_test'] = $e->getMessage();
            $results['log_file'] = $this->resolveAppLogPath();
        }
            
        $this->render('diagnostics/DiagnosticsView', [
            'title'   => __t('diagnostics_title'),
            'results' => $results,
        ]);
    }

    public function sendTestEmail(): void
    {
        global $conn;

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        if (!csrf_check($_POST['_csrf'] ?? '')) {
            $this->flashError(__t('security_check_failed'));
            header('Location: index.php?route=diagnostics/index');
            return;
        }

        try {
            $settings = new SystemSettingsModel($conn);
            $to   = trim((string)($settings->get('ERROR_EMAIL_TO') ?? ''));
            $from = trim((string)($settings->get('ERROR_EMAIL_FROM') ?? 'noreply@cbmsv2.local'));

            if ($to) {
                $smtpDiagnostic = $this->buildSmtpDiagnostic($settings);
                $mailer = new MailService($conn);
                $mailSent = $mailer->sendEmail(
                    $to,
                    '[' . __t('diagnostics_title') . '] ' . __t('test_email_subject'),
                    '<p>' . __t('test_email_body') . '</p>',
                    $from
                );
                $sendMessage = $mailSent
                    ? 'Email send succeeded.'
                    : ('Email send failed: ' . ($mailer->getLastError() !== '' ? $mailer->getLastError() : 'Unknown error'));
                $result = [
                    'tested_at' => date('Y-m-d H:i:s'),
                    'to' => $to,
                    'from' => $from,
                    'smtp' => $smtpDiagnostic,
                    'send_ok' => $mailSent,
                    'send_message' => $sendMessage,
                ];
                SessionHelper::set('diagnostics.mail_test_result', $result);

                if ($mailSent) {
                    $this->flashSuccess('Mail test completed. SMTP socket OK and test email sent to ' . $to . '.');
                } else {
                    $this->flashError('Mail test completed, but the send failed. ' . $sendMessage);
                }
            } else {
                $this->flashError(__t('no_error_email_to'));
            }
        } catch (\Throwable $e) {
            // Use placeholder for better translations
            $this->flashError(__t('mail_test_failed_detail', ['msg' => $e->getMessage()]));
        }

        header('Location: index.php?route=diagnostics/index');
    }

    /** Force a database error (using missing table). */
    public function forceDbError(): void
    {
        global $conn;
        db_query($conn, "SELECT TOP 1 * FROM tblRatesX");
    }

    /** Force a manual exception for testing. */
    public function throwException(): void
    {
        throw new \Exception("Diagnostics test exception");
    }

    /** Force a fatal error (undefined function). */
    public function fatalError(): void
    {
        undefined_function_call(); // will trigger fatal error
    }

    private function checkDatabaseConnection($pdo, string $label): bool|string
    {
        try {
            if (!($pdo instanceof \PDO)) {
                return $label . ' database connection is unavailable.';
            }

            $st = $pdo->query('SELECT 1');
            return ($st && $st->fetchColumn() == 1) ? true : ($label . ' SELECT 1 returned an unexpected result.');
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    private function buildSmtpDiagnostic(SystemSettingsModel $settings): array
    {
        $host = trim((string)($settings->get('SMTP_HOST') ?? ''));
        $port = (int)($settings->get('SMTP_PORT') ?? 25);
        $user = trim((string)($settings->get('SMTP_USER') ?? ''));
        $ssl = trim((string)($settings->get('SMTP_SSL') ?? ''));
        $socket = $this->checkSmtpSocket($host, $port);

        return [
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'ssl' => $ssl,
            'auth_enabled' => $user !== '',
            'openssl_loaded' => extension_loaded('openssl'),
            'socket_ok' => $socket['ok'],
            'socket_message' => $socket['message'],
        ];
    }

    private function checkSmtpSocket(string $host, int $port): array
    {
        if ($host === '' || $port <= 0) {
            return [
                'ok' => false,
                'message' => 'SMTP host/port is not configured.',
            ];
        }

        try {
            $errno = 0;
            $errstr = '';
            $socket = @fsockopen($host, $port, $errno, $errstr, 3.0);
            if (!$socket) {
                return [
                    'ok' => false,
                    'message' => 'Connect failed: ' . $errno . ' ' . $errstr,
                ];
            }

            stream_set_timeout($socket, 3);
            $banner = fgets($socket, 256);
            fclose($socket);

            return [
                'ok' => true,
                'message' => $banner ? trim($banner) : 'Connected successfully.',
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function resolveAppLogPath(): string
    {
        $defaultPath = realpath(__DIR__ . '/../../logs');
        $defaultFile = ($defaultPath !== false ? $defaultPath : (__DIR__ . '/../../logs')) . '/app-' . date('Y-m-d') . '.log';
        return (string)(envStr('APP_LOG_PATH', $defaultFile) ?? $defaultFile);
    }
}
