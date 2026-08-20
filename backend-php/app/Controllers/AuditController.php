<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Shared\SessionHelper;
use App\Models\AuditModel;

final class AuditController extends BaseController
{
    protected bool $requiresContext = true;

    protected array $acl = [
        // Deny everything by default
        '*'    => ['auth' => true, 'permsAny' => ['SYSADMIN']],

        // List action requires AUDIT_VIEW or SYSADMIN
        'list' => ['auth' => true, 'permsAny' => ['AUDIT_VIEW','SYSADMIN']],
    ];

    private AuditModel $model;

    public function __construct()
    {
        parent::__construct(); // ✅ enforce auth + ACL checks

        require __DIR__ . '/../../config/db.php';     // $conn is PDO
        require_once __DIR__ . '/../Models/AuditModel.php';
        $this->model = new AuditModel($conn);
    }

    public function list(): void
    {
        $q            = trim((string)($_GET['q'] ?? ''));
        $entity       = trim((string)($_GET['entity'] ?? ''));
        $userFilter   = trim((string)($_GET['userFilter'] ?? ''));
        $actionFilter = strtoupper(trim((string)($_GET['actionFilter'] ?? '')));
        $startDate    = trim((string)($_GET['startDate'] ?? ''));
        $endDate      = trim((string)($_GET['endDate'] ?? ''));
        $page         = max(1, (int)($_GET['page'] ?? 1));
        $pageSize     = max(1, min(200, (int)($_GET['pageSize'] ?? 25)));

        // Only apply fiscal filters when the session context is a valid positive pair.
        // Production can contain legacy audit rows with NULL/0 context, and some
        // environments may not have an active context configured yet.
        $fy  = (int)(SessionHelper::get('FiscalYearID') ?? 0);
        $ver = (int)(SessionHelper::get('VersionID') ?? 0);
        $fyFilter  = $fy > 0 ? $fy : null;
        $verFilter = $ver > 0 ? $ver : null;

        $res   = $this->model->listLogs(
            $q,
            $entity,
            $userFilter,
            $actionFilter,
            $startDate,
            $endDate,
            $page,
            $pageSize,
            $fyFilter,
            $verFilter
        );

        $rows  = $res['items'] ?? [];
        $total = (int)($res['total'] ?? 0);
        $ents  = $this->model->distinctEntities();

        $flash = SessionHelper::get('flash.message', null);

        $this->render('audit/AuditListView', [
            'title'        => __t('audit_log_title'),
            'rows'         => $rows,
            'total'        => $total,
            'page'         => $page,
            'pageSize'     => $pageSize,
            'q'            => $q,
            'entities'     => $ents,
            'entityFilter' => $entity,
            'userFilter'   => $userFilter,
            'actionFilter' => $actionFilter,
            'startDate'    => $startDate,
            'endDate'      => $endDate,
            'fiscalYearID' => $fyFilter,
            'versionID'    => $verFilter,
            'flash'        => $flash,
        ]);

        if ($flash !== null) {
            SessionHelper::forget('flash.message');
        }
    }
}
