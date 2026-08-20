<?php
declare(strict_types=1);

namespace App\Controllers;

require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../shared/logger.php';

use App\Shared\SessionHelper;
use App\Models\FiscalContextModel;

final class ContextController extends BaseController
{
    protected array $acl = [
        '*'            => ['auth' => true], // must be logged in
        'set'          => ['auth' => true], // POST only
        'listVersions' => ['auth' => true], // AJAX
    ];

    /** Persist FY/Version in session (validates CSRF and membership) */
    public function set(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        if (!csrf_check($_POST['_csrf'] ?? '')) {
            $this->flashError('security_check_failed');
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php?route=home/index'));
            return;
        }

        $fy  = (int)($_POST['FiscalYearID'] ?? 0);
        $ver = (int)($_POST['VersionID']    ?? 0);

        if ($fy <= 0 || $ver <= 0) {
            $this->flashError('invalid_selection');
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php?route=home/index'));
            return;
        }

        // Validate that Version belongs to FY
        require __DIR__ . '/../../config/db.php';
        require_once __DIR__ . '/../Models/FiscalContextModel.php';
        $model = new FiscalContextModel($conn);
        $valid = false;
        foreach ($model->listVersions($fy) as $row) {
            if ((int)$row['VersionID'] === $ver) { $valid = true; break; }
        }
        if (!$valid) {
            app_log('ContextController@set: version not in FY', ['fy' => $fy, 'ver' => $ver], 'warn');
            $this->flashError('invalid_selection');
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php?route=home/index'));
            return;
        }

        // Set canonical keys used across v21
        SessionHelper::set('FiscalYearID', $fy);
        SessionHelper::set('VersionID',    $ver);

        // Back-compat (if older code checks these)
        SessionHelper::set('context.fiscal_year_id', $fy);
        SessionHelper::set('context.version_id',     $ver);

        app_log('Fiscal context updated', ['fy' => $fy, 'ver' => $ver], 'info');
        $this->flashSuccess('context_updated', ['fy' => (string)$fy, 'ver' => (string)$ver]);
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php?route=home/index'));
    }

    /** JSON list of versions for a given FiscalYearID (AJAX) */
    public function listVersions(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $fy = (int)($_GET['FiscalYearID'] ?? 0);
        if ($fy <= 0) {
            echo json_encode([]);
            return;
        }

        require __DIR__ . '/../../config/db.php';
        require_once __DIR__ . '/../Models/FiscalContextModel.php';

        $model = new FiscalContextModel($conn);
        $versions = $model->listVersions($fy);

        echo json_encode($versions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
