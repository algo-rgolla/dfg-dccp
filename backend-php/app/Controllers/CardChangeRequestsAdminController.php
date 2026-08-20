<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\CardChangeRequestModel;
use App\Models\AuditModel;
use App\Shared\SessionHelper;

require_once __DIR__ . '/../../shared/csrf.php';

final class CardChangeRequestsAdminController extends BaseController
{
    protected array $acl = [
        '*' => ['auth' => true, 'permsAny' => ['ADMIN_ALL']],
        'list' => ['auth' => true, 'permsAny' => ['ADMIN_ALL']],
        'pendingCancellations' => ['auth' => true, 'permsAny' => ['ADMIN_ALL']],
        'delete' => ['auth' => true, 'permsAny' => ['ADMIN_ALL']],
    ];

    public function __construct()
    {
        parent::__construct();
    }

    public function list(): void
    {
        require __DIR__ . '/../../config/db.php';
        $model = new CardChangeRequestModel($conn);

        $filters = [
            'q' => trim((string)($_GET['q'] ?? '')),
            'employee_id' => trim((string)($_GET['employee_id'] ?? '')),
            'request_type' => trim((string)($_GET['request_type'] ?? '')),
            'status' => trim((string)($_GET['status'] ?? '')),
        ];

        $rows = $model->listAll($filters);

        $this->render('admin/CardChangeRequestList', [
            'title' => 'All Change Requests',
            'rows' => $rows,
            'filters' => $filters,
            'requestTypes' => $model->listDistinctRequestTypes(),
            'statuses' => $model->listDistinctStatuses(),
            '_csrf' => csrf_token(),
        ]);
    }

    public function delete(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }
        $returnRoute = trim((string)($_POST['return_route'] ?? 'admin/change-requests'));
        if ($returnRoute === '') {
            $returnRoute = 'admin/change-requests';
        }
        $returnUrl = 'index.php?route=' . urlencode($returnRoute);

        if (!csrf_check($_POST['_csrf'] ?? '')) {
            $this->flashError('Security check failed.');
            header('Location: ' . $returnUrl);
            exit;
        }

        require __DIR__ . '/../../config/db.php';
        $model = new CardChangeRequestModel($conn);
        $audit = new AuditModel($conn);

        $requestId = (int)($_POST['id'] ?? 0);
        $existing = $requestId > 0 ? $model->find($requestId) : null;
        if ($requestId <= 0 || $existing === null) {
            $this->flashError('Change request not found.');
            header('Location: ' . $returnUrl);
            exit;
        }

        try {
            if ($model->delete($requestId)) {
                $this->flashSuccess('Change request deleted.');
                $audit->insert([
                    'UserID' => SessionHelper::get('auth.user_id'),
                    'Username' => SessionHelper::get('auth.username', 'guest'),
                    'Action' => 'DELETE',
                    'Entity' => 'CardChangeRequest',
                    'EntityKey' => (string)$requestId,
                    'IPAddress' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'Details' => $existing,
                    'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                    'VersionID' => SessionHelper::get('VersionID'),
                ]);
            } else {
                $this->flashError('Delete failed: ' . $model->getLastError());
            }
        } catch (\Throwable $e) {
            $this->flashError('Delete failed: ' . $e->getMessage());
        }

        header('Location: ' . $returnUrl);
        exit;
    }

    public function pendingCancellations(): void
    {
        require __DIR__ . '/../../config/db.php';
        $model = new CardChangeRequestModel($conn);
        $rows = $model->listPendingCancellations();

        $dueCount = 0;
        $scheduledCount = 0;
        foreach ($rows as $row) {
            if (!empty($row['IsDueNow'])) {
                $dueCount++;
            } else {
                $scheduledCount++;
            }
        }

        $this->render('admin/PendingCardCancellations', [
            'title' => 'Pending Card Cancellations',
            'rows' => $rows,
            'dueCount' => $dueCount,
            'scheduledCount' => $scheduledCount,
            '_csrf' => csrf_token(),
        ]);
    }
}
