<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\SystemSettingsModel;

final class EmailTemplateService
{
    private SystemSettingsModel $settings;
    private const TABLE_NAME = 'dbo.tblEmailTemplates';

    private const DEFINITIONS = [
        'activation_account' => [
            'label' => 'Account Activation',
            'description' => 'Sent during onboarding when a user links their Windows login and must activate their account.',
            'subject_key' => 'EMAIL_TEMPLATE_ACTIVATION_ACCOUNT_SUBJECT',
            'body_key' => 'EMAIL_TEMPLATE_ACTIVATION_ACCOUNT_BODY',
            'subject_default' => 'CC Portal - Account Activation Required',
            'body_default' => <<<HTML
Dear {{dear_name}},

Your CC Portal account has been successfully linked to your Windows login ({{windows_login}}).

To activate your account and log in, please click the link below:

<strong><a href="{{activation_link}}">click this activation link</a></strong>.

This link is single-use and expires in 24 hours.

If you did not request this, please contact support immediately.

Thank you,
CC Portal Team
HTML,
            'tokens' => ['{{dear_name}}', '{{windows_login}}', '{{activation_link}}'],
        ],
        'address_change_submitted' => [
            'label' => 'Address Change Submitted',
            'description' => 'Confirmation sent after an address change request is submitted.',
            'subject_key' => 'EMAIL_TEMPLATE_ADDRESS_CHANGE_SUBMITTED_SUBJECT',
            'body_key' => 'EMAIL_TEMPLATE_ADDRESS_CHANGE_SUBMITTED_BODY',
            'subject_default' => 'Address Change Submitted',
            'body_default' => <<<HTML
<p>Dear {{display_name}},</p>
<p>Your address change request has been submitted successfully.</p>
<ul>
  <li><strong>Employee ID:</strong> {{employee_id}}</li>
  <li><strong>Card Type:</strong> {{card_type}}</li>
  <li><strong>Card Number:</strong> {{card_number_masked}}</li>
  <li><strong>Apply to all cards:</strong> {{apply_all}}</li>
</ul>
<p><strong>New Address:</strong><br>
{{address_html}}<br>
{{suburb}} {{state}} {{postcode}}
</p>
<p><strong>Contact:</strong><br>
Mobile: {{mobile}}<br>
Phone: {{phone}}
</p>
<p>You can view your cards here: <a href="{{portal_link}}">Portal</a></p>
HTML,
            'tokens' => ['{{display_name}}', '{{employee_id}}', '{{card_type}}', '{{card_number_masked}}', '{{apply_all}}', '{{address_html}}', '{{suburb}}', '{{state}}', '{{postcode}}', '{{mobile}}', '{{phone}}', '{{portal_link}}'],
        ],
        'card_cancel_submitted' => [
            'label' => 'Card Cancellation Submitted',
            'description' => 'Confirmation sent after a card cancellation request is submitted.',
            'subject_key' => 'EMAIL_TEMPLATE_CARD_CANCEL_SUBMITTED_SUBJECT',
            'body_key' => 'EMAIL_TEMPLATE_CARD_CANCEL_SUBMITTED_BODY',
            'subject_default' => 'Card Cancellation Submitted',
            'body_default' => <<<HTML
<p>Dear {{display_name}},</p>
<p>Your card cancellation request has been submitted successfully.</p>
<ul>
  <li><strong>Employee ID:</strong> {{employee_id}}</li>
  <li><strong>Card Type:</strong> {{card_type}}</li>
  <li><strong>Card Last 4:</strong> {{card_last4}}</li>
  <li><strong>Cancellation Date:</strong> {{cancel_date}}</li>
  <li><strong>Reason:</strong> {{cancel_reason}}</li>
</ul>
<p>You can view your cards here: <a href="{{portal_link}}">Portal</a></p>
HTML,
            'tokens' => ['{{display_name}}', '{{employee_id}}', '{{card_type}}', '{{card_last4}}', '{{cancel_date}}', '{{cancel_reason}}', '{{portal_link}}'],
        ],
        'limit_change_approval_required' => [
            'label' => 'Limit Change Approval Required',
            'description' => 'Sent to the assigned approver when a limit change request requires action.',
            'subject_key' => 'EMAIL_TEMPLATE_LIMIT_CHANGE_APPROVAL_REQUIRED_SUBJECT',
            'body_key' => 'EMAIL_TEMPLATE_LIMIT_CHANGE_APPROVAL_REQUIRED_BODY',
            'subject_default' => 'Limit Change Approval Required - Application #{{application_id}}',
            'body_default' => <<<HTML
<p>Hello,</p>
<p>A limit change application is awaiting your approval.</p>
<ul>
    <li><strong>Application ID:</strong> {{application_id}}</li>
    <li><strong>Submitted By:</strong> {{requestor_name}}</li>
    <li><strong>Employee Group:</strong> {{employee_group}}</li>
    <li><strong>Credit Limit Requested:</strong> {{credit_limit_new}}</li>
    <li><strong>Transaction Limit Requested:</strong> {{transaction_limit_new}}</li>
    <li><strong>Reason:</strong> {{reason}}</li>
</ul>
<p><a href="{{approval_link}}">Open approval screen</a></p>
HTML,
            'tokens' => ['{{application_id}}', '{{application_date_submitted}}', '{{requestor_name}}', '{{employee_group}}', '{{credit_limit_new}}', '{{transaction_limit_new}}', '{{reason}}', '{{credit_limit_change_type}}', '{{current_credit_limit}}', '{{approver}}', '{{justification_reason}}', '{{additional_justification}}', '{{credit_period_change_from}}', '{{credit_period_change_to}}', '{{transaction_period_change_from}}', '{{transaction_period_change_to}}', '{{application_status}}', '{{approved_at}}', '{{approval_link}}'],
        ],
        'limit_change_decision' => [
            'label' => 'Limit Change Decision',
            'description' => 'Sent to the applicant after a limit change request is approved or rejected.',
            'subject_key' => 'EMAIL_TEMPLATE_LIMIT_CHANGE_DECISION_SUBJECT',
            'body_key' => 'EMAIL_TEMPLATE_LIMIT_CHANGE_DECISION_BODY',
            'subject_default' => 'Limit Change Application {{decision_text_ucfirst}} - #{{application_id}}',
            'body_default' => <<<HTML
<p>Hello,</p>
<p>Your limit change application <strong>#{{application_id}}</strong> has been <strong>{{decision_text}}</strong>.</p>
<p><strong>Actioned by:</strong> {{actor}}</p>
{{decision_extra_html}}
<p><a href="{{application_link}}">Open your application</a></p>
HTML,
            'tokens' => ['{{application_id}}', '{{application_date_submitted}}', '{{decision_text}}', '{{decision_text_ucfirst}}', '{{actor}}', '{{decision_extra_html}}', '{{credit_limit_change_type}}', '{{current_credit_limit}}', '{{approver}}', '{{justification_reason}}', '{{additional_justification}}', '{{credit_period_change_from}}', '{{credit_period_change_to}}', '{{transaction_period_change_from}}', '{{transaction_period_change_to}}', '{{application_status}}', '{{approved_at}}', '{{application_link}}'],
        ],
        'limit_change_on_behalf_submitted' => [
            'label' => 'Limit Change Submitted On Behalf',
            'description' => 'Sent to the card owner when a limit change is submitted on their behalf.',
            'subject_key' => 'EMAIL_TEMPLATE_LIMIT_CHANGE_ON_BEHALF_SUBMITTED_SUBJECT',
            'body_key' => 'EMAIL_TEMPLATE_LIMIT_CHANGE_ON_BEHALF_SUBMITTED_BODY',
            'subject_default' => 'Limit Change Submitted On Your Behalf - #{{application_id}}',
            'body_default' => <<<HTML
<p>Hello,</p>
<p>A limit change application has been submitted on your behalf.</p>
<ul>
    <li><strong>Application ID:</strong> {{application_id}}</li>
    <li><strong>Submitted By:</strong> {{requestor_name}}</li>
    <li><strong>Employee ID:</strong> {{target_employee_id}}</li>
    <li><strong>Card Type:</strong> {{card_type}}</li>
    <li><strong>Card Last 4:</strong> {{card_last4}}</li>
</ul>
HTML,
            'tokens' => ['{{application_id}}', '{{application_date_submitted}}', '{{requestor_name}}', '{{target_employee_id}}', '{{card_type}}', '{{card_last4}}', '{{credit_limit_new}}', '{{credit_limit_change_type}}', '{{current_credit_limit}}', '{{approver}}', '{{justification_reason}}', '{{additional_justification}}', '{{credit_period_change_from}}', '{{credit_period_change_to}}', '{{transaction_period_change_from}}', '{{transaction_period_change_to}}', '{{application_status}}', '{{approved_at}}'],
        ],
        'limit_change_submitted' => [
            'label' => 'Limit Change Submitted',
            'description' => 'Confirmation sent to the applicant after a limit change request is submitted.',
            'subject_key' => 'EMAIL_TEMPLATE_LIMIT_CHANGE_SUBMITTED_SUBJECT',
            'body_key' => 'EMAIL_TEMPLATE_LIMIT_CHANGE_SUBMITTED_BODY',
            'subject_default' => 'Limit Change Submitted - #{{application_id}}',
            'body_default' => <<<HTML
<p>Dear {{display_name}},</p>
<p>Your limit change application has been submitted successfully.</p>
<ul>
    <li><strong>Application ID:</strong> {{application_id}}</li>
    <li><strong>Card Type:</strong> {{card_type}}</li>
    <li><strong>Card Last 4:</strong> {{card_last4}}</li>
    <li><strong>Requested Credit Limit:</strong> {{credit_limit_new}}</li>
    <li><strong>Requested Transaction Limit:</strong> {{transaction_limit_new}}</li>
</ul>
<p><a href="{{application_link}}">Open your application</a></p>
HTML,
            'tokens' => ['{{display_name}}', '{{application_id}}', '{{application_date_submitted}}', '{{card_type}}', '{{card_last4}}', '{{credit_limit_new}}', '{{transaction_limit_new}}', '{{credit_limit_change_type}}', '{{current_credit_limit}}', '{{approver}}', '{{justification_reason}}', '{{additional_justification}}', '{{credit_period_change_from}}', '{{credit_period_change_to}}', '{{transaction_period_change_from}}', '{{transaction_period_change_to}}', '{{application_status}}', '{{approved_at}}', '{{application_link}}'],
        ],
        'application_submitted' => [
            'label' => 'Application Submitted',
            'description' => 'Confirmation sent to the applicant after a normal card application is submitted successfully.',
            'subject_key' => 'EMAIL_TEMPLATE_APPLICATION_SUBMITTED_SUBJECT',
            'body_key' => 'EMAIL_TEMPLATE_APPLICATION_SUBMITTED_BODY',
            'subject_default' => 'Application Submitted Successfully - #{{application_id}}',
            'body_default' => <<<HTML
<p>Dear {{display_name}},</p>
<p>Your application has been successfully submitted and has been sent to the bank.</p>
<ul>
    <li><strong>Application ID:</strong> {{application_id}}</li>
    <li><strong>Application Type:</strong> {{application_type}}</li>
    <li><strong>Employee ID:</strong> {{employee_id}}</li>
</ul>
<p><a href="{{application_link}}">Open your application</a></p>
HTML,
            'tokens' => ['{{display_name}}', '{{application_id}}', '{{application_date_submitted}}', '{{application_type}}', '{{employee_id}}', '{{application_link}}'],
        ],
        'dpc_application_approval_required' => [
            'label' => 'DPC Application Approval Required',
            'description' => 'Sent to the selected supervisor when a DPC application requires approval.',
            'subject_key' => 'EMAIL_TEMPLATE_DPC_APPLICATION_APPROVAL_REQUIRED_SUBJECT',
            'body_key' => 'EMAIL_TEMPLATE_DPC_APPLICATION_APPROVAL_REQUIRED_BODY',
            'subject_default' => 'DPC Application Approval Required - #{{application_id}}',
            'body_default' => <<<HTML
<p>Hello,</p>
<p>A DPC application is awaiting supervisor approval.</p>
<ul>
    <li><strong>Application ID:</strong> {{application_id}}</li>
    <li><strong>Submitted By:</strong> {{requestor_name}}</li>
    <li><strong>Application Type:</strong> {{application_type}}</li>
    <li><strong>Employee ID:</strong> {{employee_id}}</li>
    <li><strong>Supervisor:</strong> {{supervisor_name}}</li>
</ul>
<p><a href="{{approval_link}}">Open approval screen</a></p>
HTML,
            'tokens' => ['{{application_id}}', '{{application_date_submitted}}', '{{requestor_name}}', '{{application_type}}', '{{employee_id}}', '{{supervisor_name}}', '{{approval_link}}'],
        ],
        'dpc_application_submitted' => [
            'label' => 'DPC Application Submitted',
            'description' => 'Confirmation sent to the applicant after a DPC application is submitted for supervisor approval.',
            'subject_key' => 'EMAIL_TEMPLATE_DPC_APPLICATION_SUBMITTED_SUBJECT',
            'body_key' => 'EMAIL_TEMPLATE_DPC_APPLICATION_SUBMITTED_BODY',
            'subject_default' => 'DPC Application Submitted - Awaiting Approval #{{application_id}}',
            'body_default' => <<<HTML
<p>Dear {{display_name}},</p>
<p>Your DPC application has been submitted and is now awaiting supervisor approval.</p>
<ul>
    <li><strong>Application ID:</strong> {{application_id}}</li>
    <li><strong>Supervisor:</strong> {{supervisor_name}}</li>
</ul>
<p><a href="{{application_link}}">Open your application</a></p>
HTML,
            'tokens' => ['{{display_name}}', '{{application_id}}', '{{application_date_submitted}}', '{{supervisor_name}}', '{{application_link}}'],
        ],
        'dpc_application_decision' => [
            'label' => 'DPC Application Decision',
            'description' => 'Sent to the applicant after a DPC application is approved or rejected.',
            'subject_key' => 'EMAIL_TEMPLATE_DPC_APPLICATION_DECISION_SUBJECT',
            'body_key' => 'EMAIL_TEMPLATE_DPC_APPLICATION_DECISION_BODY',
            'subject_default' => 'DPC Application {{decision_text_ucfirst}} - #{{application_id}}',
            'body_default' => <<<HTML
<p>Dear {{display_name}},</p>
<p>Your DPC application <strong>#{{application_id}}</strong> has been <strong>{{decision_text}}</strong>.</p>
{{decision_extra_html}}
<p><a href="{{application_link}}">Open your application</a></p>
HTML,
            'tokens' => ['{{display_name}}', '{{application_id}}', '{{application_date_submitted}}', '{{decision_text}}', '{{decision_text_ucfirst}}', '{{decision_extra_html}}', '{{application_link}}'],
        ],
    ];

