<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\SystemSettingsModel;

final class ReleasePackageService
{
    private const MAX_FILES = 500;
    private const MAX_TOTAL_BYTES = 25_000_000;

    private string $projectRoot;
    private string $runtimeRoot;
    private ?\PDO $db;

    public function __construct(?\PDO $db = null, ?string $projectRoot = null, ?string $runtimeRoot = null)
    {
        $this->db = $db;
        $this->projectRoot = $projectRoot !== null
            ? rtrim(str_replace('\\', '/', $projectRoot), '/')
            : rtrim(str_replace('\\', '/', dirname(__DIR__, 3)), '/');

        $configuredRuntime = $this->resolveConfiguredRuntimeRoot();

        $baseRuntime = $runtimeRoot !== null
            ? $runtimeRoot
            : ($configuredRuntime !== ''
                ? $configuredRuntime
                : rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/ccportal_release_packages');

        $this->runtimeRoot = rtrim(str_replace('\\', '/', $baseRuntime), '/');
    }

    public function listPackages(): array
    {
        $dir = $this->stagingRoot();
        if (!is_dir($dir)) {
            return [];
        }

        $items = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $metadataPath = $dir . '/' . $entry . '/metadata.json';
            if (!is_file($metadataPath)) {
                continue;
            }
            $data = json_decode((string)file_get_contents($metadataPath), true);
            if (!is_array($data)) {
                continue;
            }
            $items[] = $data;
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp((string)($b['uploadedAt'] ?? ''), (string)($a['uploadedAt'] ?? ''));
        });

