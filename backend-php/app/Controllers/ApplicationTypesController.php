<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\ApplicationTypeModel;
use App\Models\AuditModel;
use App\Shared\SessionHelper;

require_once __DIR__ . '/../../shared/csrf.php';

final class ApplicationTypesController extends BaseController
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
        $model = new ApplicationTypeModel($conn);

        $q = trim((string)($_GET['q'] ?? ''));
        $active = ($_GET['active'] ?? '') !== '' ? (string)$_GET['active'] : '';

        $rows = $model->listAll($q !== '' ? $q : null, $active);

        $this->render('admin/ApplicationTypeList', [
            'title' => 'Application Types',
            'rows' => $rows,
            'filters' => [
                'q' => $q,
                'active' => $active,
            ],
            '_csrf' => csrf_token(),
        ]);
    }

    public function edit(): void
    {
        require __DIR__ . '/../../config/db.php';
        $model = new ApplicationTypeModel($conn);

        $id = (int)($_GET['id'] ?? 0);
        $row = $id > 0 ? $model->find($id) : null;

        if ($id > 0 && $row === null) {
            $this->flashError('Application type not found.');
            header('Location: index.php?route=admin/application-types');
            exit;
        }

        $this->render('admin/ApplicationTypeForm', [
            'title' => $id > 0 ? 'Edit Application Type' : 'Add Application Type',
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
            header('Location: index.php?route=admin/application-types');
            exit;
        }

        require __DIR__ . '/../../config/db.php';
        $model = new ApplicationTypeModel($conn);
        $audit = new AuditModel($conn);

        $id = (int)($_POST['ApplicationTypeID'] ?? 0);
        $data = [
            'ApplicationTypeKey' => trim((string)($_POST['ApplicationTypeKey'] ?? '')),
            'ApplicationTypeName' => trim((string)($_POST['ApplicationTypeName'] ?? '')),
            'Description' => trim((string)($_POST['Description'] ?? '')),
            'PrivacyAgreementRequired' => ((string)($_POST['PrivacyAgreementRequired'] ?? '0') === '1') ? 1 : 0,
            'PrivacyAgreementText' => trim((string)($_POST['PrivacyAgreementText'] ?? '')),
            'IsActive' => ((string)($_POST['IsActive'] ?? '1') === '1') ? 1 : 0,
        ];

        if ($data['ApplicationTypeKey'] === '' || $data['ApplicationTypeName'] === '') {
            $this->flashError('Application Type Key and Application Type Name are required.');
            $target = 'index.php?route=admin/application-types-edit';
            if ($id > 0) {
                $target .= '&id=' . urlencode((string)$id);
            }
            header('Location: ' . $target);
            exit;
        }
        if ($data['PrivacyAgreementRequired'] === 1 && $data['PrivacyAgreementText'] === '') {
            $this->flashError('Privacy agreement text is required when privacy agreement is enabled.');
            $target = 'index.php?route=admin/application-types-edit';
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
                    $this->flashSuccess('Application type updated.');
                    $audit->insert([
                        'UserID' => SessionHelper::get('auth.user_id'),
                        'Username' => SessionHelper::get('auth.username', 'guest'),
                        'Action' => 'UPDATE',
                        'Entity' => 'ApplicationType',
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
                    $this->flashSuccess('Application type created.');
                    $audit->insert([
                        'UserID' => SessionHelper::get('auth.user_id'),
                        'Username' => SessionHelper::get('auth.username', 'guest'),
                        'Action' => 'CREATE',
                        'Entity' => 'ApplicationType',
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

        header('Location: index.php?route=admin/application-types');
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
            header('Location: index.php?route=admin/application-types');
            exit;
        }

        require __DIR__ . '/../../config/db.php';
        $model = new ApplicationTypeModel($conn);
        $audit = new AuditModel($conn);

        $id = (int)($_POST['id'] ?? 0);
        $existing = $id > 0 ? $model->find($id) : null;
        if ($id <= 0 || $existing === null) {
            $this->flashError('Application type not found.');
            header('Location: index.php?route=admin/application-types');
            exit;
        }

        try {
            if ($model->delete($id)) {
                $this->flashSuccess('Application type deleted.');
                $audit->insert([
                    'UserID' => SessionHelper::get('auth.user_id'),
                    'Username' => SessionHelper::get('auth.username', 'guest'),
                    'Action' => 'DELETE',
                    'Entity' => 'ApplicationType',
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

        header('Location: index.php?route=admin/application-types');
        exit;
    }
}