    public function __construct(private \PDO $db)
    {
        $this->settings = new SystemSettingsModel($db);
    }

    public function listTemplates(): array
    {
        $rows = [];
        foreach (self::DEFINITIONS as $id => $def) {
            $rows[] = $this->loadTemplate($id);
        }
        foreach ($this->loadScopedTemplateRows() as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function listTemplateDefinitions(): array
    {
        $rows = [];
        foreach (self::DEFINITIONS as $id => $def) {
            $rows[] = [
                'id' => $id,
                'label' => $def['label'],
                'description' => $def['description'],
            ];
        }

        return $rows;
    }

    public function loadTemplate(string $id, ?int $applicationTypeId = null): array
    {
        $def = self::DEFINITIONS[$id] ?? null;
        if ($def === null) {
            throw new \InvalidArgumentException('Unknown email template: ' . $id);
        }

        if ($applicationTypeId !== null && $applicationTypeId > 0) {
            $scoped = $this->loadTemplateRowFromTable($id, $applicationTypeId);
            if ($scoped !== null) {
                return $this->buildTableTemplatePayload($def, $scoped, $applicationTypeId);
            }
        }

        $defaultRow = $this->loadTemplateRowFromTable($id, null);
        if ($defaultRow !== null) {
            return $this->buildTableTemplatePayload($def, $defaultRow, null);
        }

        $subject = $this->settings->get($def['subject_key']);
        $body = $this->settings->get($def['body_key']);

        return [
            'id' => $id,
            'label' => $def['label'],
            'description' => $def['description'],
            'subject' => ($subject !== null && trim($subject) !== '') ? $subject : $def['subject_default'],
            'body' => ($body !== null && trim($body) !== '') ? $body : $def['body_default'],
            'subject_key' => $def['subject_key'],
            'body_key' => $def['body_key'],
            'tokens' => $def['tokens'],
            'application_type_id' => $applicationTypeId,
            'application_type_name' => $applicationTypeId !== null && $applicationTypeId > 0 ? $this->loadApplicationTypeName($applicationTypeId) : '',
            'scope_label' => $applicationTypeId !== null && $applicationTypeId > 0 ? $this->buildScopeLabel($applicationTypeId) : 'Default',
            'is_override' => $applicationTypeId !== null && $applicationTypeId > 0,
            'exists_in_table' => false,
        ];
    }

    public function saveTemplate(string $id, string $subject, string $body, string $updatedBy, ?int $applicationTypeId = null): bool
    {
        $def = self::DEFINITIONS[$id] ?? null;
        if ($def === null) {
            return false;
        }

        if ($this->emailTemplateTableExists()) {
            return $this->upsertTemplateRow($id, $subject, $body, $updatedBy, $applicationTypeId);
        }

        if ($applicationTypeId !== null && $applicationTypeId > 0) {
            return false;
        }

        $subjectOk = $this->settings->set($def['subject_key'], $subject, 'string', $updatedBy);
        $bodyOk = $this->settings->set($def['body_key'], $body, 'string', $updatedBy);
        return $subjectOk && $bodyOk;
    }

    public function renderTemplate(string $id, array $tokens, array $context = []): array
    {
        $template = $this->loadTemplate($id, $this->resolveApplicationTypeIdFromContext($context));
        $resolvedTokens = $this->resolveTemplateTokens($template, $tokens);
        return [
            'subject' => $this->replaceTokens($template['subject'], $resolvedTokens),
            'body' => $this->replaceTokens($template['body'], $resolvedTokens),
        ];
    }

    private function loadScopedTemplateRows(): array
    {
        if (!$this->emailTemplateTableExists()) {
            return [];
        }

        $sql = "
            SELECT
                t.EmailTemplateID,
                t.TemplateKey,
                t.ApplicationTypeID,
                t.Subject,
                t.BodyHtml,
                at.ApplicationTypeName
            FROM " . self::TABLE_NAME . " t
            LEFT JOIN dbo.tblApplicationTypes at
                ON at.ApplicationTypeID = t.ApplicationTypeID
            WHERE t.IsActive = 1
              AND t.ApplicationTypeID IS NOT NULL
            ORDER BY t.TemplateKey ASC, at.ApplicationTypeName ASC, t.EmailTemplateID ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $id = trim((string)($row['TemplateKey'] ?? ''));
            $def = self::DEFINITIONS[$id] ?? null;
            if ($def === null) {
                continue;
            }
            $out[] = $this->buildTableTemplatePayload($def, $row, (int)($row['ApplicationTypeID'] ?? 0));
        }

        return $out;
    }

    private function buildTableTemplatePayload(array $def, array $row, ?int $applicationTypeId): array
    {
        $applicationTypeId = $applicationTypeId !== null && $applicationTypeId > 0 ? $applicationTypeId : null;
        $applicationTypeName = trim((string)($row['ApplicationTypeName'] ?? ''));

        return [
            'id' => (string)($row['TemplateKey'] ?? ''),
            'label' => $def['label'],
            'description' => $def['description'],
            'subject' => trim((string)($row['Subject'] ?? '')) !== '' ? (string)$row['Subject'] : $def['subject_default'],
            'body' => trim((string)($row['BodyHtml'] ?? '')) !== '' ? (string)$row['BodyHtml'] : $def['body_default'],
            'subject_key' => $def['subject_key'],
            'body_key' => $def['body_key'],
            'tokens' => $def['tokens'],
            'application_type_id' => $applicationTypeId,
            'application_type_name' => $applicationTypeName,
            'scope_label' => $applicationTypeId !== null ? ($applicationTypeName !== '' ? $applicationTypeName : ('Application Type #' . $applicationTypeId)) : 'Default',
            'is_override' => $applicationTypeId !== null,
            'exists_in_table' => true,
        ];
    }

    private function emailTemplateTableExists(): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }

