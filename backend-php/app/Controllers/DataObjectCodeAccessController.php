<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Shared\SessionHelper;
use App\Models\AuditModel;

final class DataObjectCodeAccessController extends BaseController
{
    protected array $acl = [
        '*' => ['auth' => true, 'permsAny' => ['DATAOBJECTCODES_ADMIN']]
    ];

    public function index(): void
    {
        if (!isset($conn) || !($conn instanceof \PDO)) {
            require __DIR__ . '/../../config/db.php';
        }
        require_once __DIR__ . '/../Models/DataObjectCodeAccessModel.php';

        $fy = (int)SessionHelper::get('FiscalYearID', 0);
        $model = new \App\Models\DataObjectCodeAccessModel($conn);
        $access = $model->getAll($fy);

        $this->render('dataobjectcodes/DataObjectCodesAccessList', [
            'title' => 'docodes_access_title',
            'access' => $access,
            'fy' => $fy
        ]);
    }

    public function form(): void
    {
        if (!isset($conn) || !($conn instanceof \PDO)) {
            require __DIR__ . '/../../config/db.php';
        }
        require_once __DIR__ . '/../Models/DataObjectCodeAccessModel.php';

        $fy = (int)SessionHelper::get('FiscalYearID', 0);
        $model = new \App\Models\DataObjectCodeAccessModel($conn);

        $users = $model->getUsers();
        $codes = $model->getCodes($fy);


        $this->render('dataobjectcodes/DataObjectCodesAccessForm', [
            'title' => 'docodes_access_grant',
            'users' => $users,
            'codes' => $codes,
            'fy' => $fy
        ]);
    }

    public function save(): void
    {
        if (!isset($conn) || !($conn instanceof \PDO)) {
            require __DIR__ . '/../../config/db.php';
        }
        require_once __DIR__ . '/../Models/DataObjectCodeAccessModel.php';

        $fy = (int)SessionHelper::get('FiscalYearID', 0);
        if ($fy <= 0) {
            $this->flashError('Invalid fiscal year.');
            header('Location: index.php?route=dataobjectcodes/access');
            return;
        }

        $userId = (int)($_POST['UserID'] ?? 0);
        $code = trim($_POST['DataObjectCode'] ?? '');
        $level = $_POST['AccessLevel'] ?? 'read';
        $assignedBy = (int)SessionHelper::get('auth.user_id', 0);

        $model = new \App\Models\DataObjectCodeAccessModel($conn);
        $auditModel = new AuditModel($conn);  // ADD THIS

        if ($model->grant($userId, $fy, $code, $level, $assignedBy)) {
            $this->flashSuccess('Access granted.');

            // AUDIT LOG: GRANT
            $auditModel->insert([
                'UserID' => $assignedBy,
                'Username' => SessionHelper::get('auth.username'),
                'Action' => 'GRANT_ACCESS',
                'Entity' => 'DATAOBJECTCODE',
                'EntityKey' => $code,
                'Details' => [
                    'user_id' => $userId,
                    'fiscal_year' => $fy,
                    'access_level' => $level
                ],
                'FiscalYearID' => $fy,
                'VersionID' => SessionHelper::get('VersionID')
            ]);

        } else {
            $this->flashError('Failed: ' . $model->getLastError());
        }
        header('Location: index.php?route=dataobjectcodes/access');
    }

    public function revoke(): void
    {
        if (!isset($conn) || !($conn instanceof \PDO)) {
            require __DIR__ . '/../../config/db.php';
        }
        require_once __DIR__ . '/../Models/DataObjectCodeAccessModel.php';

        $parts = explode('_', $_GET['revoke'] ?? '');
        if (count($parts) !== 2) {
            $this->flashError('Invalid request.');
            header('Location: index.php?route=dataobjectcodes/access');
            return;
        }

        [$userId, $code] = $parts;
        $fy = (int)SessionHelper::get('FiscalYearID', 0); // CAST TO int
        if ($fy <= 0) {
            $this->flashError('Invalid fiscal year.');
            header('Location: index.php?route=dataobjectcodes/access');
            return;
        }

        $revokedBy = (int)SessionHelper::get('auth.user_id', 0);

        $model = new \App\Models\DataObjectCodeAccessModel($conn);
        $auditModel = new AuditModel($conn);  // ADD THIS

        if ($model->revoke((int)$userId, $fy, $code, $revokedBy)) {
            $this->flashSuccess('Access revoked.');

            // AUDIT LOG: REVOKE
            $auditModel->insert([
                'UserID' => $revokedBy,
                'Username' => SessionHelper::get('auth.username'),
                'Action' => 'REVOKE_ACCESS',
                'Entity' => 'DATAOBJECTCODE',
                'EntityKey' => $code,
                'Details' => [
                    'user_id' => (int)$userId,
                    'fiscal_year' => $fy
                ],
                'FiscalYearID' => $fy,
                'VersionID' => SessionHelper::get('VersionID')
            ]);

        } else {
            $this->flashError('Failed: ' . $model->getLastError());
        }
        header('Location: index.php?route=dataobjectcodes/access');
    }

   public function userAccessReport(): void
{
    if (!isset($conn) || !($conn instanceof \PDO)) {
        require __DIR__ . '/../../config/db.php';
    }
    require_once __DIR__ . '/../Models/DataObjectCodeAccessModel.php';

    $fy = (int)SessionHelper::get('FiscalYearID', 0);

    $model = new \App\Models\DataObjectCodeAccessModel($conn);

    $user = $model->getUserById($userId);
    if (!$user) {
        echo "<pre style='background:#f8d7da;padding:10px;border:1px solid #f5c6cb;color:#721c24;margin:20px;'>";
        echo "ERROR: User not found!\n";
        echo "UserID: $userId\n";
        echo "</pre>";
        exit;
    }

    $accessibleCodes = $model->getUserAccessibleCodesWithLevel($userId, $fy);
    $directAccess = $model->getDirectAccess($userId, $fy);

    // DEBUG: SHOW DATA IN VIEW
    $debug = [
        'userId' => $userId,
        'fy' => $fy,
        'directCount' => count($directAccess),
        'totalCount' => count($accessibleCodes),
        'sample' => array_slice($accessibleCodes, 0, 3)
    ];


    // RENDER VIEW
    $this->render('dataobjectcodes/DataObjectCodesAccessReport', [
        'title' => 'docodes_user_access_title',
        'user' => $user,
        'fy' => $fy,
        'accessibleCodes' => $accessibleCodes,
        'directAccess' => $directAccess,
        'debug' => $debug  // ADD THIS
    ]);
}
}