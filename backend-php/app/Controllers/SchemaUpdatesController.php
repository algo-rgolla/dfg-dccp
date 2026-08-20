<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Shared\SessionHelper;

require_once __DIR__ . '/../../shared/csrf.php';

final class SchemaUpdatesController extends BaseController
{
    protected array $acl = [
        '*' => ['auth' => true, 'permsAny' => ['SYSADMIN']],
        'index' => ['auth' => true, 'permsAny' => ['SYSADMIN']],
        'run' => ['auth' => true, 'permsAny' => ['SYSADMIN']],
    ];

    private const SCRIPT_DIR = __DIR__ . '/../Shared/sql';

    public function index(): void
    {
        $selected = trim((string)($_GET['script'] ?? ''));
        $scripts = $this->scriptCatalog();
        $preview = null;

        if ($selected !== '' && isset($scripts[$selected])) {
            $path = self::SCRIPT_DIR . '/' . $selected;
            if (is_file($path)) {
                $preview = (string)file_get_contents($path);
            }
        }

        $this->render('admin/SchemaUpdates', [
            'title' => 'Schema Updates',
            'scripts' => $scripts,
            'selectedScript' => $selected,
            'previewSql' => $preview,
            '_csrf' => csrf_token(),
        ]);
    }

    public function run(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        if (!csrf_check($_POST['_csrf'] ?? '')) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Security check failed.']);
            header('Location: index.php?route=admin/schema-updates');
            exit;
        }

