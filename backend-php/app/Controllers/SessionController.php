<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Shared\SessionHelper;

final class SessionController extends BaseController
{
    protected array $acl = [
        '*'    => ['auth' => true],
        'list' => ['permsAny' => ['SESSION_VIEW', 'ADMIN_ALL']],
        'forceLogout' => ['permsAny' => ['SESSION_ADMIN', 'ADMIN_ALL']],
    ];

    public function index(): void
    {
        $this->list();
    }

    public function list(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            \App\Shared\SessionHelper::ensureSession();
        }

        $raw = $_SESSION ?? [];
        unset($raw['_csrf'], $raw['__internal']);
        $flat = $this->flatten($raw);
        $grouped = [];
        foreach ($flat as $key => $value) {
            $prefix = $this->derivePrefix($key);
            $grouped[$prefix][$key] = $value;
        }

        ksort($grouped);
        foreach ($grouped as &$vars) {
            ksort($vars);
        }

        $this->render('session/SessionListView', [
            'title'   => __t('menu_session_vars') ?: 'Session Variables',
            'grouped' => $grouped,
            'flash'   => SessionHelper::get('flash.message') ?? null,
        ]);
    }

    public function forceLogout(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        $this->flashError(__t('method_not_allowed'));
        session_write_close();
        header('Location: index.php?route=sessions/list');
        exit;
    }

    if (!csrf_check($_POST['_csrf'] ?? '')) {
        $this->flashError(__t('security_check_failed'));
        session_write_close();
        header('Location: index.php?route=sessions/list');
        exit;
    }

    $sessionId = $_POST['SessionID'] ?? '';
    if (empty($sessionId)) {
        $this->flashError(__t('invalid_session_id'));
        session_write_close();
        header('Location: index.php?route=sessions/list');
        exit;
    }

    try {
        global $conn;
        require_once __DIR__ . '/../Models/UserSessionModel.php';
        $sessionModel = new \App\Models\UserSessionModel($conn);
        $session = $sessionModel->find($sessionId);

        if (!$session) {
            $this->flashError(__t('session_not_found'));
            session_write_close();
            header('Location: index.php?route=sessions/list');
            exit;
        }

        $sessionModel->logout($sessionId);
        $stmt = $conn->prepare("
            UPDATE dbo.tblUserSessions
            SET ForceLogout = 1
            WHERE SessionID = :sid
        ");
        $stmt->execute(['sid' => $sessionId]);

        // Set force_check flag for the next request
        SessionHelper::set('session.force_check', true);

        app_log("forceLogout: Session terminated", [
            'session_id' => $sessionId,
            'user_id' => $session['UserID'],
            'username' => $session['Username'],
            'by_user_id' => (int)SessionHelper::get('auth.user_id', 0)
        ], 'info');

        $this->flashSuccess(__t('session_terminated', ['username' => $session['Username']]));
    } catch (\Throwable $e) {
        app_log('forceLogout failed', ['error' => $e->getMessage(), 'session_id' => $sessionId], 'error');
        $this->flashError(__t('error_terminating_session'));
    }

    session_write_close();
    header('Location: index.php?route=sessions/list');
    exit;
}
    private function flatten(array $arr, string $prefix = ''): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            $k = (string)$k;
            $full = $prefix === '' ? $k : $prefix . '.' . $k;
            if (is_array($v)) {
                $out += $this->flatten($v, $full);
            } else {
                if (is_object($v)) {
                    $out[$full] = '(object) ' . get_class($v);
                } elseif (is_resource($v)) {
                    $out[$full] = '(resource)';
                } else {
                    $out[$full] = $v;
                }
            }
        }
        return $out;
    }

    private function derivePrefix(string $dotKey): string
    {
        $first = explode('.', $dotKey, 2)[0] ?? '';
        if ($first !== '' && $first !== $dotKey) {
            return strtoupper($first);
        }

        $upper = strtoupper($dotKey);
        foreach (['AUTH_', 'CTX_', 'UI_', 'SYS_', 'APP_'] as $p) {
            if (str_starts_with($upper, $p)) {
                return rtrim($p, '_');
            }
        }
        return 'OTHER';
    }
}