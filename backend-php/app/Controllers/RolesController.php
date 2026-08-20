<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Shared\SessionHelper;
use App\Models\AuditModel;
use App\Models\RoleModel;

require_once __DIR__ . '/../../shared/csrf.php';

final class RolesController extends BaseController
{
    protected array $acl = [
        '*'    => ['auth' => true],
        'list' => ['permsAny' => ['ROLES_VIEW','ROLES_ADMIN']],
        'edit' => ['permsAny' => ['ROLES_ADMIN']],
        'save' => ['permsAny' => ['ROLES_ADMIN']],
    ];

    public function __construct()
    {
        parent::__construct(); // enforce auth + ACL checks
    }

    public function list(): void
    {
        require __DIR__ . '/../../config/db.php';
        $roles = [];
        if ($conn instanceof \PDO) {
            try {
                $conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $st = $conn->query("
                    SELECT RoleID, RoleName, Active, DateCreated, DateUpdated
                    FROM dbo.tblRoles
                    ORDER BY RoleName
                ");
                $roles = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                error_log('RolesController::list roles: ' . print_r($roles, true));
                if (empty($roles)) {
                    error_log('RolesController::list query returned empty result');
                }
            } catch (\Throwable $e) {
                error_log('RolesController::list query error: ' . $e->getMessage());
                $roles = [];
            }
        } else {
            error_log('RolesController::list invalid PDO connection');
        }

        $this->render('roles/RolesList', [
            'title' => __t('menu_roles'),
            'roles' => $roles,
        ]);
    }

    public function edit(): void
    {
        require __DIR__ . '/../../config/db.php';
        $id   = (int)($_GET['id'] ?? 0);
        $role = null;

        if ($id > 0 && $conn instanceof \PDO) {
            try {
                $conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $st = $conn->prepare("
                    SELECT RoleID, RoleName, Active, DateCreated, DateUpdated
                    FROM dbo.tblRoles
                    WHERE RoleID = :id
                ");
                $st->execute([':id' => $id]);
                $role = $st->fetch(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                error_log('RolesController::edit query error: ' . $e->getMessage());
            }
        }

        $this->render('roles/RolesForm', [
            'title' => $id > 0 ? __t('edit_role') : __t('create_role'),
            'role'  => $role,
        ]);
    }

    public function save(): void
    {
        require __DIR__ . '/../../config/db.php';
        require_once __DIR__ . '/../../shared/csrf.php';

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        $id     = (int)($_POST['RoleID'] ?? 0);
        $name   = trim((string)($_POST['RoleName'] ?? ''));
        $active = isset($_POST['Active']) ? 1 : 0;

        // --- CSRF ---
        if (!csrf_check($_POST['_csrf'] ?? '')) {
            $this->flashError(__t('csrf_failed'));
            header('Location: index.php?route=roles/edit' . ($id ? '&id='.$id : ''));
            exit;
        }

        // --- Validation ---
        if ($name === '') {
            $this->flashError(__t('role_name_required'));
            header('Location: index.php?route=roles/edit' . ($id ? '&id='.$id : ''));
            exit;
        }

        if ($conn instanceof \PDO) {
            try {
                $conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                // Check duplicate
                $st = $conn->prepare("SELECT COUNT(*) FROM dbo.tblRoles WHERE RoleName = :name AND RoleID <> :id");
                $st->execute([':name' => $name, ':id' => $id]);
                $exists = (int)$st->fetchColumn();

                if ($exists > 0) {
                    $this->flashError(__t('role_name_exists'));
                    header('Location: index.php?route=roles/edit' . ($id ? '&id='.$id : ''));
                    exit;
                }

                // Audit model
                require_once __DIR__ . '/../Models/AuditModel.php';
                $audit = new AuditModel($conn);

                // --- Save ---
                if ($id > 0) {
                    $st = $conn->prepare("
                        UPDATE dbo.tblRoles
                        SET RoleName = :name, Active = :active, DateUpdated = GETDATE()
                        WHERE RoleID = :id
                    ");
                    $st->execute([':name' => $name, ':active' => $active, ':id' => $id]);

                    $this->flashSuccess(__t('role_updated', ['role' => $name]));

                    // Audit log
                    $audit->insert([
                        'UserID'       => SessionHelper::get('auth.user_id'),
                        'Username'     => SessionHelper::get('auth.username', 'guest'),
                        'Action'       => 'UPDATE',
                        'Entity'       => 'Role',
                        'EntityKey'    => (string)$id,
                        'IPAddress'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'Details'      => json_encode([
                            'RoleName' => $name,
                            'Active'   => $active,
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                        'VersionID'    => SessionHelper::get('VersionID'),
                    ]);

                } else {
                    $st = $conn->prepare("
                        INSERT INTO dbo.tblRoles (RoleName, Active, DateCreated, DateUpdated)
                        VALUES (:name, :active, GETDATE(), GETDATE())
                    ");
                    $st->execute([':name' => $name, ':active' => $active]);

                    $this->flashSuccess(__t('role_created', ['role' => $name]));

                    // Audit log
                    $newId = (int)$conn->lastInsertId();
                    $audit->insert([
                        'UserID'       => SessionHelper::get('auth.user_id'),
                        'Username'     => SessionHelper::get('auth.username', 'guest'),
                        'Action'       => 'CREATE',
                        'Entity'       => 'Role',
                        'EntityKey'    => (string)($newId ?: $name),
                        'IPAddress'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'Details'      => json_encode([
                            'RoleName' => $name,
                            'Active'   => $active,
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                        'VersionID'    => SessionHelper::get('VersionID'),
                    ]);
                }
            } catch (\Throwable $e) {
                $this->flashError(__t('role_save_failed') . ': ' . $e->getMessage());
            }
        }

        header('Location: index.php?route=roles/list');
        exit;
    }
}
