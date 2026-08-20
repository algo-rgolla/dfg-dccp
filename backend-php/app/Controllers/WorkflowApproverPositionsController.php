<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditModel;
use App\Models\WorkflowApproverPositionModel;
use App\Shared\SessionHelper;

require_once __DIR__ . '/../../shared/csrf.php';

final class WorkflowApproverPositionsController extends BaseController
{
    protected array $acl = [
        '*' => ['auth' => true, 'permsAny' => ['WORKFLOW_ADMIN', 'ADMIN_ALL']],
        'list' => ['auth' => true, 'permsAny' => ['WORKFLOW_VIEW', 'WORKFLOW_ADMIN', 'ADMIN_ALL']],
        'edit' => ['auth' => true, 'permsAny' => ['WORKFLOW_ADMIN', 'ADMIN_ALL']],
        'save' => ['auth' => true, 'permsAny' => ['WORKFLOW_ADMIN', 'ADMIN_ALL']],
        'delete' => ['auth' => true, 'permsAny' => ['WORKFLOW_ADMIN', 'ADMIN_ALL']],
    ];

    public function __construct()
    {
        parent::__construct();
    }

    public function list(): void
    {
        require __DIR__ . '/../../config/db.php';
        $model = new WorkflowApproverPositionModel($conn);
        $sessionKey = 'admin.workflow_approver_positions.filters';
        $filterKeys = ['approverType', 'employeeGroup', 'active'];
        $savedFilters = SessionHelper::get($sessionKey);
        $savedFilters = is_array($savedFilters) ? $savedFilters : [];

        if ((string)($_GET['reset'] ?? '') === '1') {
            SessionHelper::forget($sessionKey);
            $savedFilters = [];
        }

        $hasExplicitFilterInput = false;
        foreach ($filterKeys as $key) {
            if (array_key_exists($key, $_GET)) {
                $hasExplicitFilterInput = true;
                break;
            }
        }

        if ($hasExplicitFilterInput) {
            $filters = [
                'approverType' => trim((string)($_GET['approverType'] ?? '')),
                'employeeGroup' => trim((string)($_GET['employeeGroup'] ?? '')),
                'active' => ($_GET['active'] ?? '') !== '' ? (string)$_GET['active'] : '',
            ];
            SessionHelper::set($sessionKey, $filters);
        } else {
            $filters = [
                'approverType' => trim((string)($savedFilters['approverType'] ?? '')),
                'employeeGroup' => trim((string)($savedFilters['employeeGroup'] ?? '')),
                'active' => (string)($savedFilters['active'] ?? ''),
            ];
        }

        $approverType = trim((string)($filters['approverType'] ?? ''));
        $employeeGroup = trim((string)($filters['employeeGroup'] ?? ''));
        $active = (string)($filters['active'] ?? '');

        $rows = $model->listAll(
            $approverType !== '' ? $approverType : null,
            $employeeGroup !== '' ? $employeeGroup : null,
            $active
        );

        $this->render('admin/WorkflowApproverPositionList', [
            'title' => 'Workflow Approver Positions',
            'rows' => $rows,
            'filters' => $filters,
            'approverTypes' => $model->listDistinctApproverTypes(),
            'employeeGroups' => $model->listDistinctEmployeeGroups(),
            '_csrf' => csrf_token(),
        ]);
    }

    public function edit(): void
    {
        require __DIR__ . '/../../config/db.php';
        $model = new WorkflowApproverPositionModel($conn);

        $id = (int)($_GET['id'] ?? 0);
        $row = $id > 0 ? $model->find($id) : null;
        if ($id > 0 && $row === null) {
            $this->flashError('Workflow approver position not found.');
            header('Location: index.php?route=admin/workflow-approver-positions');
            exit;
        }

        $this->render('admin/WorkflowApproverPositionForm', [
            'title' => $id > 0 ? 'Edit Workflow Approver Position' : 'Add Workflow Approver Position',
            'row' => $row,
            '_csrf' => csrf_token(),
        ]);
    }

