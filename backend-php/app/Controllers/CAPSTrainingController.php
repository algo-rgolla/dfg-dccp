<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditModel;
use App\Models\CAPSTrainingModel;
use App\Shared\SessionHelper;

require_once __DIR__ . '/../../shared/csrf.php';

final class CAPSTrainingController extends BaseController
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
            'course_id' => trim((string)($_GET['course_id'] ?? '')),
            'employee_id' => trim((string)($_GET['employee_id'] ?? '')),
            'loaded' => strtoupper(trim((string)($_GET['loaded'] ?? ''))),
        ];

        $perPage = 100;
        $currentPage = max(1, (int)($_GET['page'] ?? 1));

        $rows = [];
        $totalCount = 0;
        if (!($capsConn instanceof \PDO)) {
            $this->flashError('CAPS database connection is unavailable. Please check the CAPS connection settings on this server.');
        } else {
            $model = new CAPSTrainingModel($capsConn);
            $totalCount = $model->countFiltered($filters);
            $rows = $model->listAll($filters, $currentPage, $perPage);
        }

        $this->render('admin/CAPSTrainingList', [
            'title' => 'CAPS Training',
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
            header('Location: index.php?route=admin/caps-training');
            exit;
        }

        $model = new CAPSTrainingModel($capsConn);

        $id = (int)($_GET['id'] ?? 0);
        $row = $id > 0 ? $model->find($id) : null;

        if ($id > 0 && $row === null) {
            $this->flashError('Training record not found.');
            header('Location: index.php?route=admin/caps-training');
            exit;
        }

        $this->render('admin/CAPSTrainingForm', [
            'title' => $id > 0 ? 'Edit CAPS Training Record' : 'Add CAPS Training Record',
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
            header('Location: index.php?route=admin/caps-training');
            exit;
        }

        require __DIR__ . '/../../config/db.php';
        if (!($capsConn instanceof \PDO)) {
            $this->flashError('CAPS database connection is unavailable. Please check the CAPS connection settings on this server.');
            header('Location: index.php?route=admin/caps-training');
            exit;
        }

        $model = new CAPSTrainingModel($capsConn);
        $audit = new AuditModel($conn);

        $id = (int)($_POST['TrainingID'] ?? 0);
        $data = $this->normaliseInput($_POST);

        try {
            if ($id > 0) {
                $ok = $model->update($id, $data);
                if ($ok) {
                    $this->flashSuccess('Training record updated.');
                    $audit->insert([
                        'UserID' => SessionHelper::get('auth.user_id'),
                        'Username' => SessionHelper::get('auth.username', 'guest'),
                        'Action' => 'UPDATE',
                        'Entity' => 'CAPSTraining',
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
                    $this->flashSuccess('Training record created.');
                    $audit->insert([
                        'UserID' => SessionHelper::get('auth.user_id'),
                        'Username' => SessionHelper::get('auth.username', 'guest'),
                        'Action' => 'CREATE',
                        'Entity' => 'CAPSTraining',
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

        header('Location: index.php?route=admin/caps-training');
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
            header('Location: index.php?route=admin/caps-training');
            exit;
        }

        require __DIR__ . '/../../config/db.php';
        if (!($capsConn instanceof \PDO)) {
            $this->flashError('CAPS database connection is unavailable. Please check the CAPS connection settings on this server.');
            header('Location: index.php?route=admin/caps-training');
            exit;
        }

        $model = new CAPSTrainingModel($capsConn);
        $audit = new AuditModel($conn);

        $id = (int)($_POST['id'] ?? 0);
        $existing = $id > 0 ? $model->find($id) : null;
        if ($id <= 0 || $existing === null) {
            $this->flashError('Training record not found.');
            header('Location: index.php?route=admin/caps-training');
            exit;
        }

        try {
            if ($model->delete($id)) {
                $this->flashSuccess('Training record deleted.');
                $audit->insert([
                    'UserID' => SessionHelper::get('auth.user_id'),
                    'Username' => SessionHelper::get('auth.username', 'guest'),
                    'Action' => 'DELETE',
                    'Entity' => 'CAPSTraining',
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

        header('Location: index.php?route=admin/caps-training');
        exit;
    }

    private function normaliseInput(array $input): array
    {
        return [
            'CourseID' => $this->normaliseString($input['CourseID'] ?? null),
            'OfferingID' => $this->normaliseString($input['OfferingID'] ?? null),
            'CourseTitle' => $this->normaliseString($input['CourseTitle'] ?? null),
            'EmployeeID' => $this->normaliseString($input['EmployeeID'] ?? null),
            'FirstName' => $this->normaliseString($input['FirstName'] ?? null),
            'LastName' => $this->normaliseString($input['LastName'] ?? null),
            'Email' => $this->normaliseString($input['Email'] ?? null),
            'CompletionDate' => $this->normaliseDateTime($input['CompletionDate'] ?? null),
            'Loaded' => $this->normaliseFlag($input['Loaded'] ?? null),
            'FileID' => $this->normaliseInt($input['FileID'] ?? null),
            'DateUpdated' => $this->normaliseDateTime($input['DateUpdated'] ?? null),
            'UpdatedBy' => $this->normaliseInt($input['UpdatedBy'] ?? null),
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
