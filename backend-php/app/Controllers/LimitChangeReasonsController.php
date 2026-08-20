<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditModel;
use App\Models\LimitChangeReasonModel;
use App\Shared\SessionHelper;

require_once __DIR__ . '/../../shared/csrf.php';

final class LimitChangeReasonsController extends BaseController
{
    protected array $acl = [
        '*' => ['auth' => true, 'permsAny' => ['ADMIN_ALL']],
        'list' => ['auth' => true, 'permsAny' => ['ADMIN_ALL']],
        'edit' => ['auth' => true, 'permsAny' => ['ADMIN_ALL']],
        'save' => ['auth' => true, 'permsAny' => ['ADMIN_ALL']],
        'delete' => ['auth' => true, 'permsAny' => ['ADMIN_ALL']],
    ];

    public function list(): void
    {
        require __DIR__ . '/../../config/db.php';
        $model = new LimitChangeReasonModel($conn);

        $applicationTypeId = (int)($_GET['applicationTypeId'] ?? 0);
        $active = ($_GET['active'] ?? '') !== '' ? (string)$_GET['active'] : '';

        $rows = $model->listAll($applicationTypeId > 0 ? $applicationTypeId : null, $active);

        $this->render('admin/LimitChangeReasonList', [
            'title' => 'Limit Change Reasons',
            'rows' => $rows,
            'applicationTypes' => $model->listApplicationTypeOptions(),
            'filters' => [
                'applicationTypeId' => $applicationTypeId,
                'active' => $active,
            ],
            '_csrf' => csrf_token(),
        ]);
    }

    public function edit(): void
    {
        require __DIR__ . '/../../config/db.php';
        $model = new LimitChangeReasonModel($conn);

        $id = (int)($_GET['id'] ?? 0);
        $row = $id > 0 ? $model->find($id) : null;

        if ($id > 0 && $row === null) {
            $this->flashError('Limit change reason not found.');
            header('Location: index.php?route=admin/limit-change-reasons');
            exit;
        }

        $this->render('admin/LimitChangeReasonForm', [
            'title' => $id > 0 ? 'Edit Limit Change Reason' : 'Add Limit Change Reason',
            'row' => $row,
            'applicationTypes' => $model->listApplicationTypeOptions(),
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
            header('Location: index.php?route=admin/limit-change-reasons');
            exit;
        }

        require __DIR__ . '/../../config/db.php';
        $model = new LimitChangeReasonModel($conn);
        $audit = new AuditModel($conn);

        $id = (int)($_POST['ReasonID'] ?? 0);
        $allowedTypeIds = array_map(
            static fn(array $row): int => (int)($row['ApplicationTypeID'] ?? 0),
            $model->listApplicationTypeOptions()
        );
        $data = [
            'ApplicationTypeID' => (int)($_POST['ApplicationTypeID'] ?? 0),
            'ReasonLabel' => trim((string)($_POST['ReasonLabel'] ?? '')),
            'SortOrder' => (int)($_POST['SortOrder'] ?? 0),
            'IsActive' => ((string)($_POST['IsActive'] ?? '1') === '1') ? 1 : 0,
        ];

        if ($data['ApplicationTypeID'] <= 0 || $data['ReasonLabel'] === '') {
            $this->flashError('Application Type and Reason Label are required.');
            $target = 'index.php?route=admin/limit-change-reasons-edit';
            if ($id > 0) {
                $target .= '&id=' . urlencode((string)$id);
            }
            header('Location: ' . $target);
            exit;
        }
        if (!in_array($data['ApplicationTypeID'], $allowedTypeIds, true)) {
            $this->flashError('Selected Application Type is not allowed for Limit Change Reasons.');
            $target = 'index.php?route=admin/limit-change-reasons-edit';
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
                    $this->flashSuccess('Limit change reason updated.');
                    $audit->insert([
                        'UserID' => SessionHelper::get('auth.user_id'),
                        'Username' => SessionHelper::get('auth.username', 'guest'),
                        'Action' => 'UPDATE',
                        'Entity' => 'LimitChangeReason',
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
                    $this->flashSuccess('Limit change reason created.');
                    $audit->insert([
                        'UserID' => SessionHelper::get('auth.user_id'),
                        'Username' => SessionHelper::get('auth.username', 'guest'),
                        'Action' => 'CREATE',
                        'Entity' => 'LimitChangeReason',
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

        header('Location: index.php?route=admin/limit-change-reasons');
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
            header('Location: index.php?route=admin/limit-change-reasons');
            exit;
        }

        require __DIR__ . '/../../config/db.php';
        $model = new LimitChangeReasonModel($conn);
        $audit = new AuditModel($conn);

        $id = (int)($_POST['id'] ?? 0);
        $existing = $id > 0 ? $model->find($id) : null;
        if ($id <= 0 || $existing === null) {
            $this->flashError('Limit change reason not found.');
            header('Location: index.php?route=admin/limit-change-reasons');
            exit;
        }

        try {
            if ($model->delete($id)) {
                $this->flashSuccess('Limit change reason deleted.');
                $audit->insert([
                    'UserID' => SessionHelper::get('auth.user_id'),
                    'Username' => SessionHelper::get('auth.username', 'guest'),
                    'Action' => 'DELETE',
                    'Entity' => 'LimitChangeReason',
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

        header('Location: index.php?route=admin/limit-change-reasons');
        exit;
    }
}
