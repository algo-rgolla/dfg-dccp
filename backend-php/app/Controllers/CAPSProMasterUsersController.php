<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditModel;
use App\Models\CAPSProMasterUserModel;
use App\Shared\SessionHelper;

require_once __DIR__ . '/../../shared/csrf.php';

final class CAPSProMasterUsersController extends BaseController
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
            'active_indicator' => strtoupper(trim((string)($_GET['active_indicator'] ?? ''))),
            'locked' => strtoupper(trim((string)($_GET['locked'] ?? ''))),
            'contractor_ind' => strtoupper(trim((string)($_GET['contractor_ind'] ?? ''))),
        ];

        $perPage = 100;
        $currentPage = max(1, (int)($_GET['page'] ?? 1));

        $rows = [];
        $totalCount = 0;
        if (!($capsConn instanceof \PDO)) {
            $this->flashError('CAPS database connection is unavailable. Please check the CAPS connection settings on this server.');
        } else {
            $model = new CAPSProMasterUserModel($capsConn);
            $totalCount = $model->countFiltered($filters);
            $rows = $model->listAll($filters, $currentPage, $perPage);
        }

        $this->render('admin/CAPSProMasterUserList', [
            'title' => 'CAPS ProMaster Users',
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
            header('Location: index.php?route=admin/caps-promaster-users');
            exit;
        }

        $model = new CAPSProMasterUserModel($capsConn);

        $id = (int)($_GET['id'] ?? 0);
        $row = $id > 0 ? $model->find($id) : null;

        if ($id > 0 && $row === null) {
            $this->flashError('ProMaster user not found.');
            header('Location: index.php?route=admin/caps-promaster-users');
            exit;
        }

        $this->render('admin/CAPSProMasterUserForm', [
            'title' => $id > 0 ? 'Edit CAPS ProMaster User' : 'Add CAPS ProMaster User',
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
            header('Location: index.php?route=admin/caps-promaster-users');
            exit;
        }

        require __DIR__ . '/../../config/db.php';
        if (!($capsConn instanceof \PDO)) {
            $this->flashError('CAPS database connection is unavailable. Please check the CAPS connection settings on this server.');
            header('Location: index.php?route=admin/caps-promaster-users');
            exit;
        }

        $model = new CAPSProMasterUserModel($capsConn);
        $audit = new AuditModel($conn);

        $id = (int)($_POST['ProMasterUserID'] ?? 0);
        $data = $this->normaliseInput($_POST);

        try {
            if ($id > 0) {
                $ok = $model->update($id, $data);
                if ($ok) {
                    $this->flashSuccess('ProMaster user updated.');
                    $audit->insert([
                        'UserID' => SessionHelper::get('auth.user_id'),
                        'Username' => SessionHelper::get('auth.username', 'guest'),
                        'Action' => 'UPDATE',
                        'Entity' => 'CAPSProMasterUser',
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
                    $this->flashSuccess('ProMaster user created.');
                    $audit->insert([
                        'UserID' => SessionHelper::get('auth.user_id'),
                        'Username' => SessionHelper::get('auth.username', 'guest'),
                        'Action' => 'CREATE',
                        'Entity' => 'CAPSProMasterUser',
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

        header('Location: index.php?route=admin/caps-promaster-users');
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
            header('Location: index.php?route=admin/caps-promaster-users');
            exit;
        }

        require __DIR__ . '/../../config/db.php';
        if (!($capsConn instanceof \PDO)) {
            $this->flashError('CAPS database connection is unavailable. Please check the CAPS connection settings on this server.');
            header('Location: index.php?route=admin/caps-promaster-users');
            exit;
        }

        $model = new CAPSProMasterUserModel($capsConn);
        $audit = new AuditModel($conn);

        $id = (int)($_POST['id'] ?? 0);
        $existing = $id > 0 ? $model->find($id) : null;
        if ($id <= 0 || $existing === null) {
            $this->flashError('ProMaster user not found.');
            header('Location: index.php?route=admin/caps-promaster-users');
            exit;
        }

        try {
            if ($model->delete($id)) {
                $this->flashSuccess('ProMaster user deleted.');
                $audit->insert([
                    'UserID' => SessionHelper::get('auth.user_id'),
                    'Username' => SessionHelper::get('auth.username', 'guest'),
                    'Action' => 'DELETE',
                    'Entity' => 'CAPSProMasterUser',
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

        header('Location: index.php?route=admin/caps-promaster-users');
        exit;
    }

    private function normaliseInput(array $input): array
    {
        return [
            'extract_date' => $this->normaliseDateTime($input['extract_date'] ?? null),
            'employee_id' => $this->normaliseString($input['employee_id'] ?? null),
            'contractor_ind' => $this->normaliseFlag($input['contractor_ind'] ?? null),
            'user_name' => $this->normaliseString($input['user_name'] ?? null),
            'first_name' => $this->normaliseString($input['first_name'] ?? null),
            'surname' => $this->normaliseString($input['surname'] ?? null),
            'location_name' => $this->normaliseString($input['location_name'] ?? null),
            'admin_ctr' => $this->normaliseString($input['admin_ctr'] ?? null),
            'admin_ctr_name' => $this->normaliseString($input['admin_ctr_name'] ?? null),
            'active_indicator' => $this->normaliseFlag($input['active_indicator'] ?? null),
            'locked' => $this->normaliseFlag($input['locked'] ?? null),
            'inactive_reason' => $this->normaliseString($input['inactive_reason'] ?? null),
            'admin_centre_controller' => $this->normaliseFlag($input['admin_centre_controller'] ?? null),
            'enterprise_controller' => $this->normaliseFlag($input['enterprise_controller'] ?? null),
            'email_address' => $this->normaliseString($input['email_address'] ?? null),
            'Work_Phone' => $this->normaliseString($input['Work_Phone'] ?? null),
            'Mobile' => $this->normaliseString($input['Mobile'] ?? null),
            'review_date' => $this->normaliseString($input['review_date'] ?? null),
            'create_date' => $this->normaliseDateTime($input['create_date'] ?? null),
            'created_by' => $this->normaliseString($input['created_by'] ?? null),
            'last_logon' => $this->normaliseDateTime($input['last_logon'] ?? null),
            'unprocessed_transactions' => $this->normaliseInt($input['unprocessed_transactions'] ?? null),
            'active_cards' => $this->normaliseInt($input['active_cards'] ?? null),
            'Supervisor' => $this->normaliseString($input['Supervisor'] ?? null),
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

    private function normaliseDateTime(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function normaliseInt(mixed $value): ?int
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        return is_numeric($value) ? (int)$value : null;
    }
}
