<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Shared\SessionHelper;
use App\Models\SystemMessageModel;

class SystemMessageFeedController extends BaseController
{
    protected array $acl = [
        '*' => ['auth' => true], // require login
    ];

    public function feed(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        global $conn;
        $model = new SystemMessageModel($conn);

        $userId    = (int)(SessionHelper::get('auth.user_id') ?? 0);
        $scopeCode = (string)(SessionHelper::get('scope.dataobject_code') ?? '');
        $scopeCode = $scopeCode !== '' ? $scopeCode : null;
        $groupName = trim((string)(SessionHelper::get('auth.employee_group') ?? ''));

        $ctx = $this->context();
        $rows = $model->getActiveForUser(
            $userId > 0 ? $userId : null,
            $scopeCode,
            $groupName !== '' ? $groupName : null,
            $ctx['FiscalYearID'] ?: null
        );

        echo json_encode($rows);
    }
public function ack(): void
{
    header('Content-Type: application/json');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
        return;
    }

    $msgId = (int)($_POST['MessageID'] ?? 0);
    if ($msgId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'MessageID required']);
        return;
    }

    $uid = (int)\App\Shared\SessionHelper::get('auth.user_id', 0);
    if ($uid <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        return;
    }

    if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof \PDO)) {
        require __DIR__ . '/../../config/db.php';
    }

    try {
        $model = new \App\Models\SystemMessageModel($GLOBALS['conn']);
        $model->acknowledge($msgId, $uid, $_SERVER['REMOTE_ADDR'] ?? null);
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        if (function_exists('app_log')) {
            app_log('sysmsg ack failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 'error');
        }
        http_response_code(500);
        $debug = in_array(strtolower((string)getenv('APP_DEBUG')), ['1','true','yes','on'], true);
        echo json_encode(['ok' => false, 'error' => $debug ? $e->getMessage() : 'Server error']);
    }
}


    
}
