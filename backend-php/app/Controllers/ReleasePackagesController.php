<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Rbac;
use App\Models\AuditModel;
use App\Services\ReleasePackageService;
use App\Shared\SessionHelper;

require_once __DIR__ . '/../../shared/csrf.php';

final class ReleasePackagesController extends BaseController
{
    protected array $acl = [
        '*' => ['auth' => true, 'permsAny' => ['DEPLOY_PACKAGES']],
        'index' => ['auth' => true, 'permsAny' => ['DEPLOY_PACKAGES']],
        'history' => ['auth' => true, 'permsAny' => ['DEPLOY_PACKAGES']],
        'upload' => ['auth' => true, 'permsAny' => ['DEPLOY_PACKAGES']],
        'apply' => ['auth' => true, 'permsAny' => ['DEPLOY_PACKAGES']],
        'delete' => ['auth' => true, 'permsAny' => ['DEPLOY_PACKAGES']],
        'rollback' => ['auth' => true, 'permsAny' => ['DEPLOY_PACKAGES']],
    ];

    public function index(): void
    {
        $this->assertDeployAccess();

        $service = new ReleasePackageService($this->db instanceof \PDO ? $this->db : null);
        $packages = $service->listStagedPackages();
        $environmentStatus = $service->getEnvironmentStatus();
        $applyPasswordConfigured = $this->isApplyPasswordConfigured();
        $selectedId = trim((string)($_GET['package'] ?? ''));
        $selectedPackage = $selectedId !== '' ? $service->loadPackage($selectedId) : null;
        if ($selectedPackage !== null && (string)($selectedPackage['status'] ?? '') !== 'staged') {
            $selectedPackage = null;
        }
        if ($selectedPackage === null && $packages !== []) {
            $selectedPackage = $packages[0];
            $selectedId = (string)($selectedPackage['packageId'] ?? '');
        }

        $flash = SessionHelper::get('flash.message', null);
        if ($flash !== null) {
            SessionHelper::forget('flash.message');
        }

        $this->render('admin/ReleasePackages', [
            'title' => 'Release Packages',
            'packages' => $packages,
            'selectedPackage' => $selectedPackage,
            'selectedPackageId' => $selectedId,
            'environmentStatus' => $environmentStatus,
            'applyPasswordConfigured' => $applyPasswordConfigured,
            'flash' => $flash,
            '_csrf' => csrf_token(),
        ]);
    }

    public function history(): void
    {
        $this->assertDeployAccess();

        $service = new ReleasePackageService($this->db instanceof \PDO ? $this->db : null);
        $packages = $service->listDeploymentHistory();
        $selectedId = trim((string)($_GET['package'] ?? ''));
        $selectedPackage = $selectedId !== '' ? $service->loadPackage($selectedId) : null;
        if ($selectedPackage !== null && !in_array((string)($selectedPackage['status'] ?? ''), ['applied', 'rolled_back'], true)) {
            $selectedPackage = null;
        }
        if ($selectedPackage === null && $packages !== []) {
            $selectedPackage = $packages[0];
            $selectedId = (string)($selectedPackage['packageId'] ?? '');
        }

        $flash = SessionHelper::get('flash.message', null);
        if ($flash !== null) {
            SessionHelper::forget('flash.message');
        }

        $this->render('admin/ReleasePackageHistory', [
            'title' => 'Deployment History',
            'packages' => $packages,
            'selectedPackage' => $selectedPackage,
            'selectedPackageId' => $selectedId,
            'applyPasswordConfigured' => $this->isApplyPasswordConfigured(),
            'flash' => $flash,
            '_csrf' => csrf_token(),
        ]);
    }

    public function upload(): void
    {
        $this->assertDeployAccess();

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        if (!csrf_check($_POST['_csrf'] ?? '')) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Security check failed.']);
            header('Location: index.php?route=admin/release-packages');
            exit;
        }

