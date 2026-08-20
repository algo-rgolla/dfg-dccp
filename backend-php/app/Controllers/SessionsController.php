<?php
declare(strict_types=1);

namespace App\Controllers;

require_once __DIR__ . '/../../shared/csrf.php';  // ✅ Add this

use App\Models\UserSessionModel;
use App\Controllers\BaseController;

final class SessionsController extends BaseController
{
    protected array $acl = [
        '*' => ['auth' => true],
        'index' => ['permsAny' => ['SESSION_VIEW', 'ADMIN_ALL']],
        'forcelogout' => ['permsAny' => ['SESSION_FORCE_LOGOUT', 'ADMIN_ALL']],
    ];

    public function index(): void
    {
        // ✅ Use global $conn instead of $this->db
        global $conn;
        $model = new UserSessionModel($conn);

        $rows = $model->listActive(500);

        $this->render('users/UserSessions', [
            'title' => 'Active User Sessions',
            'rows'  => $rows,
        ]);
    }

    public function forceLogout(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        if (!csrf_check($_POST['_csrf'] ?? '')) {
            http_response_code(400);
            echo 'Security check failed';
            return;
        }

        global $conn;
        $sid = $_POST['SessionID'] ?? '';

        if ($sid) {
            $model = new UserSessionModel($conn);
            $model->logout($sid);
            $_SESSION['flash'] = ['type' => 'success', 'text' => 'Session terminated.'];
        }

        header('Location: index.php?route=sessions/index');
        exit;
    }
}
