<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Shared\SessionHelper;
use App\Models\AuditModel;
use App\Models\SystemSettingsModel;
use App\Services\ApprovalInboxService;
use App\Services\MailService;
use App\Services\EmailTemplateService;

require_once __DIR__ . '/../../shared/csrf.php';

final class ApplicationsController extends BaseController
{
    private const DUPLICATE_APPLICATION_MESSAGE = 'An Application has already been lodged. Contact Defence Credit Card Support.';
    private array $prefillDebug = [];

    protected array $acl = [
        '*' => ['auth' => true],
        'capsOptions' => ['auth' => true],
        'startAgree' => ['auth' => true],
    ];
    /**
     * Start or resume an application by type key.
     * GET: index.php?route=applications/start&type=dtc
     */
    public function start(): void
    {
        $db = $this->db;

        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $typeKey = strtolower(trim((string)($_GET['type'] ?? '')));
        if ($typeKey === '') {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Missing application type.']);
            header('Location: index.php?route=portalcards/list');
            exit;
        }

        $type = $this->loadApplicationTypeByKey($db, $typeKey);
        if (!$type) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Unknown application type.']);
            header('Location: index.php?route=portalcards/list');
            exit;
        }

        $applicationTypeId = (int)$type['ApplicationTypeID'];
        $empRaw = trim((string)SessionHelper::get('auth.employee_id', ''));
        $blacklist = $this->getBlacklistMatch($empRaw, $applicationTypeId);
        if (!empty($blacklist['blocked'])) {
            SessionHelper::set('flash.message', [
                'type' => 'danger',
                'text' => $this->buildBlacklistMessage($blacklist),
            ]);
            header('Location: index.php?route=home/index');
            exit;
        }

        $existing = $this->findOpenApplication($db, $userId, $applicationTypeId, $empRaw);
        if ($existing) {
            $workflow = $this->loadWorkflowSteps($db, (int)$existing['ApplicationTypeID']);
            $this->ensureRuntimeSteps($db, (int)$existing['ApplicationID'], $workflow);
            $this->ensurePayloadRow($db, (int)$existing['ApplicationID']);
            $this->auditLog(
                'RESUME',
                'Application',
                (string)$existing['ApplicationID'],
                [
                    'route' => 'applications/start',
                    'application_type_key' => $typeKey,
                    'application_type_id' => $applicationTypeId,
                ]
            );
            header('Location: index.php?route=applications/edit&id=' . urlencode((string)$existing['ApplicationID']));
            exit;
        }

        if ($this->applicationTypeRequiresPrivacyAgreement($type)) {
            $errorKey = 'applications.privacy_error.' . strtolower($typeKey);
            $privacyError = trim((string)(SessionHelper::get($errorKey) ?? ''));
            SessionHelper::forget($errorKey);

            $this->render('applications/PrivacyAgreementStart', [
                'title' => 'Privacy Notice',
                'applicationType' => $type,
                'privacyError' => $privacyError,
                '_csrf' => csrf_token(),
            ]);
            return;
        }

        $app = $this->createDraftApplication($db, $userId, $applicationTypeId, $empRaw);
        $this->auditLog(
            'CREATE',
            'Application',
            (string)$app['ApplicationID'],
            [
                'route' => 'applications/start',
                'application_type_key' => $typeKey,
                'application_type_id' => $applicationTypeId,
            ]
        );

        header('Location: index.php?route=applications/edit&id=' . urlencode((string)$app['ApplicationID']));
        exit;
    }

    public function startAgree(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }
        if (!csrf_check($_POST['_csrf'] ?? '')) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Security check failed.']);
            header('Location: index.php?route=portalcards/list');
            exit;
        }

        $db = $this->db;
        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $typeKey = strtolower(trim((string)($_POST['type'] ?? '')));
        if ($typeKey === '') {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Missing application type.']);
            header('Location: index.php?route=portalcards/list');
            exit;
        }

        $type = $this->loadApplicationTypeByKey($db, $typeKey);
        if (!$type) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Unknown application type.']);
            header('Location: index.php?route=portalcards/list');
            exit;
        }

        if ((string)($_POST['agree_privacy'] ?? '') !== '1') {
            SessionHelper::set('applications.privacy_error.' . $typeKey, 'Please tick the checkbox to confirm you have read and agree to the Privacy Notice before continuing.');
            header('Location: index.php?route=applications/start&type=' . urlencode($typeKey));
            exit;
        }

        $applicationTypeId = (int)$type['ApplicationTypeID'];
        $empRaw = trim((string)SessionHelper::get('auth.employee_id', ''));
        $blacklist = $this->getBlacklistMatch($empRaw, $applicationTypeId);
        if (!empty($blacklist['blocked'])) {
            SessionHelper::set('flash.message', [
                'type' => 'danger',
                'text' => $this->buildBlacklistMessage($blacklist),
            ]);
            header('Location: index.php?route=home/index');
            exit;
        }

        $existing = $this->findOpenApplication($db, $userId, $applicationTypeId, $empRaw);
        if ($existing) {
            header('Location: index.php?route=applications/edit&id=' . urlencode((string)$existing['ApplicationID']));
            exit;
        }

        $app = $this->createDraftApplication($db, $userId, $applicationTypeId, $empRaw);
        $payload = $this->loadPayload($db, (int)$app['ApplicationID']);
        $agreementText = trim((string)($type['PrivacyAgreementText'] ?? ''));
        $payload['privacy_agreement_required'] = '1';
        $payload['privacy_agreement_accepted'] = '1';
        $payload['privacy_agreement_accepted_at'] = gmdate('Y-m-d H:i:s');
        $payload['privacy_agreement_application_type_id'] = (string)$applicationTypeId;
        $payload['privacy_agreement_text'] = $agreementText;
        $payload['privacy_agreement_hash'] = sha1($agreementText);
        $this->savePayload($db, (int)$app['ApplicationID'], $userId, $payload);

        $this->auditLog(
            'PRIVACY_AGREEMENT_ACCEPTED',
            'Application',
            (string)$app['ApplicationID'],
            [
                'route' => 'applications/start-agree',
                'application_type_key' => $typeKey,
                'application_type_id' => $applicationTypeId,
                'application_type_name' => (string)($type['ApplicationTypeName'] ?? ''),
                'privacy_agreement_accepted_at' => (string)$payload['privacy_agreement_accepted_at'],
                'privacy_agreement_hash' => sha1($agreementText),
            ]
        );

        $this->auditLog(
            'CREATE',
            'Application',
            (string)$app['ApplicationID'],
            [
                'route' => 'applications/start-agree',
                'application_type_key' => $typeKey,
                'application_type_id' => $applicationTypeId,
                'privacy_agreement_accepted' => true,
                'privacy_agreement_hash' => sha1($agreementText),
            ]
        );

        header('Location: index.php?route=applications/edit&id=' . urlencode((string)$app['ApplicationID']));
        exit;
    }

    /**
     * Single-page application view.
     * GET: index.php?route=applications/edit&id=123
     */