        if (!isset($_FILES['packageFile'])) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'No package file was uploaded.']);
            header('Location: index.php?route=admin/release-packages');
            exit;
        }

        $service = new ReleasePackageService($this->db instanceof \PDO ? $this->db : null);
        try {
            $packageId = $service->stageUploadedFile(
                $_FILES['packageFile'],
                (int)SessionHelper::get('auth.user_id', 0),
                (string)SessionHelper::get('auth.username', 'unknown')
            );

            $package = $service->loadPackage($packageId);
            $summary = is_array($package['summary'] ?? null) ? $package['summary'] : [];
            $this->audit(
                'UPLOAD_RELEASE_PACKAGE',
                'ReleasePackage',
                $packageId,
                [
                    'original_name' => (string)($package['originalName'] ?? ''),
                    'deploy_count' => (int)($summary['deployCount'] ?? 0),
                    'ignored_count' => (int)($summary['ignoredCount'] ?? 0),
                    'rejected_count' => (int)($summary['rejectedCount'] ?? 0),
                    'apply_ready' => !empty($package['applyReady']),
                ]
            );

            SessionHelper::set('flash.message', [
                'type' => !empty($package['applyReady']) ? 'success' : 'warning',
                'text' => !empty($package['applyReady'])
                    ? 'Release package uploaded and staged successfully.'
                    : 'Release package uploaded, but it contains rejected files or lint errors. Review it before apply.',
            ]);
            header('Location: index.php?route=admin/release-packages&package=' . urlencode($packageId));
            exit;
        } catch (\Throwable $e) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Release package upload failed: ' . $e->getMessage()]);
            header('Location: index.php?route=admin/release-packages');
            exit;
        }
    }

    public function apply(): void
    {
        $this->assertDeployAccess();

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        if (!csrf_check($_POST['_csrf'] ?? '')) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Security check failed.']);
            header('Location: index.php?route=admin/release-packages');
            exit;
        }

        $packageId = trim((string)($_POST['package_id'] ?? ''));
        if ($packageId === '') {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'No release package was selected.']);
            header('Location: index.php?route=admin/release-packages');
            exit;
        }

        $applyPassword = (string)($_POST['apply_password'] ?? '');
        if (!$this->validateApplyPassword($applyPassword)) {
            SessionHelper::set('flash.message', [
                'type' => 'danger',
                'text' => $this->isApplyPasswordConfigured()
                    ? 'Release package apply password is invalid.'
                    : 'Release package apply password is not configured on this server.',
            ]);
            header('Location: index.php?route=admin/release-packages&package=' . urlencode($packageId));
            exit;
        }

        $service = new ReleasePackageService($this->db instanceof \PDO ? $this->db : null);
        try {
            $result = $service->applyPackage(
                $packageId,
                (int)SessionHelper::get('auth.user_id', 0),
                (string)SessionHelper::get('auth.username', 'unknown')
            );

            $appliedFiles = is_array($result['appliedFiles'] ?? null) ? $result['appliedFiles'] : [];
            $this->audit(
                'APPLY_RELEASE_PACKAGE',
                'ReleasePackage',
                $packageId,
                [
                    'backup_path' => (string)($result['backupPath'] ?? ''),
                    'applied_count' => count($appliedFiles),
                ]
            );

            SessionHelper::set('flash.message', [
                'type' => 'success',
                'text' => 'Release package applied successfully. Files updated: ' . count($appliedFiles),
            ]);
            header('Location: index.php?route=admin/release-packages&package=' . urlencode($packageId));
            exit;
        } catch (\Throwable $e) {
            SessionHelper::set('flash.message', [
                'type' => 'danger',
                'text' => 'Release package apply failed: ' . $e->getMessage(),
            ]);
            header('Location: index.php?route=admin/release-packages&package=' . urlencode($packageId));
            exit;
        }
    }

    public function delete(): void
    {
        $this->assertDeployAccess();

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        if (!csrf_check($_POST['_csrf'] ?? '')) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Security check failed.']);
            header('Location: index.php?route=admin/release-packages');
            exit;
        }

        $packageId = trim((string)($_POST['package_id'] ?? ''));
        if ($packageId === '') {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'No release package was selected.']);
            header('Location: index.php?route=admin/release-packages');
            exit;
        }

        $service = new ReleasePackageService($this->db instanceof \PDO ? $this->db : null);
        try {
            $package = $service->loadPackage($packageId);
            $service->deletePackage($packageId);

            $this->audit(
                'DELETE_RELEASE_PACKAGE',
                'ReleasePackage',
                $packageId,
                [
                    'original_name' => (string)($package['originalName'] ?? ''),
                ]
            );

            SessionHelper::set('flash.message', [
                'type' => 'success',
                'text' => 'Release package deleted successfully.',
            ]);
            header('Location: index.php?route=admin/release-packages');
            exit;
        } catch (\Throwable $e) {
            SessionHelper::set('flash.message', [
                'type' => 'danger',
                'text' => 'Release package delete failed: ' . $e->getMessage(),
            ]);
            header('Location: index.php?route=admin/release-packages&package=' . urlencode($packageId));
            exit;
        }
    }

    public function rollback(): void
    {
        $this->assertDeployAccess();

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        if (!csrf_check($_POST['_csrf'] ?? '')) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Security check failed.']);
            header('Location: index.php?route=admin/release-packages');
            exit;
        }

        $packageId = trim((string)($_POST['package_id'] ?? ''));
        if ($packageId === '') {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'No release package was selected.']);
            header('Location: index.php?route=admin/release-packages');
            exit;
        }

        $applyPassword = (string)($_POST['apply_password'] ?? '');
        if (!$this->validateApplyPassword($applyPassword)) {
            SessionHelper::set('flash.message', [
                'type' => 'danger',
                'text' => $this->isApplyPasswordConfigured()
                    ? 'Release package apply password is invalid.'
                    : 'Release package apply password is not configured on this server.',
            ]);
            header('Location: index.php?route=admin/release-packages&package=' . urlencode($packageId));
            exit;
        }

        $service = new ReleasePackageService($this->db instanceof \PDO ? $this->db : null);
        try {
            $result = $service->rollbackPackage(
                $packageId,
                (int)SessionHelper::get('auth.user_id', 0),
                (string)SessionHelper::get('auth.username', 'unknown')
            );

            $rollbackFiles = is_array($result['rollbackFiles'] ?? null) ? $result['rollbackFiles'] : [];
            $this->audit(
                'ROLLBACK_RELEASE_PACKAGE',
                'ReleasePackage',
                $packageId,
                [
                    'backup_path' => (string)($result['backupPath'] ?? ''),
                    'rollback_count' => count($rollbackFiles),
                ]
            );

            SessionHelper::set('flash.message', [
                'type' => 'success',
                'text' => 'Release package rolled back successfully. Files restored: ' . count($rollbackFiles),
            ]);
            header('Location: index.php?route=admin/release-packages&package=' . urlencode($packageId));
            exit;
        } catch (\Throwable $e) {
            SessionHelper::set('flash.message', [
                'type' => 'danger',
                'text' => 'Release package rollback failed: ' . $e->getMessage(),
            ]);
            header('Location: index.php?route=admin/release-packages&package=' . urlencode($packageId));
            exit;
        }
    }

    private function assertDeployAccess(): void
    {
        if (Rbac::can('DEPLOY_PACKAGES')) {
            return;
        }

        SessionHelper::set('flash.message', [
            'type' => 'danger',
            'text' => 'You do not have the Release Deployer permission required to use this screen.',
        ]);
        header('Location: index.php?route=home/index');
        exit;
    }

    private function isApplyPasswordConfigured(): bool
    {
        $plain = trim((string)(function_exists('envStr') ? envStr('RELEASE_PACKAGE_APPLY_PASSWORD', '') : getenv('RELEASE_PACKAGE_APPLY_PASSWORD')));
        $hash = trim((string)(function_exists('envStr') ? envStr('RELEASE_PACKAGE_APPLY_PASSWORD_HASH', '') : getenv('RELEASE_PACKAGE_APPLY_PASSWORD_HASH')));
        return $plain !== '' || $hash !== '';
    }

    private function validateApplyPassword(string $candidate): bool
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return false;
        }

        $plain = trim((string)(function_exists('envStr') ? envStr('RELEASE_PACKAGE_APPLY_PASSWORD', '') : getenv('RELEASE_PACKAGE_APPLY_PASSWORD')));
        if ($plain !== '') {
            return hash_equals($plain, $candidate);
        }

        $hash = trim((string)(function_exists('envStr') ? envStr('RELEASE_PACKAGE_APPLY_PASSWORD_HASH', '') : getenv('RELEASE_PACKAGE_APPLY_PASSWORD_HASH')));
        if ($hash === '') {
            return false;
        }

        return password_verify($candidate, $hash);
    }

    private function audit(string $action, string $entity, ?string $entityKey, array $details): void
    {
        if (!($this->db instanceof \PDO)) {
            return;
        }

        try {
            $audit = new AuditModel($this->db);
            $audit->insert([
                'UserID' => SessionHelper::get('auth.user_id'),
                'Username' => SessionHelper::get('auth.username', 'guest'),
                'Action' => $action,
                'Entity' => $entity,
                'EntityKey' => $entityKey,
                'IPAddress' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'Details' => $details,
                'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                'VersionID' => SessionHelper::get('VersionID'),
            ]);
        } catch (\Throwable $e) {
            error_log('[ReleasePackagesController::audit] ' . $e->getMessage());
        }
    }
}
