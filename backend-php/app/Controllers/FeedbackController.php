<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\FeedbackModel;
use App\Shared\SessionHelper;

require_once __DIR__ . '/../../shared/csrf.php';

final class FeedbackController extends BaseController
{
    protected array $acl = [
        '*' => ['auth' => true],
        'index' => ['auth' => true],
        'save' => ['auth' => true],
    ];

    public function index(): void
    {
        $this->ensureAdminAccess();

        if (!($this->db instanceof \PDO)) {
            $this->flashError('Feedback data is unavailable right now.');
            header('Location: index.php?route=home/index');
            exit;
        }

        $model = new FeedbackModel($this->db);

        $this->render('admin/FeedbackList', [
            'title' => 'User Feedback',
            'summary' => $model->getSummary(),
            'starBreakdown' => $model->getStarBreakdown(),
            'rows' => $model->listRecent(200),
        ]);
    }

    public function save(): void
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        $returnUrl = trim((string)($_POST['return_url'] ?? 'index.php?route=home/index'));
        if ($returnUrl === '' || str_contains($returnUrl, "\r") || str_contains($returnUrl, "\n")) {
            $returnUrl = 'index.php?route=home/index';
        }

        if (!csrf_check($_POST['_csrf'] ?? null)) {
            $this->flashError('Your session token expired. Please try again.');
            $this->storeFormState($_POST, true);
            session_write_close();
            header('Location: ' . $returnUrl);
            exit;
        }

        $comments = trim((string)($_POST['comments'] ?? ''));
        $stars = (int)($_POST['stars'] ?? 0);

        if ($stars < 1 || $stars > 5) {
            $this->flashError('Please select a star rating from 1 to 5.');
            $this->storeFormState($_POST, true);
            session_write_close();
            header('Location: ' . $returnUrl);
            exit;
        }

        if ($comments === '') {
            $this->flashError('Please enter your feedback before submitting.');
            $this->storeFormState($_POST, true);
            session_write_close();
            header('Location: ' . $returnUrl);
            exit;
        }

        if (function_exists('mb_strlen')) {
            if (mb_strlen($comments) > 500) {
                $comments = mb_substr($comments, 0, 500);
            }
        } elseif (strlen($comments) > 500) {
            $comments = substr($comments, 0, 500);
        }

        if (!($this->db instanceof \PDO)) {
            $this->flashError('Feedback could not be saved right now.');
            $this->storeFormState($_POST, true);
            session_write_close();
            header('Location: ' . $returnUrl);
            exit;
        }

        $employeeId = trim((string)SessionHelper::get('auth.employee_id', ''));
        $updatedBy = (int)SessionHelper::get('auth.user_id', 0);

        if ($employeeId === '') {
            $this->flashError('Feedback could not be linked to your employee record.');
            $this->storeFormState($_POST, true);
            session_write_close();
            header('Location: ' . $returnUrl);
            exit;
        }

        try {
            $model = new FeedbackModel($this->db);
            $ok = $model->saveForEmployee($employeeId, $stars, $comments, $updatedBy > 0 ? $updatedBy : null);

            if (!$ok) {
                throw new \RuntimeException('Insert returned false.');
            }

            SessionHelper::forget('feedback.form');
            $this->flashSuccess('Thanks for your feedback.');
        } catch (\Throwable $e) {
            if (function_exists('app_log')) {
                app_log('Feedback save failed', [
                    'error' => $e->getMessage(),
                    'employee_id' => $employeeId,
                    'updated_by' => $updatedBy,
                ], 'error');
            }
            $this->flashError('Feedback could not be saved right now.');
            $this->storeFormState($_POST, true);
        }

        session_write_close();
        header('Location: ' . $returnUrl);
        exit;
    }

    private function storeFormState(array $source, bool $open): void
    {
        $comments = trim((string)($source['comments'] ?? ''));
        if (function_exists('mb_strlen')) {
            if (mb_strlen($comments) > 500) {
                $comments = mb_substr($comments, 0, 500);
            }
        } elseif (strlen($comments) > 500) {
            $comments = substr($comments, 0, 500);
        }

        $stars = (int)($source['stars'] ?? 0);
        if ($stars < 0) {
            $stars = 0;
        }
        if ($stars > 5) {
            $stars = 5;
        }

        SessionHelper::set('feedback.form', [
            'open' => $open,
            'comments' => $comments,
            'stars' => $stars,
        ]);
    }

    private function ensureAdminAccess(): void
    {
        $roles = SessionHelper::get('auth.roles', []);
        $isAdminRole = is_array($roles) && in_array('admin', $roles, true);
        $isPrivileged = \App\Core\Rbac::canAny(['ADMIN_ALL', 'SYSADMIN']);
        if (!$isAdminRole && !$isPrivileged) {
            $this->flashError('Access denied.');
            header('Location: index.php?route=home/index');
            exit;
        }
    }
}
