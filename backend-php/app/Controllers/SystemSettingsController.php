<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Shared\SessionHelper;
use App\Models\SystemSettingsModel;
use App\Models\AuditModel;

require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../shared/logger.php';

final class SystemSettingsController extends BaseController
{
    protected array $acl = [
        '*'    => ['auth' => true],
        'list' => ['permsAny' => ['SYSSETTINGS_VIEW','SYSSETTINGS_ADMIN']],
        'save' => ['permsAny' => ['SYSSETTINGS_EDIT','SYSSETTINGS_ADMIN']],
    ];

    private SystemSettingsModel $model;
    private AuditModel $audit;

    public function __construct()
    {
        parent::__construct(); // ✅ enforce auth + ACL checks
        
        require __DIR__ . '/../../config/db.php';   // creates $conn (PDO)
        require_once __DIR__ . '/../Models/SystemSettingsModel.php';
        require_once __DIR__ . '/../Models/AuditModel.php';

        $this->model = new SystemSettingsModel($conn);
        $this->audit = new AuditModel($conn);
    }

    public function list(): void
    {
        $rows  = $this->model->listAll();
        $flash = SessionHelper::get('flash.message', '');
        $savedSettingKey = trim((string)($_GET['saved'] ?? ''));

        $this->render('system/SystemSettingsListView', [
            'title' => __t('system_settings'),
            'rows'  => $rows,
            'flash' => $flash,
            'savedSettingKey' => $savedSettingKey,
        ]);

        if ($flash !== '') {
            SessionHelper::forget('flash.message');
        }
    }

    public function save(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        if (!csrf_check($_POST['_csrf'] ?? '')) {
            $this->flashError(__t('security_check_failed'));
            header('Location: index.php?route=system-settings/list'); 
            return;
        }

        $key  = trim((string)($_POST['SettingKey'] ?? ''));
        $val  = (string)($_POST['SettingValue'] ?? '');
        $type = strtolower(trim((string)($_POST['SettingType'] ?? 'string')));

        if ($key === '') {
            $this->flashError(__t('missing_setting_key'));
            header('Location: index.php?route=system-settings/list'); 
            return;
        }

        $start = microtime(true);

        $ok = $this->model->set($key, $val, $type, (string)SessionHelper::get('auth.username', 'system'));

        $elapsedMs = round((microtime(true) - $start) * 1000, 2);

        // Audit log
        $this->audit->insert([
            'UserID'       => SessionHelper::get('auth.user_id'),
            'Username'     => SessionHelper::get('auth.username', 'guest'),
            'Action'       => $ok ? 'UPDATE' : 'DENIED',
            'Entity'       => 'SystemSettings',
            'EntityKey'    => $key,
            'IPAddress'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'Details'      => json_encode([
                'value'     => $val,
                'type'      => $type,
                'error'     => $ok ? null : $this->model->getLastError(),
                'elapsedMs' => $elapsedMs
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'FiscalYearID' => SessionHelper::get('FiscalYearID'),
            'VersionID'    => SessionHelper::get('VersionID'),
        ]);

        if ($ok) {
            $this->flashSuccess(__t('setting_saved', ['key' => $key]));
        } else {
            $this->flashError(__t('save_failed_detail', ['msg' => $this->model->getLastError()]));
        }

        header('Location: index.php?route=system-settings/list&saved=' . urlencode($key) . '#setting-' . rawurlencode($key));
    }
}