        $stmt = $this->db->prepare("
            SELECT 1
            WHERE OBJECT_ID('" . self::TABLE_NAME . "', 'U') IS NOT NULL
        ");
        $stmt->execute();
        $exists = (bool)$stmt->fetchColumn();
        return $exists;
    }

    private function loadTemplateRowFromTable(string $id, ?int $applicationTypeId): ?array
    {
        if (!$this->emailTemplateTableExists()) {
            return null;
        }

        $sql = "
            SELECT TOP 1
                t.EmailTemplateID,
                t.TemplateKey,
                t.ApplicationTypeID,
                t.Subject,
                t.BodyHtml,
                at.ApplicationTypeName
            FROM " . self::TABLE_NAME . " t
            LEFT JOIN dbo.tblApplicationTypes at
                ON at.ApplicationTypeID = t.ApplicationTypeID
            WHERE t.IsActive = 1
              AND t.TemplateKey = :template_key
        ";
        $params = ['template_key' => $id];

        if ($applicationTypeId !== null && $applicationTypeId > 0) {
            $sql .= " AND t.ApplicationTypeID = :application_type_id";
            $params['application_type_id'] = $applicationTypeId;
        } else {
            $sql .= " AND t.ApplicationTypeID IS NULL";
        }

        $sql .= " ORDER BY t.EmailTemplateID DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function upsertTemplateRow(string $id, string $subject, string $body, string $updatedBy, ?int $applicationTypeId): bool
    {
        $existing = $this->loadTemplateRowFromTable($id, $applicationTypeId);
        if ($existing !== null) {
            $sql = "
                UPDATE " . self::TABLE_NAME . "
                SET Subject = :subject,
                    BodyHtml = :body_html,
                    IsActive = 1,
                    UpdatedAt = SYSUTCDATETIME(),
                    UpdatedBy = :updated_by
                WHERE EmailTemplateID = :email_template_id
            ";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                'subject' => $subject,
                'body_html' => $body,
                'updated_by' => $updatedBy,
                'email_template_id' => (int)($existing['EmailTemplateID'] ?? 0),
            ]);
        }

