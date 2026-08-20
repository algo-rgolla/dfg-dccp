<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditModel;
use App\Models\CAPSEmployeeTypeModel;
use App\Shared\SessionHelper;

require_once __DIR__ . '/../../shared/csrf.php';

final class CAPSEmployeeTypesController extends BaseController
{
    protected array $acl = [
        '*' => ['auth' => true, 'permsAny' => ['ADMIN_ALL']],
        'list' => ['auth' => true, 'permsAny' => ['ADMIN_ALL']],
        'edit' => ['auth' => true, 'permsAny' => ['ADMIN_ALL']],
        'save' => ['auth' => true, 'permsAny' => ['ADMIN_ALL']],
        'delete' => ['auth' => true, 'permsAny' => ['ADMIN_ALL']],
    ];

    public function __construct()
    {
        parent::__construct();
    }

    public function list(): void
    {
        require __DIR__ . '/../../config/db.php';
        $filters = [
            'q' => trim((string)($_GET['q'] ?? '')),
            'employee_type' => trim((string)($_GET['employee_type'] ?? '')),
            'dpc_entitled' => strtoupper(trim((string)($_GET['dpc_entitled'] ?? ''))),
            'dtc_entitled' => strtoupper(trim((string)($_GET['dtc_entitled'] ?? ''))),
        ];

        $perPage = 100;
        $currentPage = max(1, (int)($_GET['page'] ?? 1));

        $rows = [];
        $totalCount = 0;
        if (!($capsConn instanceof \PDO)) {
            $this->flashError('CAPS database connection is unavailable. Please check the CAPS connection settings on this server.');
        } else {
            $model = new CAPSEmployeeTypeModel($capsConn);
            $totalCount = $model->countFiltered($filters);
            $rows = $model->listAll($filters, $currentPage, $perPage);
        }

        $this->render('admin/CAPSEmployeeTypeList', [
            'title' => 'CAPS Employee Types',
            'rows' => $rows,
            'filters' => $filters,
            'currentPage' => $currentPage,
            'perPage' => $perPage,
            'totalCount' => $totalCount,
            'totalPages' => max(1, (int)ceil($totalCount / $perPage)),
            '_csrf' => csrf_token(),
        ]);
    }

    public function edit(): void
    {
        require __DIR__ . '/../../config/db.php';
        if (!($capsConn instanceof \PDO)) {
            $this->flashError('CAPS database connection is unavailable. Please check the CAPS connection settings on this server.');
            header('Location: index.php?route=admin/caps-employee-types');
            exit;
        }

        $model = new CAPSEmployeeTypeModel($capsConn);
        $id = (int)($_GET['id'] ?? 0);
        $row = $id > 0 ? $model->find($id) : null;

        if ($id > 0 && $row === null) {
            $this->flashError('Employee type record not found.');
            header('Location: index.php?route=admin/caps-employee-types');
            exit;
        }

        $this->render('admin/CAPSEmployeeTypeForm', [
            'title' => $id > 0 ? 'Edit CAPS Employee Type' : 'Add CAPS Employee Type',
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
            header('Location: index.php?route=admin/caps-employee-types');
            exit;
        }

        require __DIR__ . '/../../config/db.php';
        if (!($capsConn instanceof \PDO)) {
            $this->flashError('CAPS database connection is unavailable. Please check the CAPS connection settings on this server.');
            header('Location: index.php?route=admin/caps-employee-types');
            exit;
        }

        $model = new CAPSEmployeeTypeModel($capsConn);
        $audit = new AuditModel($conn);

        $id = (int)($_POST['EmployeeTypeID'] ?? 0);
        $data = $this->normaliseInput($_POST);

        try {
            if ($id > 0) {
                $ok = $model->update($id, $data);
                if ($ok) {
                    $this->flashSuccess('Employee type record updated.');
                    $audit->insert([
                        'UserID' => SessionHelper::get('auth.user_id'),
                        'Username' => SessionHelper::get('auth.username', 'guest'),
                        'Action' => 'UPDATE',
                        'Entity' => 'CAPSEmployeeType',
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
                    $this->flashSuccess('Employee type record created.');
                    $audit->insert([
                        'UserID' => SessionHelper::get('auth.user_id'),
                        'Username' => SessionHelper::get('auth.username', 'guest'),
                        'Action' => 'CREATE',
                        'Entity' => 'CAPSEmployeeType',
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

        header('Location: index.php?route=admin/caps-employee-types');
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
            header('Location: index.php?route=admin/caps-employee-types');
            exit;
        }

        require __DIR__ . '/../../config/db.php';
        if (!($capsConn instanceof \PDO)) {
            $this->flashError('CAPS database connection is unavailable. Please check the CAPS connection settings on this server.');
            header('Location: index.php?route=admin/caps-employee-types');
            exit;
        }

        $model = new CAPSEmployeeTypeModel($capsConn);
        $audit = new AuditModel($conn);

        $id = (int)($_POST['id'] ?? 0);
        $existing = $id > 0 ? $model->find($id) : null;
        if ($id <= 0 || $existing === null) {
            $this->flashError('Employee type record not found.');
            header('Location: index.php?route=admin/caps-employee-types');
            exit;
        }

        try {
            if ($model->delete($id)) {
                $this->flashSuccess('Employee type record deleted.');
                $audit->insert([
                    'UserID' => SessionHelper::get('auth.user_id'),
                    'Username' => SessionHelper::get('auth.username', 'guest'),
                    'Action' => 'DELETE',
                    'Entity' => 'CAPSEmployeeType',
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

        header('Location: index.php?route=admin/caps-employee-types');
        exit;
    }

    private function normaliseInput(array $input): array
    {
        return [
            'EmployeeType' => $this->normaliseString($input['EmployeeType'] ?? null),
            'DPCEntitled' => $this->normaliseFlag($input['DPCEntitled'] ?? null),
            'DTCEntitled' => $this->normaliseFlag($input['DTCEntitled'] ?? null),
        ];
    }

    private function normaliseString(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function normaliseFlag(mixed $value): ?string
    {
        $value = strtoupper(trim((string)$value));
        return $value === '' ? null : substr($value, 0, 1);
    }
}