public function edit(): void
{
    $db = $this->db;
    $isAdminViewer = $this->isAdminViewer();
    $adminViewMode = !empty($_GET['admin_view']) && $isAdminViewer;
    $adminEditMode = !empty($_GET['admin_edit']) && $isAdminViewer;
    $isAdminMode = $adminViewMode || $adminEditMode;

    $actorUserId = (int)(SessionHelper::get('auth.user_id') ?? 0);
    $userId = $actorUserId;
    if ($actorUserId <= 0) {
        header('Location: index.php?route=auth/loginForm');
        exit;
    }

    $applicationId = (int)($_GET['id'] ?? 0);
    if ($applicationId <= 0) {
        SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Missing application id.']);
        header('Location: index.php?route=portalcards/list');
        exit;
    }

    // Ensure EmployeeID is in session (fallback to tblUsers if missing)
    $this->ensureEmployeeIdInSession($db, $userId);

    $app = $this->loadApplication($db, $applicationId, $userId);
    if (!$app && $isAdminMode) {
        $app = $this->loadApplicationAdmin($db, $applicationId);
    }
    if (!$app) {
        SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Application not found.']);
        header('Location: index.php?route=portalcards/list');
        exit;
    }

    $applicationOwnerUserId = (int)($app['UserID'] ?? 0);
    if ($applicationOwnerUserId > 0 && $applicationOwnerUserId !== $userId) {
        $this->auditLog(
            'VIEW',
            'Application',
            (string)$applicationId,
            [
                'route' => 'applications/edit',
                'owner_user_id' => $applicationOwnerUserId,
                'admin_view' => $adminViewMode ? 1 : 0,
                'admin_edit' => $adminEditMode ? 1 : 0,
            ]
        );
    }

    $workflow = $this->loadWorkflowSteps($db, (int)$app['ApplicationTypeID']);
    if (!$workflow) {
        SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'No workflow configured for this application.']);
        header('Location: index.php?route=portalcards/list');
        exit;
    }

    // Ensure runtime step rows exist (in case workflow changed)
    $this->ensureRuntimeSteps($db, $applicationId, $workflow);
    $this->ensurePayloadRow($db, $applicationId);

    // ---- Flash (field errors + old input) ----
    $errorsKey   = 'applications.validation_errors.' . $applicationId;
    $oldInputKey = 'applications.old_input.' . $applicationId;

    $validationErrors = SessionHelper::get($errorsKey);
    SessionHelper::forget($errorsKey);

    $oldInput = SessionHelper::get($oldInputKey);
    SessionHelper::forget($oldInputKey);

    if (!is_array($validationErrors)) {
        $validationErrors = [];
    }
    if (!is_array($oldInput)) {
        $oldInput = [];
    }

    // ---- Resolve EmployeeID (admin view must prefer the selected application, not the logged-in admin) ----
    $payload = $this->loadPayload($db, $applicationId);

    $employeeId = $isAdminMode
        ? trim((string)($app['EmployeeID'] ?? ''))
        : trim((string)($app['EmployeeID'] ?? ''));
    if ($employeeId === '') {
        $employeeId = trim((string)($payload['employee_id'] ?? ''));
    }
    if ($employeeId === '' && !$isAdminMode) {
        $employeeId = trim((string)(SessionHelper::get('auth.employee_id') ?? ''));
    }
    if (trim($employeeId) === '' && $db instanceof \PDO) {
        $stUserEmp = $db->prepare("
            SELECT TOP 1 EmployeeID
            FROM dbo.tblUsers
            WHERE UserID = :uid
        ");
        $stUserEmp->execute(['uid' => $userId]);
        $employeeId = trim((string)($stUserEmp->fetchColumn() ?? ''));
    }
    if (trim($employeeId) === '' && $db instanceof \PDO) {
        $stEmp = $db->prepare("
            SELECT TOP 1 EmployeeID
            FROM dbo.tblPORTALCards
            WHERE ApplicationID = :aid
              AND EmployeeID IS NOT NULL
              AND LTRIM(RTRIM(EmployeeID)) <> ''
            ORDER BY CardID DESC
        ");
        $stEmp->execute(['aid' => $applicationId]);
        $employeeId = trim((string)($stEmp->fetchColumn() ?? ''));
    }
    if (!$isAdminMode && trim($employeeId) !== '') {
        SessionHelper::set('auth.employee_id', $employeeId);
    }

    // Prefer attempted input when submit failed (so user doesn't lose typing)
    if (!empty($oldInput)) {
        $payload = array_merge($payload, $oldInput);
    }

    // ✅ Prefill until the user saves for the first time
    $hasSavedPayload = !empty($payload['_prefill_locked']) || $this->hasMeaningfulApplicationPayload($payload);
    $shouldPrefill = !$hasSavedPayload;
    if ($shouldPrefill) {
        $prefill = $this->loadPrefillForApplication(
            $db,
            $isAdminMode ? (int)($app['UserID'] ?? 0) : $userId,
            $applicationId,
            $employeeId
        );
        foreach ($prefill as $k => $v) {
            if (!isset($payload[$k]) || trim((string)$payload[$k]) === '') {
                $payload[$k] = $v;
            }
        }
        $payload['_prefill_debug'] = $this->prefillDebug;
    }
    $portalDefault = $this->loadPortalDefaultAddress($employeeId);
    if (!empty($portalDefault) && empty($oldInput)) {
        foreach (['address1', 'address2', 'address3', 'suburb', 'state', 'postcode'] as $key) {
            $payload[$key] = (string)($portalDefault[$key] ?? '');
        }
    }
    $eligibilityNotice = '';
    $blacklist = $this->getBlacklistMatch($employeeId, (int)($app['ApplicationTypeID'] ?? 0));
    if (!empty($blacklist['blocked'])) {
        $eligibilityNotice = $this->buildBlacklistMessage($blacklist);
    }
    $submitDeclarationText = $this->getSubmitAgreementText();

    // ---- Training status (CAPS) ----
    $trainingCompleted = $this->loadTrainingCompleted($db, (int)($app['ApplicationTypeID'] ?? 0), $employeeId);
    $payload['training_completed'] = $trainingCompleted ? 1 : 0;

    // ---- Runtime completion for checklist display ----
    $runtimeSteps = $this->loadRuntimeSteps($db, $applicationId);
    if (isset($runtimeSteps['training_completed'])) {
        $runtimeSteps['training_completed']['IsComplete'] = $trainingCompleted ? 1 : 0;
        $runtimeSteps['training_completed']['LastSavedAt'] = $runtimeSteps['training_completed']['LastSavedAt'] ?? date('Y-m-d H:i:s');
    }
    if (isset($runtimeSteps['cms_complete'])) {
        $cmsHolderActive = false;
        if (!empty($payload['cms_account_holder']) && $employeeId !== '') {
            $cmsHolderActive = $this->isCapsCmsHolderActive($employeeId, (string)$payload['cms_account_holder']);
        }
        $cmsOk = !empty($payload['company'])
              && !empty($payload['cost_centre'])
              && !empty($payload['cms_account_holder'])
              && $cmsHolderActive;
        $runtimeSteps['cms_complete']['IsComplete'] = $cmsOk ? 1 : 0;
        $runtimeSteps['cms_complete']['LastSavedAt'] = $runtimeSteps['cms_complete']['LastSavedAt'] ?? date('Y-m-d H:i:s');
    }
    if (isset($runtimeSteps['phone_correct'])) {
        $mobileOk = $this->isValidMobileByCountryCode(
            (string)($payload['mobile'] ?? ''),
            (string)($payload['mobile_country_code'] ?? '+61')
        );
        $runtimeSteps['phone_correct']['IsComplete'] = $mobileOk ? 1 : 0;
        $runtimeSteps['phone_correct']['LastSavedAt'] = $runtimeSteps['phone_correct']['LastSavedAt'] ?? date('Y-m-d H:i:s');
    }
    if (isset($runtimeSteps['age_valid'])) {
        $dob = trim((string)($payload['date_of_birth'] ?? ''));
        $ageOk = false;
        if ($dob !== '' && ($ts = strtotime($dob)) !== false) {
            $birth = new \DateTimeImmutable(date('Y-m-d', $ts));
            $ageOk = ((int)$birth->diff(new \DateTimeImmutable('today'))->y) >= 18;
        }
        $runtimeSteps['age_valid']['IsComplete'] = $ageOk ? 1 : 0;
        $runtimeSteps['age_valid']['LastSavedAt'] = $runtimeSteps['age_valid']['LastSavedAt'] ?? date('Y-m-d H:i:s');
    }
    if (isset($runtimeSteps['employee_type_valid'])) {
        $etOk = $this->isEmployeeTypeEntitled(
            (int)($app['ApplicationTypeID'] ?? 0),
            (string)($payload['employee_type'] ?? ''),
            $employeeId
        );
        $runtimeSteps['employee_type_valid']['IsComplete'] = $etOk ? 1 : 0;
        $runtimeSteps['employee_type_valid']['LastSavedAt'] = $runtimeSteps['employee_type_valid']['LastSavedAt'] ?? date('Y-m-d H:i:s');
    }
    $employeeTypeEntitled = $this->isEmployeeTypeEntitled(
        (int)($app['ApplicationTypeID'] ?? 0),
        (string)($payload['employee_type'] ?? ''),
        $employeeId
    );
    if (isset($runtimeSteps['application_submitted'])) {
        $status = strtolower(trim((string)($app['Status'] ?? '')));
        $submittedStatuses = ['submitted', 'tobeapproved', 'approved', 'senttobank', 'sent_to_bank', 'cardissued', 'card_issued'];
        $subOk = in_array($status, $submittedStatuses, true);
        $runtimeSteps['application_submitted']['IsComplete'] = $subOk ? 1 : 0;
        $runtimeSteps['application_submitted']['LastSavedAt'] = $runtimeSteps['application_submitted']['LastSavedAt'] ?? date('Y-m-d H:i:s');
    }

    // (Optional but recommended) If you want checklist to show FAIL instead of empty circles
    // for untouched items, uncomment this. It updates LastSavedAt for gate rows.
    /*
    try {
        $this->applyGateCompletion($db, $applicationId, $userId, $workflow, $payload);
        $runtimeSteps = $this->loadRuntimeSteps($db, $applicationId);
    } catch (\Throwable $e) {
        // don't break the page if checklist eval fails
        error_log('[ApplicationsController::edit applyGateCompletion WARN] ' . $e->getMessage());
    }
    */

    // Lifecycle progress (left panel)
    $lifecycleProgress = $this->buildLifecycleProgress((string)($app['Status'] ?? 'Draft'), (int)($app['ApplicationTypeID'] ?? 0));

    // ✅ Determine if submission is allowed (used by view to decide whether modal can open)
    // CMS dropdown data (CAPS)
    $companies = $this->loadCapsCompanies();
    $selectedCompany = (string)($payload['company'] ?? '');
    $costCentres = $selectedCompany !== '' ? $this->loadCapsCostCentres($selectedCompany) : [];
    $selectedWbsCode = trim((string)($payload['wbs'] ?? ''));
    $wbsList = ($selectedCompany !== '' && $selectedWbsCode !== '')
        ? $this->searchCapsWbs($selectedCompany, $selectedWbsCode, 1)
        : [];
    $cmsAccountHolders = $this->loadCapsCmsAccountHolders($employeeId);
    $currentCmsHolder = trim((string)($payload['cms_account_holder'] ?? ''));
    if ($currentCmsHolder === '' && !empty($cmsAccountHolders)) {
        $firstHolder = $cmsAccountHolders[0];
        $currentCmsHolder = is_array($firstHolder)
            ? trim((string)($firstHolder['value'] ?? ''))
            : trim((string)$firstHolder);
        if ($currentCmsHolder !== '') {
            $payload['cms_account_holder'] = $currentCmsHolder;
            if (isset($runtimeSteps['cms_complete'])) {
                $cmsHolderActive = $this->isCapsCmsHolderActive($employeeId, (string)$payload['cms_account_holder']);
                $cmsOk = !empty($payload['company'])
                      && !empty($payload['cost_centre'])
                      && !empty($payload['cms_account_holder'])
                      && $cmsHolderActive;
                $runtimeSteps['cms_complete']['IsComplete'] = $cmsOk ? 1 : 0;
                $runtimeSteps['cms_complete']['LastSavedAt'] = $runtimeSteps['cms_complete']['LastSavedAt'] ?? date('Y-m-d H:i:s');
            }
        }
    }
    $holderValues = array_map(
        static fn(array $h): string => (string)($h['value'] ?? ''),
        $cmsAccountHolders
    );
    if ($currentCmsHolder !== '' && !in_array($currentCmsHolder, $holderValues, true)) {
        array_unshift($cmsAccountHolders, [
            'value' => $currentCmsHolder,
            'label' => $currentCmsHolder . ' (Inactive)',
            'is_active' => false,
        ]);
    }

    $submitBlockingSteps = $this->listIncompleteRequiredSteps($workflow, $runtimeSteps);
    $canSubmit = $submitBlockingSteps === [];

    $selectedSupervisor = [];
    if ((int)($app['ApplicationTypeID'] ?? 0) === 1) {
        $selectedSupervisor = $this->loadCapsSupervisorByEmployeeId((string)($payload['supervisor_employee_id'] ?? ''));
        if ($selectedSupervisor !== []) {
            $payload['supervisor_employee_id'] = (string)($selectedSupervisor['employee_id'] ?? '');
            $payload['supervisor_name'] = (string)($selectedSupervisor['display_name'] ?? '');
            $payload['supervisor_email'] = (string)($selectedSupervisor['email'] ?? '');
        }
    }

    $submissionToken = bin2hex(random_bytes(16));
    SessionHelper::set('applications.submission_token.' . $applicationId, $submissionToken);

    $applicantDisplay = $this->loadUserDisplayName((int)($app['UserID'] ?? 0));
    if ($applicantDisplay === '') {
        $applicantDisplay = trim(implode(' ', array_filter([
            trim((string)($payload['first_name'] ?? '')),
            trim((string)($payload['middle_name'] ?? '')),
            trim((string)($payload['surname'] ?? '')),
        ], static fn(string $value): bool => $value !== '')));
    }

    $this->render('applications/Application', [
        'title'            => 'Application',
        'app'              => $app,
        'workflow'         => $workflow,
        'runtimeSteps'     => $runtimeSteps,
        'progress'         => $lifecycleProgress,
        'data'             => $payload,
        'validationErrors' => $validationErrors,
        'canSubmit'        => $canSubmit, // ✅ NEW
        'submitBlockingSteps' => $submitBlockingSteps,
        'companies'        => $companies,
        'costCentres'      => $costCentres,
        'wbsList'          => $wbsList,
        'cmsAccountHolders' => $cmsAccountHolders,
        'eligibilityNotice' => $eligibilityNotice,
        'employeeTypeEntitled' => $employeeTypeEntitled,
        'trainingLinks'    => $this->loadTrainingLinks($db, (int)($app['ApplicationTypeID'] ?? 0)),
        'mobileNumberHoverText' => $this->getMobileNumberHoverText($db),
        'ddPostalAddressesLink' => $this->getDdPostalAddressesLink($db),
        'ddPostalAddressesLabel' => $this->getDdPostalAddressesLabel($db),
        'brandingSectionLink' => $this->getManagedSettingValue($db, 'ApplicationBrandingLink'),
        'brandingSectionLabel' => $this->getManagedSettingValue($db, 'ApplicationBrandingLinkLabel', 'Branding'),
        'submitDeclarationText' => $submitDeclarationText,
        'adminViewMode' => $adminViewMode,
        'adminEditMode' => $adminEditMode,
        'submissionToken' => $submissionToken,
        'selectedSupervisor' => $selectedSupervisor,
        'applicantDisplay' => $applicantDisplay,
        'headerUserDisplay' => $isAdminMode && $applicantDisplay !== '' ? $applicantDisplay : '',
    ]);
}

    /**
     * AJAX: return CAPS options for a given company code
     * GET: index.php?route=applications/caps-options&company=XXX
     */
    public function capsOptions(): void
    {
        $company = trim((string)($_GET['company'] ?? ''));
        $costCentres = $company !== '' ? $this->loadCapsCostCentres($company) : [];

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }

        echo json_encode([
            'company' => $company,
            'costCentres' => $costCentres,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function wbsSearch(): void
    {
        $company = trim((string)($_GET['company'] ?? ''));
        $query = trim((string)($_GET['q'] ?? ''));

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }

        echo json_encode([
            'company' => $company,
            'items' => $this->searchCapsWbs($company, $query),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function supervisorSearch(): void
    {
        $query = trim((string)($_GET['q'] ?? ''));
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }

        echo json_encode([
            'items' => $this->searchCapsSupervisors($query),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function dpcApprovals(): void
    {
        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }
        if (!$this->isAdminViewer()) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Access denied.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
        $allowedStatuses = ['tobeapproved', 'approved', 'rejected', 'senttobank', 'sent_to_bank', 'cardissued', 'card_issued', 'draft', 'inprogress'];

        $sql = "
            SELECT
                a.ApplicationID,
                a.UserID,
                a.EmployeeID,
                a.Status,
                a.SubmittedAt,
                a.LastSavedAt,
                at.ApplicationTypeKey,
                at.ApplicationTypeName,
                ISNULL(NULLIF(u.DisplayName, ''), NULLIF(u.Username, '')) AS RequestorName,
                ISNULL(u.Email, '') AS RequestorEmail,
                s.DataJson
            FROM dbo.tblApplications a
            INNER JOIN dbo.tblApplicationTypes at
                ON at.ApplicationTypeID = a.ApplicationTypeID
            LEFT JOIN dbo.tblUsers u
                ON u.UserID = a.UserID
            LEFT JOIN dbo.tblApplicationSteps s
                ON s.ApplicationID = a.ApplicationID
               AND s.StepKey = 'application'
            WHERE a.ApplicationTypeID = 1
        ";
        $params = [];
        if ($statusFilter !== '' && in_array($statusFilter, $allowedStatuses, true)) {
            $sql .= " AND LOWER(ISNULL(a.Status, '')) = :status";
            $params['status'] = $statusFilter;
        }
        $sql .= " ORDER BY ISNULL(a.SubmittedAt, a.LastSavedAt) DESC, a.ApplicationID DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $approvalRows = [];
        foreach ($rows as $row) {
            $payload = json_decode((string)($row['DataJson'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];

            $approvalRows[] = [
                'ApplicationID' => (int)($row['ApplicationID'] ?? 0),
                'Status' => (string)($row['Status'] ?? ''),
                'ApplicationTypeName' => (string)($row['ApplicationTypeName'] ?? ''),
                'ApplicationTypeKey' => (string)($row['ApplicationTypeKey'] ?? ''),
                'RequestorUserID' => (int)($row['UserID'] ?? 0),
                'RequestorEmployeeID' => (string)($row['EmployeeID'] ?? ''),
                'RequestorName' => (string)($row['RequestorName'] ?? ''),
                'RequestorEmail' => (string)($row['RequestorEmail'] ?? ''),
                'SubmittedAt' => (string)($row['SubmittedAt'] ?? ''),
                'LastSavedAt' => (string)($row['LastSavedAt'] ?? ''),
                'SupervisorName' => (string)($payload['supervisor_name'] ?? ''),
                'SupervisorEmployeeID' => (string)($payload['supervisor_employee_id'] ?? ''),
                'SupervisorEmail' => (string)($payload['supervisor_email'] ?? ''),
                'ApplicantEmail' => (string)($payload['email'] ?? ''),
                'Company' => (string)($payload['company'] ?? ''),
                'CostCentre' => (string)($payload['cost_centre'] ?? ''),
                'Branding' => (string)($payload['branding'] ?? ''),
                'ApprovedByUserID' => (int)($payload['approved_by_user_id'] ?? 0),
                'ApprovedAt' => (string)($payload['approved_at'] ?? ''),
                'RejectedByUserID' => (int)($payload['rejected_by_user_id'] ?? 0),
                'RejectedAt' => (string)($payload['rejected_at'] ?? ''),
                'RejectReason' => (string)($payload['reject_reason'] ?? ''),
            ];
        }

        $this->render('applications/DpcApprovals', [
            'title' => 'DPC Application Approvals',
            'rows' => $approvalRows,
            'statusFilter' => $statusFilter,
        ]);
    }

    public function myDpcApprovals(): void
    {
        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            SessionHelper::set('auth.intended_route', 'applications/my-dpc-approvals');
            SessionHelper::set('flash.message', [
                'type' => 'info',
                'text' => 'Please sign in or activate your account to review your DPC approvals.',
            ]);
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $service = new ApprovalInboxService($this->db);
        $approvalRows = $service->listMyDpcApprovals($userId);

        $this->render('applications/DpcApprovals', [
            'title' => 'My DPC Approvals',
            'rows' => $approvalRows,
            'statusFilter' => '',
            'heading' => 'My DPC Approvals',
            'description' => 'DPC applications currently waiting on your approval.',
            'baseRoute' => 'applications/my-dpc-approvals',
            'showFilter' => false,
            'emptyMessage' => 'You have no DPC applications waiting for approval.',
        ]);
    }

    public function approve(): void
    {
        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        $applicationId = (int)($_GET['id'] ?? 0);
        if ($userId <= 0) {
            $this->rememberApprovalRoute($applicationId);
            SessionHelper::set('flash.message', [
                'type' => 'info',
                'text' => 'Please sign in or activate your account to review this approval request.',
            ]);
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        if ($applicationId <= 0) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Missing application id.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $application = $this->loadApplicationAdmin($this->db, $applicationId);
        if (!$application || (int)($application['ApplicationTypeID'] ?? 0) !== 1) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'DPC application not found.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $payload = $this->loadPayload($this->db, $applicationId);
        $decisionErrors = SessionHelper::get('applications.approval_errors.' . $applicationId);
        SessionHelper::forget('applications.approval_errors.' . $applicationId);
        if (!is_array($decisionErrors)) {
            $decisionErrors = [];
        }

        $currentStatus = strtolower(trim((string)($application['Status'] ?? '')));
        $isApprovalFinalised = in_array($currentStatus, ['approved', 'senttobank', 'sent_to_bank', 'cardissued', 'card_issued'], true);

        $canApproveAction = ($this->isCurrentUserAssignedDpcSupervisor($userId, $payload) || $this->isAdminViewer())
            && !$isApprovalFinalised;
        $isSelfRequest = (int)($application['UserID'] ?? 0) === $userId;
        if ($isSelfRequest && !$this->isAdminViewer()) {
            $canApproveAction = false;
        }

        $selectedSupervisor = $this->loadCapsSupervisorByEmployeeId((string)($payload['supervisor_employee_id'] ?? ''));
        $supervisorDisplay = trim((string)($selectedSupervisor['label'] ?? ($payload['supervisor_name'] ?? '')));

        $this->render('applications/ApplicationApprove', [
            'title' => 'DPC Application Approval',
            'application' => $application,
            'data' => $payload,
            'progress' => $this->buildLifecycleProgress((string)($application['Status'] ?? 'Draft'), 1),
            'canApproveAction' => $canApproveAction,
            'isApprovalFinalised' => $isApprovalFinalised,
            'isSelfRequest' => $isSelfRequest,
            'decisionErrors' => $decisionErrors,
            'supervisorDisplay' => $supervisorDisplay,
            'requestorDisplay' => $this->loadUserDisplayName((int)($application['UserID'] ?? 0)),
        ]);
    }

    public function approveSave(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }
        if (!csrf_check($_POST['_csrf'] ?? '')) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Security check failed.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            $this->rememberApprovalRoute((int)($_POST['application_id'] ?? 0));
            SessionHelper::set('flash.message', [
                'type' => 'info',
                'text' => 'Please sign in or activate your account to complete this approval.',
            ]);
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $applicationId = (int)($_POST['application_id'] ?? 0);
        $decision = strtolower(trim((string)($_POST['decision'] ?? '')));
        $rejectReason = trim((string)($_POST['reject_reason'] ?? ''));
        if ($applicationId <= 0) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Missing application id.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $application = $this->loadApplicationAdmin($this->db, $applicationId);
        if (!$application || (int)($application['ApplicationTypeID'] ?? 0) !== 1) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'DPC application not found.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $currentStatus = strtolower(trim((string)($application['Status'] ?? '')));
        if (in_array($currentStatus, ['rejected', 'approved', 'senttobank', 'sent_to_bank', 'cardissued', 'card_issued'], true)) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'This application is already finalised and cannot be actioned.']);
            header('Location: index.php?route=applications/approve&id=' . urlencode((string)$applicationId));
            exit;
        }
        if (!in_array($currentStatus, ['tobeapproved', 'submitted', 'awaitingapproval'], true)) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'This application is not awaiting supervisor approval.']);
            header('Location: index.php?route=applications/approve&id=' . urlencode((string)$applicationId));
            exit;
        }

        $payload = $this->loadPayload($this->db, $applicationId);
        if ((int)($application['UserID'] ?? 0) === $userId && !$this->isAdminViewer()) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'You cannot approve your own application.']);
            header('Location: index.php?route=applications/approve&id=' . urlencode((string)$applicationId));
            exit;
        }
        if (!$this->isAdminViewer() && !$this->isCurrentUserAssignedDpcSupervisor($userId, $payload)) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'You are not the assigned supervisor for this application.']);
            header('Location: index.php?route=applications/approve&id=' . urlencode((string)$applicationId));
            exit;
        }

        $errors = [];
        if (!in_array($decision, ['approve', 'reject'], true)) {
            $errors['decision'] = 'Invalid decision.';
        }
        if ($decision === 'reject' && $rejectReason === '') {
            $errors['reject_reason'] = 'Rejection reason is required.';
        }
        if ($errors !== []) {
            SessionHelper::set('applications.approval_errors.' . $applicationId, $errors);
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Please fix validation errors.']);
            header('Location: index.php?route=applications/approve&id=' . urlencode((string)$applicationId));
            exit;
        }

        try {
            $this->db->beginTransaction();
            $targetStatus = 'Rejected';
            if ($decision === 'approve') {
                $capsApplicationId = $this->exportSubmittedApplicationToCaps($application, $payload, $userId, true);
                $payload['approved_by_user_id'] = $userId;
                $payload['approved_at'] = gmdate('Y-m-d H:i:s');
                $payload['caps_application_id'] = $capsApplicationId;
                unset($payload['rejected_by_user_id'], $payload['rejected_at'], $payload['reject_reason']);
                $targetStatus = 'SentToBank';
            } else {
                $payload['rejected_by_user_id'] = $userId;
                $payload['rejected_at'] = gmdate('Y-m-d H:i:s');
                $payload['reject_reason'] = $rejectReason;
                unset($payload['approved_by_user_id'], $payload['approved_at']);
            }

            $this->savePayload($this->db, $applicationId, $userId, $payload);
            $this->syncDpcApprovalState($applicationId, $targetStatus);
            $this->db->commit();

            try {
                $this->sendDpcApplicationDecisionEmail($application, $payload, $decision);
            } catch (\Throwable $e) {
                error_log('[ApplicationsController::approveSave notify] ' . $e->getMessage());
            }

            $this->auditLog(
                strtoupper($decision),
                'DpcApplicationApproval',
                (string)$applicationId,
                [
                    'route' => 'applications/approve-save',
                    'new_status' => $decision === 'approve' ? 'SentToBank' : 'Rejected',
                ]
            );

            SessionHelper::set('flash.message', [
                'type' => 'success',
                'text' => $decision === 'approve'
                    ? 'Application approved and sent to the bank.'
                    : 'Application rejected.',
            ]);
        } catch (\Throwable $e) {
            if ($this->db instanceof \PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[ApplicationsController::approveSave ERROR] ' . $e->getMessage());
            SessionHelper::set('flash.message', [
                'type' => 'danger',
                'text' => $e->getMessage() === self::DUPLICATE_APPLICATION_MESSAGE
                    ? $e->getMessage()
                    : 'Could not save approval decision: ' . $e->getMessage(),
            ]);
        }

        header('Location: index.php?route=applications/approve&id=' . urlencode((string)$applicationId));
        exit;
    }

    public function adminResetApprovalProcess(int $applicationId, int $adminUserId): array
    {
        if (!($this->db instanceof \PDO)) {
            throw new \RuntimeException('Database connection is unavailable.');
        }
        if ($applicationId <= 0) {
            throw new \RuntimeException('Application ID is required.');
        }

        $application = $this->loadApplicationAdmin($this->db, $applicationId);
        if (!$application) {
            throw new \RuntimeException('Application not found.');
        }
        if ((int)($application['ApplicationTypeID'] ?? 0) !== 1) {
            throw new \RuntimeException('This application does not use the supervisor approval workflow.');
        }

        $payload = $this->loadPayload($this->db, $applicationId);
        if ($payload === []) {
            throw new \RuntimeException('No application payload was found to rebuild.');
        }

        $supervisorEmployeeId = trim((string)($payload['supervisor_employee_id'] ?? ''));
        if ($supervisorEmployeeId !== '') {
            $supervisor = $this->loadCapsSupervisorByEmployeeId($supervisorEmployeeId);
            if ($supervisor !== []) {
                $payload['supervisor_employee_id'] = (string)($supervisor['employee_id'] ?? '');
                $payload['supervisor_name'] = (string)($supervisor['display_name'] ?? '');
                $payload['supervisor_email'] = (string)($supervisor['email'] ?? '');
            }
        }

        $supervisorEmail = strtolower(trim((string)($payload['supervisor_email'] ?? '')));
        if ($supervisorEmail === '') {
            throw new \RuntimeException('The application does not have a supervisor email to resend approval to.');
        }

        unset(
            $payload['approved_by_user_id'],
            $payload['approved_at'],
            $payload['rejected_by_user_id'],
            $payload['rejected_at'],
            $payload['reject_reason']
        );

        $this->savePayload($this->db, $applicationId, $adminUserId, $payload);
        $this->syncDpcApprovalState($applicationId, 'ToBeApproved');
        $stmt = $this->db->prepare("
            UPDATE dbo.tblApplications
            SET SubmittedAt = SYSDATETIME()
            WHERE ApplicationID = :aid
        ");
        $stmt->execute(['aid' => $applicationId]);

        $this->sendDpcApplicationApprovalRequiredEmail((int)($application['UserID'] ?? 0), $application, $payload);

        return [
            'application_type_key' => strtolower(trim((string)($application['ApplicationTypeKey'] ?? 'dpc'))),
            'status_after' => 'ToBeApproved',
            'recipient_emails' => [$supervisorEmail],
        ];
    }

private function canSubmit(array $workflow, array $runtimeSteps): bool
{
    return $this->listIncompleteRequiredSteps($workflow, $runtimeSteps) === [];
}

private function isSubmitBlockingWorkflowStep(array $ws): bool
{
    $k = strtolower(trim((string)($ws['StepKey'] ?? '')));
    if ($k === '') {
        return false;
    }

    if (in_array($k, ['application', 'application_submitted', 'sent_to_bank', 'card_issued'], true)) {
        return false;
    }

    if (in_array($k, [
        'address_correct',
        'age_valid',
        'employee_type_valid',
        'phone_correct',
        'training_completed',
        'cms_complete',
    ], true)) {
        return true;
    }

    return (int)($ws['IsRequired'] ?? 1) === 1;
}

private function listIncompleteRequiredSteps(array $workflow, array $runtimeSteps): array
{
    $blocking = [];
    foreach ($workflow as $ws) {
        $k = strtolower(trim((string)($ws['StepKey'] ?? '')));
        if ($k === '') continue;
        if (!$this->isSubmitBlockingWorkflowStep($ws)) continue;

        $rt = $runtimeSteps[$k] ?? null;
        $done = $rt ? ((int)($rt['IsComplete'] ?? 0) === 1) : false;

        if (!$done) {
            $label = trim((string)($ws['StepLabel'] ?? $k));
            if ($k === 'address_correct') {
                $label = 'Employee Details Correct';
            }
            $blocking[] = $label !== '' ? $label : $k;
        }
    }
    return $blocking;
}


    /**
     * Save entire application payload and advance workflow gates.
     * POST: index.php?route=applications/save&id=123
     */
    public function save(): void
{
    $db = $this->db;

    $actorUserId = (int)(SessionHelper::get('auth.user_id') ?? 0);
    $userId = $actorUserId;
    if ($actorUserId <= 0) {
        header('Location: index.php?route=auth/loginForm');
        exit;
    }

    $applicationId = (int)($_GET['id'] ?? 0);
    if ($applicationId <= 0) {
        header('Location: index.php?route=portalcards/list');
        exit;
    }

    // CSRF check
    if (function_exists('csrf_check') && !csrf_check($_POST['_csrf'] ?? '')) {
        SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Security check failed. Please try again.']);
        $csrfRedirect = 'index.php?route=applications/edit&id=' . urlencode((string)$applicationId);
        if (!empty($_POST['admin_override']) && $this->isAdminViewer()) {
            $csrfRedirect .= '&admin_edit=1';
        }
        header('Location: ' . $csrfRedirect);
        exit;
    }

    $isAdminOverride = !empty($_POST['admin_override']) && $this->isAdminViewer();
    $editRoute = 'index.php?route=applications/edit&id=' . urlencode((string)$applicationId) . ($isAdminOverride ? '&admin_edit=1' : '');

    $app = $isAdminOverride
        ? $this->loadApplicationAdmin($db, $applicationId)
        : $this->loadApplication($db, $applicationId, $actorUserId);
    if (!$app) {
        SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Application not found.']);
        header('Location: index.php?route=portalcards/list');
        exit;
    }
    $applicationOwnerUserId = (int)($app['UserID'] ?? 0);
    $requestorUserId = $applicationOwnerUserId > 0 ? $applicationOwnerUserId : $actorUserId;

    if ((int)($app['Locked'] ?? 0) === 1) {
        SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'This application is locked.']);
        header('Location: ' . $editRoute);
        exit;
    }
    $status = strtolower(trim((string)($app['Status'] ?? '')));
    if (!in_array($status, ['draft', 'inprogress'], true)) {
        SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Only Draft or InProgress applications can be modified.']);
        header('Location: ' . $editRoute);
        exit;
    }

    $workflow = $this->loadWorkflowSteps($db, (int)$app['ApplicationTypeID']);
    $runtimeSteps = $this->loadRuntimeSteps($db, $applicationId);
    $employeeId = trim((string)(SessionHelper::get('portalcards.filters.employeeId') ?? (SessionHelper::get('auth.employee_id') ?? '')));
    if ($employeeId === '') {
        $employeeId = trim((string)($app['EmployeeID'] ?? ''));
    }
    $blacklist = $this->getBlacklistMatch($employeeId, (int)($app['ApplicationTypeID'] ?? 0));

    // Build payload from POST (exclude internal fields)
    $payload = $_POST;
    unset($payload['_csrf'], $payload['_action']);
    // Once user saves, never prefill again
    $payload['_prefill_locked'] = 1;
    if (array_key_exists('suburb', $payload)) {
        $payload['suburb'] = $this->normalizeSuburbValue((string)$payload['suburb']);
    }
    if (array_key_exists('mobile', $payload)) {
        $payload['mobile'] = $this->normalizePhoneInput((string)$payload['mobile']);
    }
    if (array_key_exists('mobile_country_code', $payload)) {
        $payload['mobile_country_code'] = trim((string)$payload['mobile_country_code']);
    }

    $action = (string)($_POST['_action'] ?? 'save'); // save | submit
    $requiresSupervisorApproval = (int)($app['ApplicationTypeID'] ?? 0) === 1;

    if (!empty($payload['supervisor_employee_id'])) {
        $supervisor = $this->loadCapsSupervisorByEmployeeId((string)$payload['supervisor_employee_id']);
        if ($supervisor !== []) {
            $payload['supervisor_employee_id'] = (string)($supervisor['employee_id'] ?? '');
            $payload['supervisor_name'] = (string)($supervisor['display_name'] ?? '');
            $payload['supervisor_email'] = (string)($supervisor['email'] ?? '');
        }
    }

    if ($action === 'submit') {
        $postedSubmissionToken = trim((string)($_POST['submission_token'] ?? ''));
        $sessionSubmissionToken = trim((string)(SessionHelper::get('applications.submission_token.' . $applicationId) ?? ''));
        if ($postedSubmissionToken === '' || $sessionSubmissionToken === '' || !hash_equals($sessionSubmissionToken, $postedSubmissionToken)) {
            SessionHelper::set('flash.message', [
                'type' => 'warning',
                'text' => 'This application submit action has already been used or expired. Please review and submit again.',
            ]);
            header('Location: ' . $editRoute);
            exit;
        }
        SessionHelper::forget('applications.submission_token.' . $applicationId);
    }

    // Validate required fields only when advancing to the next step
    if ($action === 'submit') {
        if (!empty($blacklist['blocked'])) {
            $message = $this->buildBlacklistMessage($blacklist);
            $this->auditLog(
                'DENIED',
                'Application',
                (string)$applicationId,
                [
                    'route' => 'applications/save',
                    'operation' => 'submit',
                    'reason' => 'blacklisted',
                    'employee_id' => $employeeId,
                ]
            );
            SessionHelper::set('flash.message', [
                'type' => 'danger',
                'text' => $message,
            ]);
            SessionHelper::set('applications.old_input.' . $applicationId, $payload);
            header('Location: ' . $editRoute);
            exit;
        }
        $errors = $this->validateRequiredFields($payload, $workflow, $app);
        if ($requiresSupervisorApproval && trim((string)($payload['supervisor_employee_id'] ?? '')) === '') {
            $errors['supervisor_employee_id'] = 'Supervisor is required.';
        }
        if (
            $requiresSupervisorApproval
            && trim((string)($payload['supervisor_employee_id'] ?? '')) !== ''
            && strcasecmp(
                trim((string)($payload['supervisor_employee_id'] ?? '')),
                trim((string)$employeeId)
            ) === 0
        ) {
            $errors['supervisor_employee_id'] = 'Supervisor cannot be the applicant.';
        }
        if ($requiresSupervisorApproval && trim((string)($payload['supervisor_employee_id'] ?? '')) !== '' && trim((string)($payload['supervisor_email'] ?? '')) === '') {
            $errors['supervisor_employee_id'] = 'Supervisor must have a valid email address.';
        }

        if (!empty($errors)) {
            $this->auditLog(
                'DENIED',
                'Application',
                (string)$applicationId,
                [
                    'route' => 'applications/save',
                    'operation' => 'submit',
                    'reason' => 'validation_failed',
                    'error_fields' => array_keys($errors),
                ]
            );
            SessionHelper::set('flash.message', [
                'type' => 'danger',
                'text' => 'Please fill in all required fields before submitting application.',
            ]);

            // store errors for the view
            SessionHelper::set('applications.validation_errors.' . $applicationId, $errors);

            // ✅ store attempted input so fields repopulate after redirect
            SessionHelper::set('applications.old_input.' . $applicationId, $payload);

            header('Location: ' . $editRoute);
            exit;
        }


        // Clear old errors when valid
        SessionHelper::forget('applications.validation_errors.' . $applicationId);
    }

    $trainingCompleted = $this->loadTrainingCompleted($db, (int)($app['ApplicationTypeID'] ?? 0), $employeeId);
    $payload['training_completed'] = $trainingCompleted ? 1 : 0;

    // Begin transaction to save data
    $db->beginTransaction();
    try {
        // Save payload to StepKey='application'
        $this->savePayload($db, $applicationId, $actorUserId, $payload);

        // Always evaluate gates so checklist updates on both Save and Save & Next
        $this->applyGateCompletion($db, $applicationId, $actorUserId, $workflow, $payload);

        $runtimeSteps = $this->loadRuntimeSteps($db, $applicationId);

        if ($action === 'submit' && !$this->canSubmit($workflow, $runtimeSteps)) {
            $blockingSteps = $this->listIncompleteRequiredSteps($workflow, $runtimeSteps);
            $message = 'Please complete all mandatory checks before submitting application.';
            if ($blockingSteps !== []) {
                $message .= ' Outstanding items: ' . implode(', ', $blockingSteps) . '.';
            }
            throw new \RuntimeException($message);
        }

        // Advance current step only on "next"
        if ($action === 'submit') {
            $nextKey = $this->findFirstIncompleteKey($workflow, $runtimeSteps);

            if ($nextKey !== null) {
                $this->setCurrentStep($db, $applicationId, $nextKey);
            } else {
                $last = end($workflow);
                if (is_array($last) && !empty($last['StepKey'])) {
                    $this->setCurrentStep($db, $applicationId, (string)$last['StepKey']);
                }
            }
        }

        // Update LastSavedAt and change status if needed
        if ($action === 'submit') {
            $this->savePortalDefaultAddress($db, $employeeId, $payload, $applicationId, $actorUserId);
            if ($requiresSupervisorApproval) {
                $stmt = $db->prepare("
                    UPDATE dbo.tblApplications
                    SET LastSavedAt = SYSDATETIME(),
                        Status = 'ToBeApproved',
                        CurrentStepKey = 'tobeapproved',
                        Locked = 1,
                        SubmittedAt = SYSDATETIME()
                    WHERE ApplicationID = :aid
                ");
                $stmt->execute(['aid' => $applicationId]);
            } else {
                $this->exportSubmittedApplicationToCaps($app, $payload, $requestorUserId, true);
                $stmt = $db->prepare("
                    UPDATE dbo.tblApplications
                    SET LastSavedAt = SYSDATETIME(),
                        Status = 'SentToBank',
                        CurrentStepKey = 'senttobank',
                        Locked = 1,
                        SubmittedAt = SYSDATETIME()
                    WHERE ApplicationID = :aid
                ");
                $stmt->execute(['aid' => $applicationId]);
            }
        } else {
            $stmt = $db->prepare("
                UPDATE dbo.tblApplications
                SET LastSavedAt = SYSDATETIME(),
                    Status = CASE WHEN Status = 'Draft' THEN 'InProgress' ELSE Status END
                WHERE ApplicationID = :aid
            ");
            $stmt->execute(['aid' => $applicationId]);
        }

        // Commit the transaction
        $db->commit();

        if ($action === 'submit') {
            try {
                $submittedApp = $isAdminOverride
                    ? ($this->loadApplicationAdmin($db, $applicationId) ?? $app)
                    : ($this->loadApplication($db, $applicationId, $actorUserId) ?? $app);
                if ($requiresSupervisorApproval) {
                    $this->sendDpcApplicationApprovalRequiredEmail($requestorUserId, $submittedApp, $payload);
                    $this->sendDpcApplicationSubmittedConfirmation($requestorUserId, $submittedApp, $payload);
                } else {
                    $this->sendApplicationSubmittedConfirmation($requestorUserId, $submittedApp, $payload);
                }
            } catch (\Throwable $e) {
                error_log('[ApplicationsController::sendApplicationSubmittedConfirmation] ' . $e->getMessage());
            }
        }

        // Set success flash message and redirect
        SessionHelper::set('flash.message', [
            'type' => 'success',
            'text' => $action === 'submit'
                ? ($requiresSupervisorApproval
                    ? 'Your application has been submitted and is awaiting supervisor approval.'
                    : 'Your application has been successfully submitted and has been sent to the bank.')
                : 'Saved.',
        ]);
        SessionHelper::forget('applications.validation_errors.' . $applicationId);
        SessionHelper::forget('applications.old_input.' . $applicationId);
        $this->auditLog(
            $action === 'submit' ? 'SUBMIT' : 'SAVE_DRAFT',
            'Application',
            (string)$applicationId,
            [
                'route' => 'applications/save',
                'status_after' => $action === 'submit'
                    ? ($requiresSupervisorApproval ? 'ToBeApproved' : 'SentToBank')
                    : 'InProgress',
                'admin_override' => $isAdminOverride ? 1 : 0,
                'owner_user_id' => $applicationOwnerUserId,
            ]
        );

        header('Location: ' . $editRoute);
        exit;

    } catch (\Throwable $e) {
        // If an error occurs, roll back the transaction
        if ($db->inTransaction()) $db->rollBack();

        if ($action === 'submit') {
            SessionHelper::set('applications.old_input.' . $applicationId, $payload);
        }

        // Log the error and set flash message
        error_log('[ApplicationsController::save ERROR] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

        $errorMessage = $e->getMessage();
        SessionHelper::set('flash.message', [
            'type' => 'danger',
            'text' => $errorMessage === self::DUPLICATE_APPLICATION_MESSAGE
                ? $errorMessage
                : 'Could not save application: ' . $errorMessage,
        ]);

        header('Location: ' . $editRoute);
        exit;
    }
}
 
    // -----------------------
    // Helpers (mostly from your existing controller)
    // -----------------------

    private function execChecked(\PDOStatement $stmt, string $sql, array $params = []): void
    {
        $expected = substr_count($sql, '?');
        $actual   = count($params);

        if ($expected !== $actual) {
            throw new \RuntimeException("Param mismatch: expected {$expected}, got {$actual}. SQL={$sql}");
        }

        $stmt->execute($params);
    }


    private function loadApplication(\PDO $db, int $applicationId, int $userId): ?array
    {
        $emp = trim((string)SessionHelper::get('auth.employee_id', ''));
        $sql = "
            SELECT a.*, at.ApplicationTypeName
            FROM dbo.tblApplications a
            LEFT JOIN dbo.tblApplicationTypes at
              ON at.ApplicationTypeID = a.ApplicationTypeID
            WHERE ApplicationID = ?
              AND (
                UserID = ?
                OR (? <> '' AND LTRIM(RTRIM(ISNULL(CAST(a.EmployeeID AS NVARCHAR(50)), ''))) = ?)
                OR (? <> '' AND EXISTS (
                    SELECT 1
                    FROM dbo.tblPORTALCards p
                    WHERE p.ApplicationID = a.ApplicationID
                      AND p.EmployeeID = ?
                ))
              )
        ";
        $stmt = $db->prepare($sql);
        $this->execChecked($stmt, $sql, [
            $applicationId,
            $userId,
            $emp,
            $emp,
            $emp,
            $emp,
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function loadApplicationAdmin(\PDO $db, int $applicationId): ?array
    {
        $stmt = $db->prepare("
            SELECT a.*, at.ApplicationTypeName
            FROM dbo.tblApplications a
            LEFT JOIN dbo.tblApplicationTypes at
              ON at.ApplicationTypeID = a.ApplicationTypeID
            WHERE a.ApplicationID = :aid
        ");
        $stmt->execute(['aid' => $applicationId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function isAdminViewer(): bool
    {
        $roles = array_map('strtolower', (array)SessionHelper::get('auth.roles', []));
        if (in_array('admin', $roles, true)) {
            return true;
        }
        return in_array('ADMIN_ALL', (array)SessionHelper::get('auth.perms', []), true)
            || in_array('SYSADMIN', (array)SessionHelper::get('auth.perms', []), true);
    }

   private function loadApplicationTypeByKey(\PDO $db, string $typeKey): ?array
    {
        $stmt = $db->prepare("
            SELECT TOP 1 *
            FROM dbo.tblApplicationTypes
            WHERE IsActive = 1
            AND LOWER(ApplicationTypeKey) = :k
        ");
        $stmt->execute(['k' => strtolower(trim($typeKey))]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function loadWorkflowSteps(\PDO $db, int $applicationTypeId): array
    {
        $stmt = $db->prepare("
            SELECT
                WorkFlowStepID,
                StepKey,
                StepLabel,
                StepOrder,
                ViewPath,
                HelpText,
                IsRequired,
                AllowBackNavigation,
                AllowEditAfterSubmit,
                ValidatorKey,
                RulesJson,
                IsActive
            FROM dbo.tblApplicationWorkFlowSteps
            WHERE ApplicationTypeID = :atid AND IsActive = 1
            ORDER BY StepOrder ASC
        ");
        $stmt->execute(['atid' => $applicationTypeId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

   private function loadRuntimeSteps(\PDO $db, int $applicationId): array
    {
        $stmt = $db->prepare("
            SELECT StepKey, IsComplete, CompletedAt, LastSavedAt, DataJson, UpdatedBy
            FROM dbo.tblApplicationSteps
            WHERE ApplicationID = :aid
        ");
        $stmt->execute(['aid' => $applicationId]);

        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $k = strtolower(trim((string)($r['StepKey'] ?? '')));
            if ($k === '') continue;
            $map[$k] = $r;
        }
        return $map;
    }


    private function ensureRuntimeSteps(\PDO $db, int $applicationId, array $workflow): void
    {
        $sql = "
            IF NOT EXISTS (
                SELECT 1
                FROM dbo.tblApplicationSteps
                WHERE ApplicationID = ? AND StepKey = ?
            )
            INSERT INTO dbo.tblApplicationSteps (ApplicationID, StepKey, IsComplete)
            VALUES (?, ?, 0);
        ";

        $stmt = $db->prepare($sql);

        foreach ($workflow as $ws) {
            $k = (string)$ws['StepKey'];
            $stmt->execute([$applicationId, $k, $applicationId, $k]); // values repeated, placeholders are positional
        }
    }

private function ensurePayloadRow(\PDO $db, int $applicationId): void
    {
        $sql = "
            IF NOT EXISTS (
                SELECT 1
                FROM dbo.tblApplicationSteps
                WHERE ApplicationID = ? AND StepKey = 'application'
            )
            INSERT INTO dbo.tblApplicationSteps (ApplicationID, StepKey, IsComplete)
            VALUES (?, 'application', 0);
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$applicationId, $applicationId]);
    }


    private function loadPayload(\PDO $db, int $applicationId): array
    {
        $stmt = $db->prepare("
            SELECT DataJson
            FROM dbo.tblApplicationSteps
            WHERE ApplicationID = :aid AND StepKey = 'application'
        ");
        $stmt->execute(['aid' => $applicationId]);
        $json = (string)($stmt->fetchColumn() ?? '');
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function loadPayloadJson(\PDO $db, int $applicationId): ?string
    {
        $stmt = $db->prepare("
            SELECT DataJson
            FROM dbo.tblApplicationSteps
            WHERE ApplicationID = :aid AND StepKey = 'application'
        ");
        $stmt->execute(['aid' => $applicationId]);
        $raw = $stmt->fetchColumn();
        if ($raw === false || $raw === null) {
            return null;
        }
        return (string)$raw;
    }

   
    private function savePayload(\PDO $db, int $applicationId, int $userId, array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        throw new \RuntimeException('json_encode failed: ' . json_last_error_msg());
    }

    // 1) Try UPDATE first (normal path)
    $updSql = "
        UPDATE dbo.tblApplicationSteps
        SET DataJson    = ?,
            LastSavedAt = SYSUTCDATETIME(),
            UpdatedBy   = ?
        WHERE ApplicationID = ?
          AND StepKey = 'application';
    ";

    $upd = $db->prepare($updSql);
    $upd->execute([$json, $userId, $applicationId]);

    // 2) If no row updated, INSERT it (safety net)
    if ($upd->rowCount() === 0) {
        $insSql = "
            INSERT INTO dbo.tblApplicationSteps
                (ApplicationID, StepKey, IsComplete, DataJson, LastSavedAt, UpdatedBy)
            VALUES
                (?, 'application', 0, ?, SYSUTCDATETIME(), ?);
        ";

        $ins = $db->prepare($insSql);
        $ins->execute([$applicationId, $json, $userId]);
    }
}

private function findOpenApplication(\PDO $db, int $userId, int $applicationTypeId, string $employeeId = ''): ?array
{
    $employeeId = trim($employeeId);
    $sql = "
        SELECT TOP 1 a.*
        FROM dbo.tblApplications a
        LEFT JOIN dbo.tblApplicationSteps s
          ON s.ApplicationID = a.ApplicationID
         AND s.StepKey = 'application'
        WHERE a.UserID = :uid
          AND a.ApplicationTypeID = :atid
          AND a.Status IN ('Draft','InProgress','ToBeApproved','Approved','SentToBank')
          AND a.Locked = 0
    ";
    $params = [
        'uid' => $userId,
        'atid' => $applicationTypeId,
    ];

    if ($employeeId !== '') {
        $sql .= "
          AND (
                LTRIM(RTRIM(ISNULL(CAST(a.EmployeeID AS NVARCHAR(50)), ''))) = :employee_id_app
                OR LTRIM(RTRIM(ISNULL(JSON_VALUE(s.DataJson, '$.employee_id'), ''))) = :employee_id_payload
              )
        ";
        $params['employee_id_app'] = $employeeId;
        $params['employee_id_payload'] = $employeeId;
    }

    $sql .= " ORDER BY a.ApplicationID DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

private function createDraftApplication(\PDO $db, int $userId, int $applicationTypeId, string $employeeId): array
{
    $employeeId = trim($employeeId);
    $workflow = $this->loadWorkflowSteps($db, $applicationTypeId);
    if (!$workflow) {
        throw new \RuntimeException('No workflow configured for this type.');
    }

    $firstKey = (string)$workflow[0]['StepKey'];

    $db->beginTransaction();
    try {
        $sql = "
            INSERT INTO dbo.tblApplications (UserID, ApplicationTypeID, Status, CurrentStepKey, StartedAt, Locked, EmployeeID)
            OUTPUT INSERTED.ApplicationID
            VALUES (?, ?, 'Draft', ?, SYSDATETIME(), 0, ?);
        ";
        $stmt = $db->prepare($sql);
        $this->execChecked($stmt, $sql, [$userId, $applicationTypeId, $firstKey, $employeeId !== '' ? $employeeId : null]);

        $newId = (int)$stmt->fetchColumn();
        if ($newId <= 0) {
            throw new \RuntimeException('Insert did not return new ApplicationID.');
        }

        $this->ensureRuntimeSteps($db, $newId, $workflow);
        $this->ensurePayloadRow($db, $newId);

        $db->commit();
        $app = $this->loadApplication($db, $newId, $userId);
        if (!$app) {
            throw new \RuntimeException('Created application could not be reloaded.');
        }
        return $app;
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

private function applicationTypeRequiresPrivacyAgreement(array $type): bool
{
    $required = !empty($type['PrivacyAgreementRequired']);
    $text = trim((string)($type['PrivacyAgreementText'] ?? ''));
    return $required && $text !== '';
}



   
    private function setCurrentStep(\PDO $db, int $applicationId, string $stepKey): void
    {
        $stmt = $db->prepare("
            UPDATE dbo.tblApplications
            SET CurrentStepKey = :k,
                LastSavedAt = SYSDATETIME()
            WHERE ApplicationID = :aid
        ");
        $stmt->execute(['k' => $stepKey, 'aid' => $applicationId]);
    }

 private function applyGateCompletion(
    \PDO $db,
    int $applicationId,
    int $userId,
    array $workflow,
    array $payload
): void {
    $app = $this->loadApplication($db, $applicationId, $userId)
        ?? $this->loadApplicationAdmin($db, $applicationId)
        ?? [];
    $applicationTypeId = (int)($app['ApplicationTypeID'] ?? ($payload['application_type_id'] ?? 0));
    $resolvedEmployeeId = trim((string)(
        $payload['employee_id']
        ?? (SessionHelper::get('portalcards.filters.employeeId')
        ?? (SessionHelper::get('auth.employee_id')
        ?? ($app['EmployeeID'] ?? '')))
    ));

    // 1) Build the list of relevant workflow step keys (skip admin/process + payload row)
    $keys = [];
    foreach ($workflow as $ws) {
        $k = strtolower(trim((string)($ws['StepKey'] ?? '')));
        if ($k === '') continue;
        if (in_array($k, ['application', 'sent_to_bank', 'card_issued'], true)) continue;
        $keys[] = $k;
    }

    // ✅ Normalise keys (remove blanks + duplicates)
    $keys = array_values(array_unique(array_filter($keys, fn($v) => is_string($v) && trim($v) !== '')));

    if (!$keys) {
        return;
    }

    // 2) Touch all workflow rows so UI shows FAIL instead of empty circle
    //    (this guarantees LastSavedAt is not null for these steps)
    $in = implode(',', array_fill(0, count($keys), '?'));
    $touchSql = "
        UPDATE dbo.tblApplicationSteps
        SET LastSavedAt = SYSUTCDATETIME(),
            UpdatedBy   = ?
        WHERE ApplicationID = ?
          AND LOWER(LTRIM(RTRIM(StepKey))) IN ($in)
    ";

    $touchParams = array_merge([$userId, $applicationId], $keys);

    $touchStmt = $db->prepare($touchSql);

    // ✅ Use param-count guard to avoid ODBC 07002 "COUNT field incorrect"
    if (method_exists($this, 'execChecked')) {
        $this->execChecked($touchStmt, $touchSql, $touchParams);
    } else {
        $touchStmt->execute($touchParams);
    }

    // Helper: Age from DOB (expects YYYY-MM-DD in payload; readonly field can display DD-MM-YYYY)
    $dob = trim((string)($payload['date_of_birth'] ?? ''));
    $age = null;
    if ($dob !== '' && ($ts = strtotime($dob)) !== false) {
        $birth = new \DateTimeImmutable(date('Y-m-d', $ts));
        $age = (int)$birth->diff(new \DateTimeImmutable('today'))->y;
    }

    // 3) Now compute and set completion status per key
    $updStmt = $db->prepare("
        UPDATE dbo.tblApplicationSteps
        SET
            IsComplete = ?,
            CompletedAt = CASE
                WHEN ? = 1 AND CompletedAt IS NULL THEN SYSUTCDATETIME()
                ELSE CompletedAt
            END,
            LastSavedAt = SYSUTCDATETIME(),
            UpdatedBy = ?
        WHERE ApplicationID = ?
          AND LOWER(LTRIM(RTRIM(StepKey))) = ?
    ");

    foreach ($keys as $key) {

        $valid = false;

        switch ($key) {
            case 'address_correct':
                $a1 = (string)($payload['address1'] ?? '');
                $a2 = (string)($payload['address2'] ?? '');
                $a3 = (string)($payload['address3'] ?? '');
                $sub = (string)($payload['suburb'] ?? '');
                $title = strtoupper(trim((string)($payload['title'] ?? '')));
                $gender = strtoupper(trim((string)($payload['gender'] ?? '')));
                $titleOk = in_array($title, ['PROF', 'DR', 'MR', 'MRS', 'MS', 'MISS', 'MX'], true);
                $genderOk = in_array($gender, ['M', 'F', 'X'], true);

                $valid = !empty($payload['address1'])
                      && !empty($payload['suburb'])
                      && !empty($payload['state'])
                      && !empty($payload['postcode'])
                      && $titleOk
                      && $genderOk
                      && mb_strlen($a1) <= 30
                      && mb_strlen($a2) <= 30
                      && mb_strlen($a3) <= 30
                      && mb_strlen($sub) <= 22
                      && ctype_digit(trim((string)($payload['postcode'] ?? '')))
                      && mb_strlen(trim((string)($payload['postcode'] ?? ''))) <= 4;
                break;

            case 'age_valid':
                $valid = ($age !== null && $age >= 18);
                break;

            case 'employee_type_valid':
                $valid = $this->isEmployeeTypeEntitled(
                    $applicationTypeId,
                    (string)($payload['employee_type'] ?? ''),
                    $resolvedEmployeeId
                );
                break;

            case 'phone_correct':
                $valid = $this->isValidMobileByCountryCode(
                    (string)($payload['mobile'] ?? ''),
                    (string)($payload['mobile_country_code'] ?? '+61')
                );
                break;

            case 'training_completed':
                $valid = !empty($payload['training_completed']);
                break;

            case 'cms_complete':
                $valid = !empty($payload['company'])
                      && !empty($payload['cost_centre'])
                      && !empty($payload['cms_account_holder'])
                      && $this->isCapsCmsHolderActive($resolvedEmployeeId, (string)($payload['cms_account_holder'] ?? ''));
                break;

            case 'application_submitted':
                $valid = false; // set later when you implement submit
                break;

            default:
                $valid = false;
                break;
        }

        $ok = $valid ? 1 : 0;

        $updStmt->execute([
            $ok,           // IsComplete
            $ok,           // used in CASE
            $userId,       // UpdatedBy
            $applicationId,
            $key
        ]);
    }
}


private function loadPrefillForApplication(\PDO $db, int $userId, int $applicationId, string $preferredEmployeeId = ''): array
{
    // Prefill from CAPS on first load only
    $employeeId = trim($preferredEmployeeId);
    if ($employeeId === '') {
        $employeeId = trim((string)(SessionHelper::get('portalcards.filters.employeeId') ?? (SessionHelper::get('auth.employee_id') ?? '')));
    }
    if ($employeeId === '' && $applicationId > 0) {
        $stmt = $db->prepare("
            SELECT TOP 1 EmployeeID
            FROM dbo.tblApplications
            WHERE ApplicationID = :aid
        ");
        $stmt->execute(['aid' => $applicationId]);
        $employeeId = trim((string)($stmt->fetchColumn() ?? ''));
    }
    if ($employeeId === '' && $userId > 0) {
        $stmt = $db->prepare("
            SELECT TOP 1 EmployeeID
            FROM dbo.tblUsers
            WHERE UserID = :uid
        ");
        $stmt->execute(['uid' => $userId]);
        $employeeId = trim((string)($stmt->fetchColumn() ?? ''));
    }
    if ($employeeId === '' && $applicationId > 0) {
        $stmt = $db->prepare("
            SELECT TOP 1 EmployeeID
            FROM dbo.tblPORTALCards
            WHERE ApplicationID = :aid
              AND EmployeeID IS NOT NULL
              AND LTRIM(RTRIM(EmployeeID)) <> ''
            ORDER BY CardID DESC
        ");
        $stmt->execute(['aid' => $applicationId]);
        $employeeId = trim((string)($stmt->fetchColumn() ?? ''));
    }

    $out = [];
    $portalDefault = $this->loadPortalDefaultAddress($employeeId);
    $portalDefaultFound = !empty($portalDefault);

    // Try CAPS connection first
    global $capsConn;
    $capsFound = false;
    if ($employeeId !== '' && ($capsConn instanceof \PDO)) {
        $stmt = $capsConn->prepare("
            SELECT
                PostalAddress_Unit             AS address1,
                PostalAddress_ClientLocation   AS address2,
                PostalAddress_DeliveryLocation AS address3,
                PostalAddress_City             AS suburb,
                PostalAddress_State            AS state,
                PostalAddress_PostCode         AS postcode,
                GroupName        AS group_name,
                COALESCE(NULLIF(LTRIM(RTRIM(FormalFirstName)), ''), NULLIF(LTRIM(RTRIM(Firstname)), '')) AS first_name,
                Surname          AS surname,
                Email_Address    AS email,
                TelephoneNumber  AS phone,
                MobileNumber     AS mobile,
                DateOfBirth      AS date_of_birth,
                EmployeeType     AS employee_type,
                Title            AS title,
                LEFT(LTRIM(RTRIM(Gender)), 1) AS gender
            FROM dbo.tblCAPSCDMCPortal
            WHERE EmployeeID = :eid
        ");
        $stmt->execute(['eid' => $employeeId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $out = $row;
            $capsFound = true;
        }
    }

    if ($portalDefaultFound) {
        foreach (['address1', 'address2', 'address3', 'suburb', 'state', 'postcode'] as $key) {
            unset($out[$key]);
        }
        $out = array_merge($out, $portalDefault);
    }

    // Fallback / additional system fields from session if needed
    $out = $out + [
        'first_name'         => (string)(SessionHelper::get('auth.first_name') ?? ''),
        'surname'            => (string)(SessionHelper::get('auth.last_name') ?? ''),
        'company'            => (string)(SessionHelper::get('auth.company') ?? ''),
        'cost_centre'        => (string)(SessionHelper::get('auth.cost_centre') ?? ''),
        'cms_account_holder' => (string)(SessionHelper::get('auth.cms_account_holder') ?? ''),
    ];

    // Normalise
    foreach ($out as $k => $v) {
        $out[$k] = trim((string)$v);
    }

    $nonEmptyKeys = [];
    foreach ($out as $k => $v) {
        if (trim((string)$v) !== '') {
            $nonEmptyKeys[] = $k;
        }
    }

    $this->prefillDebug = [
        'employee_id'   => $employeeId,
        'portal_default_found' => $portalDefaultFound ? 'yes' : 'no',
        'caps_conn'     => ($capsConn instanceof \PDO) ? 'yes' : 'no',
        'caps_found'    => $capsFound ? 'yes' : 'no',
        'prefill_keys'  => array_keys($out),
        'prefill_count' => count($nonEmptyKeys),
        'prefill_non_empty' => $nonEmptyKeys,
    ];

    return $out;
}

private function hasMeaningfulApplicationPayload(array $payload): bool
{
    $ignoredKeys = [
        '_prefill_debug',
        '_prefill_locked',
        'privacy_agreement_required',
        'privacy_agreement_accepted',
        'privacy_agreement_accepted_at',
        'privacy_agreement_application_type_id',
        'privacy_agreement_text',
        'privacy_agreement_hash',
    ];

    foreach ($payload as $key => $value) {
        if (in_array((string)$key, $ignoredKeys, true)) {
            continue;
        }
        if (trim((string)$value) !== '') {
            return true;
        }
    }

    return false;
}

private function loadTrainingLinks(\PDO $db, int $applicationTypeId): array
{
    $settings = new SystemSettingsModel($db);

    $trainingUrlKey = match ($applicationTypeId) {
        1 => 'TRAINING_LINK_URL_DPC',
        2, 3, 4 => 'TRAINING_LINK_URL_DTC',
        default => 'TRAINING_LINK_URL',
    };
    $trainingLabelKey = match ($applicationTypeId) {
        1 => 'TRAINING_LINK_LABEL_DPC',
        2, 3, 4 => 'TRAINING_LINK_LABEL_DTC',
        default => 'TRAINING_LINK_LABEL',
    };

    return [
        'training_url' => trim((string)($settings->get($trainingUrlKey) ?? '')),
        'training_label' => trim((string)($settings->get($trainingLabelKey) ?? '')),
        'faq_url' => trim((string)($settings->get('TRAINING_FAQ_LINK_URL') ?? '')),
    ];
}

private function getMobileNumberHoverText(\PDO $db): string
{
    try {
        $settings = new SystemSettingsModel($db);
        return trim((string)($settings->get('EDIT_CONTACT_MOBILE_NUMBER_HOVER_TEXT') ?? ''));
    } catch (\Throwable $e) {
        return '';
    }
}

private function getDdPostalAddressesLink(\PDO $db): string
{
    try {
        $settings = new SystemSettingsModel($db);
        return trim((string)($settings->get('DDPostalAddresses') ?? ''));
    } catch (\Throwable $e) {
        return '';
    }
}

private function getDdPostalAddressesLabel(\PDO $db): string
{
    try {
        $settings = new SystemSettingsModel($db);
        $label = trim((string)($settings->get('DDPostalAddressesLabel') ?? ''));
        return $label !== '' ? $label : 'DD Postal Addresses';
    } catch (\Throwable $e) {
        return 'DD Postal Addresses';
    }
}

private function getManagedSettingValue(\PDO $db, string $settingKey, string $default = ''): string
{
    try {
        $settings = new SystemSettingsModel($db);
        $value = trim((string)($settings->get($settingKey) ?? ''));
        return $value !== '' ? $value : $default;
    } catch (\Throwable $e) {
        return $default;
    }
}

private function exportSubmittedApplicationToCaps(array $app, array $payload, int $userId, bool $releaseAfterExport = true): int
{
    if (!$this->isCapsWriteEnabled()) {
        return 0;
    }

    global $capsConn;
    if (!($capsConn instanceof \PDO)) {
        throw new \RuntimeException('CAPS database connection is not available.');
    }

    $sourceApplicationId = (int)($app['ApplicationID'] ?? 0);
    if ($sourceApplicationId <= 0) {
        throw new \RuntimeException('Application is missing ApplicationID for CAPS export.');
    }

    $sourceMarker = $this->buildCapsSourceMarker($sourceApplicationId);
    $applicationTypeId = (int)($app['ApplicationTypeID'] ?? 0);
    $employeeId = trim((string)(
        $app['EmployeeID']
            ?? ($payload['employee_id']
            ?? (SessionHelper::get('portalcards.filters.employeeId')
            ?? (SessionHelper::get('auth.employee_id') ?? '')))
    ));
    $capsProfile = $this->loadCapsCdmcProfile($employeeId);
    $typeMeta = $this->loadApplicationTypeMetaById($applicationTypeId);
    $row = $this->buildCapsApplicationExportRow($app, $payload, $userId, $typeMeta, $capsProfile, $sourceMarker);

    $columns = array_keys($row);
    $sql = sprintf(
        'INSERT INTO dbo.tblCAPSApplication (%s) OUTPUT INSERTED.ApplicationID VALUES (%s)',
        implode(', ', $columns),
        implode(', ', array_map(static fn(string $column): string => ':' . $column, $columns))
    );
    $stmt = $capsConn->prepare($sql);
    $stmt->execute($row);
    $capsApplicationId = (int)($stmt->fetchColumn() ?? 0);

    if ($capsApplicationId <= 0) {
        throw new \RuntimeException('CAPS application insert did not return an ApplicationID.');
    }

    $this->insertCapsLimitDetailsPortal(
        $capsConn,
        $capsApplicationId,
        $employeeId !== '' ? $employeeId : null,
        $sourceApplicationId,
        $this->capsNullable((string)($payload['supervisor_name'] ?? ''), 100),
        null,
        null,
        null,
        null,
        null,
        null,
        isset($row['CreditLimit']) ? (float)$row['CreditLimit'] : null,
        null,
        null,
        null,
        isset($row['TransactionLimit']) ? (float)$row['TransactionLimit'] : null
    );

    $resolvedEmployeeId = $this->capsTrim($employeeId, 10);
    if ($releaseAfterExport && in_array($applicationTypeId, [1, 2, 3, 4], true) && $this->shouldReleaseCapsApplication($resolvedEmployeeId)) {
        $releaseCapsApplicationId = $this->findCapsApplicationIdBySourceMarker($sourceMarker, $employeeId);
        if ($releaseCapsApplicationId <= 0) {
            throw new \RuntimeException('CAPS release lookup failed to find the inserted ApplicationID.');
        }
        $releaseOutput = $this->releaseCapsApplication(
            $capsConn,
            $resolvedEmployeeId,
            $releaseCapsApplicationId
        );
        if ($releaseOutput === -1) {
            throw new \RuntimeException(self::DUPLICATE_APPLICATION_MESSAGE);
        }
        if ($releaseOutput < 0) {
            throw new \RuntimeException(
                'CAPS release failed for ApplicationID ' . $releaseCapsApplicationId
                . ' with output code ' . $releaseOutput . '.'
            );
        }
        $capsApplicationId = $releaseCapsApplicationId;
    }

    return $capsApplicationId;
}

private function findCapsApplicationIdBySourceMarker(string $sourceMarker, string $employeeId): int
{
    $sourceMarker = trim($sourceMarker);
    if ($sourceMarker === '') {
        return 0;
    }
    $employeeId = trim($employeeId);
    if ($employeeId === '') {
        return 0;
    }

    global $capsConn;
    if (!($capsConn instanceof \PDO)) {
        return 0;
    }

    $check = $capsConn->prepare("
        SELECT TOP 1 ApplicationID
        FROM dbo.tblCAPSApplication
        WHERE CONVERT(varchar(max), Notes) LIKE :marker
          AND LTRIM(RTRIM(ISNULL(EmployeeID, ''))) = :employee_id
        ORDER BY ApplicationID DESC
    ");
    $check->execute([
        'marker' => '%' . $sourceMarker . '%',
        'employee_id' => $employeeId,
    ]);
    return (int)($check->fetchColumn() ?? 0);
}

private function buildCapsApplicationExportRow(
    array $app,
    array $payload,
    int $userId,
    array $typeMeta,
    array $capsProfile,
    string $sourceMarker
): array {
    $applicationTypeId = (int)($app['ApplicationTypeID'] ?? 0);
    $cardType = $this->mapCapsCardType($applicationTypeId, $typeMeta);
    $cardTypeSub = $this->resolveCapsCardTypeSub($applicationTypeId);
    $status = 'Awaiting Review';
    $notes = $sourceMarker
        . '; SourceStatus=' . trim((string)($app['Status'] ?? 'Draft'))
        . '; ExportedAt=' . gmdate('Y-m-d H:i:s');

    $firstName = $this->capsTrim($payload['first_name'] ?? ($capsProfile['FormalFirstName'] ?? ($capsProfile['Firstname'] ?? '')), 50);
    $surname = $this->capsTrim($payload['surname'] ?? ($capsProfile['Surname'] ?? ''), 50);
    $middleName = $this->capsTrim($capsProfile['FormalMiddleName'] ?? '', 50);
    $title = $this->capsTrim($payload['title'] ?? ($capsProfile['Title'] ?? ''), 50);
    $gender = strtoupper($this->capsTrim($payload['gender'] ?? ($capsProfile['Gender'] ?? ''), 10));
    $dateOfBirth = $this->formatCapsDateOfBirth((string)($payload['date_of_birth'] ?? ($capsProfile['DateofBirth'] ?? '')));
    $applicationTypeName = $this->resolveCapsApplicationTypeName(
        $applicationTypeId,
        $typeMeta,
        $app
    );
    $bankCardType = $this->resolveBankCardType(
        $applicationTypeId,
        (string)($payload['branding'] ?? '')
    );
    [$creditLimit, $transactionLimit] = $this->resolveCapsDefaultLimits($applicationTypeId);
    $cmsUser = $this->capsTrim($payload['cms_account_holder'] ?? '', 20);
    $employeeId = $this->capsTrim(
        $app['EmployeeID']
            ?? ($payload['employee_id']
            ?? (SessionHelper::get('portalcards.filters.employeeId')
            ?? (SessionHelper::get('auth.employee_id') ?? ''))),
        12
    );
    $fullPhone = $this->combinePhoneForCaps(
        (string)($payload['phone_country_code'] ?? '+61'),
        (string)($payload['phone'] ?? '')
    );
    $fullMobile = $this->combinePhoneForCaps(
        (string)($payload['mobile_country_code'] ?? '+61'),
        (string)($payload['mobile'] ?? '')
    );

    return [
        'EmployeeID' => $employeeId !== '' ? $employeeId : null,
        'CardID' => null,
        'ApplicationType' => 'Portal',
        'CardType' => $cardType !== '' ? $cardType : null,
        'CardTypeSub' => $cardTypeSub !== null ? $this->capsTrim($cardTypeSub, 20) : null,
        'Title' => $title !== '' ? $title : null,
        'FirstName' => $firstName !== '' ? $firstName : null,
        'MiddleName' => $middleName !== '' ? $middleName : null,
        'Surname' => $surname !== '' ? $surname : null,
        'NameOnCard' => $this->buildCapsNameOnCard($firstName, $middleName, $surname),
        'Gender' => $gender !== '' ? $gender : null,
        'DateOfBirth' => $dateOfBirth !== '' ? $dateOfBirth : null,
        'Address1' => $this->capsNullable($payload['address1'] ?? '', 50),
        'Address2' => $this->capsNullable($payload['address2'] ?? '', 50),
        'Address3' => $this->capsNullable($payload['address3'] ?? '', 50),
        'Suburb' => $this->capsNullable($payload['suburb'] ?? '', 50),
        'State' => $this->capsNullable($payload['state'] ?? '', 10),
        'PostCode' => $this->capsNullable($payload['postcode'] ?? '', 10),
        'HomePhone' => null,
        'WorkPhone' => $fullPhone !== '' ? $fullPhone : null,
        'MobilePhone' => $fullMobile !== '' ? $fullMobile : null,
        'Email' => $this->capsNullable($payload['email'] ?? ($capsProfile['Email_Address'] ?? ''), 100),
        'CreditLimit' => $creditLimit,
        'TransactionLimit' => $transactionLimit,
        'CashDaily' => null,
        'CashOTC' => null,
        'BankCardType' => $bankCardType !== null ? $this->capsTrim($bankCardType, 20) : null,
        'ReportGroup' => $this->capsNullable($payload['group_name'] ?? ($capsProfile['GroupName'] ?? ''), 20),
        'NationalityCode' => 'AU',
        'OccupationCode' => '1',
        'DefaultCompany' => $this->capsNullable($payload['company'] ?? '', 10),
        'DefaultCC' => $this->capsNullable($payload['cost_centre'] ?? '', 10),
        'DefaultWBS' => $this->capsNullable($payload['wbs'] ?? '', 50),
        'DefaultIO' => null,
        'DefaultFund' => null,
        'CMSUser' => $cmsUser !== '' ? $cmsUser : null,
        'CMSUserType' => null,
        'CMSUserTypeEID' => $employeeId !== '' ? $employeeId : null,
        'CMSUserName' => $cmsUser !== '' ? $cmsUser : null,
        'Status' => $status,
        'SignedApplication' => null,
        'Notes' => $notes,
        'DateExported' => null,
        'ExportBatch' => null,
        'EmailSent' => null,
        'CurrentLimit' => null,
        'Justification' => null,
        'ChangesPermanent' => null,
        'LimitDateFrom' => null,
        'LimitDateTo' => null,
        'LimitDateReduced' => null,
        'ASFINEmployeeID' => null,
        'ASFINSurname' => null,
        'ASFINFirstName' => null,
        'ASFINSigned' => null,
        'ASFINSignedDate' => null,
        'BankResponse' => null,
        'BankResponseDate' => null,
        'ReviewedBy' => null,
        'DateReviewed' => null,
        'SubmittedBy' => $userId > 0 ? $userId : null,
        'DateSubmitted' => $this->currentServerTimestamp(),
        'UpdatedBy' => $userId > 0 ? $userId : null,
        'DateUpdated' => $this->currentServerTimestamp(),
        'ProChargeUserName' => $cmsUser !== '' ? $cmsUser : null,
        'ErrorsChecked' => null,
        'LastFourDigits' => null,
        'ApplicationTypeName' => $applicationTypeName !== '' ? $applicationTypeName : null,
        'EmailErrorID' => null,
        'WarningDate' => null,
        'ErrorEmailSent' => null,
        'CreditLimitVarChar' => $creditLimit !== null ? (string)((int)$creditLimit == $creditLimit ? (int)$creditLimit : $creditLimit) : null,
    ];
}

private function loadApplicationTypeMetaById(int $applicationTypeId): array
{
    if ($applicationTypeId <= 0 || !($this->db instanceof \PDO)) {
        return [];
    }

    $stmt = $this->db->prepare("
        SELECT TOP 1 ApplicationTypeID, ApplicationTypeKey, ApplicationTypeName, PrivacyAgreementText
        FROM dbo.tblApplicationTypes
        WHERE ApplicationTypeID = :id
    ");
    $stmt->execute(['id' => $applicationTypeId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

private function loadCapsCdmcProfile(string $employeeId): array
{
    $employeeId = trim($employeeId);
    if ($employeeId === '') {
        return [];
    }

    global $capsConn;
    if (!($capsConn instanceof \PDO)) {
        return [];
    }

    $stmt = $capsConn->prepare("
        SELECT TOP 1 *
        FROM dbo.tblCAPSCDMCPortal
        WHERE EmployeeID = :eid
        ORDER BY CDMCID DESC
    ");
    $stmt->execute(['eid' => $employeeId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

private function searchCapsSupervisors(string $query, int $limit = 20): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    global $capsConn;
    if (!($capsConn instanceof \PDO)) {
        return [];
    }

    try {
        $limit = max(1, min(50, $limit));
        $like = '%' . $query . '%';
        $tokens = array_values(array_filter(
            preg_split('/[\s,]+/', $query) ?: [],
            static fn(string $value): bool => trim($value) !== ''
        ));
        $tokens = array_slice(array_values(array_unique($tokens)), 0, 5);

        $params = [
            'q_like_employee' => $like,
            'q_like_first' => $like,
            'q_like_surname' => $like,
            'q_like_email' => $like,
            'q_like_full' => $like,
            'q_like_reverse' => $like,
        ];

        $tokenClauses = [];
        foreach ($tokens as $index => $token) {
            $value = '%' . $token . '%';
            $employeeParam = 'q_token_employee_' . $index;
            $firstParam = 'q_token_first_' . $index;
            $surnameParam = 'q_token_surname_' . $index;
            $emailParam = 'q_token_email_' . $index;
            $fullParam = 'q_token_full_' . $index;
            $reverseParam = 'q_token_reverse_' . $index;

            $params[$employeeParam] = $value;
            $params[$firstParam] = $value;
            $params[$surnameParam] = $value;
            $params[$emailParam] = $value;
            $params[$fullParam] = $value;
            $params[$reverseParam] = $value;

            $tokenClauses[] = "
                    (
                        NULLIF(LTRIM(RTRIM(c.EmployeeID)), '') LIKE :{$employeeParam}
                        OR NULLIF(LTRIM(RTRIM(c.Firstname)), '') LIKE :{$firstParam}
                        OR NULLIF(LTRIM(RTRIM(c.Surname)), '') LIKE :{$surnameParam}
                        OR NULLIF(LTRIM(RTRIM(c.Email_Address)), '') LIKE :{$emailParam}
                        OR LTRIM(RTRIM(ISNULL(NULLIF(LTRIM(RTRIM(c.Firstname)), ''), '') + ' ' + ISNULL(NULLIF(LTRIM(RTRIM(c.Surname)), ''), ''))) LIKE :{$fullParam}
                        OR LTRIM(RTRIM(ISNULL(NULLIF(LTRIM(RTRIM(c.Surname)), ''), '') + ' ' + ISNULL(NULLIF(LTRIM(RTRIM(c.Firstname)), ''), ''))) LIKE :{$reverseParam}
                    )";
        }

        $tokenSql = $tokenClauses !== [] ? ("\n              AND " . implode("\n              AND ", $tokenClauses)) : '';

        $sql = "
            SELECT TOP {$limit}
                src.employee_id,
                MAX(src.first_name) AS first_name,
                MAX(src.surname) AS surname,
                MAX(src.email) AS email
            FROM (
                SELECT
                    NULLIF(LTRIM(RTRIM(EmployeeID)), '') AS employee_id,
                    NULLIF(LTRIM(RTRIM(Firstname)), '') AS first_name,
                    NULLIF(LTRIM(RTRIM(Surname)), '') AS surname,
                    NULLIF(LTRIM(RTRIM(Email_Address)), '') AS email
                FROM dbo.tblCAPSCDMCPortal c
                WHERE NULLIF(LTRIM(RTRIM(c.EmployeeID)), '') IS NOT NULL
                  AND NULLIF(LTRIM(RTRIM(c.Email_Address)), '') IS NOT NULL
                  AND (
                        NULLIF(LTRIM(RTRIM(c.EmployeeID)), '') LIKE :q_like_employee
                        OR NULLIF(LTRIM(RTRIM(c.Firstname)), '') LIKE :q_like_first
                        OR NULLIF(LTRIM(RTRIM(c.Surname)), '') LIKE :q_like_surname
                        OR NULLIF(LTRIM(RTRIM(c.Email_Address)), '') LIKE :q_like_email
                        OR LTRIM(RTRIM(ISNULL(NULLIF(LTRIM(RTRIM(c.Firstname)), ''), '') + ' ' + ISNULL(NULLIF(LTRIM(RTRIM(c.Surname)), ''), ''))) LIKE :q_like_full
                        OR LTRIM(RTRIM(ISNULL(NULLIF(LTRIM(RTRIM(c.Surname)), ''), '') + ', ' + ISNULL(NULLIF(LTRIM(RTRIM(c.Firstname)), ''), ''))) LIKE :q_like_reverse
                      )
                  {$tokenSql}
            ) src
            GROUP BY src.employee_id
            ORDER BY
                ISNULL(MAX(src.surname), ''),
                ISNULL(MAX(src.first_name), ''),
                src.employee_id
        ";

        $stmt = $capsConn->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_values(array_filter(array_map(
            fn(array $row): array => $this->mapCapsSupervisorRow($row),
            $rows
        ), static fn(array $item): bool => (string)($item['employee_id'] ?? '') !== ''));
    } catch (\Throwable $e) {
        error_log('[ApplicationsController::searchCapsSupervisors] ' . $e->getMessage());
        return [];
    }
}

private function loadCapsSupervisorByEmployeeId(string $employeeId): array
{
    $employeeId = trim($employeeId);
    if ($employeeId === '') {
        return [];
    }

    global $capsConn;
    if (!($capsConn instanceof \PDO)) {
        return [];
    }

    try {
        $stmt = $capsConn->prepare("
            SELECT TOP 1
                NULLIF(LTRIM(RTRIM(EmployeeID)), '') AS employee_id,
                MAX(NULLIF(LTRIM(RTRIM(Firstname)), '')) AS first_name,
                MAX(NULLIF(LTRIM(RTRIM(Surname)), '')) AS surname,
                MAX(NULLIF(LTRIM(RTRIM(Email_Address)), '')) AS email
            FROM dbo.tblCAPSCDMCPortal
            WHERE NULLIF(LTRIM(RTRIM(EmployeeID)), '') = :eid
            GROUP BY NULLIF(LTRIM(RTRIM(EmployeeID)), '')
        ");
        $stmt->execute(['eid' => $employeeId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $item = $this->mapCapsSupervisorRow($row);

        return ((string)($item['employee_id'] ?? '') !== '' && (string)($item['email'] ?? '') !== '')
            ? $item
            : [];
    } catch (\Throwable $e) {
        error_log('[ApplicationsController::loadCapsSupervisorByEmployeeId] ' . $e->getMessage());
        return [];
    }
}

private function mapCapsSupervisorRow(array $row): array
{
    $employeeId = trim((string)($row['employee_id'] ?? ($row['EmployeeID'] ?? '')));
    $firstName = trim((string)($row['first_name'] ?? ($row['Firstname'] ?? '')));
    $surname = trim((string)($row['surname'] ?? ($row['Surname'] ?? '')));
    $email = trim((string)($row['email'] ?? ($row['Email_Address'] ?? '')));

    $displayName = trim($surname !== '' || $firstName !== '' ? ($surname . ($surname !== '' && $firstName !== '' ? ', ' : '') . $firstName) : '');
    if ($displayName === '') {
        $displayName = $employeeId;
    }

    $labelParts = [$displayName];
    if ($employeeId !== '') {
        $labelParts[] = $employeeId;
    }
    if ($email !== '') {
        $labelParts[] = $email;
    }

    return [
        'employee_id' => $employeeId,
        'first_name' => $firstName,
        'surname' => $surname,
        'email' => $email,
        'display_name' => $displayName,
        'label' => implode(' - ', array_filter($labelParts, static fn(string $value): bool => $value !== '')),
    ];
}

private function resolveCapsDefaultLimits(int $applicationTypeId): array
{
    return match ($applicationTypeId) {
        1 => [
            $this->loadCapsSystemParameterFloat('DPCDefaultCreditLimit'),
            $this->loadCapsSystemParameterFloat('DPCDefaultTransactionLimit'),
        ],
        2, 3, 4 => [
            $this->loadCapsSystemParameterFloat('DTCDefaultCreditLimit'),
            0.0,
        ],
        default => [null, null],
    };
}

private function loadCapsSystemParameterFloat(string $parameterName): ?float
{
    $parameterName = trim($parameterName);
    if ($parameterName === '') {
        return null;
    }

    global $capsConn;
    if (!($capsConn instanceof \PDO)) {
        return null;
    }

    $stmt = $capsConn->prepare("
        SELECT TOP 1 ParameterValue
        FROM dbo.tblCAPSSystemParameters
        WHERE ParameterName = :name
    ");
    $stmt->execute(['name' => $parameterName]);
    $raw = trim((string)($stmt->fetchColumn() ?? ''));
    if ($raw === '') {
        return null;
    }

    return is_numeric($raw) ? (float)$raw : null;
}

private function buildCapsSourceMarker(int $sourceApplicationId): string
{
    return 'CCPORTAL_APPLICATION_ID=' . $sourceApplicationId;
}

private function shouldReleaseCapsApplication(string $employeeId): bool
{
    $employeeId = trim($employeeId);
    if ($employeeId === '') {
        return false;
    }

    if (strlen($employeeId) > 7 && !str_starts_with($employeeId, '8')) {
        return false;
    }

    return true;
}

private function releaseCapsApplication(\PDO $capsConn, string $employeeId, int $capsApplicationId): int
{
    if ($employeeId === '') {
        throw new \RuntimeException('CAPS release cannot run because EmployeeID is empty.');
    }
    if ($capsApplicationId <= 0) {
        throw new \RuntimeException('CAPS release cannot run because CAPS ApplicationID is invalid.');
    }

    $stmt = $capsConn->prepare("
        SET NOCOUNT ON;
        DECLARE @NAToDinersIDOutput int;
        EXEC dbo.spCAPSReleaseApplication
            @EmployeeID = :employee_id,
            @ApplicationID = :application_id,
            @UpdatedBy = 1,
            @NAToDinersIDOutput = @NAToDinersIDOutput OUTPUT;
        SELECT @NAToDinersIDOutput AS NAToDinersIDOutput;
    ");
    $stmt->execute([
        'employee_id' => $employeeId,
        'application_id' => $capsApplicationId,
    ]);

    while ($stmt->columnCount() === 0 && $stmt->nextRowset()) {
        // Advance to the rowset produced by the final SELECT.
    }

    $value = $stmt->columnCount() > 0 ? $stmt->fetchColumn() : null;
    if ($value === false || $value === null || $value === '') {
        return 0;
    }
    return (int)$value;
}

private function mapCapsCardType(int $applicationTypeId, array $typeMeta): string
{
    return match ($applicationTypeId) {
        1 => 'DPC',
        2, 3, 4, 6 => 'DTC',
        default => strtoupper($this->capsTrim($typeMeta['ApplicationTypeKey'] ?? '', 10)),
    };
}

private function resolveCapsCardTypeSub(int $applicationTypeId): ?string
{
    return match ($applicationTypeId) {
        1 => 'NAB DPC',
        2, 3 => 'NAB DTC',
        4 => 'NAB Lodge',
        default => null,
    };
}

private function resolveCapsApplicationTypeName(int $applicationTypeId, array $typeMeta, array $app): string
{
    $label = match ($applicationTypeId) {
        1 => 'DPC NAB',
        2 => 'DTC NAB CiH',
        3 => 'DTC NAB Dual',
        4 => 'DTC NAB Lodge',
        5 => 'DPC Limit Change',
        6 => 'DTC Limit Change',
        default => (string)($typeMeta['ApplicationTypeName'] ?? ($app['ApplicationTypeName'] ?? '')),
    };

    return $this->capsTrim($label, 20);
}

private function resolveBankCardType(int $applicationTypeId, string $branding): ?string
{
    $branding = trim($branding);

    return match (true) {
        $applicationTypeId === 4 => 'LW',
        $applicationTypeId === 3 && strcasecmp($branding, 'Unbranded') === 0 => 'EK',
        $applicationTypeId === 3 => 'LU',
        $applicationTypeId === 1 && strcasecmp($branding, 'Unbranded') === 0 => 'EV',
        $applicationTypeId === 1 => 'KH',
        $applicationTypeId === 2 && strcasecmp($branding, 'Unbranded') === 0 => 'EK',
        $applicationTypeId === 2 => 'LU',
        default => null,
    };
}

private function buildCapsNameOnCard(string $firstName, string $middleName, string $surname): ?string
{
    $parts = array_filter([
        trim($firstName),
        trim($middleName),
        trim($surname),
    ], static fn(string $value): bool => $value !== '');

    $name = $this->capsTrim(implode(' ', $parts), 50);
    return $name !== '' ? $name : null;
}

private function loadUserEmail(int $userId): string
{
    if ($userId <= 0 || !($this->db instanceof \PDO)) {
        return '';
    }

    $stmt = $this->db->prepare("
        SELECT Email
        FROM dbo.tblUsers
        WHERE UserID = :uid
    ");
    $stmt->execute(['uid' => $userId]);
    return trim((string)($stmt->fetchColumn() ?? ''));
}

private function loadUserDisplayName(int $userId): string
{
    if ($userId <= 0 || !($this->db instanceof \PDO)) {
        return '';
    }

    $stmt = $this->db->prepare("
        SELECT TOP 1 LTRIM(RTRIM(ISNULL(NULLIF(DisplayName, ''), NULLIF(Username, ''))))
        FROM dbo.tblUsers
        WHERE UserID = :uid
    ");
    $stmt->execute(['uid' => $userId]);
    return trim((string)($stmt->fetchColumn() ?? ''));
}

private function renderEmailTemplate(string $templateId, array $tokens, array $context = []): array
{
    try {
        $service = new EmailTemplateService($this->db);
        return $service->renderTemplate($templateId, $tokens, $context);
    } catch (\Throwable $e) {
        error_log('[ApplicationsController::renderEmailTemplate] ' . $e->getMessage());
        return [
            'subject' => '',
            'body' => '',
        ];
    }
}

private function buildAbsoluteRouteUrl(string $routeAndQuery): string
{
    $query = 'index.php?route=' . ltrim($routeAndQuery, '&?');

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $scriptName = trim((string)($_SERVER['SCRIPT_NAME'] ?? ''));

    if ($host !== '' && $scriptName !== '') {
        $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        if ($basePath === '/' || $basePath === '.') {
            $basePath = '';
        }
        return $scheme . '://' . $host . $basePath . '/' . $query;
    }

    $settings = new SystemSettingsModel($this->db);
    $appUrl = rtrim((string)($settings->get('APP_URL') ?? ''), '/');
    if ($appUrl !== '') {
        $appUrl = (string)preg_replace('#/backend-php/public/?$#i', '', $appUrl);
        return $appUrl . '/' . $query;
    }

    return $query;
}

private function sendApplicationSubmittedConfirmation(int $userId, array $app, array $payload): void
{
    $email = $this->loadUserEmail($userId);
    if ($email === '') {
        return;
    }

    $applicationId = (int)($app['ApplicationID'] ?? 0);
    $displayName = (string)(SessionHelper::get('auth.display_name') ?? SessionHelper::get('auth.username') ?? 'User');
    $applicationType = trim((string)($app['ApplicationTypeName'] ?? 'Application'));
    $employeeId = trim((string)(
        $app['EmployeeID']
            ?? ($payload['employee_id']
            ?? (SessionHelper::get('portalcards.filters.employeeId')
            ?? (SessionHelper::get('auth.employee_id') ?? '')))
    ));
    $applicationLink = $this->buildAbsoluteRouteUrl(
        'applications/edit&id=' . urlencode((string)$applicationId)
    );

    $rendered = $this->renderEmailTemplate('application_submitted', [
        '{{display_name}}' => htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'),
        '{{application_id}}' => htmlspecialchars((string)$applicationId, ENT_QUOTES, 'UTF-8'),
        '{{application_type}}' => htmlspecialchars($applicationType !== '' ? $applicationType : 'Application', ENT_QUOTES, 'UTF-8'),
        '{{employee_id}}' => htmlspecialchars($employeeId !== '' ? $employeeId : '-', ENT_QUOTES, 'UTF-8'),
        '{{application_link}}' => htmlspecialchars($applicationLink, ENT_QUOTES, 'UTF-8'),
    ], [
        'application_type_id' => (int)($app['ApplicationTypeID'] ?? 0),
    ]);

    $mailer = new MailService($this->db);
    $ok = $mailer->sendEmail($email, $rendered['subject'], $rendered['body']);
    if (!$ok) {
        error_log('[ApplicationsController::sendApplicationSubmittedConfirmation] MailService returned false for to=' . $email . ', application=' . $applicationId);
    }
}

private function sendDpcApplicationApprovalRequiredEmail(int $requestorUserId, array $app, array $payload): void
{
    $applicationId = (int)($app['ApplicationID'] ?? 0);
    if ($applicationId <= 0) {
        return;
    }

    $recipient = strtolower(trim((string)($payload['supervisor_email'] ?? '')));
    if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $requestorName = $this->loadUserDisplayName($requestorUserId);
    if ($requestorName === '') {
        $requestorName = (string)(SessionHelper::get('auth.display_name') ?? SessionHelper::get('auth.username') ?? 'User');
    }

    $applicationType = trim((string)($app['ApplicationTypeName'] ?? 'DPC Application'));
    $employeeId = trim((string)(
        $app['EmployeeID']
            ?? ($payload['employee_id']
            ?? (SessionHelper::get('portalcards.filters.employeeId')
            ?? (SessionHelper::get('auth.employee_id') ?? '')))
    ));
    $approvalLink = $this->buildAbsoluteRouteUrl(
        'applications/approve&id=' . urlencode((string)$applicationId)
    );

    $rendered = $this->renderEmailTemplate('dpc_application_approval_required', [
        '{{application_id}}' => htmlspecialchars((string)$applicationId, ENT_QUOTES, 'UTF-8'),
        '{{requestor_name}}' => htmlspecialchars($requestorName, ENT_QUOTES, 'UTF-8'),
        '{{application_type}}' => htmlspecialchars($applicationType, ENT_QUOTES, 'UTF-8'),
        '{{employee_id}}' => htmlspecialchars($employeeId !== '' ? $employeeId : '-', ENT_QUOTES, 'UTF-8'),
        '{{supervisor_name}}' => htmlspecialchars((string)($payload['supervisor_name'] ?? '-'), ENT_QUOTES, 'UTF-8'),
        '{{approval_link}}' => htmlspecialchars($approvalLink, ENT_QUOTES, 'UTF-8'),
    ], [
        'application_type_id' => (int)($app['ApplicationTypeID'] ?? 0),
    ]);

    $mailer = new MailService($this->db);
    $ok = $mailer->sendEmail($recipient, $rendered['subject'], $rendered['body']);
    if (!$ok) {
        error_log('[ApplicationsController::sendDpcApplicationApprovalRequiredEmail] MailService returned false for to=' . $recipient . ', application=' . $applicationId);
    }
}

private function sendDpcApplicationSubmittedConfirmation(int $userId, array $app, array $payload): void
{
    $email = $this->loadUserEmail($userId);
    if ($email === '') {
        return;
    }

    $applicationId = (int)($app['ApplicationID'] ?? 0);
    $displayName = $this->loadUserDisplayName($userId);
    if ($displayName === '') {
        $displayName = (string)(SessionHelper::get('auth.display_name') ?? SessionHelper::get('auth.username') ?? 'User');
    }
    $applicationLink = $this->buildAbsoluteRouteUrl(
        'applications/edit&id=' . urlencode((string)$applicationId)
    );

    $rendered = $this->renderEmailTemplate('dpc_application_submitted', [
        '{{display_name}}' => htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'),
        '{{application_id}}' => htmlspecialchars((string)$applicationId, ENT_QUOTES, 'UTF-8'),
        '{{supervisor_name}}' => htmlspecialchars((string)($payload['supervisor_name'] ?? '-'), ENT_QUOTES, 'UTF-8'),
        '{{application_link}}' => htmlspecialchars($applicationLink, ENT_QUOTES, 'UTF-8'),
    ], [
        'application_type_id' => (int)($app['ApplicationTypeID'] ?? 0),
    ]);

    $mailer = new MailService($this->db);
    $ok = $mailer->sendEmail($email, $rendered['subject'], $rendered['body']);
    if (!$ok) {
        error_log('[ApplicationsController::sendDpcApplicationSubmittedConfirmation] MailService returned false for to=' . $email . ', application=' . $applicationId);
    }
}

private function sendDpcApplicationDecisionEmail(array $app, array $payload, string $decision): void
{
    $userId = (int)($app['UserID'] ?? 0);
    $email = $this->loadUserEmail($userId);
    if ($email === '') {
        return;
    }

    $applicationId = (int)($app['ApplicationID'] ?? 0);
    $displayName = $this->loadUserDisplayName($userId);
    if ($displayName === '') {
        $displayName = 'User';
    }
    $decisionText = strtolower(trim($decision)) === 'approve' ? 'approved' : 'rejected';
    $applicationLink = $this->buildAbsoluteRouteUrl(
        'applications/edit&id=' . urlencode((string)$applicationId)
    );
    $extraHtml = '';
    if ($decisionText === 'rejected' && trim((string)($payload['reject_reason'] ?? '')) !== '') {
        $extraHtml = '<p><strong>Rejection reason:</strong> ' . htmlspecialchars((string)$payload['reject_reason'], ENT_QUOTES, 'UTF-8') . '</p>';
    }

    $rendered = $this->renderEmailTemplate('dpc_application_decision', [
        '{{display_name}}' => htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'),
        '{{application_id}}' => htmlspecialchars((string)$applicationId, ENT_QUOTES, 'UTF-8'),
        '{{decision_text}}' => htmlspecialchars($decisionText, ENT_QUOTES, 'UTF-8'),
        '{{decision_text_ucfirst}}' => htmlspecialchars(ucfirst($decisionText), ENT_QUOTES, 'UTF-8'),
        '{{decision_extra_html}}' => $extraHtml,
        '{{application_link}}' => htmlspecialchars($applicationLink, ENT_QUOTES, 'UTF-8'),
    ], [
        'application_type_id' => (int)($app['ApplicationTypeID'] ?? 0),
    ]);

    $mailer = new MailService($this->db);
    $ok = $mailer->sendEmail($email, $rendered['subject'], $rendered['body']);
    if (!$ok) {
        error_log('[ApplicationsController::sendDpcApplicationDecisionEmail] MailService returned false for to=' . $email . ', application=' . $applicationId);
    }
}

private function isCurrentUserAssignedDpcSupervisor(int $userId, array $payload): bool
{
    $supervisorEmail = strtolower(trim((string)($payload['supervisor_email'] ?? '')));
    if ($userId <= 0 || $supervisorEmail === '') {
        return false;
    }

    $userEmail = strtolower(trim($this->loadUserEmail($userId)));
    if ($userEmail === '') {
        return false;
    }

    return hash_equals($supervisorEmail, $userEmail);
}

private function rememberApprovalRoute(int $applicationId): void
{
    if ($applicationId <= 0) {
        return;
    }

    SessionHelper::set('auth.intended_route', 'applications/approve&id=' . $applicationId);
}

private function syncDpcApprovalState(int $applicationId, string $status): void
{
    $statusKey = strtolower(trim($status));
    $currentStepKey = match ($statusKey) {
        'tobeapproved', 'awaitingapproval' => 'tobeapproved',
        'approved' => 'approved',
        'rejected' => 'rejected',
        'senttobank', 'sent_to_bank' => 'senttobank',
        'cardissued', 'card_issued' => 'cardissued',
        default => 'inprogress',
    };

    $locked = !in_array($statusKey, ['draft', 'inprogress'], true) ? 1 : 0;
    $stmt = $this->db->prepare("
        UPDATE dbo.tblApplications
        SET Status = :status,
            CurrentStepKey = :step_key,
            Locked = :locked,
            LastSavedAt = SYSDATETIME()
        WHERE ApplicationID = :aid
    ");
    $stmt->execute([
        'status' => $status,
        'step_key' => $currentStepKey,
        'locked' => $locked,
        'aid' => $applicationId,
    ]);
}

private function combinePhoneForCaps(string $countryCode, string $number): string
{
    $numberDigits = $this->normalizePhoneDigits($number);
    if ($numberDigits === '') {
        return '';
    }

    $codeDigits = $this->normalizePhoneDigits($countryCode);
    if ($codeDigits === '') {
        return $numberDigits;
    }

    if (str_starts_with($numberDigits, $codeDigits)) {
        return $this->capsTrim('+' . $numberDigits, 20);
    }

    if (str_starts_with($numberDigits, '0')) {
        $numberDigits = substr($numberDigits, 1);
    }

    $formatted = '+' . $codeDigits . $numberDigits;
    if (str_starts_with($formatted, '+61')) {
        $formatted = '0' . substr($formatted, 3);
    } elseif (str_starts_with($formatted, '+')) {
        $formatted = substr($formatted, 1);
    }

    return $this->capsTrim($formatted, 20);
}

private function formatCapsDateOfBirth(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return $this->capsTrim($value, 20);
    }

    return date('Ymd', $ts);
}

private function capsNullable(mixed $value, int $maxLength): ?string
{
    $trimmed = $this->capsTrim($value, $maxLength);
    return $trimmed !== '' ? $trimmed : null;
}

private function capsTrim(mixed $value, int $maxLength): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    return mb_substr($text, 0, $maxLength);
}

private function insertCapsLimitDetailsPortal(
    \PDO $capsConn,
    int $capsApplicationId,
    ?string $employeeId,
    ?int $portalId,
    ?string $emailApprover,
    ?string $director,
    ?string $asfin,
    ?string $cfo,
    ?string $creditDateFrom,
    ?string $creditDateTo,
    ?float $creditOriginalLimit,
    ?float $creditNewLimit,
    ?string $dateFrom,
    ?string $dateTo,
    ?float $originalLimit,
    ?float $newLimit
): void {
    if ($capsApplicationId <= 0) {
        throw new \RuntimeException('ApplicationID is required for CAPS limit details export.');
    }

    $stmt = $capsConn->prepare("
        INSERT INTO dbo.tblCAPSLimitDetailsPortal (
            ApplicationID,
            EmployeeID,
            PortalID,
            EMAIL,
            DIRECTOR,
            ASFIN,
            CFO,
            CreditLimitDateFrom,
            CreditLimitDateTo,
            CreditLimitOriginal,
            CreditLimitNew,
            TransactionLimitDateFrom,
            TransactionLimitDateTo,
            TransactionLimitOriginal,
            TransactionLimitNew
        ) VALUES (
            :application_id,
            :employee_id,
            :portal_id,
            :email_approver,
            :director,
            :asfin,
            :cfo,
            :credit_date_from,
            :credit_date_to,
            :credit_original_limit,
            :credit_new_limit,
            :date_from,
            :date_to,
            :original_limit,
            :new_limit
        )
    ");
    $stmt->execute([
        'application_id' => $capsApplicationId,
        'employee_id' => $employeeId,
        'portal_id' => $portalId,
        'email_approver' => $emailApprover,
        'director' => $director,
        'asfin' => $asfin,
        'cfo' => $cfo,
        'credit_date_from' => $creditDateFrom,
        'credit_date_to' => $creditDateTo,
        'credit_original_limit' => $creditOriginalLimit,
        'credit_new_limit' => $creditNewLimit,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'original_limit' => $originalLimit,
        'new_limit' => $newLimit,
    ]);
}

    /**
     * Delete / cancel an application
     * POST: index.php?route=applications/delete&id=123
     */
    public function delete(): void
    {
        $db = $this->db;

        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $applicationId = (int)($_GET['id'] ?? 0);
        if ($applicationId <= 0) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Missing application id.']);
            header('Location: index.php?route=portalcards/list');
            exit;
        }

        if (function_exists('csrf_check') && !csrf_check($_POST['_csrf'] ?? '')) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Security check failed. Please try again.']);
            header('Location: index.php?route=applications/edit&id=' . urlencode((string)$applicationId));
            exit;
        }

        $app = $this->loadApplication($db, $applicationId, $userId);
        if (!$app) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Application not found.']);
            header('Location: index.php?route=portalcards/list');
            exit;
        }

        if ((int)($app['Locked'] ?? 0) === 1) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'This application is locked.']);
            header('Location: index.php?route=applications/edit&id=' . urlencode((string)$applicationId));
            exit;
        }

        $status = strtolower(trim((string)($app['Status'] ?? '')));
        if (!in_array($status, ['draft', 'inprogress'], true)) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Only Draft or InProgress applications can be deleted.']);
            header('Location: index.php?route=applications/edit&id=' . urlencode((string)$applicationId));
            exit;
        }

        $db->beginTransaction();
        try {
            $this->deleteCapsLimitDetailsPortalByPortalId($applicationId);

            $stmt = $db->prepare("
                DELETE FROM dbo.tblApplicationSteps
                WHERE ApplicationID = :aid
            ");
            $stmt->execute(['aid' => $applicationId]);

            $stmt = $db->prepare("
                DELETE FROM dbo.tblApplications
                WHERE ApplicationID = :aid
            ");
            $stmt->execute(['aid' => $applicationId]);

            $db->commit();

            SessionHelper::set('flash.message', ['type' => 'success', 'text' => 'Application deleted.']);
            $this->auditLog(
                'DELETE',
                'Application',
                (string)$applicationId,
                ['route' => 'applications/delete']
            );
            header('Location: index.php?route=portalcards/list');
            exit;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[ApplicationsController::delete ERROR] ' . $e->getMessage());
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Could not delete application.']);
            header('Location: index.php?route=applications/edit&id=' . urlencode((string)$applicationId));
            exit;
        }
    }

private function auditLog(string $action, string $entity, ?string $entityKey = null, array $details = []): void
{
    try {
        $audit = new AuditModel($this->db);
        $audit->insert([
            'UserID'       => SessionHelper::get('auth.user_id'),
            'Username'     => SessionHelper::get('auth.username', 'guest'),
            'Action'       => $action,
            'Entity'       => $entity,
            'EntityKey'    => $entityKey,
            'IPAddress'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'Details'      => $details,
            'FiscalYearID' => SessionHelper::get('FiscalYearID'),
            'VersionID'    => SessionHelper::get('VersionID'),
        ]);
    } catch (\Throwable $e) {
        error_log('[ApplicationsController::auditLog] ' . $e->getMessage());
    }
}

private function ensureEmployeeIdInSession(\PDO $db, int $userId): void
{
    $emp = (string)(SessionHelper::get('auth.employee_id') ?? '');
    if ($emp !== '') {
        return;
    }

    $stmt = $db->prepare("
        SELECT EmployeeID
        FROM dbo.tblUsers
        WHERE UserID = :uid
    ");
    $stmt->execute(['uid' => $userId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    $emp = (string)($row['EmployeeID'] ?? '');

    if ($emp !== '') {
        SessionHelper::set('auth.employee_id', $emp);
        SessionHelper::set('portalcards.filters.employeeId', $emp);
    }
}

private function loadTrainingCompleted(\PDO $db, int $applicationTypeId, string $employeeId): bool
{
    if ($applicationTypeId <= 0 || trim($employeeId) === '') {
        return false;
    }

    $settingKey = null;
    if ($applicationTypeId === 1) {
        $settingKey = 'TRAINING_DPC';
    } elseif (in_array($applicationTypeId, [2, 3, 4], true)) {
        $settingKey = 'TRAINING_DTC';
    }

    if ($settingKey === null) {
        return false;
    }

    $stmt = $db->prepare("
        SELECT SettingValue
        FROM dbo.tblSystemSettings
        WHERE SettingKey = :k
    ");
    $stmt->execute(['k' => $settingKey]);
    $courseId = trim((string)($stmt->fetchColumn() ?? ''));
    if ($courseId === '') {
        return false;
    }

    global $capsConn;
    if (!($capsConn instanceof \PDO)) {
        return false;
    }

    $st = $capsConn->prepare("
        SELECT TOP 1 1
        FROM dbo.tblCAPSTraining
        WHERE EmployeeID = :eid
          AND CourseID = :cid
    ");
    $st->execute(['eid' => $employeeId, 'cid' => $courseId]);
    return (bool)$st->fetchColumn();
}

private function loadCapsCompanies(): array
{
    global $capsConn;
    if (!($capsConn instanceof \PDO)) {
        return [];
    }

    $stmt = $capsConn->prepare("
        SELECT DISTINCT
            CompanyCode,
            OrganisationName AS CompanyName
        FROM dbo.qryCAPSCostCentreOrganisation
        WHERE CompanyCode IS NOT NULL
          AND LTRIM(RTRIM(CompanyCode)) <> ''
        ORDER BY
            CompanyCode
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $out = [];
    $seen = [];
    foreach ($rows as $r) {
        $code = trim((string)($r['CompanyCode'] ?? ''));
        $name = trim((string)($r['CompanyName'] ?? ''));
        if ($code !== '') {
            $key = strtoupper($code);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'CompanyCode' => $code,
                'CompanyName' => $name,
            ];
        }
    }
    return $out;
}

private function loadCapsCostCentres(string $companyCode): array
{
    $companyCode = trim($companyCode);
    if ($companyCode === '') {
        return [];
    }

    global $capsConn;
    if (!($capsConn instanceof \PDO)) {
        return [];
    }

    $stmt = $capsConn->prepare("
        SELECT CostCentreNumber AS CostCentre, CostCentreName
        FROM dbo.tblCAPSCostCentre
        WHERE CompanyCode = :cc
          AND RecType = '3'
        ORDER BY CostCentreNumber
    ");
    $stmt->execute(['cc' => $companyCode]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $code = trim((string)($r['CostCentre'] ?? ''));
        if ($code === '') continue;
        $out[] = [
            'CostCentre' => $code,
            'CostCentreName' => trim((string)($r['CostCentreName'] ?? '')),
        ];
    }
    return $out;
}

private function searchCapsWbs(string $companyCode, string $query, int $limit = 50): array
{
    $companyCode = trim($companyCode);
    $query = trim($query);
    if ($companyCode === '' || $query === '') {
        return [];
    }
    $limit = max(1, $limit);

    global $capsConn;
    if (!($capsConn instanceof \PDO)) {
        return [];
    }

    $stmt = $capsConn->prepare("
        EXEC dbo.spCAPSGetWBS
            @CompanyCode = :cc,
            @Search = :search,
            @Top = :topRows
    ");
    $stmt->bindValue(':cc', $companyCode, \PDO::PARAM_STR);
    $stmt->bindValue(':search', $query, \PDO::PARAM_STR);
    $stmt->bindValue(':topRows', $limit, \PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $code = trim((string)($r['code'] ?? $r['CostCentre'] ?? ''));
        if ($code === '') continue;

        $name = trim((string)($r['Decription'] ?? $r['Description'] ?? $r['CostCentreName'] ?? ''));
        $out[] = [
            'CostCentre' => $code,
            'CostCentreName' => $name,
        ];
        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

private function loadCapsCmsAccountHolders(string $employeeId = ''): array
{
    $employeeId = trim((string)$employeeId);
    if ($employeeId === '') {
        $employeeId = trim((string)(SessionHelper::get('portalcards.filters.employeeId') ?? (SessionHelper::get('auth.employee_id') ?? '')));
    }
    if ($employeeId === '') {
        return [];
    }

    global $capsConn;
    if (!($capsConn instanceof \PDO)) {
        return [];
    }

    try {
        $stmt = $capsConn->prepare("
            EXEC dbo.spCAPSGetCmsAccountHolders @EmployeeID = :eid
        ");
        $stmt->execute(['eid' => $employeeId]);
    } catch (\Throwable $e) {
        // Fallback keeps non-prod working until the sprocs are deployed.
        $stmt = $capsConn->prepare("
            SELECT user_name, active_indicator
            FROM dbo.tblCAPSProMasterUser
            WHERE employee_id = :eid
            ORDER BY
              CASE WHEN active_indicator = 'Y' THEN 0 ELSE 1 END,
              user_name
        ");
        $stmt->execute(['eid' => $employeeId]);
    }
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $name = trim((string)($r['user_name'] ?? ''));
        if ($name === '') continue;
        $isActive = strtoupper(trim((string)($r['active_indicator'] ?? ''))) === 'Y';
        $out[] = [
            'value' => $name,
            'label' => $name . ($isActive ? ' (Active)' : ' (Inactive)'),
            'is_active' => $isActive,
        ];
    }
    return $out;
}

private function isCapsCmsHolderActive(string $employeeId, string $holder): bool
{
    $employeeId = trim($employeeId);
    $holder = trim($holder);
    if ($employeeId === '' || $holder === '') {
        return false;
    }

    global $capsConn;
    if (!($capsConn instanceof \PDO)) {
        return false;
    }

    try {
        $stmt = $capsConn->prepare("
            EXEC dbo.spCAPSIsCmsAccountHolderActive
                @EmployeeID = :eid,
                @UserName = :uname
        ");
        $stmt->execute([
            'eid' => $employeeId,
            'uname' => $holder,
        ]);
    } catch (\Throwable $e) {
        // Fallback keeps non-prod working until the sprocs are deployed.
        $stmt = $capsConn->prepare("
            SELECT TOP 1 1
            FROM dbo.tblCAPSProMasterUser
            WHERE employee_id = :eid
              AND user_name = :uname
              AND active_indicator = 'Y'
        ");
        $stmt->execute([
            'eid' => $employeeId,
            'uname' => $holder,
        ]);
    }
    return (bool)$stmt->fetchColumn();
}



    private function findFirstIncompleteKey(array $workflow, array $runtimeSteps): ?string
    {
        foreach ($workflow as $ws) {
            $k = strtolower(trim((string)($ws['StepKey'] ?? '')));
            if (!$this->isSubmitBlockingWorkflowStep($ws)) {
                continue;
            }

            $rt = $runtimeSteps[$k] ?? null;
            $done = $rt ? ((int)($rt['IsComplete'] ?? 0) === 1) : false;

            if (!$done) {
                return $k;
            }
        }
        return null;
    }

    private function buildProgress(array $workflow, array $runtimeSteps, string $activeStepKey): array
    {
        $out = [];
        foreach ($workflow as $ws) {
            $k = (string)$ws['StepKey'];
            $rt = $runtimeSteps[$k] ?? null;

            $out[] = [
                'StepKey'   => $k,
                'Label'     => (string)$ws['StepLabel'],
                'Order'     => (int)$ws['StepOrder'],
                'IsActive'  => ($k === $activeStepKey),
                'Complete'  => $rt ? ((int)$rt['IsComplete'] === 1) : false,
            ];
        }
        return $out;
    }

private function buildLifecycleProgress(string $status, int $applicationTypeId = 0): array
{
    $status = strtolower(trim($status));

    $needsApprovalStep = in_array($applicationTypeId, [1, 5, 6, 7, 8], true);
    if ($needsApprovalStep) {
        if ($status === 'rejected') {
            $steps = [
                ['key' => 'inprogress',  'label' => 'Application in Progress'],
                ['key' => 'submitted',   'label' => 'Application Submitted'],
                ['key' => 'tobeapproved','label' => 'Awaiting Approval'],
                ['key' => 'rejected',    'label' => 'Rejected'],
            ];
        } else {
            $steps = [
                ['key' => 'inprogress',  'label' => 'Application in Progress'],
                ['key' => 'submitted',   'label' => 'Application Submitted'],
                ['key' => 'tobeapproved','label' => 'Awaiting Approval'],
                ['key' => 'approved',    'label' => 'Approved'],
                ['key' => 'senttobank',  'label' => 'Sent to Bank'],
                ['key' => 'cardissued',  'label' => 'Card Issued'],
            ];
        }
    } else {
        $steps = [
            ['key' => 'inprogress',  'label' => 'Application in Progress'],
            ['key' => 'submitted',   'label' => 'Application Submitted'],
            ['key' => 'senttobank',  'label' => 'Sent to Bank'],
            ['key' => 'cardissued',  'label' => 'Card Issued'],
        ];
    }

    // Map DB Status -> current lifecycle step
    // (Add/adjust mappings as your Status values evolve)
    $map = [
        'draft'      => 'inprogress',
        'inprogress' => 'inprogress',

        'submitted'  => 'submitted',

        'tobeapproved' => 'tobeapproved',
        'awaiting_approval' => 'tobeapproved',
        'awaitingapproval' => 'tobeapproved',

        'rejected'   => 'rejected',

        'approved'   => 'approved',

        'senttobank' => 'senttobank',
        'sent_to_bank' => 'senttobank',

        'cardissued' => 'cardissued',
        'card_issued' => 'cardissued',

        'limitchanged' => 'limitchanged',
        'limit_changed' => 'limitchanged',
        'limitchange' => 'limitchanged',
    ];

    $currentKey = $map[$status] ?? 'inprogress';

    // Determine order index of current
    $currentIdx = 0;
    foreach ($steps as $i => $s) {
        if ($s['key'] === $currentKey) {
            $currentIdx = $i;
            break;
        }
    }

    // Build UI model (complete = all prior steps)
    $out = [];
    foreach ($steps as $i => $s) {
        $out[] = [
            'Key'      => $s['key'],
            'Label'    => $s['label'],
            'IsActive' => ($i === $currentIdx),
            'Complete' => ($i <= $currentIdx),
        ];
    }

    return $out;
}


    private function validateRequiredFields(array $payload, array $workflow, array $app = []): array
{
    $errors = [];

    // Required “form” fields (your application layout)
    $requiredFields = [
        'title'          => 'Title',
        'gender'         => 'Gender',
        'email'          => 'Email',
        'address1'       => 'Address Line 1',
        'suburb'         => 'Suburb',
        'state'          => 'State',
        'postcode'       => 'Postcode',
        'date_of_birth'  => 'Date of Birth',
        'employee_type'  => 'Employee Type',
        'mobile'         => 'Mobile Number',
        'company'        => 'Company',
        'cost_centre'    => 'Cost Centre',
        'cms_account_holder' => 'CMS Account Holder',
    ];

    $applicationTypeId = (int)($payload['application_type_id'] ?? 0);
    if ($applicationTypeId !== 4) {
        $requiredFields['branding'] = 'Branding';
    }
    if ($applicationTypeId === 1) {
        $requiredFields['supervisor_employee_id'] = 'Supervisor';
    }

    foreach ($requiredFields as $key => $label) {
        $v = $payload[$key] ?? null;

        if (is_string($v)) {
            $v = trim($v);
        }

        if ($v === null || $v === '') {
            $errors[$key] = "{$label} is required.";
        }
    }

    // Length checks (allow prefill on load, but block submit if too long)
    $a1 = (string)($payload['address1'] ?? '');
    $a2 = (string)($payload['address2'] ?? '');
    $a3 = (string)($payload['address3'] ?? '');
    $suburb = (string)($payload['suburb'] ?? '');

    if (mb_strlen($a1) > 30 || mb_strlen($a2) > 30 || mb_strlen($a3) > 30) {
        $errors['address1'] = 'Each Address Line must be 30 characters or less.';
    }
    $postcode = trim((string)($payload['postcode'] ?? ''));
    if ($postcode !== '' && !ctype_digit($postcode)) {
        $errors['postcode'] = 'Postcode must contain only numbers.';
    }
    if (mb_strlen($postcode) > 4) {
        $errors['postcode'] = 'Postcode must be 4 digits or less.';
    }
    $mobile = (string)($payload['mobile'] ?? '');
    $mobileCountryCode = trim((string)($payload['mobile_country_code'] ?? '+61'));
    if ($mobile !== '' && !$this->isValidMobileByCountryCode($mobile, $mobileCountryCode)) {
        $errors['mobile'] = 'Mobile Number must be valid for country code ' . $mobileCountryCode . '.';
    }
    $phone = trim((string)($payload['phone'] ?? ''));
    $phoneCountryCode = trim((string)($payload['phone_country_code'] ?? '+61'));
    if ($phone !== '' && !$this->isValidPhoneByCountryCode($phone, $phoneCountryCode)) {
        $errors['phone'] = 'Phone Number must be a valid non-mobile phone number for country code ' . $phoneCountryCode . '.';
    }
    $email = trim((string)($payload['email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'Email must be a valid email address.';
    }
    $title = strtoupper(trim((string)($payload['title'] ?? '')));
    $allowedTitles = ['PROF', 'DR', 'MR', 'MRS', 'MS', 'MISS', 'MX'];
    if ($title !== '' && !in_array($title, $allowedTitles, true)) {
        $errors['title'] = 'Title must be one of: PROF, DR, MR, MRS, MS, MISS, MX.';
    }
    $gender = strtoupper(trim((string)($payload['gender'] ?? '')));
    $allowedGenders = ['M', 'F', 'X'];
    if ($gender !== '' && !in_array($gender, $allowedGenders, true)) {
        $errors['gender'] = 'Gender must be one of: M, F, X.';
    }
    $branding = trim((string)($payload['branding'] ?? ''));
    if ($branding !== '' && !in_array($branding, ['Branded', 'Unbranded'], true)) {
        $errors['branding'] = 'Branding must be either Branded or Unbranded.';
    }
    $cmsHolder = trim((string)($payload['cms_account_holder'] ?? ''));
    if ($cmsHolder !== '') {
        $employeeId = trim((string)($payload['employee_id'] ?? ''));
        if ($employeeId === '') {
            $employeeId = trim((string)(SessionHelper::get('portalcards.filters.employeeId')
                ?? (SessionHelper::get('auth.employee_id') ?? '')));
        }
        if (!$this->isCapsCmsHolderActive($employeeId, $cmsHolder)) {
            $errors['cms_account_holder'] = 'CMS Account Holder must be an active CMS holder.';
        }
    }
    $employeeType = trim((string)($payload['employee_type'] ?? ''));
    if ($applicationTypeId > 0 && $employeeType !== '') {
        $employeeId = trim((string)(
            $payload['employee_id']
            ?? (SessionHelper::get('portalcards.filters.employeeId')
            ?? (SessionHelper::get('auth.employee_id')
            ?? ($app['EmployeeID'] ?? '')))
        ));
        if (!$this->isEmployeeTypeEntitled($applicationTypeId, $employeeType, $employeeId)) {
            $errors['employee_type'] = $this->buildEmployeeTypeEntitlementMessage($applicationTypeId, $employeeType);
        }
    }
    if ($applicationTypeId === 1) {
        $supervisorEmployeeId = trim((string)($payload['supervisor_employee_id'] ?? ''));
        if ($supervisorEmployeeId !== '') {
            $supervisor = $this->loadCapsSupervisorByEmployeeId($supervisorEmployeeId);
            if ($supervisor === []) {
                $errors['supervisor_employee_id'] = 'Supervisor must be selected from the CAPS directory.';
            } elseif (filter_var((string)($supervisor['email'] ?? ''), FILTER_VALIDATE_EMAIL) === false) {
                $errors['supervisor_employee_id'] = 'Supervisor must have a valid email address.';
            }
        }
    }

    // Optional: ensure checklist steps that are required are checked before you allow submit
    // (You can enforce later; for now, we enforce only if user clicked "next")
    return $errors;
}

private function isValidAuPhone(string $raw): bool
{
    return $this->isValidPhoneByCountryCode($raw, '+61');
}

private function normalizePhoneDigits(string $raw): string
{
    return preg_replace('/\D+/', '', trim($raw)) ?? '';
}

private function normalizePhoneInput(string $raw): string
{
    $value = preg_replace('/[^\d+]/', '', trim($raw)) ?? '';
    if ($value === '') {
        return '';
    }

    if (str_starts_with($value, '+')) {
        $value = '+' . str_replace('+', '', substr($value, 1));
    } else {
        $value = str_replace('+', '', $value);
    }

    return substr($value, 0, 20);
}

private function isValidMobileByCountryCode(string $raw, string $countryCode): bool
{
    $digits = $this->normalizePhoneDigits($raw);
    if ($digits === '') {
        return false;
    }

    $code = trim($countryCode);
    if ($code === '') {
        $code = '+61';
    }

    switch ($code) {
        case '+61':
            $d = str_starts_with($digits, '61') ? substr($digits, 2) : (str_starts_with($digits, '0') ? substr($digits, 1) : $digits);
            return (bool)preg_match('/^4\d{8}$/', $d);
        case '+1':
            $d = (strlen($digits) === 11 && str_starts_with($digits, '1')) ? substr($digits, 1) : $digits;
            return (bool)preg_match('/^\d{10}$/', $d);
        case '+44':
            $d = str_starts_with($digits, '44') ? substr($digits, 2) : (str_starts_with($digits, '0') ? substr($digits, 1) : $digits);
            return (bool)preg_match('/^\d{9,10}$/', $d);
        case '+64':
            $d = str_starts_with($digits, '64') ? substr($digits, 2) : (str_starts_with($digits, '0') ? substr($digits, 1) : $digits);
            return (bool)preg_match('/^\d{8,10}$/', $d);
        default:
            return (bool)preg_match('/^\d{6,14}$/', $digits);
    }
}

private function isValidPhoneByCountryCode(string $raw, string $countryCode): bool
{
    $digits = $this->normalizePhoneDigits($raw);
    if ($digits === '') {
        return false;
    }

    $code = trim($countryCode);
    if ($code === '') {
        $code = '+61';
    }

    switch ($code) {
        case '+61':
            $d = str_starts_with($digits, '61') ? substr($digits, 2) : (str_starts_with($digits, '0') ? substr($digits, 1) : $digits);
            return (bool)preg_match('/^[2378]\d{8}$/', $d);
        case '+1':
            $d = (strlen($digits) === 11 && str_starts_with($digits, '1')) ? substr($digits, 1) : $digits;
            return (bool)preg_match('/^\d{10}$/', $d);
        case '+44':
            $d = str_starts_with($digits, '44') ? substr($digits, 2) : (str_starts_with($digits, '0') ? substr($digits, 1) : $digits);
            return (bool)preg_match('/^\d{9,10}$/', $d) && !(bool)preg_match('/^7\d{9}$/', $d);
        case '+64':
            $d = str_starts_with($digits, '64') ? substr($digits, 2) : (str_starts_with($digits, '0') ? substr($digits, 1) : $digits);
            return (bool)preg_match('/^\d{8,10}$/', $d) && !(bool)preg_match('/^2\d{7,9}$/', $d);
        default:
            return (bool)preg_match('/^\d{6,14}$/', $digits);
    }
}

private function isEmployeeTypeEntitled(int $applicationTypeId, string $employeeType, string $employeeId = ''): bool
{
    $employeeType = trim($employeeType);
    if ($applicationTypeId <= 0) {
        return false;
    }
    $employeeId = trim($employeeId);
    if ($employeeId !== '' && $this->hasEligibilityOverride($employeeId, 'POSITION_TYPE_CHECK', $applicationTypeId)) {
        return true;
    }

    $col = null;
    if ($applicationTypeId === 1) {
        $col = 'DPCEntitled';
    } elseif (in_array($applicationTypeId, [2, 3, 4], true)) {
        $col = 'DTCEntitled';
    }

    if ($col === null) {
        return false;
    }

    if ($employeeType === '') {
        return $this->isEmployeeIdEntitledFallback($employeeId, $applicationTypeId);
    }

    global $capsConn;
    if (!($capsConn instanceof \PDO)) {
        return false;
    }

    foreach ($this->buildEntitlementEmployeeTypeCandidates($employeeId, $employeeType) as $candidate) {
        $stmt = $capsConn->prepare("
            SELECT {$col}
            FROM dbo.tblCAPSEmployeeType
            WHERE EmployeeType = :et
        ");
        $stmt->execute(['et' => $candidate]);
        $result = $stmt->fetchColumn();
        if ($result === false) {
            continue;
        }
        $val = strtoupper(trim((string)$result));
        return $val === 'Y';
    }
    return $this->isEmployeeIdEntitledFallback($employeeId, $applicationTypeId);
}

private function isEmployeeIdEntitledFallback(string $employeeId, int $applicationTypeId): bool
{
    $employeeId = trim($employeeId);
    if ($employeeId === '' || !preg_match('/^8\d+$/', $employeeId)) {
        return false;
    }

    $length = strlen($employeeId);
    if ($length === 7) {
        return in_array($applicationTypeId, [1, 2, 3, 4], true);
    }

    if ($length === 8) {
        return $applicationTypeId === 1;
    }

    return false;
}

private function buildEntitlementEmployeeTypeCandidates(string $employeeId, string $employeeType): array
{
    $candidates = [];

    $employeeType = trim($employeeType);
    if ($employeeType !== '') {
        $candidates[] = $employeeType;
    }

    $employeeId = trim($employeeId);
    global $capsConn;
    if ($employeeId !== '' && ($capsConn instanceof \PDO)) {
        try {
            $stmt = $capsConn->prepare("
                SELECT TOP 1 EmployeeType
                FROM dbo.tblCAPSCDMCPortal
                WHERE EmployeeID = :eid
            ");
            $stmt->execute(['eid' => $employeeId]);
            $rawEmployeeType = trim((string)($stmt->fetchColumn() ?? ''));
            if ($rawEmployeeType !== '') {
                array_unshift($candidates, $rawEmployeeType);
            }
        } catch (\Throwable $e) {
            error_log('[ApplicationsController::buildEntitlementEmployeeTypeCandidates] ' . $e->getMessage());
        }
    }

    $normalized = $this->normalizeEntitlementEmployeeType($employeeType);
    if ($normalized !== '') {
        $candidates[] = $normalized;
    }

    $unique = [];
    foreach ($candidates as $candidate) {
        $key = strtoupper(trim($candidate));
        if ($key === '' || isset($unique[$key])) {
            continue;
        }
        $unique[$key] = trim($candidate);
    }

    return array_values($unique);
}

private function normalizeEntitlementEmployeeType(string $employeeType): string
{
    $employeeType = trim($employeeType);
    if ($employeeType === '') {
        return '';
    }

    $allowed = ['ASA', 'ASD', 'ANNPSR'];
    return in_array(strtoupper($employeeType), $allowed, true) ? $employeeType : 'Defence';
}

private function getBlacklistMatch(string $employeeId, ?int $applicationTypeId = null): array
{
    $employeeId = trim($employeeId);
    if ($employeeId === '' || !($this->db instanceof \PDO)) {
        return ['blocked' => false, 'reason' => ''];
    }

    try {
        $sql = "
            SELECT TOP 1 Reason
            FROM dbo.tblCardApplicationBlacklist
            WHERE EmployeeID = :eid
              AND IsActive = 1
              AND (AppliesToApplicationTypeID IS NULL OR AppliesToApplicationTypeID = :atid)
              AND (EffectiveFrom IS NULL OR EffectiveFrom <= SYSUTCDATETIME())
              AND (EffectiveTo IS NULL OR EffectiveTo >= SYSUTCDATETIME())
            ORDER BY
              CASE WHEN AppliesToApplicationTypeID IS NULL THEN 1 ELSE 0 END,
              BlacklistID DESC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'eid' => $employeeId,
            'atid' => (int)($applicationTypeId ?? 0),
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return ['blocked' => false, 'reason' => ''];
        }
        return [
            'blocked' => true,
            'reason' => trim((string)($row['Reason'] ?? '')),
        ];
    } catch (\Throwable $e) {
        error_log('[ApplicationsController::getBlacklistMatch] ' . $e->getMessage());
        return ['blocked' => false, 'reason' => ''];
    }
}

private function hasEligibilityOverride(string $employeeId, string $overrideType, ?int $applicationTypeId = null): bool
{
    $employeeId = trim($employeeId);
    $overrideType = strtoupper(trim($overrideType));
    if ($employeeId === '' || $overrideType === '' || !($this->db instanceof \PDO)) {
        return false;
    }

    try {
        $sql = "
            SELECT TOP 1 1
            FROM dbo.tblApplicationEligibilityOverride
            WHERE EmployeeID = :eid
              AND OverrideType = :otype
              AND IsActive = 1
              AND (AppliesToApplicationTypeID IS NULL OR AppliesToApplicationTypeID = :atid)
              AND (EffectiveFrom IS NULL OR EffectiveFrom <= SYSUTCDATETIME())
              AND (EffectiveTo IS NULL OR EffectiveTo >= SYSUTCDATETIME())
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'eid' => $employeeId,
            'otype' => $overrideType,
            'atid' => (int)($applicationTypeId ?? 0),
        ]);
        return (bool)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        error_log('[ApplicationsController::hasEligibilityOverride] ' . $e->getMessage());
        return false;
    }
}

private function buildBlacklistMessage(array $blacklist): string
{
    $reason = trim((string)($blacklist['reason'] ?? ''));
    if ($reason !== '') {
        return 'You are not eligible to apply for this card due to a restricted list entry. Reason: ' . $reason;
    }
    return 'You are not eligible to apply for this card due to a restricted list entry. Please contact support.';
}

private function loadPortalDefaultAddress(string $employeeId): array
{
    $employeeId = trim($employeeId);
    if ($employeeId === '' || !($this->db instanceof \PDO)) {
        return [];
    }

    try {
        $stmt = $this->db->prepare("
            SELECT TOP 1
                Address1 AS address1,
                Address2 AS address2,
                Address3 AS address3,
                Suburb AS suburb,
                State AS state,
                PostCode AS postcode,
                Phone AS phone,
                Mobile AS mobile
            FROM dbo.tblPortalDefaultAddresses
            WHERE EmployeeID = :eid
            ORDER BY UpdatedAt DESC, DefaultAddressID DESC
        ");
        $stmt->execute(['eid' => $employeeId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        if (!is_array($row)) {
            return [];
        }
        foreach ($row as $k => $v) {
            $row[$k] = trim((string)$v);
        }
        return $row;
    } catch (\Throwable $e) {
        error_log('[ApplicationsController::loadPortalDefaultAddress] ' . $e->getMessage());
        return [];
    }
}

private function savePortalDefaultAddress(\PDO $db, string $employeeId, array $payload, int $applicationId, int $userId): void
{
    $employeeId = trim($employeeId);
    if ($employeeId === '') {
        return;
    }

    $address = [
        'address1' => trim((string)($payload['address1'] ?? '')),
        'address2' => trim((string)($payload['address2'] ?? '')),
        'address3' => trim((string)($payload['address3'] ?? '')),
        'suburb' => $this->normalizeSuburbValue((string)($payload['suburb'] ?? '')),
        'state' => trim((string)($payload['state'] ?? '')),
        'postcode' => trim((string)($payload['postcode'] ?? '')),
        'phone' => trim((string)($payload['phone'] ?? '')),
        'mobile' => trim((string)($payload['mobile'] ?? '')),
    ];

    if ($address['address1'] === '' || $address['suburb'] === '' || $address['state'] === '' || $address['postcode'] === '') {
        return;
    }

    try {
        $update = $db->prepare("
            UPDATE dbo.tblPortalDefaultAddresses
            SET Address1 = :a1,
                Address2 = :a2,
                Address3 = :a3,
                Suburb = :suburb,
                State = :state,
                PostCode = :postcode,
                Phone = :phone,
                Mobile = :mobile,
                SourceApplicationID = :app_id,
                ConfirmedAt = SYSUTCDATETIME(),
                UpdatedAt = SYSUTCDATETIME(),
                UpdatedBy = :updated_by
            WHERE EmployeeID = :eid
        ");
        $update->execute([
            'a1' => $address['address1'],
            'a2' => $address['address2'] !== '' ? $address['address2'] : null,
            'a3' => $address['address3'] !== '' ? $address['address3'] : null,
            'suburb' => $address['suburb'],
            'state' => $address['state'],
            'postcode' => $address['postcode'],
            'phone' => $address['phone'] !== '' ? $address['phone'] : null,
            'mobile' => $address['mobile'] !== '' ? $address['mobile'] : null,
            'app_id' => $applicationId,
            'updated_by' => $userId > 0 ? $userId : null,
            'eid' => $employeeId,
        ]);

        $exists = $db->prepare("
            SELECT TOP 1 DefaultAddressID
            FROM dbo.tblPortalDefaultAddresses
            WHERE EmployeeID = :eid
        ");
        $exists->execute(['eid' => $employeeId]);
        if ((int)($exists->fetchColumn() ?? 0) > 0) {
            return;
        }

        $insert = $db->prepare("
            INSERT INTO dbo.tblPortalDefaultAddresses
                (EmployeeID, Address1, Address2, Address3, Suburb, State, PostCode, Phone, Mobile, SourceApplicationID, CreatedBy, UpdatedBy)
            VALUES
                (:eid, :a1, :a2, :a3, :suburb, :state, :postcode, :phone, :mobile, :app_id, :created_by, :updated_by)
        ");
        $insert->execute([
            'eid' => $employeeId,
            'a1' => $address['address1'],
            'a2' => $address['address2'] !== '' ? $address['address2'] : null,
            'a3' => $address['address3'] !== '' ? $address['address3'] : null,
            'suburb' => $address['suburb'],
            'state' => $address['state'],
            'postcode' => $address['postcode'],
            'phone' => $address['phone'] !== '' ? $address['phone'] : null,
            'mobile' => $address['mobile'] !== '' ? $address['mobile'] : null,
            'app_id' => $applicationId,
            'created_by' => $userId > 0 ? $userId : null,
            'updated_by' => $userId > 0 ? $userId : null,
        ]);
    } catch (\Throwable $e) {
        error_log('[ApplicationsController::savePortalDefaultAddress] ' . $e->getMessage());
    }
}

private function normalizeSuburbValue(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    return mb_substr($value, 0, 21);
}

private function buildEmployeeTypeEntitlementMessage(int $applicationTypeId, string $employeeType): string
{
    $employeeType = trim($employeeType);
    $typeLabel = match ($applicationTypeId) {
        1 => 'DPC',
        2 => 'DTC',
        3 => 'Dual',
        4 => 'Lodge',
        default => 'this',
    };

    if ($employeeType === '') {
        return 'Employee Type is not available, so entitlement for ' . $typeLabel . ' could not be confirmed.';
    }

    return 'Employee Type ' . $employeeType . ' is not entitled for ' . $typeLabel . ' applications based on CAPS entitlement settings.';
}

private function getSubmitAgreementText(): string
{
    if (!($this->db instanceof \PDO)) {
        return 'By submitting this application, you confirm the details provided are true and correct.';
    }

    try {
        $settings = new SystemSettingsModel($this->db);
        $text = trim((string)($settings->get('SUBMIT_AGREEMENT_TEXT') ?? ''));
        if ($text !== '') {
            return $text;
        }
    } catch (\Throwable $e) {
        error_log('[ApplicationsController::getSubmitAgreementText] ' . $e->getMessage());
    }

    return 'By submitting this application, you confirm the details provided are true and correct.';
}


}
