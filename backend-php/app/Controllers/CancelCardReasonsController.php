<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditModel;
use App\Models\CancelCardReasonModel;
use App\Shared\SessionHelper;

require_once __DIR__ . '/../../shared/csrf.php';

final class CancelCardReasonsController extends BaseController
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
        $model = new CancelCardReasonModel($conn);

        $active = ($_GET['active'] ?? '') !== '' ? (string)$_GET['active'] : '';
        $rows = $model->listAll($active);

        $this->render('admin/CancelCardReasonList', [
            'title' => 'Cancel Card Reasons',
            'rows' => $rows,
            'filters' => [
                'active' => $active,
            ],
            '_csrf' => csrf_token(),
        ]);
    }

    public function edit(): void
    {
        require __DIR__ . '/../../config/db.php';
        $model = new CancelCardReasonModel($conn);

        $id = (int)($_GET['id'] ?? 0);
        $row = $id > 0 ? $model->find($id) : null;

        if ($id > 0 && $row === null) {
            $this->flashError('Cancel card reason not found.');
            header('Location: index.php?route=admin/cancel-card-reasons');
            exit;
        }

        $this->render('admin/CancelCardReasonForm', [
            'title' => $id > 0 ? 'Edit Cancel Card Reason' : 'Add Cancel Card Reason',
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
            header('Location: index.php?route=admin/cancel-card-reasons');
            exit;
        }

        require __DIR__ . '/../../config/db.php';
        $model = new CancelCardReasonModel($conn);
        $audit = new AuditModel($conn);

        $id = (int)($_POST['ReasonID'] ?? 0);
        $data = [
            'ReasonLabel' => trim((string)($_POST['ReasonLabel'] ?? '')),
            'SortOrder' => (int)($_POST['SortOrder'] ?? 0),
            'IsActive' => ((string)($_POST['IsActive'] ?? '1') === '1') ? 1 : 0,
        ];

        if ($data['ReasonLabel'] === '') {
            $this->flashError('Reason Label is required.');
            $target = 'index.php?route=admin/cancel-card-reasons-edit';
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
                    $this->flashSuccess('Cancel card reason updated.');
                    $audit->insert([
                        'UserID' => SessionHelper::get('auth.user_id'),
                        'Username' => SessionHelper::get('auth.username', 'guest'),
                        'Action' => 'UPDATE',
                        'Entity' => 'CancelCardReason',
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
                    $this->flashSuccess('Cancel card reason created.');
                    $audit->insert([
                        'UserID' => SessionHelper::get('auth.user_id'),
                        'Username' => SessionHelper::get('auth.username', 'guest'),
                        'Action' => 'CREATE',
                        'Entity' => 'CancelCardReason',
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

        header('Location: index.php?route=admin/cancel-card-reasons');
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
            header('Location: index.php?route=admin/cancel-card-reasons');
            exit;
        }

        require __DIR__ . '/../../config/db.php';
        $model = new CancelCardReasonModel($conn);
        $audit = new AuditModel($conn);

        $id = (int)($_POST['id'] ?? 0);
        $existing = $id > 0 ? $model->find($id) : null;
        if ($id <= 0 || $existing === null) {
            $this->flashError('Cancel card reason not found.');
            header('Location: index.php?route=admin/cancel-card-reasons');
            exit;
        }

        try {
            if ($model->delete($id)) {
                $this->flashSuccess('Cancel card reason deleted.');
                $audit->insert([
                    'UserID' => SessionHelper::get('auth.user_id'),
                    'Username' => SessionHelper::get('auth.username', 'guest'),
                    'Action' => 'DELETE',
                    'Entity' => 'CancelCardReason',
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

        header('Location: index.php?route=admin/cancel-card-reasons');
        exit;
    }
}
