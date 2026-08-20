<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\ApplicationTypeModel;
use App\Services\EmailTemplateService;
use App\Shared\SessionHelper;

require_once __DIR__ . '/../../shared/csrf.php';

final class EmailTemplateAdminController extends BaseController
{
    private EmailTemplateService $templates;

    public function __construct()
    {
        parent::__construct();
        require __DIR__ . '/../../config/db.php';
        $this->templates = new EmailTemplateService($conn);
    }

    public function index(): void
    {
        $this->ensureAdminAccess();
        $sessionKey = 'admin.email_templates.filters';
        $savedFilters = SessionHelper::get($sessionKey);
        $savedFilters = is_array($savedFilters) ? $savedFilters : [];

        if ((string)($_GET['reset'] ?? '') === '1') {
            SessionHelper::forget($sessionKey);
            $savedFilters = [];
        }

        if (array_key_exists('template_id', $_GET)) {
            $selectedTemplateId = trim((string)($_GET['template_id'] ?? ''));
            SessionHelper::set($sessionKey, [
                'template_id' => $selectedTemplateId,
            ]);
        } else {
            $selectedTemplateId = trim((string)($savedFilters['template_id'] ?? ''));
        }

        $rows = $this->templates->listTemplates();

        if ($selectedTemplateId !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($selectedTemplateId): bool {
                return trim((string)($row['id'] ?? '')) === $selectedTemplateId;
            }));
        }

        $this->render('admin/EmailTemplateList', [
            'title' => 'Email Templates',
            'rows' => $rows,
            'templateOptions' => $this->templates->listTemplateDefinitions(),
            'applicationTypes' => $this->loadApplicationTypes(),
            'selectedTemplateId' => $selectedTemplateId,
        ]);
    }

    public function edit(): void
    {
        $this->ensureAdminAccess();

        $id = trim((string)($_GET['id'] ?? ''));
        if ($id === '') {
            $this->flashError('Missing template.');
            header('Location: index.php?route=admin/email-templates');
            exit;
        }

        $applicationTypeId = (int)($_GET['application_type_id'] ?? 0);

        try {
            $template = $this->templates->loadTemplate($id, $applicationTypeId > 0 ? $applicationTypeId : null);
        } catch (\Throwable $e) {
            $this->flashError('Email template not found.');
            header('Location: index.php?route=admin/email-templates');
            exit;
        }

        $this->render('admin/EmailTemplateForm', [
            'title' => 'Edit Email Template',
            'template' => $template,
            'applicationTypes' => $this->loadApplicationTypes(),
            'preview' => [
                'subject' => $this->renderPreviewText((string)($template['subject'] ?? ''), $template),
                'body' => $this->renderPreviewText((string)($template['body'] ?? ''), $template),
            ],
        ]);
    }

    public function save(): void
    {
        $this->ensureAdminAccess();

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo 'Method not allowed';
            return;
        }

        if (!csrf_check($_POST['_csrf'] ?? null)) {
            $this->flashError('Security check failed.');
            header('Location: index.php?route=admin/email-templates');
            exit;
        }

        $id = trim((string)($_POST['template_id'] ?? ''));
        $subject = (string)($_POST['subject'] ?? '');
        $body = (string)($_POST['body'] ?? '');
        $applicationTypeId = (int)($_POST['application_type_id'] ?? 0);
        $scopeQuery = $applicationTypeId > 0 ? '&application_type_id=' . urlencode((string)$applicationTypeId) : '';

        if ($id === '' || trim($subject) === '' || trim($body) === '') {
            $this->flashError('Subject and body are required.');
            header('Location: index.php?route=admin/email-templates-edit&id=' . urlencode($id) . $scopeQuery);
            exit;
        }

        $updatedBy = (string)SessionHelper::get('auth.username', 'system');
        if (!$this->templates->saveTemplate($id, $subject, $body, $updatedBy, $applicationTypeId > 0 ? $applicationTypeId : null)) {
            $this->flashError('Failed to save email template.');
            header('Location: index.php?route=admin/email-templates-edit&id=' . urlencode($id) . $scopeQuery);
            exit;
        }

        $this->flashSuccess('Email template updated.');
        header('Location: index.php?route=admin/email-templates');
        exit;
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

    private function renderPreviewText(string $content, array $template): string
    {
        $tokens = [];
        foreach (($template['tokens'] ?? []) as $token) {
            $token = (string)$token;
            $tokens[$token] = $this->sampleValueForToken($token);
        }

        return strtr($content, $tokens);
    }

    private function sampleValueForToken(string $token): string
    {
        return match ($token) {
            '{{dear_name}}', '{{display_name}}', '{{requestor_name}}', '{{actor}}' => 'Alex Example',
            '{{windows_login}}' => 'alex.example',
            '{{activation_link}}', '{{approval_link}}', '{{application_link}}', '{{portal_link}}' => 'https://example.local/link',
            '{{application_id}}' => '12345',
            '{{application_date_submitted}}' => '24/04/2026 1:15 PM',
            '{{employee_id}}', '{{target_employee_id}}' => '1234567',
            '{{employee_group}}' => 'Defence',
            '{{card_type}}' => 'DTC',
            '{{card_number_masked}}' => '************1234',
            '{{card_last4}}' => '1234',
            '{{apply_all}}' => 'Yes',
            '{{address_html}}' => '1 Example Street<br>Canberra ACT 2600',
            '{{suburb}}' => 'Canberra',
            '{{state}}' => 'ACT',
            '{{postcode}}' => '2600',
            '{{mobile}}' => '0400123456',
            '{{phone}}' => '0261234567',
            '{{cancel_date}}' => '24/04/2026',
            '{{cancel_reason}}' => 'No Longer Required',
            '{{credit_limit_new}}' => '5000',
            '{{credit_limit_change_type}}' => 'Temporary',
            '{{current_credit_limit}}' => '3000',
            '{{transaction_limit_new}}' => '2000',
            '{{approver}}' => 'Taylor Approver',
            '{{reason}}', '{{justification_reason}}' => 'Business travel requirements',
            '{{additional_justification}}' => 'Temporary increase needed for upcoming interstate travel.',
            '{{credit_period_change_from}}' => '24/04/2026',
            '{{credit_period_change_to}}' => '24/07/2026',
            '{{transaction_period_change_from}}' => '24/04/2026',
            '{{transaction_period_change_to}}' => '24/07/2026',
            '{{application_status}}' => 'Approved',
            '{{approved_at}}' => '24/04/2026 2:30 PM',
            '{{decision_text}}' => 'approved',
            '{{decision_text_ucfirst}}' => 'Approved',
            '{{decision_extra_html}}' => '<p><strong>Rejection reason:</strong> Example reason shown here.</p>',
            default => 'Sample',
        };
    }

    private function loadApplicationTypes(): array
    {
        try {
            $model = new ApplicationTypeModel($this->db);
            return $model->listAll(null, '1');
        } catch (\Throwable $e) {
            return [];
        }
    }
}
