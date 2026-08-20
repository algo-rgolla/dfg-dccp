<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Rbac;
use App\Shared\SessionHelper;

final class LogMaintenanceController extends BaseController
{
    protected array $acl = [
        '*' => ['auth' => true],
    ];

    public function list(): void
    {
        $this->ensureAdminAccess();

        $files = $this->listMatchingFiles($this->getAppLogDir(), 'app-*.log');

        $this->render('logs/AppLogList', [
            'title' => 'Application Logs',
            'files' => $files,
        ]);
    }

    public function view(): void
    {
        $this->ensureAdminAccess();

        $selected = trim((string)($_GET['file'] ?? ''));
        $files = $this->listMatchingFiles($this->getAppLogDir(), 'app-*.log');
        $defaultFile = $files[0]['name'] ?? '';
        $fileName = $selected !== '' ? $selected : $defaultFile;
        $filePath = $this->resolveFileFromDir($this->getAppLogDir(), $fileName);

        $this->render('logs/AppLogView', [
            'title' => 'Application Log',
            'fileName' => $fileName,
            'content' => $filePath !== null ? $this->readTail($filePath) : '',
            'availableFiles' => $files,
            'missing' => $filePath === null,
        ]);
    }

    public function download(): void
    {
        $this->ensureAdminAccess();

        $selected = trim((string)($_GET['file'] ?? ''));
        $files = $this->listMatchingFiles($this->getAppLogDir(), 'app-*.log');
        $defaultFile = $files[0]['name'] ?? '';
        $fileName = $selected !== '' ? $selected : $defaultFile;
        $filePath = $this->resolveFileFromDir($this->getAppLogDir(), $fileName);

        if ($filePath === null) {
            http_response_code(404);
            echo 'Log file not found';
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

    private function getAppLogDir(): string
    {
        return dirname(__DIR__, 2) . '/logs';
    }

    private function listMatchingFiles(string $dir, string $pattern): array
    {
        $rows = [];
        foreach (glob(rtrim($dir, '/\\') . '/' . $pattern) ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $rows[] = [
                'name' => basename($file),
                'size' => (int)@filesize($file),
                'modified_at' => @date('Y-m-d H:i:s', (int)@filemtime($file)) ?: '',
            ];
        }

        usort($rows, static fn(array $a, array $b): int => strcmp((string)$b['name'], (string)$a['name']));
        return $rows;
    }

    private function resolveFileFromDir(string $dir, string $fileName): ?string
    {
        if ($fileName === '' || basename($fileName) !== $fileName) {
            return null;
        }

        $path = rtrim($dir, '/\\') . '/' . $fileName;
        return is_file($path) ? $path : null;
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
