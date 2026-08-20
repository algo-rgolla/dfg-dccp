<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Rbac;
use App\Shared\SessionHelper;

final class ErrorLogController extends BaseController
{
    protected array $acl = [
        '*' => ['auth' => true],
    ];

    public function errors(): void
    {
        $this->ensureAdminAccess();

        $entries = [];
        $logDir = dirname(__DIR__, 2) . '/logs';
        $files = glob($logDir . '/app-*.log') ?: [];
        rsort($files, SORT_STRING);

        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            if (!is_array($lines)) {
                continue;
            }

            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $line = (string)$lines[$i];
                $severity = $this->parseSeverity($line);
                if (!$this->isErrorSeverity($severity)) {
                    continue;
                }
                $entries[] = [
                    'file' => basename($file),
                    'severity' => $severity,
                    'line' => $line,
                ];
                if (count($entries) >= 250) {
                    break 2;
                }
            }
        }

        $this->render('logs/ErrorLogView', [
            'title' => 'Application Error Log',
            'entries' => $entries,
        ]);
    }

    private function ensureAdminAccess(): void
    {
        $rbac = new Rbac($this->db);
        $roles = array_map('strtolower', (array)SessionHelper::get('auth.roles', []));
        if ($rbac->canAny(['ADMIN_ALL', 'SYSADMIN']) || in_array('admin', $roles, true)) {
            return;
        }

        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    private function parseSeverity(string $line): string
    {
        if (preg_match('/\[(DEBUG|INFO|WARN|ERROR|CRITICAL)\]/', $line, $matches)) {
            return strtoupper((string)$matches[1]);
        }

        if (str_contains($line, 'Unhandled Exception') || str_contains($line, 'Fatal Error')) {
            return 'ERROR';
        }

        return 'UNKNOWN';
    }

    private function isErrorSeverity(string $severity): bool
    {
        return in_array($severity, ['ERROR', 'CRITICAL'], true);
    }
}