        $sql = "
            INSERT INTO " . self::TABLE_NAME . "
                (TemplateKey, ApplicationTypeID, Subject, BodyHtml, IsActive, CreatedAt, CreatedBy, UpdatedAt, UpdatedBy)
            VALUES
                (:template_key, :application_type_id, :subject, :body_html, 1, SYSUTCDATETIME(), :created_by, SYSUTCDATETIME(), :updated_by)
        ";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'template_key' => $id,
            'application_type_id' => $applicationTypeId !== null && $applicationTypeId > 0 ? $applicationTypeId : null,
            'subject' => $subject,
            'body_html' => $body,
            'created_by' => $updatedBy,
            'updated_by' => $updatedBy,
        ]);
    }

    private function resolveApplicationTypeIdFromContext(array $context): ?int
    {
        $applicationTypeId = (int)($context['application_type_id'] ?? 0);
        if ($applicationTypeId > 0) {
            return $applicationTypeId;
        }

        $applicationTypeKey = strtolower(trim((string)($context['application_type_key'] ?? '')));
        if ($applicationTypeKey === '') {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT TOP 1 ApplicationTypeID
            FROM dbo.tblApplicationTypes
            WHERE LOWER(ApplicationTypeKey) = :type_key
        ");
        $stmt->execute(['type_key' => $applicationTypeKey]);
        $resolved = (int)($stmt->fetchColumn() ?? 0);
        return $resolved > 0 ? $resolved : null;
    }

    private function loadApplicationTypeName(int $applicationTypeId): string
    {
        if ($applicationTypeId <= 0) {
            return '';
        }

        $stmt = $this->db->prepare("
            SELECT TOP 1 ApplicationTypeName
            FROM dbo.tblApplicationTypes
            WHERE ApplicationTypeID = :application_type_id
        ");
        $stmt->execute(['application_type_id' => $applicationTypeId]);
        return trim((string)($stmt->fetchColumn() ?? ''));
    }

    private function buildScopeLabel(int $applicationTypeId): string
    {
        $name = $this->loadApplicationTypeName($applicationTypeId);
        return $name !== '' ? $name : ('Application Type #' . $applicationTypeId);
    }

    private function replaceTokens(string $content, array $tokens): string
    {
        if ($tokens === []) {
            return $content;
        }

        $replace = [];
        foreach ($tokens as $key => $value) {
            $replace[(string)$key] = (string)$value;
        }

        return strtr($content, $replace);
    }

    private function resolveTemplateTokens(array $template, array $tokens): array
    {
        $resolved = [];
        foreach ($tokens as $key => $value) {
            $resolved[(string)$key] = $this->normalizeTemplateTokenValue($value);
        }

        foreach (($template['tokens'] ?? []) as $token) {
            $token = (string)$token;
            if (!array_key_exists($token, $resolved)) {
                $resolved[$token] = 'Not Applicable';
            }
        }

        return $resolved;
    }

    private function normalizeTemplateTokenValue(mixed $value): string
    {
        if ($value === null) {
            return 'Not Applicable';
        }

        if (is_string($value)) {
            return trim($value) !== '' ? $value : 'Not Applicable';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return $value !== [] ? trim(implode(', ', array_map(static fn ($item): string => trim((string)$item), $value))) ?: 'Not Applicable' : 'Not Applicable';
        }

        $stringValue = trim((string)$value);
        return $stringValue !== '' ? $stringValue : 'Not Applicable';
    }
}