        return $items;
    }

    public function listStagedPackages(): array
    {
        return array_values(array_filter(
            $this->listPackages(),
            static fn(array $package): bool => (string)($package['status'] ?? '') === 'staged'
        ));
    }

    public function listDeploymentHistory(): array
    {
        return array_values(array_filter(
            $this->listPackages(),
            static fn(array $package): bool => in_array((string)($package['status'] ?? ''), ['applied', 'rolled_back'], true)
        ));
    }

    public function loadPackage(string $packageId): ?array
    {
        $packageId = $this->sanitizePackageId($packageId);
        if ($packageId === '') {
            return null;
        }

        $metadataPath = $this->packageDir($packageId) . '/metadata.json';
        if (!is_file($metadataPath)) {
            return null;
        }

        $data = json_decode((string)file_get_contents($metadataPath), true);
        return is_array($data) ? $data : null;
    }

    public function getEnvironmentStatus(): array
    {
        $runtimeRoot = $this->runtimeRoot;
        $projectRoot = $this->projectRoot;

        return [
            'projectRoot' => $projectRoot,
            'runtimeRoot' => $runtimeRoot,
            'folders' => [
                [
                    'label' => 'Release Package Runtime Root',
                    'path' => $runtimeRoot,
                    'notes' => 'Parent location used for staged upload packages and automatic backups.',
                    'children' => [
                        $this->folderStatus($this->stagingRoot(), 'Staging Folder', 'Uploaded ZIPs are unpacked and validated here before apply.'),
                        $this->folderStatus($this->backupRoot(), 'Backup Folder', 'Existing files are copied here before overwrite during apply.'),
                    ],
                ],
                [
                    'label' => 'Approved Deployment Roots',
                    'path' => $projectRoot,
                    'notes' => 'Only files inside these project paths can be applied by the controlled deploy feature.',
                    'children' => [
                        $this->folderStatus($projectRoot . '/backend-php/app', 'backend-php/app', 'Controllers, models, services, and views.'),
                        $this->folderStatus($projectRoot . '/backend-php/config', 'backend-php/config', 'Routes, menu, and configuration files.'),
                        $this->folderStatus($projectRoot . '/backend-php/shared', 'backend-php/shared', 'Shared helpers and common utilities.'),
                        $this->folderStatus($projectRoot . '/backend-php/lang', 'backend-php/lang', 'Language files.'),
                        $this->folderStatus($projectRoot . '/backend-php/public', 'backend-php/public', 'Public assets and entry-point-adjacent files allowed by the deployer.'),
                    ],
                ],
            ],
        ];
    }

    public function stageUploadedFile(array $uploadedFile, int $userId, string $username): string
    {
        if ($this->listStagedPackages() !== []) {
            throw new \RuntimeException('A staged package already exists. Apply or delete the current staged package before uploading another.');
        }

        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive is not available on this server.');
        }
        if (($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Package upload failed.');
        }

        $originalName = trim((string)($uploadedFile['name'] ?? 'package.zip'));
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'zip') {
            throw new \RuntimeException('Only .zip release packages are supported.');
        }

        $packageId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $packageDir = $this->packageDir($packageId);
        $filesDir = $packageDir . '/files';
        $this->ensureDirectory($filesDir);

        $zipPath = $packageDir . '/package.zip';
        if (!move_uploaded_file((string)$uploadedFile['tmp_name'], $zipPath)) {
            throw new \RuntimeException('Unable to move the uploaded package into staging.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Unable to open uploaded ZIP package.');
        }

        $files = [];
        $manifestText = '';
        $deployCount = 0;
        $rejectedCount = 0;
        $ignoredCount = 0;
        $totalBytes = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }

            $entryName = (string)($stat['name'] ?? '');
            $entrySize = (int)($stat['size'] ?? 0);
            if ($entryName === '' || str_ends_with($entryName, '/')) {
                continue;
            }

            $trimmedEntryName = trim(str_replace('\\', '/', $entryName), '/');
            if ($entrySize === 0 && $trimmedEntryName !== '' && pathinfo($trimmedEntryName, PATHINFO_EXTENSION) === '') {
                $files[] = [
                    'entryName' => $entryName,
                    'relativePath' => '',
                    'status' => 'ignored',
                    'reason' => 'Folder structure entry.',
                    'size' => $entrySize,
                    'lint' => null,
                ];
                $ignoredCount++;
                continue;
            }

            $totalBytes += max(0, $entrySize);
            if (count($files) >= self::MAX_FILES) {
                throw new \RuntimeException('Package contains too many files.');
            }
            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                throw new \RuntimeException('Package exceeds the maximum allowed size of 25 MB.');
            }

            $classification = $this->classifyEntry($entryName);
            $record = [
                'entryName' => $entryName,
                'relativePath' => $classification['relativePath'],
                'status' => $classification['status'],
                'reason' => $classification['reason'],
                'size' => $entrySize,
                'lint' => null,
            ];

            if ($classification['status'] === 'ignored') {
                $ignoredCount++;
                if ($manifestText === '' && strcasecmp(basename(str_replace('\\', '/', $entryName)), 'MANIFEST.txt') === 0) {
                    $manifestText = (string)$zip->getFromIndex($i);
                }
                $files[] = $record;
                continue;
            }

            if ($classification['status'] === 'rejected') {
                $rejectedCount++;
                $files[] = $record;
                continue;
            }

            $stream = $zip->getStream($entryName);
            if (!is_resource($stream)) {
                $record['status'] = 'rejected';
                $record['reason'] = 'Could not read ZIP entry.';
                $rejectedCount++;
                $files[] = $record;
                continue;
            }

            $targetPath = $filesDir . '/' . $classification['relativePath'];
            $this->ensureDirectory(dirname($targetPath));
            $out = fopen($targetPath, 'wb');
            if (!is_resource($out)) {
                fclose($stream);
                throw new \RuntimeException('Unable to create staging file: ' . $classification['relativePath']);
            }
            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);

            if (strtolower(pathinfo($targetPath, PATHINFO_EXTENSION)) === 'php') {
                $record['lint'] = $this->lintPhpFile($targetPath);
            }

            $deployCount++;
            $files[] = $record;
        }

        $zip->close();

        $hasLintErrors = false;
        foreach ($files as $file) {
            if (($file['status'] ?? '') === 'deploy' && (($file['lint']['status'] ?? '') === 'error')) {
                $hasLintErrors = true;
                break;
            }
        }

        $metadata = [
            'packageId' => $packageId,
            'originalName' => $originalName,
            'uploadedAt' => gmdate('Y-m-d H:i:s'),
            'uploadedByUserId' => $userId,
            'uploadedByUsername' => $username,
            'status' => 'staged',
            'applyReady' => $deployCount > 0 && $rejectedCount === 0 && !$hasLintErrors,
            'summary' => [
                'deployCount' => $deployCount,
                'ignoredCount' => $ignoredCount,
                'rejectedCount' => $rejectedCount,
                'totalBytes' => $totalBytes,
            ],
            'manifestText' => $manifestText,
            'files' => $files,
            'backupPath' => null,
            'appliedAt' => null,
            'appliedByUserId' => null,
            'appliedByUsername' => null,
        ];

        $this->saveMetadata($packageId, $metadata);
        return $packageId;
    }

    public function applyPackage(string $packageId, int $userId, string $username): array
    {
        $package = $this->loadPackage($packageId);
        if (!$package) {
            throw new \RuntimeException('Release package not found.');
        }
        if (empty($package['applyReady'])) {
            throw new \RuntimeException('This package is not ready to apply. Resolve rejected files or lint errors first.');
        }

        $backupId = $packageId . '_' . date('Ymd_His');
        $backupRoot = $this->backupRoot() . '/' . $backupId;
        $filesDir = $this->packageDir($packageId) . '/files';
        $applied = [];

        foreach (($package['files'] ?? []) as $file) {
            if (($file['status'] ?? '') !== 'deploy') {
                continue;
            }

            $relativePath = (string)($file['relativePath'] ?? '');
            if ($relativePath === '') {
                continue;
            }

            $sourcePath = $filesDir . '/' . $relativePath;
            if (!is_file($sourcePath)) {
                throw new \RuntimeException('Staged file missing: ' . $relativePath);
            }

            $targetPath = $this->projectRoot . '/' . $relativePath;
            $this->assertTargetWithinProject($targetPath);

            if (is_file($targetPath)) {
                $backupPath = $backupRoot . '/' . $relativePath;
                $this->ensureDirectory(dirname($backupPath));
                if (!copy($targetPath, $backupPath)) {
                    throw new \RuntimeException('Unable to back up existing file: ' . $relativePath);
                }
            }

            $this->ensureDirectory(dirname($targetPath));
            if (!copy($sourcePath, $targetPath)) {
                throw new \RuntimeException('Unable to deploy file: ' . $relativePath);
            }

            $applied[] = [
                'relativePath' => $relativePath,
                'targetPath' => $targetPath,
                'backedUp' => is_file($backupRoot . '/' . $relativePath),
            ];
        }

        $package['status'] = 'applied';
        $package['backupPath'] = $backupRoot;
        $package['appliedAt'] = gmdate('Y-m-d H:i:s');
        $package['appliedByUserId'] = $userId;
        $package['appliedByUsername'] = $username;
        $package['appliedFiles'] = $applied;
        $this->saveMetadata($packageId, $package);

        return [
            'backupPath' => $backupRoot,
            'appliedFiles' => $applied,
        ];
    }

    public function rollbackPackage(string $packageId, int $userId, string $username): array
    {
        $package = $this->loadPackage($packageId);
        if (!$package) {
            throw new \RuntimeException('Release package not found.');
        }

        $status = (string)($package['status'] ?? '');
        if ($status !== 'applied') {
            throw new \RuntimeException('Only applied packages can be rolled back.');
        }

        $appliedFiles = is_array($package['appliedFiles'] ?? null) ? $package['appliedFiles'] : [];
        if ($appliedFiles === []) {
            throw new \RuntimeException('No applied file list was recorded for this package.');
        }

        $backupRoot = trim((string)($package['backupPath'] ?? ''));
        $requiresBackupFolder = false;
        foreach ($appliedFiles as $file) {
            if (!empty($file['backedUp'])) {
                $requiresBackupFolder = true;
                break;
            }
        }
        if ($requiresBackupFolder && ($backupRoot === '' || !is_dir($backupRoot))) {
            throw new \RuntimeException('Rollback backup folder was not found for this package.');
        }

        $restored = [];
        foreach ($appliedFiles as $file) {
            $relativePath = trim((string)($file['relativePath'] ?? ''));
            if ($relativePath === '') {
                continue;
            }

            $targetPath = $this->projectRoot . '/' . $relativePath;
            $this->assertTargetWithinProject($targetPath);

            $backupPath = $backupRoot . '/' . $relativePath;
            $backedUp = !empty($file['backedUp']);

            if ($backedUp) {
                if (!is_file($backupPath)) {
                    throw new \RuntimeException('Rollback backup file missing: ' . $relativePath);
                }
                $this->ensureDirectory(dirname($targetPath));
                if (!copy($backupPath, $targetPath)) {
                    throw new \RuntimeException('Unable to restore backup file: ' . $relativePath);
                }
                $restored[] = [
                    'relativePath' => $relativePath,
                    'action' => 'restored',
                ];
                continue;
            }

            if (is_file($targetPath) && !unlink($targetPath)) {
                throw new \RuntimeException('Unable to remove deployed file during rollback: ' . $relativePath);
            }
            $restored[] = [
                'relativePath' => $relativePath,
                'action' => 'removed',
            ];
        }

        $package['status'] = 'rolled_back';
        $package['rolledBackAt'] = gmdate('Y-m-d H:i:s');
        $package['rolledBackByUserId'] = $userId;
        $package['rolledBackByUsername'] = $username;
        $package['rollbackFiles'] = $restored;
        $this->saveMetadata($packageId, $package);

        return [
            'backupPath' => $backupRoot,
            'rollbackFiles' => $restored,
        ];
    }

    public function deletePackage(string $packageId): void
    {
        $packageId = $this->sanitizePackageId($packageId);
        if ($packageId === '') {
            throw new \RuntimeException('Invalid release package id.');
        }

        $packageDir = $this->packageDir($packageId);
        if (!is_dir($packageDir)) {
            throw new \RuntimeException('Release package not found.');
        }

        $this->deleteDirectory($packageDir);
    }

    private function classifyEntry(string $entryName): array
    {
        $normalized = str_replace('\\', '/', trim($entryName));
        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;

        if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1) {
            return [
                'status' => 'rejected',
                'relativePath' => '',
                'reason' => 'Unsafe path detected.',
            ];
        }

        $backendPos = stripos($normalized, 'backend-php/');
        if ($backendPos === false) {
            return [
                'status' => 'ignored',
                'relativePath' => '',
                'reason' => 'Non-deploy support file.',
            ];
        }

        $relativePath = substr($normalized, $backendPos);
        $relativePath = ltrim($relativePath, '/');
        if (!$this->isAllowedDeployPath($relativePath, $reason)) {
            return [
                'status' => 'rejected',
                'relativePath' => $relativePath,
                'reason' => $reason,
            ];
        }

        return [
            'status' => 'deploy',
            'relativePath' => $relativePath,
            'reason' => '',
        ];
    }

    private function isAllowedDeployPath(string $relativePath, ?string &$reason = null): bool
    {
        $path = str_replace('\\', '/', trim($relativePath));
        $blockedPrefixes = [
            'backend-php/.env',
            'backend-php/logs/',
            'backend-php/node_modules/',
            'vendor/',
            'transfer/',
            '.git/',
        ];
        foreach ($blockedPrefixes as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
                $reason = 'Path is blocked from deployment.';
                return false;
            }
        }

        $allowedPrefixes = [
            'backend-php/app/',
            'backend-php/config/',
            'backend-php/shared/',
            'backend-php/lang/',
            'backend-php/public/',
        ];
        $allowed = false;
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            $reason = 'Path is outside the approved deployment roots.';
            return false;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $allowedExtensions = ['php', 'sql', 'css', 'js', 'json', 'txt', 'md', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico'];
        if ($ext === '' || !in_array($ext, $allowedExtensions, true)) {
            $reason = 'File type is not permitted for controlled deployment.';
            return false;
        }

        $reason = null;
        return true;
    }

    private function lintPhpFile(string $filePath): array
    {
        if (!defined('PHP_BINARY') || !is_file(PHP_BINARY) || !function_exists('proc_open')) {
            return ['status' => 'skipped', 'message' => 'PHP lint not available on this server.'];
        }

        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open([PHP_BINARY, '-l', $filePath], $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            return ['status' => 'skipped', 'message' => 'PHP lint process could not be started.'];
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode === 0) {
            return ['status' => 'ok', 'message' => trim($stdout) !== '' ? trim($stdout) : 'No syntax errors detected.'];
        }

        $message = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
        return ['status' => 'error', 'message' => $message !== '' ? $message : 'PHP lint failed.'];
    }

    private function saveMetadata(string $packageId, array $metadata): void
    {
        $metadataPath = $this->packageDir($packageId) . '/metadata.json';
        $this->ensureDirectory(dirname($metadataPath));
        file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function sanitizePackageId(string $packageId): string
    {
        return preg_match('/^[A-Za-z0-9_.-]+$/', $packageId) === 1 ? $packageId : '';
    }

    private function packageDir(string $packageId): string
    {
        $packageId = $this->sanitizePackageId($packageId);
        return $this->stagingRoot() . '/' . $packageId;
    }

    private function stagingRoot(): string
    {
        return $this->runtimeRoot . '/staging';
    }

    private function backupRoot(): string
    {
        return $this->runtimeRoot . '/backups';
    }

    private function ensureDirectory(string $path): void
    {
        if ($path === '') {
            return;
        }
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException('Unable to create directory: ' . $path);
        }
    }

    private function assertTargetWithinProject(string $path): void
    {
        $normalized = str_replace('\\', '/', $path);
        if (!str_starts_with($normalized, $this->projectRoot . '/')) {
            throw new \RuntimeException('Refusing to deploy outside the project root.');
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            throw new \RuntimeException('Unable to read package directory for deletion.');
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . '/' . $item;
            if (is_dir($child)) {
                $this->deleteDirectory($child);
                continue;
            }

            if (!unlink($child)) {
                throw new \RuntimeException('Unable to delete package file: ' . $child);
            }
        }

        if (!rmdir($path)) {
            throw new \RuntimeException('Unable to delete package directory: ' . $path);
        }
    }

    private function resolveConfiguredRuntimeRoot(): string
    {
        if ($this->db instanceof \PDO) {
            try {
                $settings = new SystemSettingsModel($this->db);
                $configured = trim((string)($settings->get('RELEASE_PACKAGE_RUNTIME_ROOT') ?? ''));
                if ($configured !== '') {
                    return $configured;
                }
            } catch (\Throwable $e) {
            }
        }

        if (function_exists('envStr')) {
            return trim((string)envStr('RELEASE_PACKAGE_RUNTIME_ROOT', ''));
        }

        $raw = getenv('RELEASE_PACKAGE_RUNTIME_ROOT');
        return $raw !== false ? trim((string)$raw) : '';
    }

    private function folderStatus(string $path, string $label, string $notes = ''): array
    {
        $normalized = str_replace('\\', '/', $path);
        $exists = is_dir($normalized);

        return [
            'label' => $label,
            'path' => $normalized,
            'exists' => $exists,
            'readable' => $exists ? is_readable($normalized) : false,
            'writable' => $exists ? is_writable($normalized) : false,
            'notes' => $notes,
        ];
    }
}