    public function save(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }
        if (!csrf_check($_POST['_csrf'] ?? '')) {
            $this->flashError('Security check failed.');
            header('Location: index.php?route=admin/workflow-approver-positions');
            exit;
        }

        require __DIR__ . '/../../config/db.php';
        $model = new WorkflowApproverPositionModel($conn);
        $audit = new AuditModel($conn);

        $id = (int)($_POST['ApproverPositionID'] ?? 0);
        $data = [
            'ApproverType' => strtoupper(trim((string)($_POST['ApproverType'] ?? ''))),
            'EmployeeGroup' => trim((string)($_POST['EmployeeGroup'] ?? '')),
            'PositionNumber' => trim((string)($_POST['PositionNumber'] ?? '')),
            'Email' => trim((string)($_POST['Email'] ?? '')),
            'DisplayName' => trim((string)($_POST['DisplayName'] ?? '')),
            'IsActive' => ((string)($_POST['IsActive'] ?? '1') === '1') ? 1 : 0,
        ];

        if ($data['ApproverType'] === '' || $data['PositionNumber'] === '') {
            $this->flashError('Approver Type and Position Number are required.');
            $target = 'index.php?route=admin/workflow-approver-positions-edit';
            if ($id > 0) {
                $target .= '&id=' . urlencode((string)$id);
            }
            header('Location: ' . $target);
            exit;
        }

        try {
            if ($id > 0) {
                $ok = $model->update($id, $data);
                if ($ok) {
                    $this->flashSuccess('Workflow approver position updated.');
                    $audit->insert([
                        'UserID' => SessionHelper::get('auth.user_id'),
                        'Username' => SessionHelper::get('auth.username', 'guest'),
                        'Action' => 'UPDATE',
                        'Entity' => 'WorkflowApproverPosition',
                        'EntityKey' => (string)$id,
                        'IPAddress' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'Details' => $data,
                        'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                        'VersionID' => SessionHelper::get('VersionID'),
                    ]);
                } else {
                    $this->flashError('Save failed: ' . $model->getLastError());
                }
            } else {
                $newId = $model->create($data);
                if ($newId > 0) {
                    $this->flashSuccess('Workflow approver position created.');
                    $audit->insert([
                        'UserID' => SessionHelper::get('auth.user_id'),
                        'Username' => SessionHelper::get('auth.username', 'guest'),
                        'Action' => 'CREATE',
                        'Entity' => 'WorkflowApproverPosition',
                        'EntityKey' => (string)$newId,
                        'IPAddress' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'Details' => $data,
                        'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                        'VersionID' => SessionHelper::get('VersionID'),
                    ]);
                } else {
                    $this->flashError('Create failed: ' . $model->getLastError());
                }
            }
        } catch (\Throwable $e) {
            $this->flashError('Save failed: ' . $e->getMessage());
        }

        header('Location: index.php?route=admin/workflow-approver-positions');
        exit;
    }

    public function delete(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }
        if (!csrf_check($_POST['_csrf'] ?? '')) {
            $this->flashError('Security check failed.');
            header('Location: index.php?route=admin/workflow-approver-positions');
            exit;
        }

        require __DIR__ . '/../../config/db.php';
        $model = new WorkflowApproverPositionModel($conn);
        $audit = new AuditModel($conn);

        $id = (int)($_POST['id'] ?? 0);
        $existing = $id > 0 ? $model->find($id) : null;
        if ($id <= 0 || $existing === null) {
            $this->flashError('Workflow approver position not found.');
            header('Location: index.php?route=admin/workflow-approver-positions');
            exit;
        }

        try {
            if ($model->delete($id)) {
                $this->flashSuccess('Workflow approver position deleted.');
                $audit->insert([
                    'UserID' => SessionHelper::get('auth.user_id'),
                    'Username' => SessionHelper::get('auth.username', 'guest'),
                    'Action' => 'DELETE',
                    'Entity' => 'WorkflowApproverPosition',
                    'EntityKey' => (string)$id,
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

        header('Location: index.php?route=admin/workflow-approver-positions');
        exit;
    }
}
