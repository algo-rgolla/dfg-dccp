<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Rbac;
use App\Shared\SessionHelper;

final class PhpErrorLogController extends BaseController
{
    protected array $acl = [
        '*' => ['auth' => true],
    ];

    public function list(): void
    {
        $this->ensureAdminAccess();

        [$configuredPath, $files] = $this->discoverPhpErrorLogs();

        $this->render('logs/PhpErrorLogList', [
            'title' => 'PHP Error Logs',
            'configuredPath' => $configuredPath,
            'files' => $files,
        ]);
    }

    public function view(): void
    {
        $this->ensureAdminAccess();

        [$configuredPath, $files] = $this->discoverPhpErrorLogs();
        $selected = trim((string)($_GET['file'] ?? ''));
        $defaultFile = $files[0]['name'] ?? '';
        $fileName = $selected !== '' ? $selected : $defaultFile;

        $filePath = null;
        foreach ($files as $row) {
            if ((string)$row['name'] === $fileName) {
                $filePath = (string)$row['path'];
                break;
            }
        }

        $this->render('logs/PhpErrorLogView', [
            'title' => 'PHP Error Log',
            'configuredPath' => $configuredPath,
            'fileName' => $fileName,
            'content' => $filePath !== null ? $this->readTail($filePath) : '',
            'availableFiles' => $files,
            'missing' => $filePath === null,
        ]);
    }

    public function download(): void
    {
        $this->ensureAdminAccess();

        [, $files] = $this->discoverPhpErrorLogs();
        $selected = trim((string)($_GET['file'] ?? ''));
        $defaultFile = $files[0]['name'] ?? '';
        $fileName = $selected !== '' ? $selected : $defaultFile;

        $filePath = null;
        foreach ($files as $row) {
            if ((string)$row['name'] === $fileName) {
                $filePath = (string)$row['path'];
                break;
            }
        }

        if ($filePath === null || !is_file($filePath)) {
            http_response_code(404);
            echo 'PHP error log file not found';
            return;
        }

        $this->sendDownload($filePath, $fileName);
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

    private function discoverPhpErrorLogs(): array
    {
        $configuredPath = trim((string)ini_get('error_log'));
        $files = [];

        if ($configuredPath !== '') {
            $configuredPath = str_replace('\\', '/', $configuredPath);
            $dir = dirname($configuredPath);
            $base = basename($configuredPath);
            $prefix = pathinfo($base, PATHINFO_FILENAME);
            $ext = pathinfo($base, PATHINFO_EXTENSION);
            $pattern = $prefix . '*' . ($ext !== '' ? '.' . $ext : '');

            foreach (glob($dir . '/' . $pattern) ?: [] as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $files[$file] = [
                    'name' => basename($file),
                    'path' => $file,
                    'size' => (int)@filesize($file),
                    'modified_at' => @date('Y-m-d H:i:s', (int)@filemtime($file)) ?: '',
                ];
            }

            if (is_file($configuredPath)) {
                $files[$configuredPath] = [
                    'name' => basename($configuredPath),
                    'path' => $configuredPath,
                    'size' => (int)@filesize($configuredPath),
                    'modified_at' => @date('Y-m-d H:i:s', (int)@filemtime($configuredPath)) ?: '',
                ];
            }
        }

        $rows = array_values($files);
        usort($rows, static fn(array $a, array $b): int => strcmp((string)$b['modified_at'], (string)$a['modified_at']));

        return [$configuredPath, $rows];
    }

    private function readTail(string $path, int $maxLines = 400): string
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return '';
        }
        return implode("\n", array_slice($lines, -$maxLines));
    }

    private function sendDownload(string $path, string $downloadName): void
    {
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"');
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
        exit;
    }
}