        $file = trim((string)($_POST['script_file'] ?? ''));
        $scripts = $this->scriptCatalog();
        if ($file === '' || !isset($scripts[$file])) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Unknown schema update selected.']);
            header('Location: index.php?route=admin/schema-updates');
            exit;
        }

        $path = self::SCRIPT_DIR . '/' . $file;
        if (!is_file($path)) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Schema script file not found.']);
            header('Location: index.php?route=admin/schema-updates');
            exit;
        }

        $sql = (string)file_get_contents($path);

        try {
            $this->runSqlBatches($sql);
            SessionHelper::set('flash.message', ['type' => 'success', 'text' => 'Schema update executed successfully: ' . $scripts[$file]['label']]);
        } catch (\Throwable $e) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Schema update failed: ' . $e->getMessage()]);
        }

        header('Location: index.php?route=admin/schema-updates&script=' . urlencode($file));
        exit;
    }

    private function scriptCatalog(): array
    {
        return [
            'application_type_privacy_agreement.sql' => [
                'label' => 'Add Privacy Agreement Columns',
                'description' => 'Adds PrivacyAgreementRequired and PrivacyAgreementText to tblApplicationTypes if they are missing.',
            ],
            'lodge_limit_change_application_type.sql' => [
                'label' => 'Create Lodge Limit Change Type',
                'description' => 'Creates lodge_limit_change and copies workflow steps and approval rules from dtc_limit_change.',
            ],
            'enable_start_agreements.sql' => [
                'label' => 'Enable Start Agreements',
                'description' => 'Turns on start agreements for DPC, DTC, Lodge, and all supported limit change application types.',
            ],
            'portal_no_card_held_messages.sql' => [
                'label' => 'Add Portal No Card Held Settings',
                'description' => 'Creates editable system setting keys for DTC, DPC, Dual, and Lodge no-card-held helper text on the portal cards page.',
            ],
            'portal_card_hover_messages.sql' => [
                'label' => 'Add Portal Card Hover Settings',
                'description' => 'Creates editable system setting keys for DTC, DPC, Dual, and Lodge hover text on portal card titles.',
            ],
            'portal_cards_handy_link.sql' => [
                'label' => 'Add Portal Cards Handy Link Settings',
                'description' => 'Creates editable system setting keys for the portal cards handy link text and URL shown under the intro text.',
            ],
            'portal_lost_stolen_message.sql' => [
                'label' => 'Add Portal Lost/Stolen Message Setting',
                'description' => 'Creates the editable system setting key used by the Lost/Stolen button modal on the portal card list.',
            ],
            'portal_default_addresses_phone_mobile.sql' => [
                'label' => 'Add Default Address Phone And Mobile Columns',
                'description' => 'Adds Phone and Mobile columns to tblPortalDefaultAddresses if they are missing.',
            ],
            'portal_cards_active_ceiling.sql' => [
                'label' => 'Add Portal Cards Active Ceiling Column',
                'description' => 'Adds ActiveCeiling to tblPORTALCards if it is missing.',
            ],
            'limit_change_reasons.sql' => [
                'label' => 'Create Limit Change Reasons Lookup',
                'description' => 'Creates the limit change reason lookup table and seeds default reasons for existing limit change application types.',
            ],
            'cancel_card_reasons.sql' => [
                'label' => 'Create Cancel Card Reasons Lookup',
                'description' => 'Creates the cancel card reason lookup table and seeds the default reasons used by the Cancel Card form and modal.',
            ],
            'limit_change_max_credit_amount.sql' => [
                'label' => 'Add Limit Change Max Credit Amount Setting',
                'description' => 'Creates the editable system setting key that controls the maximum New Credit Limit on the Request Limit Change form.',
            ],
            'edit_contact_mobile_number_hover_text.sql' => [
                'label' => 'Add Edit Contact Mobile Number Hover Setting',
                'description' => 'Creates the editable system setting key for the Mobile Number label hover text on the Edit Contact details screen.',
            ],
            'edit_contact_work_postal_address_hover_text.sql' => [
                'label' => 'Add Edit Contact Work Postal Address Hover Setting',
                'description' => 'Creates the editable system setting key for the Work Postal Address label hover text on the Edit Contact details screen.',
            ],
            'temp_limit_period.sql' => [
                'label' => 'Add Temporary Limit Period Setting',
                'description' => 'Creates the editable system setting key that controls the maximum temporary Period of Change duration in months for limit change requests.',
            ],
            'login_agreement_settings.sql' => [
                'label' => 'Add Login Agreement Settings',
                'description' => 'Creates editable system setting keys used to show a mandatory agreement after every successful portal login.',
            ],
            'submit_agreement_settings.sql' => [
                'label' => 'Add Submit Agreement Text Setting',
                'description' => 'Creates the editable system setting key used by the submission agreement modal for all applications.',
            ],
            'restricted_list_default_reason.sql' => [
                'label' => 'Add Restricted List Default Reason Setting',
                'description' => 'Creates the editable system setting key used as the default Reason on the Restricted List add/edit screen.',
            ],
            'dd_postal_addresses_link.sql' => [
                'label' => 'Add DD Postal Addresses Link Setting',
                'description' => 'Creates the editable system setting keys used by the Application Employee Details section for the DD Postal Addresses spreadsheet link and label.',
            ],
            'application_section_links.sql' => [
                'label' => 'Add Application Section Link Settings',
                'description' => 'Creates editable system setting keys used by the Application Branding section link and label.',
            ],
            'dpc_approval_notification_email.sql' => [
                'label' => 'Add DPC Approval Notification Email Setting',
                'description' => 'Creates the editable system setting key used to notify the DPC mailbox when a DPC application is submitted for supervisor approval.',
            ],
            'tblapplications_employeeid_nvarchar.sql' => [
                'label' => 'Convert Applications EmployeeID To NVARCHAR',
                'description' => 'Changes dbo.tblApplications.EmployeeID to NVARCHAR(50) and backfills existing blank values from application payload or tblUsers.',
            ],
            'card_change_request_cancel_dates.sql' => [
                'label' => 'Add Card Cancellation Date Columns',
                'description' => 'Adds CancelDate and ProcessedAt to tblCardChangeRequests and backfills existing cancel requests.',
            ],
            'caps_writes_enabled.sql' => [
                'label' => 'Add CAPS Writes Toggle Setting',
                'description' => 'Creates the editable system setting key that turns outbound CAPS database writes on or off.',
            ],
            'new_user_activation_enabled.sql' => [
                'label' => 'Add New User Activation Toggle Setting',
                'description' => 'Creates the editable system setting key that turns new user onboarding and activation on or off.',
            ],
            'show_activation_link_instead_of_email.sql' => [
                'label' => 'Add Activation Link Delivery Mode Setting',
                'description' => 'Creates the editable system setting key that switches activation delivery between showing the link and sending it by email.',
            ],
            'smtp_from_name.sql' => [
                'label' => 'Add SMTP From Name Setting',
                'description' => 'Creates the editable system setting key used as the visible sender name for outbound portal emails.',
            ],
            'release_package_deployer_role.sql' => [
                'label' => 'Create Release Deployer Role',
                'description' => 'Creates the DEPLOY_PACKAGES permission, Release Deployer role, and links the permission to that role.',
            ],
            'release_package_runtime_root.sql' => [
                'label' => 'Add Release Package Runtime Root Setting',
                'description' => 'Creates the RELEASE_PACKAGE_RUNTIME_ROOT system setting used to override the deployment staging and backup base folder.',
            ],
        ];
    }

    private function runSqlBatches(string $sql): void
    {
        if (!($this->db instanceof \PDO)) {
            throw new \RuntimeException('Database connection is not available.');
        }

        $batches = preg_split('/^\s*GO\s*$/mi', $sql) ?: [];
        foreach ($batches as $batch) {
            $batch = trim($batch);
            if ($batch === '') {
                continue;
            }
            $this->db->exec($batch);
        }
    }
}
