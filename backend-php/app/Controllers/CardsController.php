<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Shared\SessionHelper;
use App\Core\Rbac;
use App\Models\CancelCardReasonModel;
use App\Models\LimitChangeReasonModel;
use App\Services\ApprovalInboxService;
use App\Services\MailService;
use App\Services\EmailTemplateService;
use App\Models\SystemSettingsModel;
use App\Models\AuditModel;

require_once __DIR__ . '/../../shared/csrf.php';

final class CardsController extends BaseController
{
    protected array $acl = [
        '*' => ['auth' => true],
        'processDueCancellations' => ['auth' => true, 'permsAny' => ['ADMIN_ALL', 'SYSADMIN']],
    ];

    private const STATUS_ADDR_SUBMITTED = 'Addr Update Subm';
    private const STATUS_CANCEL_SUBMITTED = 'Cancel Subm';
    private const STATUS_SUPERSEDED_BY_CANCEL = 'Superseded by Cancel';
    private const CAPS_STATUS_AWAITING_EXPORT = 'Awaiting Export';
    private const REQUEST_TYPE_CONTACT_CHANGE = 'CONTACT_CHANGE';
    private ?array $cardChangeRequestColumnCache = null;
    /**
     * Card history (all cards in tblPORTALCards for this employee).
     * GET: index.php?route=cards/history&type=dtc|dpc|lodge
     */
    public function history(): void
    {
        $db = $this->db;

        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $employeeId = trim((string)(SessionHelper::get('portalcards.filters.employeeId') ?? (SessionHelper::get('auth.employee_id') ?? '')));
        if ($employeeId === '') {
            $stmt = $db->prepare("
                SELECT EmployeeID
                FROM dbo.tblUsers
                WHERE UserID = :uid
            ");
            $stmt->execute(['uid' => $userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $employeeId = trim((string)($row['EmployeeID'] ?? ''));
        }

        if ($employeeId === '') {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Employee ID not found in session.']);
            header('Location: index.php?route=portalcards/list');
            exit;
        }

        $typeKey = strtolower(trim((string)($_GET['type'] ?? '')));

        $where = "EmployeeID = :emp";
        $params = ['emp' => $employeeId];

        if ($typeKey === 'dpc') {
            $where .= " AND CardType = 'DPC'";
        } elseif ($typeKey === 'dtc') {
            $where .= " AND CardType = 'DTC' AND ISNULL(CardTypeSub,'') NOT LIKE '%LODGE%'";
        } elseif ($typeKey === 'lodge') {
            $where .= " AND (CardType = 'DTC' AND ISNULL(CardTypeSub,'') LIKE '%LODGE%')";
        }

        $stmt = $db->prepare("
            SELECT *
            FROM dbo.tblPORTALCards
            WHERE $where
            ORDER BY DateIssued DESC, DateLoaded DESC, CardID DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $statusDescriptions = $this->loadCapsBlockingCodeDescriptions(array_column($rows, 'Status'));
        foreach ($rows as &$row) {
            $statusCode = strtoupper(trim((string)($row['Status'] ?? '')));
            $row['StatusDescription'] = $statusCode !== '' ? ($statusDescriptions[$statusCode] ?? '') : '';
        }
        unset($row);

        $this->render('portalcards/CardHistory', [
            'title' => 'Card History',
            'rows' => $rows,
            'typeKey' => $typeKey,
        ]);
    }
    /**
     * Start or resume an application for a given application type key.
     *
     * URL example:
     *   index.php?route=cards/apply&type=dtc
     *
     * Requirements:
     * - tblApplicationTypes: ApplicationTypeID, ApplicationTypeKey, IsActive
     * - tblApplications: UserID, ApplicationTypeID, Status, CurrentStepKey, StartedAt (default), LastSavedAt, Locked
     * - tblApplicationWorkFlowSteps: ApplicationTypeID, StepKey, StepOrder, ViewPath, IsActive
     * - tblApplicationSteps: ApplicationID, StepKey, IsComplete, LastSavedAt, DataJson, UpdatedBy
     */
    public function apply(): void
    {
        $db = $this->db; // expects BaseController provides PDO on $this->db

        // ---- Inputs / session ----
        $typeKey = strtolower(trim((string)($_GET['type'] ?? '')));
        if ($typeKey === '') {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Missing application type.']);
            header('Location: index.php?route=cards/index');
            exit;
        }

        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        // IMPORTANT: do NOT include "ccportal." when using SessionHelper
        $employeeId = trim((string)(SessionHelper::get('portalcards.filters.employeeId') ?? (SessionHelper::get('auth.employee_id') ?? '')));
        if ($employeeId === '') {
            $stmt = $db->prepare("
                SELECT EmployeeID
                FROM dbo.tblUsers
                WHERE UserID = :uid
            ");
            $stmt->execute(['uid' => $userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $employeeId = trim((string)($row['EmployeeID'] ?? ''));
            if ($employeeId !== '') {
                SessionHelper::set('auth.employee_id', $employeeId);
                SessionHelper::set('portalcards.filters.employeeId', $employeeId);
            }
        }

        if ($employeeId === '') {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Employee ID not found in session.']);
            header('Location: index.php?route=cards/index');
            exit;
        }

        // ---- Resolve ApplicationTypeID ----
        $stmt = $db->prepare("
            SELECT ApplicationTypeID
            FROM dbo.tblApplicationTypes
            WHERE ApplicationTypeKey = :k AND IsActive = 1
        ");
        $stmt->execute(['k' => $typeKey]);
        $applicationTypeId = (int)($stmt->fetchColumn() ?? 0);

        if ($applicationTypeId <= 0) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => "Unknown application type '{$typeKey}'."]);
            header('Location: index.php?route=cards/index');
            exit;
        }

        // ---- Start / resume workflow ----
        $db->beginTransaction();
        try {
            // 1) Resume newest unlocked Draft/InProgress
            $stmt = $db->prepare("
                SELECT TOP 1 ApplicationID, CurrentStepKey, Locked
                FROM dbo.tblApplications
                WHERE UserID = :uid
                  AND ApplicationTypeID = :atid
                  AND Status IN ('Draft','InProgress')
                ORDER BY ApplicationID DESC
            ");
            $stmt->execute(['uid' => $userId, 'atid' => $applicationTypeId]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($existing && (int)($existing['Locked'] ?? 0) === 1) {
                $existing = null; // locked draft: start a new one
            }

            if ($existing) {
                $applicationId = (int)$existing['ApplicationID'];
            } else {
                $employeeId = trim($employeeId);
                // 2) Create new application (StartedAt default exists)
                $stmt = $db->prepare("
                    INSERT INTO dbo.tblApplications
                        (UserID, ApplicationTypeID, Status, CurrentStepKey, StartedAt, LastSavedAt, Locked, EmployeeID)
                    VALUES
                        (:uid, :atid, 'Draft', NULL, SYSDATETIME(), SYSDATETIME(), 0, :emp);

                    SELECT SCOPE_IDENTITY() AS NewID;
                ");
                $stmt->execute(['uid' => $userId, 'atid' => $applicationTypeId, 'emp' => $employeeId !== '' ? $employeeId : null]);

                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                $applicationId = (int)($row['NewID'] ?? 0);

                if ($applicationId <= 0) {
                    throw new \RuntimeException('Failed to create application.');
                }
            }

            // 3) Load workflow config steps (ordered)
            $stmt = $db->prepare("
                SELECT StepKey, StepOrder
                FROM dbo.tblApplicationWorkFlowSteps
                WHERE ApplicationTypeID = :atid
                  AND IsActive = 1
                ORDER BY StepOrder ASC
            ");
            $stmt->execute(['atid' => $applicationTypeId]);
            $workflowSteps = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (!$workflowSteps) {
                throw new \RuntimeException('No workflow steps configured for this application type.');
            }

            $firstStepKey = (string)$workflowSteps[0]['StepKey'];

            // 4) Seed runtime steps (tblApplicationSteps) if missing
            $stmt = $db->prepare("
                SELECT StepKey
                FROM dbo.tblApplicationSteps
                WHERE ApplicationID = :aid
            ");
            $stmt->execute(['aid' => $applicationId]);

            $existingKeys = array_flip(array_map(
                static fn(array $r): string => (string)$r['StepKey'],
                $stmt->fetchAll(\PDO::FETCH_ASSOC)
            ));

            $ins = $db->prepare("
                INSERT INTO dbo.tblApplicationSteps
                    (ApplicationID, StepKey, IsComplete, LastSavedAt, UpdatedBy)
                VALUES
                    (:aid, :k, 0, SYSUTCDATETIME(), :uid)
            ");

            foreach ($workflowSteps as $ws) {
                $k = (string)$ws['StepKey'];
                if (isset($existingKeys[$k])) {
                    continue;
                }
                $ins->execute([
                    'aid' => $applicationId,
                    'k'   => $k,
                    'uid' => $userId,
                ]);
            }

            // 5) Ensure CurrentStepKey set, and mark as InProgress
            $stmt = $db->prepare("
                SELECT CurrentStepKey
                FROM dbo.tblApplications
                WHERE ApplicationID = :aid
            ");
            $stmt->execute(['aid' => $applicationId]);
            $curKey = (string)($stmt->fetchColumn() ?? '');

            if ($curKey === '') {
                $upd = $db->prepare("
                    UPDATE dbo.tblApplications
                    SET CurrentStepKey = :k,
                        Status = 'InProgress',
                        LastSavedAt = SYSDATETIME()
                    WHERE ApplicationID = :aid
                ");
                $upd->execute(['k' => $firstStepKey, 'aid' => $applicationId]);
                $curKey = $firstStepKey;
            }

            // 6) Prefill first step DataJson if empty
            $stmt = $db->prepare("
                SELECT DataJson
                FROM dbo.tblApplicationSteps
                WHERE ApplicationID = :aid AND StepKey = :k
            ");
            $stmt->execute(['aid' => $applicationId, 'k' => $firstStepKey]);
            $dataJson = $stmt->fetchColumn();

            if ($dataJson === null || trim((string)$dataJson) === '') {
                $profile = $this->loadEmployeeDefaults($db, $employeeId);

                $payload = [
                    'NameOnCard'    => (string)($profile['NameOnCard'] ?? ''),
                    'AddressLine1'  => (string)($profile['AddressLine1'] ?? ''),
                    'AddressLine2'  => (string)($profile['AddressLine2'] ?? ''),
                    'AddressLine3'  => (string)($profile['AddressLine3'] ?? ''),
                    'Suburb'        => (string)($profile['Suburb'] ?? ''),
                    'State'         => (string)($profile['State'] ?? ''),
                    'PostCode'      => (string)($profile['PostCode'] ?? ''),
                    'Email'         => (string)($profile['Email'] ?? ''),
                    'GroupName'     => (string)($profile['GroupName'] ?? ''),
                    'CostCentre'    => (string)($profile['CostCentre'] ?? ''),
                    'DateOfBirth'   => $profile['DateOfBirth'] ?? null,
                ];

                $upd = $db->prepare("
                    UPDATE dbo.tblApplicationSteps
                    SET DataJson = :json,
                        LastSavedAt = SYSUTCDATETIME(),
                        UpdatedBy = :uid
                    WHERE ApplicationID = :aid AND StepKey = :k
                ");
                $upd->execute([
                    'json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                    'uid'  => $userId,
                    'aid'  => $applicationId,
                    'k'    => $firstStepKey,
                ]);
            }

            // 7) bump application LastSavedAt when entering/resuming
            $upd = $db->prepare("
                UPDATE dbo.tblApplications
                SET LastSavedAt = SYSDATETIME()
                WHERE ApplicationID = :aid
            ");
            $upd->execute(['aid' => $applicationId]);

            $db->commit();

            // 8) Go to wizard
            header('Location: index.php?route=applications/wizard&id=' . urlencode((string)$applicationId));
            exit;

        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[CardsController::apply ERROR] ' . $e->getMessage());
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Could not start application.']);
            header('Location: index.php?route=cards/index');
            exit;
        }
    }

    /**
     * Defaults from qryCAPSCDMCHistoryActive WHERE EmployeeID = :eid
     * Sources:
     * - NameOnCard: Firstname + Surname
     * - AddressLine1..3: OutDinersAddress1..3
     * - Suburb: OutSuburb
     * - State: OutState
     * - PostCode: OutPostCode
     * - Email: Email_Address (not editable)
     * - GroupName: GroupName
     * - CostCentre: CostCentre
     * - DateOfBirth: DateofBirth
     */
    private function loadEmployeeDefaults(\PDO $db, string $employeeId): array
    {
        $stmt = $db->prepare("
            SELECT
                EmployeeID,

                LTRIM(RTRIM(
                    CONCAT(
                        COALESCE(NULLIF(LTRIM(RTRIM(Firstname)), ''), ''),
                        CASE
                            WHEN NULLIF(LTRIM(RTRIM(Firstname)), '') IS NOT NULL
                             AND NULLIF(LTRIM(RTRIM(Surname)), '')  IS NOT NULL THEN ' '
                            ELSE ''
                        END,
                        COALESCE(NULLIF(LTRIM(RTRIM(Surname)), ''), '')
                    )
                )) AS NameOnCard,

                OutDinersAddress1 AS AddressLine1,
                OutDinersAddress2 AS AddressLine2,
                OutDinersAddress3 AS AddressLine3,
                OutSuburb         AS Suburb,
                OutState          AS [State],
                OutPostCode       AS PostCode,

                Email_Address     AS Email,
                GroupName         AS GroupName,
                CostCentre        AS CostCentre,
                DateofBirth       AS DateOfBirth
            FROM qryCAPSCDMCHistoryActive
            WHERE EmployeeID = :eid
        ");
        $stmt->execute(['eid' => $employeeId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: [];
    }

    private function loadCapsBlockingCodeDescriptions(array $codes): array
    {
        $cleanCodes = [];
        foreach ($codes as $code) {
            $value = strtoupper(trim((string)$code));
            if ($value !== '') {
                $cleanCodes[$value] = true;
            }
        }

        if ($cleanCodes === []) {
            return [];
        }

        global $capsConn;
        if (!($capsConn instanceof \PDO)) {
            return [];
        }

        $params = [];
        $placeholders = [];
        $index = 0;
        foreach (array_keys($cleanCodes) as $code) {
            $param = 'code_' . $index++;
            $placeholders[] = ':' . $param;
            $params[$param] = $code;
        }

        $stmt = $capsConn->prepare("
            SELECT
                UPPER(LTRIM(RTRIM(BlockCode))) AS BlockCode,
                LTRIM(RTRIM(ISNULL(BlockCodeDesc, ''))) AS BlockCodeDesc
            FROM dbo.tblCAPSNABBlockingCodes
            WHERE UPPER(LTRIM(RTRIM(BlockCode))) IN (" . implode(', ', $placeholders) . ")
        ");
        $stmt->execute($params);

        $map = [];
        foreach (($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $row) {
            $code = strtoupper(trim((string)($row['BlockCode'] ?? '')));
            if ($code === '') {
                continue;
            }
            $map[$code] = trim((string)($row['BlockCodeDesc'] ?? ''));
        }

        return $map;
    }

    /**
     * Card limit change request screen (layout only for now).
     * GET: index.php?route=cards/request-limit-change&type=dtc|dpc|lodge
     */
    public function requestLimitChange(): void
    {
        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $db = $this->db;
        $rbac = new \App\Core\Rbac($db);
        $isAdminOverride = !empty($_GET['admin']) && $rbac->canAny(['ADMIN_ALL', 'SYSADMIN']);
        $typeKey = strtolower(trim((string)($_GET['type'] ?? '')));
        $cardId = (int)($_GET['id'] ?? 0);
        $onBehalfEmployeeId = trim((string)($_GET['ob_employee_id'] ?? ''));
        $isOnBehalf = ((string)($_GET['on_behalf'] ?? '') === '1');
        $onBehalfCardType = strtoupper(trim((string)($_GET['ob_card_type'] ?? '')));
        $onBehalfLast4 = preg_replace('/\D/', '', (string)($_GET['ob_last4'] ?? '')) ?? '';
        $requestedApplicationId = (int)($_GET['application_id'] ?? 0);
        $applicationEmployeeId = '';
        $requestedApplicationMatchesContext = false;
        if ($requestedApplicationId > 0) {
            $existingApp = $isAdminOverride
                ? $this->loadAnyApplicationById($db, $requestedApplicationId)
                : $this->loadApplicationById($db, $requestedApplicationId, $userId);
            if ($existingApp) {
                $existingPayload = $this->loadPayload($db, $requestedApplicationId);
                if ($typeKey === '') {
                    $typeMeta = $this->loadApplicationTypeMetaById((int)($existingApp['ApplicationTypeID'] ?? 0));
                    $typeKey = strtolower(trim((string)($typeMeta['ApplicationTypeKey'] ?? '')));
                }
                $applicationEmployeeId = trim((string)($existingPayload['target_employee_id'] ?? ''));
                $applicationOwnerEmployeeId = trim((string)($existingApp['EmployeeID'] ?? ''));
                if ($applicationEmployeeId === '' && $applicationOwnerEmployeeId !== '') {
                    $applicationEmployeeId = $applicationOwnerEmployeeId;
                }
                if ((string)($existingPayload['on_behalf'] ?? '') === '1') {
                    $isOnBehalf = true;
                }
                if ($cardId <= 0) {
                    $cardId = (int)($existingPayload['card_id'] ?? 0);
                }
                $requestedCardId = (int)($existingPayload['card_id'] ?? 0);
                $requestedApplicationMatchesContext =
                    (int)($existingApp['ApplicationTypeID'] ?? 0) > 0
                    && ($requestedCardId <= 0 || $cardId <= 0 || $requestedCardId === $cardId);
            }
        }
        $employeeId = $onBehalfEmployeeId !== '' ? $onBehalfEmployeeId : ($applicationEmployeeId !== '' ? $applicationEmployeeId : $this->resolveEmployeeId($userId));

        $card = null;
        if ($cardId > 0) {
            if ($employeeId !== '') {
                $card = $this->loadPortalCard($cardId, $employeeId);
            }
            if (!$card && $isOnBehalf) {
                // On-behalf links can carry a target employee format that does not
                // exactly match tblPORTALCards.EmployeeID; CardID is authoritative here.
                $card = $this->loadPortalCardById($cardId);
            }
        }
        if ($card) {
            $cardEmployeeId = trim((string)($card['EmployeeID'] ?? ''));
            if ($cardEmployeeId !== '') {
                $employeeId = $cardEmployeeId;
            }
        }
        if (!$card || !$this->isActivePortalCard($card)) {
            if ($isOnBehalf || $onBehalfEmployeeId !== '') {
                SessionHelper::set('onBehalf.error', 'Limit change requests must be linked to an active card.');
                SessionHelper::set('onBehalf.old', [
                    'card_type' => $onBehalfCardType,
                    'employee_id' => $onBehalfEmployeeId,
                    'last4' => $onBehalfLast4,
                ]);
            } else {
                SessionHelper::set('flash.message', [
                    'type' => 'danger',
                    'text' => 'Limit change requests must be linked to an active card.',
                ]);
            }
            header('Location: index.php?route=home/index');
            exit;
        }

        $typeKey = $this->resolveLimitChangeTypeKey($typeKey, $card);
        $typeLabel = match ($typeKey) {
            'dtc_limit_change' => 'Defence Travel Card (DTC) Limit Change',
            'dpc_limit_change' => 'Defence Purchasing Card (DPC) Limit Change',
            'lodge_limit_change' => 'Lodge Card Limit Change',
            default => 'Limit Change',
        };

        $type = $this->loadApplicationTypeByKey($db, $typeKey);
        if (!$type) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Unknown limit change type.']);
            header('Location: index.php?route=home/index');
            exit;
        }
        $applicationTypeId = (int)$type['ApplicationTypeID'];

        $workflow = $this->loadWorkflowSteps($db, $applicationTypeId);
        if ($workflow) {
            $workflow = array_values(array_filter($workflow, static function ($ws) {
                $k = strtolower(trim((string)($ws['StepKey'] ?? '')));
                return $k !== 'cms_complete';
            }));
        }
        if (!$workflow) {
            $workflow = [
                ['StepKey' => 'card_details',     'StepLabel' => 'Card Details Complete', 'IsRequired' => 1],
                ['StepKey' => 'limits_complete',  'StepLabel' => 'Limits Complete', 'IsRequired' => 1],
                ['StepKey' => 'justification_complete', 'StepLabel' => 'Justification Complete', 'IsRequired' => 1],
                ['StepKey' => 'application_submitted', 'StepLabel' => 'Application Submitted', 'IsRequired' => 1],
            ];
        }

        $existingDraft = null;
        if ($requestedApplicationId <= 0) {
            $existingDraft = $this->findOpenLimitChangeApplication($db, $userId, $applicationTypeId, $employeeId, $cardId);
            if (!$existingDraft && $this->applicationTypeRequiresPrivacyAgreement($type)) {
                $errorKey = 'cards.limit_change.privacy_error.' . strtolower($typeKey);
                $privacyError = trim((string)(SessionHelper::get($errorKey) ?? ''));
                SessionHelper::forget($errorKey);

                $this->render('applications/PrivacyAgreementStart', [
                    'title' => 'Privacy Notice',
                    'heading' => 'Privacy Notice',
                    'applicationType' => $type,
                    'privacyError' => $privacyError,
                    '_csrf' => csrf_token(),
                    'formAction' => 'index.php?route=cards/request-limit-change-agree',
                    'backHref' => 'index.php?route=home/index',
                    'hiddenFields' => [
                        'id' => (string)$cardId,
                        'ob_employee_id' => $onBehalfEmployeeId,
                        'on_behalf' => $isOnBehalf ? '1' : '0',
                        'ob_card_type' => $onBehalfCardType,
                        'ob_last4' => $onBehalfLast4,
                    ],
                ]);
                return;
            }
        }

        if ($requestedApplicationId > 0) {
            $requestedApp = $isAdminOverride
                ? $this->loadAnyApplicationById($db, $requestedApplicationId)
                : $this->loadApplicationById($db, $requestedApplicationId, $userId);
            $requestedPayload = $requestedApp ? $this->loadPayload($db, $requestedApplicationId) : [];
            $requestedTargetEmployeeId = trim((string)($requestedPayload['target_employee_id'] ?? ''));
            $requestedOwnerEmployeeId = trim((string)($requestedApp['EmployeeID'] ?? ''));
            if ($requestedTargetEmployeeId === '' && $requestedOwnerEmployeeId !== '') {
                $requestedTargetEmployeeId = $requestedOwnerEmployeeId;
            }
            $requestedCardId = (int)($requestedPayload['card_id'] ?? 0);
            $matchesEmployee = $employeeId !== '' && $requestedTargetEmployeeId !== '' && strcasecmp($requestedTargetEmployeeId, $employeeId) === 0;
            $matchesCard = $cardId > 0 && $requestedCardId > 0 && $requestedCardId === $cardId;
            if (
                $requestedApp
                && (int)($requestedApp['ApplicationTypeID'] ?? 0) === $applicationTypeId
                && $matchesEmployee
                && $matchesCard
            ) {
                $applicationId = $requestedApplicationId;
                $this->ensureRuntimeSteps($db, $applicationId, $workflow);
                $this->ensurePayloadRow($db, $applicationId);
            } else {
                $applicationId = $this->ensureLimitChangeApplication(
                    $db,
                    $userId,
                    $applicationTypeId,
                    $workflow,
                    $employeeId,
                    $cardId
                );
            }
        } elseif ($existingDraft) {
            $applicationId = (int)($existingDraft['ApplicationID'] ?? 0);
            $this->ensureRuntimeSteps($db, $applicationId, $workflow);
            $this->ensurePayloadRow($db, $applicationId);
        } else {
            $applicationId = $this->ensureLimitChangeApplication(
                $db,
                $userId,
                $applicationTypeId,
                $workflow,
                $employeeId,
                $cardId
            );
        }

        $payload = $this->loadPayload($db, $applicationId);
        $errorsKey = 'limitChange.validation_errors.' . $applicationId;
        $oldKey = 'limitChange.old_input.' . $applicationId;
        $validationErrors = SessionHelper::get($errorsKey);
        SessionHelper::forget($errorsKey);
        $oldInput = SessionHelper::get($oldKey);
        SessionHelper::forget($oldKey);
        if (is_array($oldInput) && $oldInput) {
            $payload = array_merge($payload, $oldInput);
        }
        if (!is_array($validationErrors)) {
            $validationErrors = [];
        }
        if (!$payload) {
            $payload = [];
        }
        if ($card) {
            $payload += [
                'on_behalf' => $isOnBehalf ? '1' : (string)($payload['on_behalf'] ?? ''),
                'target_employee_id' => $employeeId,
                'card_id' => (int)($card['CardID'] ?? 0),
                'card_type' => (string)($card['CardType'] ?? ''),
                'card_type_sub' => (string)($card['CardTypeSub'] ?? ''),
                'name_on_card' => (string)($card['NameOnCard'] ?? ''),
                'card_number' => (string)($card['CardNumber'] ?? ''),
                'card_expiry' => (string)($card['Expiry'] ?? ''),
                'date_issued' => (string)($card['DateIssued'] ?? ''),
                'credit_limit_current' => (string)($this->firstNonEmpty($card, ['ActiveCeiling', 'CreditLimitAmount', 'CreditLimit']) ?? ''),
                'transaction_limit_current' => (string)($card['TransactionLimit'] ?? ''),
            ];
        }
        $hasLimitChangeScopeSelection = trim((string)($payload['limit_change_scope'] ?? '')) !== '';
        unset($payload['employee_type'], $payload['employee_group'], $payload['employee_group_routing']);
        $isCreditOnlyLimitChange = $this->isCreditOnlyLimitChangeType(
            (string)($typeKey ?? ''),
            (string)($card['CardType'] ?? ($payload['card_type'] ?? '')),
            (string)($card['CardTypeSub'] ?? ($payload['card_type_sub'] ?? ''))
        );
        $payload = $this->normalizeLimitChangePeriodPayload(
            $payload,
            $isCreditOnlyLimitChange
        );
        $limitChangeScope = strtolower(trim((string)($payload['limit_change_scope'] ?? ($isCreditOnlyLimitChange ? 'credit_only' : 'both'))));
        if (!in_array($limitChangeScope, ['both', 'credit_only', 'transaction_only'], true)) {
            $limitChangeScope = $isCreditOnlyLimitChange ? 'credit_only' : 'both';
            $payload['limit_change_scope'] = $limitChangeScope;
        }
        if ($isCreditOnlyLimitChange) {
            $limitChangeScope = 'credit_only';
            $payload['limit_change_scope'] = 'credit_only';
        }
        $transactionLimitOptions = $this->loadCapsTransactionLimitOptions($card, $payload);
        $payload = $this->hydrateTransactionLimitPayload($payload, $transactionLimitOptions);
        $creditLimitMaxAmount = $this->getLimitChangeMaxCreditAmount();
        $temporaryLimitPeriodMonths = $this->getLimitChangeTemporaryPeriodMonths();
        $reasonOptions = $this->loadLimitChangeReasonOptions($applicationTypeId);
        $submitDeclarationText = $this->getSubmitAgreementText();

        $appRow = $isAdminOverride
            ? ($this->loadAnyApplicationById($db, $applicationId) ?? [])
            : ($this->loadApplicationById($db, $applicationId, $userId) ?? []);
        $appStatus = (string)($appRow['Status'] ?? 'Draft');
        $isLocked = !in_array(strtolower(trim($appStatus)), ['draft', 'inprogress'], true);
        $runtimeSteps = $this->loadRuntimeSteps($db, $applicationId);
        $derivedRuntimeSteps = $this->buildLimitChangeRuntimeSteps(
            $workflow,
            $payload,
            (string)($card['CardType'] ?? ''),
            $appStatus
        );
        if ($runtimeSteps === []) {
            $runtimeSteps = $derivedRuntimeSteps;
        } else {
            // Always reflect the latest saved payload in the checklist, even after submission.
            $runtimeSteps = array_replace($runtimeSteps, $derivedRuntimeSteps);
        }

        $authEmployeeGroupDebug = trim((string)SessionHelper::get('auth.EmployeeGroup', ''));
        $authEmployeeGroupLowerDebug = trim((string)SessionHelper::get('auth.employee_group', ''));
        $employeeType = $this->resolveApprovalEmployeeType($userId, $employeeId, $payload, $card);
        $employeeGroupRouting = $this->resolveEmployeeGroup($userId, $employeeId, $payload, $card);
        $employeeGroupDisplay = $this->resolveEmployeeGroupDisplay($userId, $employeeId, $payload, $card);
        $employeeGroup = $employeeGroupDisplay !== '' ? $employeeGroupDisplay : $employeeGroupRouting;
        $payload['employee_type'] = $employeeType;
        $payload['employee_group'] = $employeeGroup;
        $payload['employee_group_routing'] = $employeeGroupRouting;
        $this->savePayload($db, $applicationId, $userId, $payload);
        $applicantEmail = $this->resolveLimitChangeApplicantEmail($userId, $employeeId, $payload, $card);
        $approverRules = $this->loadApprovalRules((int)$applicationTypeId, $employeeGroup);
        $approverDirectory = $this->loadApproverDirectory($employeeGroup);
        $sesApproverEmails = $this->loadSesApproverEmails($employeeGroup, true);
        $storedApprovalStages = $this->normalizeStoredApprovalStages($payload);
        $currentApprovalStage = $this->resolveCurrentApprovalStageNumber($payload, $storedApprovalStages);
        $totalApprovalStages = count($storedApprovalStages) > 0 ? count($storedApprovalStages) : max(1, (int)($payload['approval_stage_total'] ?? 1));
        $currentApprovalStageRow = $this->findStoredApprovalStage($storedApprovalStages, $currentApprovalStage);
        $currentApprovalDisplay = $currentApprovalStageRow !== null
            ? $this->resolveApproverDisplayFromSelection((string)($currentApprovalStageRow['approver'] ?? ''), $employeeGroup)
            : $this->resolveApproverDisplayFromSelection((string)($payload['approver'] ?? ''), $employeeGroup);
        $previousApprovals = $this->buildLimitChangeApprovalHistoryDisplay($payload, $employeeGroup);

        $progress = $this->buildLimitChangeProgress((string)($typeKey), $applicationId);

        $submissionToken = bin2hex(random_bytes(16));
        SessionHelper::set('cards.limit_change.submission_token.' . $applicationId, $submissionToken);

        $this->render('cards/LimitChange', [
            'title' => 'Request Limit Change',
            'typeKey' => $typeKey,
            'typeLabel' => $typeLabel,
            'applicationId' => $applicationId,
            'progress' => $progress,
            'workflow' => $workflow,
            'runtimeSteps' => $runtimeSteps,
            'data' => $payload,
            'card' => $card,
            'appStatus' => $appStatus,
            'validationErrors' => $validationErrors,
            'authEmployeeGroupDebug' => $authEmployeeGroupDebug,
            'authEmployeeGroupLowerDebug' => $authEmployeeGroupLowerDebug,
            'employeeType' => $employeeType,
            'employeeGroupDisplay' => $employeeGroupDisplay,
            'applicantEmail' => $applicantEmail,
            'approverRules' => $approverRules,
            'approverDirectory' => $approverDirectory,
            'isAdminOverride' => $isAdminOverride,
            'limitChangeScope' => $limitChangeScope,
            'showDpcScopeModal' => ($typeKey === 'dpc_limit_change' && !$isLocked && !$hasLimitChangeScopeSelection),
            'manualApproverThreshold' => $this->getLimitChangeManualApproverThreshold(),
            'sesApproverEmails' => $sesApproverEmails,
            'transactionLimitOptions' => $transactionLimitOptions,
            'creditLimitMaxAmount' => $creditLimitMaxAmount,
            'temporaryLimitPeriodMonths' => $temporaryLimitPeriodMonths,
            'reasonOptions' => $reasonOptions,
            'submitDeclarationText' => $submitDeclarationText,
            'submissionToken' => $submissionToken,
            'currentApprovalStage' => $currentApprovalStage,
            'totalApprovalStages' => $totalApprovalStages,
            'currentApprovalDisplay' => $currentApprovalDisplay,
            'previousApprovals' => $previousApprovals,
        ]);
    }

    public function requestLimitChangeAgree(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        require_once __DIR__ . '/../../shared/csrf.php';
        if (function_exists('csrf_check') && !csrf_check($_POST['_csrf'] ?? '')) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Security check failed. Please try again.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $db = $this->db;
        $actorUserId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($actorUserId <= 0) {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }
        $rbac = new \App\Core\Rbac($db);
        $isAdminOverride = !empty($_POST['admin_override']) && $rbac->canAny(['ADMIN_ALL', 'SYSADMIN']);

        $typeKey = strtolower(trim((string)($_POST['type'] ?? '')));
        $cardId = (int)($_POST['id'] ?? 0);
        $onBehalfEmployeeId = trim((string)($_POST['ob_employee_id'] ?? ''));
        $isOnBehalf = ((string)($_POST['on_behalf'] ?? '') === '1');
        $onBehalfCardType = strtoupper(trim((string)($_POST['ob_card_type'] ?? '')));
        $onBehalfLast4 = preg_replace('/\D/', '', (string)($_POST['ob_last4'] ?? '')) ?? '';

        if ($typeKey === '') {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Missing limit change type.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        if ((string)($_POST['agree_privacy'] ?? '') !== '1') {
            SessionHelper::set(
                'cards.limit_change.privacy_error.' . $typeKey,
                'Please tick the checkbox to confirm you have read and agree to the Privacy Notice before continuing.'
            );
            header('Location: ' . $this->buildLimitChangeRoute($typeKey, $cardId, 0, [
                'ob_employee_id' => $onBehalfEmployeeId,
                'on_behalf' => $isOnBehalf ? '1' : '0',
                'ob_card_type' => $onBehalfCardType,
                'ob_last4' => $onBehalfLast4,
            ]));
            exit;
        }

        $employeeId = $onBehalfEmployeeId !== '' ? $onBehalfEmployeeId : $this->resolveEmployeeId($userId);
        $card = null;
        if ($cardId > 0) {
            if ($employeeId !== '') {
                $card = $this->loadPortalCard($cardId, $employeeId);
            }
            if (!$card && $isOnBehalf) {
                $card = $this->loadPortalCardById($cardId);
            }
        }

        if (!$card || !$this->isActivePortalCard($card)) {
            SessionHelper::set('flash.message', [
                'type' => 'danger',
                'text' => 'Limit change requests must be linked to an active card.',
            ]);
            header('Location: index.php?route=home/index');
            exit;
        }

        $typeKey = $this->resolveLimitChangeTypeKey($typeKey, $card);
        $type = $this->loadApplicationTypeByKey($db, $typeKey);
        if (!$type) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Unknown limit change type.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $applicationTypeId = (int)($type['ApplicationTypeID'] ?? 0);
        if ($applicationTypeId <= 0) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Unknown limit change type.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $workflow = $this->loadWorkflowSteps($db, $applicationTypeId);
        if ($workflow) {
            $workflow = array_values(array_filter($workflow, static function ($ws) {
                $k = strtolower(trim((string)($ws['StepKey'] ?? '')));
                return $k !== 'cms_complete';
            }));
        }
        if (!$workflow) {
            $workflow = [
                ['StepKey' => 'card_details',     'StepLabel' => 'Card Details Complete', 'IsRequired' => 1],
                ['StepKey' => 'limits_complete',  'StepLabel' => 'Limits Complete', 'IsRequired' => 1],
                ['StepKey' => 'justification_complete', 'StepLabel' => 'Justification Complete', 'IsRequired' => 1],
                ['StepKey' => 'application_submitted', 'StepLabel' => 'Application Submitted', 'IsRequired' => 1],
            ];
        }

        $application = $this->findOpenLimitChangeApplication($db, $userId, $applicationTypeId, $employeeId, $cardId);
        if ($application) {
            $applicationId = (int)($application['ApplicationID'] ?? 0);
            $this->ensureRuntimeSteps($db, $applicationId, $workflow);
            $this->ensurePayloadRow($db, $applicationId);
        } else {
            $applicationId = $this->ensureLimitChangeApplication($db, $userId, $applicationTypeId, $workflow, $employeeId, $cardId);
        }

        $payload = $this->loadPayload($db, $applicationId);
        $agreementText = trim((string)($type['PrivacyAgreementText'] ?? ''));
        $payload['privacy_agreement_required'] = '1';
        $payload['privacy_agreement_accepted'] = '1';
        $payload['privacy_agreement_accepted_at'] = gmdate('Y-m-d H:i:s');
        $payload['privacy_agreement_application_type_id'] = (string)$applicationTypeId;
        $payload['privacy_agreement_text'] = $agreementText;
        $payload['privacy_agreement_hash'] = sha1($agreementText);
        $payload['target_employee_id'] = $employeeId;
        $payload['card_id'] = (int)($card['CardID'] ?? 0);
        $payload['type_key'] = $typeKey;
        if ($isOnBehalf) {
            $payload['on_behalf'] = '1';
        }
        unset($payload['employee_type'], $payload['employee_group'], $payload['employee_group_routing']);
        $payload['employee_type'] = $this->resolveApprovalEmployeeType($userId, $employeeId, $payload, $card);
        $employeeGroupRouting = $this->resolveEmployeeGroup($userId, $employeeId, $payload, $card);
        $employeeGroupDisplay = $this->resolveEmployeeGroupDisplay($userId, $employeeId, $payload, $card);
        $payload['employee_group'] = $employeeGroupDisplay !== '' ? $employeeGroupDisplay : $employeeGroupRouting;
        $payload['employee_group_routing'] = $employeeGroupRouting;
        $this->savePayload($db, $applicationId, $userId, $payload);

        $this->auditLog(
            'PRIVACY_AGREEMENT_ACCEPTED',
            'Application',
            (string)$applicationId,
            [
                'route' => 'cards/request-limit-change-agree',
                'application_type_key' => $typeKey,
                'application_type_id' => $applicationTypeId,
                'application_type_name' => (string)($type['ApplicationTypeName'] ?? ''),
                'card_id' => (int)($card['CardID'] ?? 0),
                'target_employee_id' => $employeeId,
                'privacy_agreement_accepted_at' => (string)$payload['privacy_agreement_accepted_at'],
                'privacy_agreement_hash' => sha1($agreementText),
            ]
        );

        header('Location: ' . $this->buildLimitChangeRoute($typeKey, (int)($card['CardID'] ?? 0), $applicationId, [
            'ob_employee_id' => $onBehalfEmployeeId,
            'on_behalf' => $isOnBehalf ? '1' : '0',
            'ob_card_type' => $onBehalfCardType,
            'ob_last4' => $onBehalfLast4,
        ]));
        exit;
    }

    /**
     * Start a limit change for another employee/card selected from On behalf of modal.
     * GET: index.php?route=cards/on-behalf-limit-change-start&card_type=DTC|DPC|LODGE&employee_id=...&last4=1234
     */
    public function onBehalfLimitChangeStart(): void
    {
        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $cardType = strtoupper(trim((string)($_GET['card_type'] ?? '')));
        $employeeId = trim((string)($_GET['employee_id'] ?? ''));
        $last4 = preg_replace('/\D/', '', (string)($_GET['last4'] ?? '')) ?? '';

        if (!in_array($cardType, ['DTC', 'DPC', 'LODGE'], true) || $employeeId === '' || strlen($last4) !== 4) {
            SessionHelper::set('onBehalf.error', 'Please provide Card Type, EmployeeID, and exactly 4 card digits.');
            SessionHelper::set('onBehalf.old', [
                'card_type' => $cardType,
                'employee_id' => $employeeId,
                'last4' => $last4,
            ]);
            header('Location: index.php?route=home/index');
            exit;
        }

        $card = $this->findPortalCardForOnBehalf($cardType, $employeeId, $last4);
        if (!$card) {
            SessionHelper::set('onBehalf.error', 'Card cannot be found for the entered details.');
            SessionHelper::set('onBehalf.old', [
                'card_type' => $cardType,
                'employee_id' => $employeeId,
                'last4' => $last4,
            ]);
            header('Location: index.php?route=home/index');
            exit;
        }
        if (!$this->isActivePortalCard($card)) {
            SessionHelper::set('onBehalf.error', 'Limit change requests must be linked to an active card.');
            SessionHelper::set('onBehalf.old', [
                'card_type' => $cardType,
                'employee_id' => $employeeId,
                'last4' => $last4,
            ]);
            header('Location: index.php?route=home/index');
            exit;
        }

        $typeKey = match ($cardType) {
            'DPC' => 'dpc_limit_change',
            'LODGE' => 'lodge_limit_change',
            default => 'dtc_limit_change',
        };
        header(
            'Location: index.php?route=cards/request-limit-change'
            . '&type=' . urlencode($typeKey)
            . '&id=' . urlencode((string)((int)($card['CardID'] ?? 0)))
            . '&ob_employee_id=' . urlencode($employeeId)
            . '&ob_card_type=' . urlencode($cardType)
            . '&ob_last4=' . urlencode($last4)
            . '&on_behalf=1'
        );
        exit;
    }

    /**
     * Save limit change (draft/submit) as an Application record.
     * POST: index.php?route=cards/limit-change-save
     */
    public function limitChangeSave(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        require_once __DIR__ . '/../../shared/csrf.php';
        if (function_exists('csrf_check') && !csrf_check($_POST['_csrf'] ?? '')) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Security check failed. Please try again.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $db = $this->db;
        $actorUserId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($actorUserId <= 0) {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }
        $rbac = new \App\Core\Rbac($db);
        $isAdminOverride = !empty($_POST['admin_override']) && $rbac->canAny(['ADMIN_ALL', 'SYSADMIN']);

        $applicationId = (int)($_POST['application_id'] ?? 0);
        if ($applicationId <= 0) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Missing application id.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $app = $isAdminOverride
            ? $this->loadAnyApplicationById($db, $applicationId)
            : $this->loadApplicationById($db, $applicationId, $actorUserId);
        if (!$app) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Application not found.']);
            header('Location: index.php?route=home/index');
            exit;
        }
        $applicationOwnerUserId = (int)($app['UserID'] ?? 0);
        $requestorUserId = $applicationOwnerUserId > 0 ? $applicationOwnerUserId : $actorUserId;
        $status = strtolower(trim((string)($app['Status'] ?? 'draft')));
        if ($this->isLimitChangeLockedStatus($status)) {
            $typeKey = (string)($_POST['type_key'] ?? '');
            $cardId = (int)($_POST['card_id'] ?? 0);
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'This application is locked and cannot be edited.']);
            $lockedRedirect = 'index.php?route=cards/request-limit-change&type=' . urlencode($typeKey) . '&id=' . urlencode((string)$cardId) . '&application_id=' . urlencode((string)$applicationId);
            if ($isAdminOverride) {
                $lockedRedirect .= '&admin=1';
            }
            header('Location: ' . $lockedRedirect);
            exit;
        }

        $action = (string)($_POST['_action'] ?? 'save');
        if ($action === 'submit') {
            $postedSubmissionToken = trim((string)($_POST['submission_token'] ?? ''));
            $sessionSubmissionToken = trim((string)(SessionHelper::get('cards.limit_change.submission_token.' . $applicationId) ?? ''));
            if ($postedSubmissionToken === '' || $sessionSubmissionToken === '' || !hash_equals($sessionSubmissionToken, $postedSubmissionToken)) {
                SessionHelper::set('flash.message', ['type' => 'warning', 'text' => 'This limit change submit action has already been used or expired. Please review and submit again.']);
                $expiredRedirect = 'index.php?route=cards/request-limit-change&type=' . urlencode((string)($_POST['type_key'] ?? '')) . '&id=' . urlencode((string)($_POST['card_id'] ?? 0)) . '&application_id=' . urlencode((string)$applicationId);
                if ($isAdminOverride) {
                    $expiredRedirect .= '&admin=1';
                }
                header('Location: ' . $expiredRedirect);
                exit;
            }
            SessionHelper::forget('cards.limit_change.submission_token.' . $applicationId);
        }
        $payload = $_POST;
        unset($payload['_csrf'], $payload['_action']);
        unset($payload['employee_type'], $payload['employee_group'], $payload['employee_group_routing']);

        $savedPayload = $this->loadPayload($db, $applicationId);
        if (!is_array($savedPayload)) {
            $savedPayload = [];
        }

        $employeeId = trim((string)($payload['target_employee_id'] ?? ''));
        if ($employeeId === '') {
            $employeeId = trim((string)($savedPayload['target_employee_id'] ?? ''));
        }
        if ($employeeId === '') {
            $employeeId = $this->resolveEmployeeId($requestorUserId);
        }
        $cardId = (int)($payload['card_id'] ?? 0);
        if ($cardId <= 0) {
            $cardId = (int)($savedPayload['card_id'] ?? 0);
            if ($cardId > 0) {
                $payload['card_id'] = $cardId;
            }
        }
        $card = $cardId > 0 ? $this->loadPortalCardById($cardId) : null;
        $onBehalfFlag = trim((string)($payload['on_behalf'] ?? '')) === '1';
        if ($card && $employeeId !== '') {
            $cardEmployeeId = trim((string)($card['EmployeeID'] ?? ''));
            if ($cardEmployeeId !== '' && strcasecmp($cardEmployeeId, $employeeId) !== 0) {
                // Trust the linked card and realign the target owner from the card record.
                $employeeId = $cardEmployeeId;
                $payload['target_employee_id'] = $cardEmployeeId;
            }
        }
        if (!$card || !$this->isActivePortalCard($card)) {
            SessionHelper::set('flash.message', [
                'type' => 'danger',
                'text' => 'Limit change requests must be linked to an active card.',
            ]);
            $redir = 'index.php?route=cards/request-limit-change&type=' . urlencode((string)($payload['type_key'] ?? '')) . '&id=' . urlencode((string)$cardId) . '&application_id=' . urlencode((string)$applicationId);
            if ($isAdminOverride) {
                $redir .= '&admin=1';
            }
            if ($onBehalfFlag && $employeeId !== '') {
                $redir .= '&on_behalf=1&ob_employee_id=' . urlencode($employeeId);
            }
            header('Location: ' . $redir);
            exit;
        }

        $employeeType = $this->resolveApprovalEmployeeType($requestorUserId, $employeeId, $payload, $card);
        $employeeGroupRouting = $this->resolveEmployeeGroup($requestorUserId, $employeeId, $payload, $card);
        $employeeGroupDisplay = $this->resolveEmployeeGroupDisplay($requestorUserId, $employeeId, $payload, $card);
        $employeeGroup = $employeeGroupDisplay !== '' ? $employeeGroupDisplay : $employeeGroupRouting;
        $payload['employee_type'] = $employeeType;
        $payload['employee_group'] = $employeeGroup;
        $payload['employee_group_routing'] = $employeeGroupRouting;

        $isCreditOnlyLimitChange = $this->isCreditOnlyLimitChangeType(
            (string)($payload['type_key'] ?? ''),
            (string)($card['CardType'] ?? ($payload['card_type'] ?? '')),
            (string)($card['CardTypeSub'] ?? ($payload['card_type_sub'] ?? ''))
        );
        $payload = $this->normalizeLimitChangePeriodPayload(
            $payload,
            $isCreditOnlyLimitChange
        );
        $limitChangeScope = strtolower(trim((string)($payload['limit_change_scope'] ?? ($isCreditOnlyLimitChange ? 'credit_only' : 'both'))));
        if (!in_array($limitChangeScope, ['both', 'credit_only', 'transaction_only'], true)) {
            $limitChangeScope = $isCreditOnlyLimitChange ? 'credit_only' : 'both';
            $payload['limit_change_scope'] = $limitChangeScope;
        }
        if ($isCreditOnlyLimitChange) {
            $limitChangeScope = 'credit_only';
            $payload['limit_change_scope'] = 'credit_only';
        }
        if ($limitChangeScope === 'transaction_only') {
            $payload['credit_limit_new'] = trim((string)($payload['credit_limit_current'] ?? ($this->firstNonEmpty($card, ['ActiveCeiling', 'CreditLimitAmount', 'CreditLimit']) ?? '')));
            $payload['credit_limit_change_duration_type'] = 'permanent';
            $payload['credit_period_change_from'] = '';
            $payload['credit_period_change_to'] = '';
        }
        if ($limitChangeScope === 'credit_only') {
            $payload['transaction_limit_new_amount'] = trim((string)($payload['transaction_limit_current'] ?? ($card['TransactionLimit'] ?? '')));
            $payload['transaction_limit_change_duration_type'] = 'permanent';
            $payload['transaction_period_change_from'] = '';
            $payload['transaction_period_change_to'] = '';
        }
        $maxTemporaryMonths = $this->getLimitChangeTemporaryPeriodMonths();
        $periodValidationErrors = [];
        $cardTypeForPeriods = (string)($card['CardType'] ?? ($payload['card_type'] ?? ''));
        $isCreditOnlyLimitChange = $this->isCreditOnlyLimitChangeType(
            (string)($payload['type_key'] ?? ''),
            $cardTypeForPeriods,
            (string)($card['CardTypeSub'] ?? ($payload['card_type_sub'] ?? ''))
        );
        if ($limitChangeScope !== 'transaction_only') {
            $this->validateLimitChangePeriodFields(
                $periodValidationErrors,
                $payload,
                'credit_limit_change_duration_type',
                'credit_period_change_from',
                'credit_period_change_to',
                'Credit Limit',
                $maxTemporaryMonths
            );
        }
        if (!$isCreditOnlyLimitChange && $limitChangeScope !== 'credit_only') {
            $this->validateLimitChangePeriodFields(
                $periodValidationErrors,
                $payload,
                'transaction_limit_change_duration_type',
                'transaction_period_change_from',
                'transaction_period_change_to',
                'Transaction Limit',
                $maxTemporaryMonths
            );
        }
        if (!empty($periodValidationErrors)) {
            SessionHelper::set('flash.message', [
                'type' => 'danger',
                'text' => 'Please correct the highlighted date fields before saving.',
            ]);
            SessionHelper::set('limitChange.validation_errors.' . $applicationId, $periodValidationErrors);
            SessionHelper::set('limitChange.old_input.' . $applicationId, $payload);
            $redir = 'index.php?route=cards/request-limit-change&type=' . urlencode((string)($payload['type_key'] ?? '')) . '&id=' . urlencode((string)$cardId) . '&application_id=' . urlencode((string)$applicationId);
            if ($isAdminOverride) {
                $redir .= '&admin=1';
            }
            if ($onBehalfFlag && $employeeId !== '') {
                $redir .= '&on_behalf=1&ob_employee_id=' . urlencode($employeeId);
            }
            header('Location: ' . $redir);
            exit;
        }

        $transactionLimitOptions = $this->loadCapsTransactionLimitOptions($card, $payload);
        $txnSelection = $this->resolveTransactionLimitSelection((string)($payload['transaction_limit_new'] ?? ''), $transactionLimitOptions);
        if ($txnSelection !== null) {
            $payload['transaction_limit_new_amount'] = $this->normalizeMoneyString($txnSelection['trans_limit']);
            $payload['transaction_limit_new_label'] = $txnSelection['label'];
        } else {
            unset($payload['transaction_limit_new_amount'], $payload['transaction_limit_new_label']);
        }

        $creditAmountForApprover = $this->parseMoney(trim((string)($payload['credit_limit_new'] ?? '')));
        $txnAmountForApprover = $txnSelection !== null
            ? (float)($txnSelection['trans_limit'] ?? 0)
            : $this->parseMoney((string)($payload['transaction_limit_new_amount'] ?? ''));
        $approverAmount = $limitChangeScope === 'transaction_only'
            ? $txnAmountForApprover
            : $creditAmountForApprover;

        $rules = $this->loadApprovalRules((int)($app['ApplicationTypeID'] ?? 0), $employeeGroup);
        $directory = $this->loadApproverDirectory($employeeGroup);
        $submissionApprovalStages = [];
        $firstStageSelectionMode = '';
        $firstStageCandidates = [];
        if ($approverAmount !== null) {
            $submissionApprovalStages = $this->hydrateApprovalStagesWithCandidates(
                $this->buildApprovalStageDefinitions($rules, $approverAmount),
                $directory
            );
            $submissionApprovalStages = $this->filterApplicantFromApprovalStages(
                $requestorUserId,
                $employeeId,
                $payload,
                $card,
                $employeeGroup,
                $submissionApprovalStages
            );
            foreach ($submissionApprovalStages as $idx => $stage) {
                if (!is_array($stage)) {
                    continue;
                }
                $selectionMode = trim((string)($stage['selection_mode'] ?? ''));
                if ($selectionMode !== 'resolved') {
                    continue;
                }
                $submissionApprovalStages[$idx]['candidate_approvers'] = $this->filterResolvableApproverOptions(
                    is_array($stage['candidate_approvers'] ?? null) ? $stage['candidate_approvers'] : [],
                    $employeeGroup
                );
            }
            if ($submissionApprovalStages !== []) {
                $firstStageSelectionMode = trim((string)($submissionApprovalStages[0]['selection_mode'] ?? ''));
                $firstStageCandidates = is_array($submissionApprovalStages[0]['candidate_approvers'] ?? null)
                    ? array_values($submissionApprovalStages[0]['candidate_approvers'])
                    : [];
                $submissionApprovalStages[0]['candidate_approvers'] = $firstStageCandidates;
            }
        }
        $usesManualApproverEmail = $firstStageSelectionMode === 'manual';

        if ($usesManualApproverEmail) {
            $approverEmail = $this->normalizeComparableEmailValue(
                $payload['approver_email'] ?? $this->extractApproverEmailFromSelection((string)($payload['approver'] ?? ''))
            );
            if ($approverEmail !== null) {
                $payload['approver_email'] = $approverEmail;
                $payload['approver'] = $this->buildManualApproverSelection($approverEmail);
                if (!$this->isEmailInSesApproverView($approverEmail, $employeeGroup)) {
                    $payload['approver_email_warning'] = 'The nominated approver must be a SES Band 1 / 1 Star or above. Defence HR records indicate this person does not meet this level.' . "\n\n" . 'By continuing, you are confirming that the approver meets this requirement in line with Policy.';
                } else {
                    unset($payload['approver_email_warning']);
                }
            } else {
                $payload['approver_email'] = '';
                $payload['approver'] = '';
                unset($payload['approver_email_warning']);
            }
        } else {
            unset($payload['approver_email'], $payload['approver_email_warning']);
            if ($firstStageCandidates !== []) {
                if (count($firstStageCandidates) === 1) {
                    $candidate = $firstStageCandidates[0];
                    $payload['approver'] = (string)($candidate['value'] ?? '');
                    $payload['selected_approver_type'] = (string)($candidate['type'] ?? '');
                    $payload['selected_approver_position'] = $candidate['position'] ?? null;
                } else {
                    $payload['approver'] = '';
                    $payload['selected_approver_type'] = '';
                    $payload['selected_approver_position'] = null;
                }
            }
        }

        $typeKey = (string)($payload['type_key'] ?? '');
        $workflow = $this->loadWorkflowSteps($db, (int)($app['ApplicationTypeID'] ?? 0));
        if ($workflow) {
            $workflow = array_values(array_filter($workflow, static function ($ws) {
                $k = strtolower(trim((string)($ws['StepKey'] ?? '')));
                return $k !== 'cms_complete';
            }));
        }
        if (!$workflow) {
            $workflow = [
                ['StepKey' => 'card_details',     'StepLabel' => 'Card Details Complete', 'IsRequired' => 1],
                ['StepKey' => 'limits_complete',  'StepLabel' => 'Limits Complete', 'IsRequired' => 1],
                ['StepKey' => 'justification_complete', 'StepLabel' => 'Justification Complete', 'IsRequired' => 1],
                ['StepKey' => 'application_submitted', 'StepLabel' => 'Application Submitted', 'IsRequired' => 1],
            ];
        }

        // Validate required fields on submit
        if ($action === 'submit') {
            $errors = [];
            $creditNew = trim((string)($payload['credit_limit_new'] ?? ''));
            $approver = trim((string)($payload['approver'] ?? ''));
            $reason = trim((string)($payload['limit_change_reason'] ?? ''));
            $reasonOther = trim((string)($payload['limit_change_reason_other'] ?? ''));
            $agedTxnConfirmed = ((string)($payload['aged_transactions_confirmed'] ?? '') === '1');
            $txnNew = trim((string)($payload['transaction_limit_new'] ?? ''));
            $cardType = (string)($card['CardType'] ?? ($payload['card_type'] ?? ''));
            $isCreditOnlyLimitChange = $this->isCreditOnlyLimitChangeType(
                $typeKey,
                $cardType,
                (string)($card['CardTypeSub'] ?? ($payload['card_type_sub'] ?? ''))
            );
            $limitChangeScope = strtolower(trim((string)($payload['limit_change_scope'] ?? 'both')));
            if (!in_array($limitChangeScope, ['both', 'credit_only', 'transaction_only'], true)) {
                $limitChangeScope = 'both';
            }
            $creditAmount = $this->parseMoney($creditNew);
            $txnSelection = $this->resolveTransactionLimitSelection($txnNew, $transactionLimitOptions);
            $txnAmount = $txnSelection !== null ? $txnSelection['trans_limit'] : null;
            if ($txnSelection !== null) {
                $payload['transaction_limit_new_amount'] = $this->normalizeMoneyString($txnSelection['trans_limit']);
                $payload['transaction_limit_new_label'] = $txnSelection['label'];
            }
            $approverAmount = $limitChangeScope === 'transaction_only' ? $txnAmount : $creditAmount;

            if ($limitChangeScope !== 'transaction_only') {
                if ($creditNew === '') {
                    $errors['credit_limit_new'] = 'Credit Limit New is required.';
                } elseif ($creditAmount === null || $creditAmount <= 0) {
                    $errors['credit_limit_new'] = 'Credit Limit New must be greater than 0.';
                } elseif ($creditAmount > $this->getLimitChangeMaxCreditAmount()) {
                    $errors['credit_limit_new'] = 'Credit Limit New cannot exceed ' . $this->formatWholeDollarAmount($this->getLimitChangeMaxCreditAmount()) . '.';
                } elseif (abs(fmod($creditAmount, 100.0)) > 0.00001) {
                    $errors['credit_limit_new'] = 'Credit Limit New must be in multiples of 100.';
                }
            }
            if (!$isCreditOnlyLimitChange && $limitChangeScope !== 'credit_only') {
                if ($txnNew === '') {
                    $errors['transaction_limit_new'] = 'Transaction Limit New is required.';
                } elseif ($txnSelection === null || $txnAmount === null || $txnAmount <= 0) {
                    $errors['transaction_limit_new'] = 'Transaction Limit New must be selected from the list.';
                }
            }
            if (!$isCreditOnlyLimitChange && $limitChangeScope !== 'credit_only' && $txnAmount !== null) {
                $creditLimitForTxnComparison = $limitChangeScope === 'transaction_only'
                    ? $this->parseMoney((string)($payload['credit_limit_current'] ?? ($this->firstNonEmpty($card, ['ActiveCeiling', 'CreditLimitAmount', 'CreditLimit']) ?? '')))
                    : $creditAmount;
                if ($creditLimitForTxnComparison !== null && $txnAmount > $creditLimitForTxnComparison) {
                    $errors['transaction_limit_new'] = 'Transaction Limit cannot exceed Credit Limit.';
                }
            }
            if ($usesManualApproverEmail) {
                $approverEmail = $this->normalizeComparableEmailValue($payload['approver_email'] ?? '');
                if ($approverEmail === null) {
                    $errors['approver_email'] = 'Select your Band 1 / 1 Star or above approver.';
                } elseif (!filter_var($approverEmail, FILTER_VALIDATE_EMAIL)) {
                    $errors['approver_email'] = 'Approver Email must be a valid email address.';
                } elseif (!$this->capsCdmcEmailExists($approverEmail)) {
                    $errors['approver_email'] = 'Email address does not exist in Defence Corporate Directory.';
                } elseif ($this->isApplicantApproverByEmail($requestorUserId, $employeeId, $payload, $card, $approverEmail)) {
                    $errors['approver_email'] = 'Approver cannot be the applicant.';
                }
            } elseif ($submissionApprovalStages === []) {
                $errors['approver'] = $this->appendPortalContactMessage(
                    'No approval workflow is configured for the selected limit.'
                );
            }
            if ($reason === '') {
                $errors['limit_change_reason'] = 'Reason is required.';
            } elseif (!$this->isAllowedLimitChangeReason($reason, (int)($app['ApplicationTypeID'] ?? 0))) {
                $errors['limit_change_reason'] = 'Reason must be selected from the configured list.';
            }
            if ($reasonOther === '') {
                $errors['limit_change_reason_other'] = 'Other Reason is required.';
            }
            if (!$agedTxnConfirmed) {
                $errors['aged_transactions_confirmed'] = 'You must confirm there are no un-acquitted transactions aged more than 45 days.';
            }
            if ($limitChangeScope !== 'transaction_only') {
                $this->validateLimitChangePeriodFields(
                    $errors,
                    $payload,
                    'credit_limit_change_duration_type',
                    'credit_period_change_from',
                    'credit_period_change_to',
                    'Credit Limit',
                    $maxTemporaryMonths
                );
            }
            if (!$isCreditOnlyLimitChange && $limitChangeScope !== 'credit_only') {
                $this->validateLimitChangePeriodFields(
                    $errors,
                    $payload,
                    'transaction_limit_change_duration_type',
                    'transaction_period_change_from',
                    'transaction_period_change_to',
                    'Transaction Limit',
                    $maxTemporaryMonths
                );
            }

            foreach ($submissionApprovalStages as $stage) {
                if (!is_array($stage)) {
                    continue;
                }
                $stageNumber = max(1, (int)($stage['stage'] ?? 1));
                $stageSelectionMode = trim((string)($stage['selection_mode'] ?? ''));
                $stageCandidates = is_array($stage['candidate_approvers'] ?? null)
                    ? array_values($stage['candidate_approvers'])
                    : [];

                if ($stageSelectionMode === 'manual') {
                    if ($stageNumber > 1) {
                        $errors['approver'] = $this->appendPortalContactMessage(
                            'Approval stage ' . $stageNumber . ' requires a manual approver and cannot be resolved automatically.'
                        );
                        break;
                    }
                    continue;
                }

                if ($stageCandidates === []) {
                    $errors['approver'] = $this->appendPortalContactMessage(
                        'No valid approvers were found for approval stage ' . $stageNumber . '.'
                    );
                    break;
                }
            }

            if (!empty($errors)) {
                SessionHelper::set('flash.message', [
                    'type' => 'danger',
                    'text' => 'Please correct the highlighted fields before submitting.',
                ]);

                SessionHelper::set('limitChange.validation_errors.' . $applicationId, $errors);
                SessionHelper::set('limitChange.old_input.' . $applicationId, $payload);

                // Save current payload as draft so inputs persist
                $this->savePayload($db, $applicationId, $actorUserId, $payload);
                $redir = 'index.php?route=cards/request-limit-change&type=' . urlencode($typeKey) . '&id=' . urlencode((string)$cardId) . '&application_id=' . urlencode((string)$applicationId);
                if ($isAdminOverride) {
                    $redir .= '&admin=1';
                }
                if ($onBehalfFlag && $employeeId !== '') {
                    $redir .= '&on_behalf=1&ob_employee_id=' . urlencode($employeeId);
                }
                header('Location: ' . $redir);
                exit;
            }
        }

        // Persist payload
        if ($action === 'submit') {
            $parsed = $this->parseApproverSelection((string)($payload['approver'] ?? ''));
            $payload['selected_approver_type'] = $parsed['type'] ?? '';
            $payload['selected_approver_position'] = $parsed['position'] ?? null;
            if ($submissionApprovalStages === []) {
                $submissionApprovalStages = [[
                    'stage' => 1,
                    'rules' => [],
                    'selection_mode' => $usesManualApproverEmail ? 'manual' : 'resolved',
                    'candidate_approvers' => [],
                ]];
            }

            foreach ($submissionApprovalStages as $idx => $stage) {
                $submissionApprovalStages[$idx]['selection_mode'] = trim((string)($stage['selection_mode'] ?? ''));
                $submissionApprovalStages[$idx]['candidate_approvers'] = is_array($stage['candidate_approvers'] ?? null)
                    ? array_values($stage['candidate_approvers'])
                    : [];
                $submissionApprovalStages[$idx]['approver'] = '';
                $submissionApprovalStages[$idx]['approver_type'] = '';
                $submissionApprovalStages[$idx]['approver_position'] = null;
                $submissionApprovalStages[$idx]['approved_by_user_id'] = 0;
                $submissionApprovalStages[$idx]['approved_at'] = '';
                $submissionApprovalStages[$idx]['forwarded_by_user_id'] = 0;
                $submissionApprovalStages[$idx]['forwarded_at'] = '';
            }

            if ($usesManualApproverEmail) {
                $submissionApprovalStages[0]['approver'] = (string)($payload['approver'] ?? '');
                $submissionApprovalStages[0]['approver_type'] = 'EMAIL';
                $submissionApprovalStages[0]['approver_position'] = null;
            } elseif ($firstStageCandidates !== []) {
                if (count($firstStageCandidates) === 1) {
                    $candidate = $firstStageCandidates[0];
                    $submissionApprovalStages[0]['approver'] = (string)($candidate['value'] ?? '');
                    $submissionApprovalStages[0]['approver_type'] = (string)($candidate['type'] ?? '');
                    $submissionApprovalStages[0]['approver_position'] = $candidate['position'] ?? null;
                }
            } else {
                $submissionApprovalStages[0]['approver'] = (string)($payload['approver'] ?? '');
                $submissionApprovalStages[0]['approver_type'] = (string)($payload['selected_approver_type'] ?? '');
                $submissionApprovalStages[0]['approver_position'] = $payload['selected_approver_position'] ?? null;
            }

            $payload['approval_stages'] = array_values($submissionApprovalStages);
            $payload['approval_current_stage'] = (int)($submissionApprovalStages[0]['stage'] ?? 1);
            $payload['approval_stage_total'] = count($submissionApprovalStages);
            $payload['approval_history'] = [];
        }
        $this->savePayload($db, $applicationId, $actorUserId, $payload);

        // Update runtime steps for checklist
        $cardType = (string)($card['CardType'] ?? ($payload['card_type'] ?? ''));
        $stepStates = $this->evaluateLimitChangeSteps($payload, $cardType);
        foreach ($stepStates as $stepKey => $isComplete) {
            $this->upsertRuntimeStep($db, $applicationId, $stepKey, $isComplete ? 1 : 0, $actorUserId);
        }
        if ($action === 'submit') {
            $this->upsertRuntimeStep($db, $applicationId, 'application_submitted', 1, $actorUserId);
        }

        // Update application status
        if ($action === 'submit') {
            $stmt = $db->prepare("
                UPDATE dbo.tblApplications
                SET LastSavedAt = SYSDATETIME(),
                    Status = 'ToBeApproved',
                    CurrentStepKey = 'tobeapproved',
                    Locked = 0,
                    SubmittedAt = SYSDATETIME()
                WHERE ApplicationID = :aid
            ");
            $stmt->execute(['aid' => $applicationId]);

            if (!$this->deferLimitChangeCapsExportUntilApproval((int)($app['ApplicationTypeID'] ?? 0))) {
                try {
                    $this->exportLimitChangeApplicationToCaps($app, $payload, $card, $requestorUserId);
                } catch (\Throwable $e) {
                    error_log('[CardsController::limitChangeSave caps] ' . $e->getMessage());
                    SessionHelper::set('flash.message', [
                        'type' => 'danger',
                        'text' => 'Limit change could not be exported to CAPS: ' . $e->getMessage(),
                    ]);
                    $redir = 'index.php?route=cards/request-limit-change&type=' . urlencode($typeKey) . '&id=' . urlencode((string)$cardId) . '&application_id=' . urlencode((string)$applicationId);
                    if ($isAdminOverride) {
                        $redir .= '&admin=1';
                    }
                    if ($onBehalfFlag && $employeeId !== '') {
                        $redir .= '&on_behalf=1&ob_employee_id=' . urlencode($employeeId);
                    }
                    header('Location: ' . $redir);
                    exit;
                }
            }

            try {
                $employeeType = $this->resolveApprovalEmployeeType($requestorUserId, $employeeId, $payload, $card);
                $employeeGroupRouting = $this->resolveEmployeeGroup($requestorUserId, $employeeId, $payload, $card);
                $employeeGroupDisplay = $this->resolveEmployeeGroupDisplay($requestorUserId, $employeeId, $payload, $card);
                $employeeGroup = $employeeGroupDisplay !== '' ? $employeeGroupDisplay : $employeeGroupRouting;
                $payload['employee_type'] = $employeeType;
                $payload['employee_group'] = $employeeGroup;
                $payload['employee_group_routing'] = $employeeGroupRouting;
                $this->sendLimitChangeApprovalEmail(
                    $requestorUserId,
                    $applicationId,
                    $payload,
                    $employeeGroup
                );
            } catch (\Throwable $e) {
                error_log('[CardsController::limitChangeSave mail] ' . $e->getMessage());
            }

            try {
                $this->sendLimitChangeSubmittedConfirmation($requestorUserId, $applicationId, $payload, $card);
            } catch (\Throwable $e) {
                error_log('[CardsController::limitChangeSave applicantMail] ' . $e->getMessage());
            }

            try {
                $this->sendOnBehalfOwnerSubmittedEmail(
                    $requestorUserId,
                    $applicationId,
                    $payload,
                    $card
                );
            } catch (\Throwable $e) {
                error_log('[CardsController::limitChangeSave onBehalfNotify] ' . $e->getMessage());
            }
        } else {
            $stmt = $db->prepare("
                UPDATE dbo.tblApplications
                SET LastSavedAt = SYSDATETIME(),
                    Status = CASE WHEN Status = 'Draft' THEN 'InProgress' ELSE Status END,
                    CurrentStepKey = CASE WHEN Status = 'Draft' THEN 'inprogress' ELSE CurrentStepKey END,
                    Locked = 0
                WHERE ApplicationID = :aid
            ");
            $stmt->execute(['aid' => $applicationId]);
        }

        if ($action === 'submit') {
            // Prevent stale modal errors from prior on-behalf lookup attempts.
            SessionHelper::forget('onBehalf.error');
            SessionHelper::forget('onBehalf.old');
        }

        $flashType = 'success';
        $flashText = ($action === 'submit') ? 'Limit change submitted.' : 'Limit change saved.';
        SessionHelper::set('flash.message', [
            'type' => $flashType,
            'text' => $flashText,
        ]);
        $this->auditLog(
            $action === 'submit' ? 'SUBMIT' : 'SAVE_DRAFT',
            'LimitChangeApplication',
            (string)$applicationId,
            [
                'route' => 'cards/limit-change-save',
                'card_id' => $cardId,
                'type_key' => $typeKey,
            ]
        );
        if ($onBehalfFlag) {
            $this->auditLog(
                $action === 'submit' ? 'SUBMIT_ON_BEHALF' : 'SAVE_DRAFT_ON_BEHALF',
                'LimitChangeOnBehalf',
                (string)$applicationId,
                [
                    'route' => 'cards/limit-change-save',
                    'application_id' => $applicationId,
                    'card_id' => $cardId,
                    'type_key' => $typeKey,
                    'target_employee_id' => $employeeId,
                    'requestor_user_id' => $requestorUserId,
                    'requestor_display' => $this->loadUserDisplayName($requestorUserId),
                    'card_owner_employee_id' => trim((string)($card['EmployeeID'] ?? '')),
                    'card_type' => trim((string)($card['CardTypeSub'] ?? ($card['CardType'] ?? ''))),
                    'card_last4' => substr((string)(preg_replace('/\D+/', '', (string)($card['CardNumber'] ?? '')) ?? ''), -4),
                ]
            );
        }

        $redir = 'index.php?route=cards/request-limit-change&type=' . urlencode($typeKey) . '&id=' . urlencode((string)$cardId) . '&application_id=' . urlencode((string)$applicationId);
        if ($isAdminOverride) {
            $redir .= '&admin=1';
        }
        if ($onBehalfFlag && $employeeId !== '') {
            $redir .= '&on_behalf=1&ob_employee_id=' . urlencode($employeeId);
        }
        header('Location: ' . $redir);
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

        $stmt = $this->db->prepare("
            SELECT
                a.ApplicationID,
                a.UserID,
                a.EmployeeID,
                a.ApplicationTypeID,
                a.Status,
                at.ApplicationTypeKey
            FROM dbo.tblApplications a
            LEFT JOIN dbo.tblApplicationTypes at
              ON at.ApplicationTypeID = a.ApplicationTypeID
            WHERE a.ApplicationID = :aid
        ");
        $stmt->execute(['aid' => $applicationId]);
        $app = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$app) {
            throw new \RuntimeException('Application not found.');
        }

        $applicationTypeKey = strtolower(trim((string)($app['ApplicationTypeKey'] ?? '')));
        if (!str_contains($applicationTypeKey, 'limit_change')) {
            throw new \RuntimeException('This application is not a limit change workflow.');
        }

        $payload = $this->loadPayload($this->db, $applicationId);
        if ($payload === []) {
            throw new \RuntimeException('No application payload was found to rebuild.');
        }

        $cardId = (int)($payload['card_id'] ?? 0);
        $card = null;
        if ($cardId > 0) {
            $stCard = $this->db->prepare("SELECT * FROM dbo.tblPORTALCards WHERE CardID = :cid");
            $stCard->execute(['cid' => $cardId]);
            $card = $stCard->fetch(\PDO::FETCH_ASSOC) ?: null;
        }

        $userId = (int)($app['UserID'] ?? 0);
        $employeeId = trim((string)($app['EmployeeID'] ?? ($payload['target_employee_id'] ?? '')));
        if ($employeeId === '' && is_array($card)) {
            $employeeId = trim((string)($card['EmployeeID'] ?? ''));
        }

        $typeKey = (string)($payload['type_key'] ?? $applicationTypeKey);
        $isCreditOnlyLimitChange = $this->isCreditOnlyLimitChangeType(
            $typeKey,
            (string)($card['CardType'] ?? ($payload['card_type'] ?? '')),
            (string)($card['CardTypeSub'] ?? ($payload['card_type_sub'] ?? ''))
        );

        $payload['employee_type'] = $this->resolveApprovalEmployeeType($userId, $employeeId, $payload, $card);
        $employeeGroupRouting = $this->resolveEmployeeGroup($userId, $employeeId, $payload, $card);
        $employeeGroupDisplay = $this->resolveEmployeeGroupDisplay($userId, $employeeId, $payload, $card);
        $employeeGroup = $employeeGroupDisplay !== '' ? $employeeGroupDisplay : $employeeGroupRouting;
        $payload['employee_group'] = $employeeGroup;
        $payload['employee_group_routing'] = $employeeGroupRouting;

        $payload = $this->normalizeLimitChangePeriodPayload($payload, $isCreditOnlyLimitChange);
        $limitChangeScope = strtolower(trim((string)($payload['limit_change_scope'] ?? ($isCreditOnlyLimitChange ? 'credit_only' : 'both'))));
        if (!in_array($limitChangeScope, ['both', 'credit_only', 'transaction_only'], true)) {
            $limitChangeScope = $isCreditOnlyLimitChange ? 'credit_only' : 'both';
        }
        if ($isCreditOnlyLimitChange) {
            $limitChangeScope = 'credit_only';
        }
        $payload['limit_change_scope'] = $limitChangeScope;

        $transactionLimitOptions = $this->loadCapsTransactionLimitOptions($card, $payload);
        $payload = $this->hydrateTransactionLimitPayload($payload, $transactionLimitOptions);

        $creditAmountForApprover = $this->parseMoney(trim((string)($payload['credit_limit_new'] ?? '')));
        $txnSelection = $this->resolveTransactionLimitSelection(trim((string)($payload['transaction_limit_new'] ?? '')), $transactionLimitOptions);
        $txnAmountForApprover = $txnSelection !== null
            ? (float)($txnSelection['trans_limit'] ?? 0)
            : $this->parseMoney((string)($payload['transaction_limit_new_amount'] ?? ''));
        $approverAmount = $limitChangeScope === 'transaction_only'
            ? $txnAmountForApprover
            : $creditAmountForApprover;
        if ($approverAmount === null || $approverAmount <= 0) {
            throw new \RuntimeException('The application does not contain a valid limit amount for workflow routing.');
        }

        $rules = $this->loadApprovalRules((int)($app['ApplicationTypeID'] ?? 0), $employeeGroup);
        $directory = $this->loadApproverDirectory($employeeGroup);
        $submissionApprovalStages = $this->hydrateApprovalStagesWithCandidates(
            $this->buildApprovalStageDefinitions($rules, $approverAmount),
            $directory
        );
        $submissionApprovalStages = $this->filterApplicantFromApprovalStages(
            $userId,
            $employeeId,
            $payload,
            $card,
            $employeeGroup,
            $submissionApprovalStages
        );
        foreach ($submissionApprovalStages as $idx => $stage) {
            if (!is_array($stage)) {
                continue;
            }
            $selectionMode = trim((string)($stage['selection_mode'] ?? ''));
            if ($selectionMode !== 'resolved') {
                continue;
            }
            $submissionApprovalStages[$idx]['candidate_approvers'] = $this->filterResolvableApproverOptions(
                is_array($stage['candidate_approvers'] ?? null) ? $stage['candidate_approvers'] : [],
                $employeeGroup
            );
        }
        if ($submissionApprovalStages === []) {
            throw new \RuntimeException('No approval workflow is configured for the selected limit.');
        }

        $firstStageSelectionMode = trim((string)($submissionApprovalStages[0]['selection_mode'] ?? ''));
        $firstStageCandidates = is_array($submissionApprovalStages[0]['candidate_approvers'] ?? null)
            ? array_values($submissionApprovalStages[0]['candidate_approvers'])
            : [];
        $submissionApprovalStages[0]['candidate_approvers'] = $firstStageCandidates;
        $usesManualApproverEmail = $firstStageSelectionMode === 'manual';

        if ($usesManualApproverEmail) {
            $approverEmail = $this->normalizeComparableEmailValue(
                $payload['approver_email'] ?? $this->extractApproverEmailFromSelection((string)($payload['approver'] ?? ''))
            );
            if ($approverEmail === null) {
                throw new \RuntimeException('This workflow stage requires a manual approver email, but none is stored on the application.');
            }
            $payload['approver_email'] = $approverEmail;
            $payload['approver'] = $this->buildManualApproverSelection($approverEmail);
            if (!$this->isEmailInSesApproverView($approverEmail, $employeeGroup)) {
                $payload['approver_email_warning'] = 'The nominated approver must be a SES Band 1 / 1 Star or above. Defence HR records indicate this person does not meet this level.' . "\n\n" . 'By continuing, you are confirming that the approver meets this requirement in line with Policy.';
            } else {
                unset($payload['approver_email_warning']);
            }
        } else {
            unset($payload['approver_email'], $payload['approver_email_warning']);
            if ($firstStageCandidates === []) {
                throw new \RuntimeException('No valid approvers were found for the first approval stage.');
            }
            if (count($firstStageCandidates) === 1) {
                $candidate = $firstStageCandidates[0];
                $payload['approver'] = (string)($candidate['value'] ?? '');
                $payload['selected_approver_type'] = (string)($candidate['type'] ?? '');
                $payload['selected_approver_position'] = $candidate['position'] ?? null;
            } else {
                $payload['approver'] = '';
                $payload['selected_approver_type'] = '';
                $payload['selected_approver_position'] = null;
            }
        }

        foreach ($submissionApprovalStages as $stage) {
            if (!is_array($stage)) {
                continue;
            }
            $stageNumber = max(1, (int)($stage['stage'] ?? 1));
            $stageSelectionMode = trim((string)($stage['selection_mode'] ?? ''));
            $stageCandidates = is_array($stage['candidate_approvers'] ?? null)
                ? array_values($stage['candidate_approvers'])
                : [];
            if ($stageSelectionMode === 'manual' && $stageNumber > 1) {
                throw new \RuntimeException('Approval stage ' . $stageNumber . ' requires a manual approver and cannot be resolved automatically.');
            }
            if ($stageSelectionMode !== 'manual' && $stageCandidates === []) {
                throw new \RuntimeException('No valid approvers were found for approval stage ' . $stageNumber . '.');
            }
        }

        $parsed = $this->parseApproverSelection((string)($payload['approver'] ?? ''));
        $payload['selected_approver_type'] = $parsed['type'] ?? '';
        $payload['selected_approver_position'] = $parsed['position'] ?? null;

        foreach ($submissionApprovalStages as $idx => $stage) {
            $submissionApprovalStages[$idx]['selection_mode'] = trim((string)($stage['selection_mode'] ?? ''));
            $submissionApprovalStages[$idx]['candidate_approvers'] = is_array($stage['candidate_approvers'] ?? null)
                ? array_values($stage['candidate_approvers'])
                : [];
            $submissionApprovalStages[$idx]['approver'] = '';
            $submissionApprovalStages[$idx]['approver_type'] = '';
            $submissionApprovalStages[$idx]['approver_position'] = null;
            $submissionApprovalStages[$idx]['approved_by_user_id'] = 0;
            $submissionApprovalStages[$idx]['approved_at'] = '';
            $submissionApprovalStages[$idx]['forwarded_by_user_id'] = 0;
            $submissionApprovalStages[$idx]['forwarded_at'] = '';
        }

        if ($usesManualApproverEmail) {
            $submissionApprovalStages[0]['approver'] = (string)($payload['approver'] ?? '');
            $submissionApprovalStages[0]['approver_type'] = 'EMAIL';
            $submissionApprovalStages[0]['approver_position'] = null;
        } elseif ($firstStageCandidates !== [] && count($firstStageCandidates) === 1) {
            $candidate = $firstStageCandidates[0];
            $submissionApprovalStages[0]['approver'] = (string)($candidate['value'] ?? '');
            $submissionApprovalStages[0]['approver_type'] = (string)($candidate['type'] ?? '');
            $submissionApprovalStages[0]['approver_position'] = $candidate['position'] ?? null;
        } else {
            $submissionApprovalStages[0]['approver'] = (string)($payload['approver'] ?? '');
            $submissionApprovalStages[0]['approver_type'] = (string)($payload['selected_approver_type'] ?? '');
            $submissionApprovalStages[0]['approver_position'] = $payload['selected_approver_position'] ?? null;
        }

        $payload['approval_stages'] = array_values($submissionApprovalStages);
        $payload['approval_current_stage'] = (int)($submissionApprovalStages[0]['stage'] ?? 1);
        $payload['approval_stage_total'] = count($submissionApprovalStages);
        $payload['approval_history'] = [];
        unset(
            $payload['approved_by_user_id'],
            $payload['approved_at'],
            $payload['rejected_by_user_id'],
            $payload['rejected_at'],
            $payload['reject_reason'],
            $payload['forward_to']
        );

        $this->savePayload($this->db, $applicationId, $adminUserId, $payload);
        $this->syncLimitChangeApprovalState($applicationId, $adminUserId, 'ToBeApproved');
        $submittedStmt = $this->db->prepare("
            UPDATE dbo.tblApplications
            SET SubmittedAt = SYSDATETIME()
            WHERE ApplicationID = :aid
        ");
        $submittedStmt->execute(['aid' => $applicationId]);

        $recipients = $this->resolveLimitChangeApprovalRecipients($payload, $employeeGroup);
        $this->sendLimitChangeApprovalEmail($userId, $applicationId, $payload, $employeeGroup);

        return [
            'application_type_key' => $applicationTypeKey,
            'status_after' => 'ToBeApproved',
            'recipient_emails' => array_values(array_filter(array_map(
                static fn(array $recipient): string => trim((string)($recipient['email'] ?? '')),
                $recipients
            ), static fn(string $email): bool => $email !== '')),
        ];
    }

    /**
     * Delete a draft/in-progress limit change application.
     * POST: index.php?route=cards/limit-change-delete
     */
    public function limitChangeDelete(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        require_once __DIR__ . '/../../shared/csrf.php';
        if (function_exists('csrf_check') && !csrf_check($_POST['_csrf'] ?? '')) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Security check failed. Please try again.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $db = $this->db;
        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $applicationId = (int)($_POST['application_id'] ?? 0);
        $typeKey = trim((string)($_POST['type_key'] ?? ''));
        $cardId = (int)($_POST['card_id'] ?? 0);
        $rbac = new Rbac($db);
        $isAdminDelete = $rbac->canAny(['ADMIN_ALL', 'SYSADMIN']) && (($_POST['admin_delete'] ?? '') === '1');
        if ($applicationId <= 0) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Missing application id.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $app = $isAdminDelete ? $this->loadAnyApplicationById($db, $applicationId) : $this->loadApplicationById($db, $applicationId, $userId);
        if (!$app) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Application not found.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $status = strtolower(trim((string)($app['Status'] ?? '')));
        if (!$isAdminDelete && !in_array($status, ['draft', 'inprogress'], true)) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Only draft or in-progress applications can be deleted.']);
            header('Location: index.php?route=cards/request-limit-change&type=' . urlencode($typeKey) . '&id=' . urlencode((string)$cardId));
            exit;
        }

        $db->beginTransaction();
        try {
            $this->deleteCapsLimitDetailsPortalByPortalId($applicationId);

            $delSteps = $db->prepare("DELETE FROM dbo.tblApplicationSteps WHERE ApplicationID = :aid");
            $delSteps->execute(['aid' => $applicationId]);

            if ($isAdminDelete) {
                $delApp = $db->prepare("DELETE FROM dbo.tblApplications WHERE ApplicationID = :aid");
                $delApp->execute(['aid' => $applicationId]);
            } else {
                $delApp = $db->prepare("DELETE FROM dbo.tblApplications WHERE ApplicationID = :aid AND UserID = :uid");
                $delApp->execute(['aid' => $applicationId, 'uid' => $userId]);
            }

            $db->commit();
            SessionHelper::set('flash.message', ['type' => 'success', 'text' => 'Limit change application deleted.']);
            $this->auditLog(
                'DELETE',
                'LimitChangeApplication',
                (string)$applicationId,
                [
                    'route' => 'cards/limit-change-delete',
                    'card_id' => $cardId,
                    'type_key' => $typeKey,
                    'admin_delete' => $isAdminDelete ? 1 : 0,
                ]
            );
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[CardsController::limitChangeDelete] ' . $e->getMessage());
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Unable to delete application.']);
        }

        if ($isAdminDelete) {
            header('Location: index.php?route=cards/limit-change-approvals');
            exit;
        }

        header('Location: index.php?route=home/index');
        exit;
    }

    /**
     * Approver review screen for limit change requests.
     * GET: index.php?route=cards/limit-change-approve&id=123
     */
    public function limitChangeApprove(): void
    {
        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            $this->rememberLimitChangeApprovalRoute((int)($_GET['id'] ?? 0));
            SessionHelper::set('flash.message', [
                'type' => 'info',
                'text' => 'Please sign in or activate your account to review this approval request.',
            ]);
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $applicationId = (int)($_GET['id'] ?? 0);
        if ($applicationId <= 0) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Missing application id.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $stmt = $this->db->prepare("
            SELECT a.*, at.ApplicationTypeKey, u.Username, u.Email
            FROM dbo.tblApplications a
            LEFT JOIN dbo.tblApplicationTypes at ON at.ApplicationTypeID = a.ApplicationTypeID
            LEFT JOIN dbo.tblUsers u ON u.UserID = a.UserID
            WHERE a.ApplicationID = :aid
        ");
        $stmt->execute(['aid' => $applicationId]);
        $app = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$app) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Application not found.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $applicationOwnerUserId = (int)($app['UserID'] ?? 0);
        if ($applicationOwnerUserId > 0 && $applicationOwnerUserId !== $userId) {
            $this->auditLog(
                'VIEW',
                'Application',
                (string)$applicationId,
                [
                    'route' => 'cards/limit-change-approve',
                    'owner_user_id' => $applicationOwnerUserId,
                    'application_type_key' => (string)($app['ApplicationTypeKey'] ?? ''),
                ]
            );
        }

        $payload = $this->loadPayload($this->db, $applicationId);
        $cardId = (int)($payload['card_id'] ?? 0);
        $card = null;
        if ($cardId > 0) {
            $stCard = $this->db->prepare("SELECT * FROM dbo.tblPORTALCards WHERE CardID = :cid");
            $stCard->execute(['cid' => $cardId]);
            $card = $stCard->fetch(\PDO::FETCH_ASSOC) ?: null;
        }

        $progress = $this->buildLimitChangeProgress((string)($app['ApplicationTypeKey'] ?? ''), $applicationId);
        $employeeGroup = trim((string)($payload['employee_group'] ?? ''));
        if ($employeeGroup === '') {
            $employeeGroupDisplay = $this->resolveEmployeeGroupDisplay((int)($app['UserID'] ?? 0), (string)($app['EmployeeID'] ?? ''), $payload, $card);
            $employeeGroupRouting = $this->resolveEmployeeGroup((int)($app['UserID'] ?? 0), (string)($app['EmployeeID'] ?? ''), $payload, $card);
            $employeeGroup = $employeeGroupDisplay !== '' ? $employeeGroupDisplay : $employeeGroupRouting;
        }
        $approverDisplay = $this->resolveLimitChangeCurrentApproverDisplay($payload, $employeeGroup);
        $isSelfRequest = $this->isLimitChangeSelfRequest($userId, $app, $payload, $card);
        $canApproveAction = !$isSelfRequest && $this->isCurrentUserAssignedApprover($userId, $payload, $employeeGroup);
        $decisionErrors = SessionHelper::get('limitChange.approval_errors.' . $applicationId);
        SessionHelper::forget('limitChange.approval_errors.' . $applicationId);
        $decisionOld = SessionHelper::get('limitChange.approval_old.' . $applicationId);
        SessionHelper::forget('limitChange.approval_old.' . $applicationId);
        if (!is_array($decisionErrors)) {
            $decisionErrors = [];
        }
        if (is_array($decisionOld) && $decisionOld) {
            $payload = array_merge($payload, $decisionOld);
        }
        $payload = $this->normalizeLimitChangePeriodPayload(
            $payload,
            $this->isDtcCardType((string)($card['CardType'] ?? ($payload['card_type'] ?? '')))
        );
        $storedStages = $this->normalizeStoredApprovalStages($payload);
        $currentApprovalStage = $this->resolveCurrentApprovalStageNumber($payload, $storedStages);
        $totalApprovalStages = count($storedStages) > 0 ? count($storedStages) : max(1, (int)($payload['approval_stage_total'] ?? 1));
        $previousApprovals = $this->buildLimitChangeApprovalHistoryDisplay($payload, $employeeGroup);
        $payload = $this->hydrateTransactionLimitPayload($payload, $this->loadCapsTransactionLimitOptions($card, $payload));
        $selectedApproverEmail = $this->normalizeComparableEmailValue(
            ($payload['approver_email'] ?? '') !== ''
                ? $payload['approver_email']
                : $this->extractApproverEmailFromSelection((string)($payload['approver'] ?? ''))
        );
        $approvalRequiresSesConfirmation = $this->shouldRequireLimitChangeSesConfirmation($payload);
        $manualApproverNeedsConfirmation = $selectedApproverEmail !== null
            && $selectedApproverEmail !== ''
            && $approvalRequiresSesConfirmation
            && !$this->isEmailInSesApproverView($selectedApproverEmail, $employeeGroup);
        if ($manualApproverNeedsConfirmation) {
            $payload['approver_email_warning'] = 'The nominated approver must be a SES Band 1 / 1 Star or above. Defence HR records indicate this person does not meet this level.' . "\n\n" . 'By continuing, you are confirming that the approver meets this requirement in line with Policy.';
        }
        $forwardOptions = $this->buildForwardApproverOptions(
            (int)($app['ApplicationTypeID'] ?? 0),
            $payload,
            $employeeGroup
        );

        $this->render('cards/LimitChangeApprove', [
            'title' => 'Limit Change Approval',
            'application' => $app,
            'card' => $card,
            'data' => $payload,
            'approverDisplay' => $approverDisplay,
            'canApproveAction' => $canApproveAction,
            'isSelfRequest' => $isSelfRequest,
            'progress' => $progress,
            'decisionErrors' => $decisionErrors,
            'approvalRequiresSesConfirmation' => $approvalRequiresSesConfirmation,
            'manualApproverNeedsConfirmation' => $manualApproverNeedsConfirmation,
            'forwardOptions' => $forwardOptions,
            'currentApprovalStage' => $currentApprovalStage,
            'totalApprovalStages' => $totalApprovalStages,
            'previousApprovals' => $previousApprovals,
        ]);
    }

    /**
     * Save approver decision for a limit change request.
     * POST: index.php?route=cards/limit-change-approve-save
     */
    public function limitChangeApproveSave(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        require_once __DIR__ . '/../../shared/csrf.php';
        if (function_exists('csrf_check') && !csrf_check($_POST['_csrf'] ?? '')) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Security check failed. Please try again.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $applicationId = (int)($_POST['application_id'] ?? 0);
        $decision = strtolower(trim((string)($_POST['decision'] ?? '')));
        $rejectReason = trim((string)($_POST['reject_reason'] ?? ''));
        $forwardTo = trim((string)($_POST['forward_to'] ?? ''));
        $manualApproverConfirmed = ((string)($_POST['manual_approver_confirmed'] ?? '') === '1');

        if ($applicationId <= 0) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Missing application id.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $stmt = $this->db->prepare("SELECT ApplicationID FROM dbo.tblApplications WHERE ApplicationID = :aid");
        $stmt->execute(['aid' => $applicationId]);
        if (!$stmt->fetchColumn()) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Application not found.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $stStatus = $this->db->prepare("
            SELECT Status
            FROM dbo.tblApplications
            WHERE ApplicationID = :aid
        ");
        $stStatus->execute(['aid' => $applicationId]);
        $currentStatus = strtolower(trim((string)($stStatus->fetchColumn() ?? '')));
        if (in_array($currentStatus, ['approved', 'rejected', 'senttobank', 'sent_to_bank', 'limitchanged', 'limit_changed'], true)) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'This application is already finalised and cannot be actioned.']);
            header('Location: index.php?route=cards/limit-change-approve&id=' . urlencode((string)$applicationId));
            exit;
        }

        $payload = $this->loadPayload($this->db, $applicationId);
        $stApp = $this->db->prepare("SELECT ApplicationID, UserID, EmployeeID, ApplicationTypeID FROM dbo.tblApplications WHERE ApplicationID = :aid");
        $stApp->execute(['aid' => $applicationId]);
        $app = $stApp->fetch(\PDO::FETCH_ASSOC) ?: [];
        if ($this->isLimitChangeSelfRequest($userId, $app, $payload, null)) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'You cannot action your own limit change request.']);
            header('Location: index.php?route=cards/limit-change-approve&id=' . urlencode((string)$applicationId));
            exit;
        }
        $employeeGroup = trim((string)($payload['employee_group'] ?? ''));
        if ($employeeGroup === '') {
            $employeeGroupDisplay = $this->resolveEmployeeGroupDisplay((int)($app['UserID'] ?? 0), (string)($app['EmployeeID'] ?? ''), $payload, null);
            $employeeGroupRouting = $this->resolveEmployeeGroup((int)($app['UserID'] ?? 0), (string)($app['EmployeeID'] ?? ''), $payload, null);
            $employeeGroup = $employeeGroupDisplay !== '' ? $employeeGroupDisplay : $employeeGroupRouting;
        }
        if (!$this->isCurrentUserAssignedApprover($userId, $payload, $employeeGroup)) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'You are not the assigned approver for this request.']);
            header('Location: index.php?route=cards/limit-change-approve&id=' . urlencode((string)$applicationId));
            exit;
        }
        $forwardOptions = $this->buildForwardApproverOptions(
            (int)($app['ApplicationTypeID'] ?? 0),
            $payload,
            $employeeGroup
        );
        $allowedForwardValues = array_map(
            static fn(array $row): string => (string)($row['value'] ?? ''),
            $forwardOptions
        );
        $selectedApproverEmail = $this->normalizeComparableEmailValue(
            ($payload['approver_email'] ?? '') !== ''
                ? $payload['approver_email']
                : $this->extractApproverEmailFromSelection((string)($payload['approver'] ?? ''))
        );
        $approvalRequiresSesConfirmation = $this->shouldRequireLimitChangeSesConfirmation($payload);
        $manualApproverNeedsConfirmation = $selectedApproverEmail !== null
            && $selectedApproverEmail !== ''
            && $approvalRequiresSesConfirmation
            && !$this->isEmailInSesApproverView($selectedApproverEmail, $employeeGroup);
        $storedStages = $this->normalizeStoredApprovalStages($payload);
        $currentStageNumber = $this->resolveCurrentApprovalStageNumber($payload, $storedStages);
        $currentStage = $this->findStoredApprovalStage($storedStages, $currentStageNumber);
        $nextStage = null;
        foreach ($storedStages as $stage) {
            if ((int)($stage['stage'] ?? 0) > $currentStageNumber) {
                $nextStage = $stage;
                break;
            }
        }
        $nextStageOptions = [];
        if ($nextStage !== null) {
            $nextStageOptions = is_array($nextStage['candidate_approvers'] ?? null)
                ? array_values(array_filter($nextStage['candidate_approvers'], static fn($row): bool => is_array($row)))
                : [];
            if ($nextStageOptions === [] && trim((string)($nextStage['selection_mode'] ?? '')) !== 'manual') {
                $nextStageOptions = $this->buildApproverOptionsForRules(
                    (array)($nextStage['rules'] ?? []),
                    $this->loadApproverDirectory($employeeGroup),
                    true
                );
            }
        }

        $errors = [];
        if (!in_array($decision, ['approve', 'reject', 'forward'], true)) {
            $errors['decision'] = 'Invalid decision.';
        }
        if ($decision === 'reject' && $rejectReason === '') {
            $errors['reject_reason'] = 'Rejection reason is required.';
        }
        if ($decision === 'forward') {
            if (!$allowedForwardValues) {
                $errors['forward_to'] = 'No valid forward approvers are available for this request.';
            } elseif ($forwardTo === '') {
                $errors['forward_to'] = 'Forward target is required.';
            } elseif (!in_array($forwardTo, $allowedForwardValues, true)) {
                $errors['forward_to'] = 'Forward target must be a valid approver option.';
            }
        }
        if ($decision === 'approve' && $approvalRequiresSesConfirmation && !$manualApproverConfirmed) {
            $errors['manual_approver_confirmed'] = 'Confirmation is required before approval can proceed.';
        }
        if ($decision === 'approve' && $nextStage !== null && $nextStageOptions === []) {
            $errors['forward_to'] = 'No valid approvers are available for the next approval stage.';
        }

        if ($errors) {
            SessionHelper::set('limitChange.approval_errors.' . $applicationId, $errors);
            SessionHelper::set('limitChange.approval_old.' . $applicationId, [
                'forward_to' => $forwardTo,
                'manual_approver_confirmed' => $manualApproverConfirmed ? '1' : '',
            ]);
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Please fix validation errors.']);
            header('Location: index.php?route=cards/limit-change-approve&id=' . urlencode((string)$applicationId));
            exit;
        }

        $cardId = (int)($payload['card_id'] ?? 0);
        $card = $cardId > 0 ? $this->loadPortalCardById($cardId) : null;

        $newStatus = 'ToBeApproved';
        $successMessage = 'Decision saved.';
        $approvalAdvancedToNextStage = false;
        if ($decision === 'approve') {
            $approvedAt = gmdate('Y-m-d H:i:s');
            if ($currentStage !== null) {
                $currentStage['approved_by_user_id'] = $userId;
                $currentStage['approved_at'] = $approvedAt;
                $storedStages = $this->updateStoredApprovalStage($storedStages, $currentStage);
            }

            $this->appendLimitChangeApprovalHistory($payload, [
                'stage' => $currentStageNumber,
                'decision' => 'approve',
                'acted_by_user_id' => $userId,
                'acted_at' => $approvedAt,
                'approver' => (string)($payload['approver'] ?? ''),
            ]);

            if ($nextStage !== null) {
                $nextStageCandidates = is_array($nextStage['candidate_approvers'] ?? null)
                    ? array_values(array_filter($nextStage['candidate_approvers'], static fn($row): bool => is_array($row)))
                    : array_values(array_filter($nextStageOptions, static fn($row): bool => is_array($row)));
                if (trim((string)($nextStage['selection_mode'] ?? '')) !== 'manual' && $nextStageCandidates === []) {
                    SessionHelper::set('flash.message', [
                        'type' => 'danger',
                        'text' => 'No valid approver could be resolved for the next approval stage.',
                    ]);
                    header('Location: index.php?route=cards/limit-change-approve&id=' . urlencode((string)$applicationId));
                    exit;
                }
                $nextApprover = '';
                $parsedNext = ['type' => '', 'position' => null];
                if (count($nextStageCandidates) === 1) {
                    $nextOption = $nextStageCandidates[0];
                    $nextApprover = trim((string)($nextOption['value'] ?? ''));
                    $parsedNext = $this->parseApproverSelection($nextApprover);
                }
                $nextStage['candidate_approvers'] = $nextStageCandidates;
                $nextStage['approver'] = $nextApprover;
                $nextStage['approver_type'] = $parsedNext['type'] ?? '';
                $nextStage['approver_position'] = $parsedNext['position'] ?? null;
                $nextStage['forwarded_by_user_id'] = $userId;
                $nextStage['forwarded_at'] = $approvedAt;
                $storedStages = $this->updateStoredApprovalStage($storedStages, $nextStage);

                $payload['approval_stages'] = $storedStages;
                $payload['approval_current_stage'] = (int)($nextStage['stage'] ?? ($currentStageNumber + 1));
                $payload['approval_stage_total'] = count($storedStages);
                $payload['approver'] = $nextApprover;
                $payload['selected_approver_type'] = $parsedNext['type'] ?? '';
                $payload['selected_approver_position'] = $parsedNext['position'] ?? null;
                $payload['forwarded_by_user_id'] = $userId;
                $payload['forwarded_at'] = $approvedAt;
                $payload['forward_to'] = $nextApprover;
                unset(
                    $payload['approved_by_user_id'],
                    $payload['approved_at'],
                    $payload['rejected_by_user_id'],
                    $payload['rejected_at'],
                    $payload['reject_reason'],
                    $payload['caps_application_id'],
                    $payload['caps_limit_change_output_id'],
                    $payload['caps_writes_skipped']
                );

                $newStatus = 'ToBeApproved';
                $approvalAdvancedToNextStage = true;
                $successMessage = 'Approval recorded and routed to the next approval stage.';
            } else {
                $capsWritesEnabled = $this->isCapsWriteEnabled();
                $capsApplicationId = 0;
                $limitChangeAppOutputId = 0;

                if ($capsWritesEnabled) {
                    $capsApplicationId = $this->exportLimitChangeApplicationToCaps($app, $payload, $card, $userId);
                    if ($capsApplicationId <= 0) {
                        SessionHelper::set('flash.message', [
                            'type' => 'danger',
                            'text' => 'Approved application could not be inserted into CAPS tblCAPSApplication.',
                        ]);
                        header('Location: index.php?route=cards/limit-change-approve&id=' . urlencode((string)$applicationId));
                        exit;
                    }

                    $limitChangeAppOutputId = $this->runCapsLimitChangeApprovalInsert($capsApplicationId, $userId);
                    if ($limitChangeAppOutputId < 0) {
                        SessionHelper::set('flash.message', [
                            'type' => 'danger',
                            'text' => 'Approved application could not be sent to CAPS. Stored procedure output: ' . $limitChangeAppOutputId,
                        ]);
                        header('Location: index.php?route=cards/limit-change-approve&id=' . urlencode((string)$applicationId));
                        exit;
                    }
                    if ($limitChangeAppOutputId === 0) {
                        SessionHelper::set('flash.message', [
                            'type' => 'danger',
                            'text' => 'Approved application could not be sent to CAPS because no output value was returned.',
                        ]);
                        header('Location: index.php?route=cards/limit-change-approve&id=' . urlencode((string)$applicationId));
                        exit;
                    }
                }
                $newStatus = 'Approved';
                $payload['approval_stages'] = $storedStages;
                $payload['approval_current_stage'] = $currentStageNumber;
                $payload['approval_stage_total'] = count($storedStages) > 0 ? count($storedStages) : 1;
                $payload['approved_by_user_id'] = $userId;
                $payload['approved_at'] = $approvedAt;
                if ($capsWritesEnabled) {
                    $payload['caps_application_id'] = $capsApplicationId;
                    $payload['caps_limit_change_output_id'] = $limitChangeAppOutputId;
                    unset($payload['caps_writes_skipped']);
                } else {
                    $payload['caps_writes_skipped'] = '1';
                    unset($payload['caps_application_id'], $payload['caps_limit_change_output_id']);
                }
                unset(
                    $payload['rejected_by_user_id'],
                    $payload['rejected_at'],
                    $payload['reject_reason'],
                    $payload['forwarded_by_user_id'],
                    $payload['forwarded_at'],
                    $payload['forward_to']
                );
            }
        } elseif ($decision === 'reject') {
            $newStatus = 'Rejected';
            $payload['rejected_by_user_id'] = $userId;
            $payload['rejected_at'] = gmdate('Y-m-d H:i:s');
            $payload['reject_reason'] = $rejectReason;
            $payload['approval_stages'] = $storedStages;
            $payload['approval_current_stage'] = $currentStageNumber;
            $payload['approval_stage_total'] = count($storedStages) > 0 ? count($storedStages) : 1;
            $this->appendLimitChangeApprovalHistory($payload, [
                'stage' => $currentStageNumber,
                'decision' => 'reject',
                'acted_by_user_id' => $userId,
                'acted_at' => $payload['rejected_at'],
                'approver' => (string)($payload['approver'] ?? ''),
                'reason' => $rejectReason,
            ]);
            unset(
                $payload['approved_by_user_id'],
                $payload['approved_at'],
                $payload['forwarded_by_user_id'],
                $payload['forwarded_at'],
                $payload['forward_to']
            );
        } else {
            $newStatus = 'ToBeApproved';
            $payload['forwarded_by_user_id'] = $userId;
            $payload['forwarded_at'] = gmdate('Y-m-d H:i:s');
            $payload['forward_to'] = $forwardTo;
            $payload['approver'] = $forwardTo;
            $parsedForward = $this->parseApproverSelection($forwardTo);
            $payload['selected_approver_type'] = $parsedForward['type'] ?? '';
            $payload['selected_approver_position'] = $parsedForward['position'] ?? null;
            if ($currentStage !== null) {
                $currentStage['approver'] = $forwardTo;
                $currentStage['approver_type'] = $parsedForward['type'] ?? '';
                $currentStage['approver_position'] = $parsedForward['position'] ?? null;
                $currentStage['forwarded_by_user_id'] = $userId;
                $currentStage['forwarded_at'] = (string)$payload['forwarded_at'];
                $storedStages = $this->updateStoredApprovalStage($storedStages, $currentStage);
            }
            $payload['approval_stages'] = $storedStages;
            $payload['approval_current_stage'] = $currentStageNumber;
            $payload['approval_stage_total'] = count($storedStages) > 0 ? count($storedStages) : 1;
            $this->appendLimitChangeApprovalHistory($payload, [
                'stage' => $currentStageNumber,
                'decision' => 'forward',
                'acted_by_user_id' => $userId,
                'acted_at' => $payload['forwarded_at'],
                'approver' => (string)($payload['approver'] ?? ''),
                'forward_to' => $forwardTo,
            ]);
            unset(
                $payload['approved_by_user_id'],
                $payload['approved_at'],
                $payload['rejected_by_user_id'],
                $payload['rejected_at'],
                $payload['reject_reason']
            );
        }
        $this->savePayload($this->db, $applicationId, $userId, $payload);
        $this->syncLimitChangeApprovalState($applicationId, $userId, $newStatus);

        try {
            if ($decision === 'approve' && $approvalAdvancedToNextStage) {
                $this->sendLimitChangeApprovalEmail((int)($app['UserID'] ?? 0), $applicationId, $payload, $employeeGroup);
            } elseif ($decision === 'approve' || $decision === 'reject') {
                $this->sendLimitChangeDecisionEmail($applicationId, $decision, $rejectReason);
            } elseif ($decision === 'forward') {
                $this->sendLimitChangeApprovalEmail((int)($app['UserID'] ?? 0), $applicationId, $payload, $employeeGroup);
            }
        } catch (\Throwable $e) {
            error_log('[CardsController::limitChangeApproveSave notify] ' . $e->getMessage());
        }
        $this->auditLog(
            strtoupper($decision),
            'LimitChangeApproval',
            (string)$applicationId,
            [
                'route' => 'cards/limit-change-approve-save',
                'new_status' => $newStatus,
                'decision' => $decision,
                'stage' => $currentStageNumber,
                'total_stages' => count($storedStages) > 0 ? count($storedStages) : max(1, (int)($payload['approval_stage_total'] ?? 1)),
                'acted_by_user_id' => $userId,
                'acted_by_display' => $this->loadUserDisplayName($userId),
                'current_approver' => (string)($payload['approver'] ?? ''),
                'current_approver_display' => $this->resolveApproverDisplayFromSelection((string)($payload['approver'] ?? ''), $employeeGroup),
                'forward_to' => $forwardTo,
                'forward_to_display' => $forwardTo !== '' ? $this->resolveApproverDisplayFromSelection($forwardTo, $employeeGroup) : '',
                'reject_reason' => $rejectReason,
                'approval_advanced' => $approvalAdvancedToNextStage ? '1' : '0',
            ]
        );

        SessionHelper::set('flash.message', ['type' => 'success', 'text' => $successMessage]);
        header('Location: index.php?route=cards/limit-change-approve&id=' . urlencode((string)$applicationId));
        exit;
    }

    public function limitChangeApproverEmailCheck(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            http_response_code(401);
            echo json_encode([
                'ok' => false,
                'error' => 'Not authenticated.',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }

        $email = $this->normalizeComparableEmailValue($_GET['email'] ?? '');
        $employeeGroup = trim((string)($_GET['employee_group'] ?? ''));
        $matchedEmployeeId = $email !== null ? $this->loadCapsEmployeeIdByEmail($email) : '';

        if ($email === null) {
            echo json_encode([
                'ok' => true,
                'email' => '',
                'exists_in_caps' => false,
                'exists_in_ses_view' => false,
                'matched_employee_id' => '',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }

        echo json_encode([
            'ok' => true,
            'email' => $email,
            'exists_in_caps' => $this->capsCdmcEmailExists($email),
            'exists_in_ses_view' => $this->isEmailInSesApproverView($email, $employeeGroup),
            'matched_employee_id' => $matchedEmployeeId,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function limitChangeScopeSave(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode([
                'ok' => false,
                'error' => 'Method not allowed.',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }

        require_once __DIR__ . '/../../shared/csrf.php';
        if (function_exists('csrf_check') && !csrf_check($_POST['_csrf'] ?? '')) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'error' => 'Security check failed.',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }

        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            http_response_code(401);
            echo json_encode([
                'ok' => false,
                'error' => 'Not authenticated.',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }

        $applicationId = (int)($_POST['application_id'] ?? 0);
        $scope = strtolower(trim((string)($_POST['limit_change_scope'] ?? '')));
        if (!in_array($scope, ['both', 'credit_only', 'transaction_only'], true)) {
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'error' => 'Invalid scope.',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($applicationId <= 0) {
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'error' => 'Application is required.',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }

        $app = $this->loadApplicationById($this->db, $applicationId, $userId);
        if (!$app) {
            http_response_code(404);
            echo json_encode([
                'ok' => false,
                'error' => 'Application not found.',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }

        $applicationTypeId = (int)($app['ApplicationTypeID'] ?? 0);
        $type = $this->loadApplicationTypeMetaById($applicationTypeId);
        $typeKey = strtolower(trim((string)($type['ApplicationTypeKey'] ?? '')));
        if ($typeKey !== 'dpc_limit_change') {
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'error' => 'Scope selection only applies to DPC limit change applications.',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }

        $payload = $this->loadPayload($this->db, $applicationId);
        $payload['limit_change_scope'] = $scope;
        $payload = $this->normalizeLimitChangePeriodPayload(
            $payload,
            $this->isDtcCardType((string)($payload['card_type'] ?? ''))
        );
        $this->savePayload($this->db, $applicationId, $userId, $payload);

        echo json_encode([
            'ok' => true,
            'application_id' => $applicationId,
            'limit_change_scope' => (string)($payload['limit_change_scope'] ?? $scope),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function buildForwardApproverOptions(int $applicationTypeId, array $payload, string $employeeGroup): array
    {
        $storedStages = $this->normalizeStoredApprovalStages($payload);
        if ($storedStages !== []) {
            $currentStageNumber = $this->resolveCurrentApprovalStageNumber($payload, $storedStages);
            $currentStage = $this->findStoredApprovalStage($storedStages, $currentStageNumber);
            $directory = $this->loadApproverDirectory($employeeGroup);
            $options = $currentStage !== null
                ? $this->buildApproverOptionsForRules((array)($currentStage['rules'] ?? []), $directory, true)
                : [];
            $currentApprover = trim((string)($payload['approver'] ?? ''));
            if ($currentApprover === '') {
                return $options;
            }

            return array_values(array_filter($options, static function (array $row) use ($currentApprover): bool {
                return trim((string)($row['value'] ?? '')) !== $currentApprover;
            }));
        }

        $scope = strtolower(trim((string)($payload['limit_change_scope'] ?? 'both')));
        if (!in_array($scope, ['both', 'credit_only', 'transaction_only'], true)) {
            $scope = 'both';
        }
        $amount = $scope === 'transaction_only'
            ? $this->parseMoney($this->resolveTransactionLimitDisplayValue($payload))
            : $this->parseMoney((string)($payload['credit_limit_new'] ?? ''));
        if ($amount === null || $amount <= 0) {
            return [];
        }

        $rules = $this->loadApprovalRules($applicationTypeId, $employeeGroup);
        $directory = $this->loadApproverDirectory($employeeGroup);
        $options = $this->buildApproverOptionsForAmount($rules, $directory, $amount);
        $currentApprover = trim((string)($payload['approver'] ?? ''));

        if ($currentApprover === '') {
            return $options;
        }

        return array_values(array_filter($options, static function (array $row) use ($currentApprover): bool {
            return trim((string)($row['value'] ?? '')) !== $currentApprover;
        }));
    }

    /**
     * Admin screen: list all limit change approvals.
     * GET: index.php?route=cards/limit-change-approvals
     */
    public function limitChangeApprovals(): void
    {
        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }
        if (!Rbac::canAny(['ADMIN_ALL', 'SYSADMIN']) && !Rbac::hasRole('admin')) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Access denied.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
        $allowedStatuses = ['tobeapproved', 'approved', 'rejected', 'senttobank', 'sent_to_bank', 'limitchanged', 'limit_changed', 'draft', 'inprogress'];

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
                u.Username,
                u.Email,
                s.DataJson
            FROM dbo.tblApplications a
            INNER JOIN dbo.tblApplicationTypes at
                ON at.ApplicationTypeID = a.ApplicationTypeID
            LEFT JOIN dbo.tblUsers u
                ON u.UserID = a.UserID
            LEFT JOIN dbo.tblApplicationSteps s
                ON s.ApplicationID = a.ApplicationID
               AND s.StepKey = 'application'
            WHERE LOWER(ISNULL(at.ApplicationTypeKey, '')) LIKE :typeKey
        ";
        $params = ['typeKey' => '%limit_change'];
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
            $payloadRaw = (string)($row['DataJson'] ?? '');
            $payload = json_decode($payloadRaw, true);
            $payload = is_array($payload) ? $payload : [];
            $payload = $this->hydrateTransactionLimitPayload($payload);
            $requestorEmployeeId = trim((string)($row['EmployeeID'] ?? ''));
            $applicantEmployeeId = trim((string)($payload['target_employee_id'] ?? ''));
            if ($applicantEmployeeId === '') {
                $applicantEmployeeId = $requestorEmployeeId;
            }
            $requestorName = trim((string)($row['Username'] ?? ''));
            $requestorEmail = trim((string)($row['Email'] ?? ''));

            $approvalRows[] = [
                'ApplicationID' => (int)($row['ApplicationID'] ?? 0),
                'Status' => (string)($row['Status'] ?? ''),
                'ApplicationTypeName' => (string)($row['ApplicationTypeName'] ?? ''),
                'ApplicationTypeKey' => (string)($row['ApplicationTypeKey'] ?? ''),
                'RequestorUserID' => (int)($row['UserID'] ?? 0),
                'RequestorEmployeeID' => $requestorEmployeeId,
                'RequestorName' => $requestorName,
                'RequestorEmail' => $requestorEmail,
                'ApplicantEmployeeID' => $applicantEmployeeId,
                'ApplicantName' => $this->resolveApplicantDisplayNameForApprovalRow(
                    $requestorName,
                    $requestorEmployeeId,
                    $applicantEmployeeId
                ),
                'ApplicantEmail' => $this->resolveApplicantEmailForApprovalRow(
                    $requestorEmail,
                    $requestorEmployeeId,
                    $applicantEmployeeId
                ),
                'IsOnBehalf' => trim((string)($payload['on_behalf'] ?? '')) === '1',
                'SubmittedAt' => (string)($row['SubmittedAt'] ?? ''),
                'LastSavedAt' => (string)($row['LastSavedAt'] ?? ''),
                'CardID' => (int)($payload['card_id'] ?? 0),
                'CardType' => (string)($payload['card_type_sub'] ?? ($payload['card_type'] ?? '')),
                'CreditLimitNew' => (string)($payload['credit_limit_new'] ?? ''),
                'TransactionLimitNew' => $this->resolveTransactionLimitDisplayValue($payload),
                'SelectedApprover' => (string)($payload['approver'] ?? ''),
                'ApproverType' => (string)($payload['selected_approver_type'] ?? ''),
                'ApprovedByUserID' => (int)($payload['approved_by_user_id'] ?? 0),
                'ApprovedAt' => (string)($payload['approved_at'] ?? ''),
                'RejectedByUserID' => (int)($payload['rejected_by_user_id'] ?? 0),
                'RejectedAt' => (string)($payload['rejected_at'] ?? ''),
                'RejectReason' => (string)($payload['reject_reason'] ?? ''),
                'ForwardTo' => (string)($payload['forward_to'] ?? ''),
            ];
        }

        $this->render('cards/LimitChangeApprovals', [
            'title' => 'Limit Change Approvals',
            'rows' => $approvalRows,
            'statusFilter' => $statusFilter,
        ]);
    }

    public function myLimitChangeApprovals(): void
    {
        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            SessionHelper::set('auth.intended_route', 'cards/my-limit-change-approvals');
            SessionHelper::set('flash.message', [
                'type' => 'info',
                'text' => 'Please sign in or activate your account to review your limit change approvals.',
            ]);
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $service = new ApprovalInboxService($this->db);
        $approvalRows = $service->listMyLimitChangeApprovals($userId);

        $this->render('cards/LimitChangeApprovals', [
            'title' => 'My Limit Change Approvals',
            'rows' => $approvalRows,
            'statusFilter' => '',
            'heading' => 'My Limit Change Approvals',
            'description' => 'Limit change requests currently waiting on your approval.',
            'baseRoute' => 'cards/my-limit-change-approvals',
            'showFilter' => false,
            'allowDelete' => false,
            'emptyMessage' => 'You have no limit change requests waiting for approval.',
        ]);
    }

    /**
     * Edit address screen for a specific card.
     * GET: index.php?route=cards/change-address&id=123
     */
    public function changeAddress(): void
    {
        $db = $this->db;
        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $cardId = (int)($_GET['id'] ?? 0);
        if ($cardId <= 0) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Missing card id.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $employeeId = trim((string)(SessionHelper::get('portalcards.filters.employeeId') ?? (SessionHelper::get('auth.employee_id') ?? '')));
        if ($employeeId === '') {
            $stmt = $db->prepare("
                SELECT EmployeeID
                FROM dbo.tblUsers
                WHERE UserID = :uid
            ");
            $stmt->execute(['uid' => $userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $employeeId = trim((string)($row['EmployeeID'] ?? ''));
            if ($employeeId !== '') {
                SessionHelper::set('auth.employee_id', $employeeId);
                SessionHelper::set('portalcards.filters.employeeId', $employeeId);
            }
        }

        $rbac = new Rbac($this->db);
        $isAdminOverride = !empty($_GET['admin']) && $rbac->canAny(['ADMIN_ALL', 'SYSADMIN']);

        if ($isAdminOverride) {
            $stmt = $db->prepare("
                SELECT *
                FROM dbo.tblPORTALCards
                WHERE CardID = :cid
            ");
            $stmt->execute(['cid' => $cardId]);
        } else {
            $stmt = $db->prepare("
                SELECT *
                FROM dbo.tblPORTALCards
                WHERE CardID = :cid
                  AND EmployeeID = :emp
            ");
            $stmt->execute(['cid' => $cardId, 'emp' => $employeeId]);
        }
        $card = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        if (!$card) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Card not found.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $pendingAddress = $this->hasSubmittedChangeRequestForTypes($cardId, $this->contactChangeRequestTypes(), [self::STATUS_ADDR_SUBMITTED]);
        $pendingCancel = $this->hasEffectiveCancelRequest($cardId);

        $historyStmt = $db->prepare("
            SELECT *
            FROM dbo.tblCardChangeRequests
            WHERE CardID = :cid
            ORDER BY CreatedAt DESC, RequestID DESC
        ");
        $historyStmt->execute(['cid' => $cardId]);
        $historyRows = $historyStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $data = [
            'address1' => (string)($card['Address1'] ?? ''),
            'address2' => (string)($card['Address2'] ?? ''),
            'address3' => (string)($card['Address3'] ?? ''),
            'suburb'   => (string)($card['Suburb'] ?? ''),
            'state'    => (string)($card['State'] ?? ''),
            'postcode' => (string)($card['PostCode'] ?? ''),
            'mobile'   => (string)($card['MobilePhone'] ?? ''),
            'mobile_country_code' => '+61',
            'work_phone' => (string)($card['WorkPhone'] ?? ''),
            'email'    => $this->firstNonEmpty($card, ['Email', 'Email_Address']),
        ];
        if ($pendingAddress) {
            foreach ($historyRows as $row) {
                if (!in_array(strtoupper(trim((string)($row['RequestType'] ?? ''))), $this->contactChangeRequestTypes(), true)) {
                    continue;
                }
                if (trim((string)($row['Status'] ?? '')) !== self::STATUS_ADDR_SUBMITTED) {
                    continue;
                }
                $payload = json_decode((string)($row['PayloadJson'] ?? ''), true);
                if (!is_array($payload)) {
                    continue;
                }
                $data = array_merge($data, [
                    'address1' => (string)($payload['address1'] ?? $data['address1']),
                    'address2' => (string)($payload['address2'] ?? $data['address2']),
                    'address3' => (string)($payload['address3'] ?? $data['address3']),
                    'suburb'   => (string)($payload['suburb'] ?? $data['suburb']),
                    'state'    => (string)($payload['state'] ?? $data['state']),
                    'postcode' => (string)($payload['postcode'] ?? $data['postcode']),
                    'mobile'   => (string)($payload['mobile'] ?? $data['mobile']),
                    'mobile_country_code' => (string)($payload['mobile_country_code'] ?? $data['mobile_country_code']),
                    'work_phone' => (string)($payload['work_phone'] ?? ($payload['phone'] ?? $data['work_phone'])),
                    'email'    => (string)($payload['email'] ?? $data['email']),
                ]);
                break;
            }
        }

        foreach ($data as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            $data[$key] = trim($value);
        }

        $progress = $this->buildAddressChangeProgress($cardId);
        $submissionToken = bin2hex(random_bytes(16));
        SessionHelper::set('cards.change_address.submission_token.' . $cardId, $submissionToken);

        $workflow = [
            ['StepKey' => 'address_correct', 'StepLabel' => 'Address Correct'],
            ['StepKey' => 'phone_correct',   'StepLabel' => 'Contact Details'],
        ];

        $this->render('cards/EditAddress', [
            'title' => 'Edit Address',
            'cardId' => $cardId,
            'cardType' => (string)($card['CardTypeSub'] ?? ''),
            'cardNumber' => (string)($card['CardNumber'] ?? ''),
            'mobileNumberHoverText' => $this->getEditAddressMobileNumberHoverText(),
            'workPostalAddressHoverText' => $this->getEditAddressWorkPostalAddressHoverText(),
            'ddPostalAddressesLink' => $this->getDdPostalAddressesLink(),
            'ddPostalAddressesLabel' => $this->getDdPostalAddressesLabel(),
            'data' => $data,
            'progress' => $progress,
            'workflow' => $workflow,
            'runtimeSteps' => [],
            'pendingAddress' => $pendingAddress,
            'pendingCancel' => $pendingCancel,
            'historyRows' => $historyRows,
            'submissionToken' => $submissionToken,
        ]);
    }

    /**
     * Cancel card confirmation screen.
     * GET: index.php?route=cards/cancel-card&id=123
     */
    public function cancelCard(): void
    {
        $db = $this->db;
        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $cardId = (int)($_GET['id'] ?? 0);
        if ($cardId <= 0) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Missing card id.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $employeeId = trim((string)(SessionHelper::get('portalcards.filters.employeeId') ?? (SessionHelper::get('auth.employee_id') ?? '')));
        if ($employeeId === '') {
            $stmt = $db->prepare("
                SELECT EmployeeID
                FROM dbo.tblUsers
                WHERE UserID = :uid
            ");
            $stmt->execute(['uid' => $userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $employeeId = trim((string)($row['EmployeeID'] ?? ''));
            if ($employeeId !== '') {
                SessionHelper::set('auth.employee_id', $employeeId);
                SessionHelper::set('portalcards.filters.employeeId', $employeeId);
            }
        }

        $rbac = new Rbac($this->db);
        $isAdminOverride = !empty($_GET['admin']) && $rbac->canAny(['ADMIN_ALL', 'SYSADMIN']);

        if ($isAdminOverride) {
            $stmt = $db->prepare("
                SELECT *
                FROM dbo.tblPORTALCards
                WHERE CardID = :cid
            ");
            $stmt->execute(['cid' => $cardId]);
        } else {
            $stmt = $db->prepare("
                SELECT *
                FROM dbo.tblPORTALCards
                WHERE CardID = :cid
                  AND EmployeeID = :emp
            ");
            $stmt->execute(['cid' => $cardId, 'emp' => $employeeId]);
        }
        $card = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        if (!$card) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Card not found.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $cancelMaxMonths = $this->getCancelCardMaxFutureMonths();
        $today = $this->getBusinessToday();
        $cancelDateMin = $today->format('Y-m-d');
        $cancelDateMax = $today->modify('+' . $cancelMaxMonths . ' months')->format('Y-m-d');

        $this->render('cards/CancelCard', [
            'title' => 'Cancel Card',
            'cardId' => $cardId,
            'cardTypeSub' => (string)($card['CardTypeSub'] ?? ''),
            'cardNumber' => (string)($card['CardNumber'] ?? ''),
            'cardExpiry' => (string)($card['Expiry'] ?? ''),
            'nameOnCard' => (string)($card['NameOnCard'] ?? ''),
            'cancelReasonOptions' => $this->loadCancelCardReasonOptions(),
            'cancelDateMin' => $cancelDateMin,
            'cancelDateMax' => $cancelDateMax,
            'cancelMaxMonths' => $cancelMaxMonths,
        ]);
    }

    /**
     * Save or submit address change request.
     * POST: index.php?route=cards/change-address-save&id=123
     */
    public function changeAddressSave(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        require_once __DIR__ . '/../../shared/csrf.php';
        if (function_exists('csrf_check') && !csrf_check($_POST['_csrf'] ?? '')) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Security check failed. Please try again.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $cardId = (int)($_GET['id'] ?? 0);
        if ($cardId <= 0) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Missing card id.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $employeeId = $this->resolveEmployeeId($userId);
        if ($employeeId === '') {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Employee ID not found.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $card = $this->loadPortalCard($cardId, $employeeId);
        if (!$card) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Card not found.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $payload = [
            'address1' => trim((string)($_POST['address1'] ?? '')),
            'address2' => trim((string)($_POST['address2'] ?? '')),
            'address3' => trim((string)($_POST['address3'] ?? '')),
            'suburb'   => $this->normalizeSuburbValue((string)($_POST['suburb'] ?? '')),
            'state'    => trim((string)($_POST['state'] ?? '')),
            'postcode' => trim((string)($_POST['postcode'] ?? '')),
            'mobile'   => $this->normalizePhoneInput((string)($_POST['mobile'] ?? '')),
            'mobile_country_code' => trim((string)($_POST['mobile_country_code'] ?? '+61')),
            'work_phone' => $this->normalizePhoneInput((string)($_POST['work_phone'] ?? '')),
            'email'    => trim((string)($_POST['email'] ?? '')),
            'apply_all_cards' => ((string)($_POST['apply_all_cards'] ?? '0') === '1') ? 1 : 0,
        ];

        $postedSubmissionToken = trim((string)($_POST['submission_token'] ?? ''));
        $sessionSubmissionToken = trim((string)(SessionHelper::get('cards.change_address.submission_token.' . $cardId) ?? ''));
        $processedSubmissionToken = trim((string)(SessionHelper::get('cards.change_address.processed_token.' . $cardId) ?? ''));
        if ($postedSubmissionToken !== '' && $processedSubmissionToken !== '' && hash_equals($processedSubmissionToken, $postedSubmissionToken)) {
            SessionHelper::set('flash.message', [
                'type' => 'success',
                'text' => 'Address change already submitted.',
            ]);
            header('Location: index.php?route=cards/change-address&id=' . urlencode((string)$cardId));
            exit;
        }

        $errors = $this->validateAddressPayload($payload);
        if (!empty($errors)) {
            SessionHelper::set('flash.message', [
                'type' => 'danger',
                'text' => 'Please fix the highlighted fields before submitting.',
            ]);
            header('Location: index.php?route=cards/change-address&id=' . urlencode((string)$cardId));
            exit;
        }

        $status = self::STATUS_ADDR_SUBMITTED;
        $targetCards = ((int)($payload['apply_all_cards'] ?? 0) === 1)
            ? $this->loadActivePortalCardsByEmployee($employeeId)
            : [$card];
        if ($targetCards === []) {
            SessionHelper::set('flash.message', [
                'type' => 'danger',
                'text' => 'No active cards were found for this address update.',
            ]);
            header('Location: index.php?route=cards/change-address&id=' . urlencode((string)$cardId));
            exit;
        }

        $cardsToProcess = [];
        foreach ($targetCards as $targetCard) {
            $targetCardId = (int)($targetCard['CardID'] ?? 0);
            if ($targetCardId <= 0) {
                continue;
            }
            $groups = $this->determineAddressChangeGroups($targetCard, $payload);
            if ($groups === []) {
                continue;
            }
            if ($this->hasBlockingAddressChangeRequest($targetCardId)) {
                SessionHelper::set('flash.message', [
                    'type' => 'danger',
                    'text' => 'A submitted card change already exists for one of the selected cards. Please wait for it to be actioned before submitting another update.',
                ]);
                header('Location: index.php?route=cards/change-address&id=' . urlencode((string)$cardId));
                exit;
            }

            $cardsToProcess[] = [
                'card' => $targetCard,
                'groups' => $groups,
            ];
        }

        if ($cardsToProcess === []) {
            SessionHelper::set('flash.message', [
                'type' => 'danger',
                'text' => 'No changes were detected. Update at least one field before submitting.',
            ]);
            header('Location: index.php?route=cards/change-address&id=' . urlencode((string)$cardId));
            exit;
        }

        try {
            if ($this->db instanceof \PDO && !$this->db->inTransaction()) {
                $this->db->beginTransaction();
            }

            foreach ($cardsToProcess as $entry) {
                $targetCard = $entry['card'];
                $groups = $entry['groups'];
                $requestPayload = $payload;
                $requestPayload['change_groups'] = $groups;
                $requestPayload['source_card_id'] = $cardId;

                $requestId = $this->insertCardChangeRequest([
                    'CardID' => (int)($targetCard['CardID'] ?? 0),
                    'EmployeeID' => $employeeId,
                    'RequestType' => self::REQUEST_TYPE_CONTACT_CHANGE,
                    'Status' => $status,
                    'Payload' => $requestPayload,
                    'SubmittedAt' => 'now',
                    'UpdatedBy' => $userId,
                ]);
                $this->exportCardRequestGroupsToCaps($requestId, $targetCard, $groups, $requestPayload, $userId);
            }

            $this->savePortalDefaultAddressFromChangeRequest($employeeId, $payload, $cardId, $userId);

            if ($this->db instanceof \PDO && $this->db->inTransaction()) {
                $this->db->commit();
            }
            SessionHelper::set('cards.change_address.processed_token.' . $cardId, $postedSubmissionToken);
            SessionHelper::forget('cards.change_address.submission_token.' . $cardId);
        } catch (\Throwable $e) {
            if ($this->db instanceof \PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[CardsController::changeAddressSave] ' . $e->getMessage());
            SessionHelper::set('flash.message', [
                'type' => 'danger',
                'text' => 'Address change could not be submitted to CAPS. Please try again or contact support.',
            ]);
            header('Location: index.php?route=cards/change-address&id=' . urlencode((string)$cardId));
            exit;
        }

        $this->sendAddressChangeConfirmation($userId, $employeeId, $card, $payload);

        SessionHelper::set('flash.message', [
            'type' => 'success',
            'text' => count($cardsToProcess) > 1
                ? ('Address change submitted for ' . count($cardsToProcess) . ' cards.')
                : 'Address change submitted.',
        ]);
        $this->auditLog(
            'SUBMIT',
            'CardChangeRequest',
            (string)$cardId,
            [
                'route' => 'cards/change-address-save',
                'request_type' => self::REQUEST_TYPE_CONTACT_CHANGE,
                'employee_id' => $employeeId,
                'cards_affected' => count($cardsToProcess),
            ]
        );

        header('Location: index.php?route=home/index');
        exit;
    }

    /**
     * Submit cancel card request.
     * POST: index.php?route=cards/cancel-card-submit
     */
    public function cancelCardSubmit(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        require_once __DIR__ . '/../../shared/csrf.php';
        if (function_exists('csrf_check') && !csrf_check($_POST['_csrf'] ?? '')) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Security check failed. Please try again.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $cardId = (int)($_POST['card_id'] ?? 0);
        if ($cardId <= 0) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Missing card id.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $employeeId = $this->resolveEmployeeId($userId);
        if ($employeeId === '') {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Employee ID not found.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $card = $this->loadPortalCard($cardId, $employeeId);
        if (!$card) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Card not found.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        if ($this->hasOpenChangeRequest($cardId, 'CANCEL_CARD', [self::STATUS_CANCEL_SUBMITTED])) {
            SessionHelper::set('flash.message', [
                'type' => 'danger',
                'text' => 'A cancel request is already submitted for this card.',
            ]);
            header('Location: index.php?route=home/index');
            exit;
        }

        $payload = [
            'card_type_sub' => (string)($card['CardTypeSub'] ?? ''),
            'card_number'   => (string)($card['CardNumber'] ?? ''),
            'expiry'        => (string)($card['Expiry'] ?? ''),
            'name_on_card'  => (string)($card['NameOnCard'] ?? ''),
            'reason'        => trim((string)($_POST['cancel_reason'] ?? '')),
            'reason_other'  => trim((string)($_POST['cancel_reason_other'] ?? '')),
            'cancel_date'   => trim((string)($_POST['cancel_date'] ?? '')),
        ];

        $errors = $this->validateCancelCardPayload($payload);
        if (!empty($errors)) {
            SessionHelper::set('flash.message', [
                'type' => 'danger',
                'text' => implode(' ', array_values($errors)),
            ]);
            SessionHelper::set('cards.cancel.old_input.' . $cardId, [
                'card_id' => $cardId,
                'cancel_reason' => $payload['reason'],
                'cancel_reason_other' => $payload['reason_other'],
                'cancel_date' => $payload['cancel_date'],
            ]);
            SessionHelper::set('cards.cancel.modal_reopen', [
                'card_id' => $cardId,
                'cancel_reason' => $payload['reason'],
                'cancel_reason_other' => $payload['reason_other'],
                'cancel_date' => $payload['cancel_date'],
            ]);
            header('Location: index.php?route=home/index');
            exit;
        }

        try {
            if ($this->db instanceof \PDO && !$this->db->inTransaction()) {
                $this->db->beginTransaction();
            }

            $this->supersedePendingCardChanges($card, $userId);

            $requestPayload = $payload;
            $requestPayload['change_groups'] = ['CancelCard'];
            $requestId = $this->insertCardChangeRequest([
                'CardID' => $cardId,
                'EmployeeID' => $employeeId,
                'RequestType' => 'CANCEL_CARD',
                'Status' => self::STATUS_CANCEL_SUBMITTED,
                'Payload' => $requestPayload,
                'SubmittedAt' => 'now',
                'UpdatedBy' => $userId,
            ]);
            $cancelEffectiveNow = $this->isCancelRequestEffectiveOnDate((string)($requestPayload['cancel_date'] ?? ''), $this->getBusinessToday());
            if ($cancelEffectiveNow) {
                $this->exportCardRequestGroupsToCaps($requestId, $card, ['CancelCard'], $requestPayload, $userId);
                $this->markCancelRequestProcessed($requestId, $userId);
            }

            if ($this->db instanceof \PDO && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($this->db instanceof \PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[CardsController::cancelCardSubmit] ' . $e->getMessage());
            SessionHelper::set('cards.cancel.modal_reopen', [
                'card_id' => $cardId,
                'cancel_reason' => $payload['reason'],
                'cancel_reason_other' => $payload['reason_other'],
                'cancel_date' => $payload['cancel_date'],
            ]);
            SessionHelper::set('flash.message', [
                'type' => 'danger',
                'text' => 'Card cancellation could not be submitted to CAPS. ' . $e->getMessage(),
            ]);
            header('Location: index.php?route=home/index');
            exit;
        }

        $this->auditLog(
            'SUBMIT',
            'CardChangeRequest',
            (string)$cardId,
            [
                'route' => 'cards/cancel-card-submit',
                'request_type' => 'CANCEL_CARD',
                'employee_id' => $employeeId,
                'cancel_date' => (string)($requestPayload['cancel_date'] ?? ''),
                'processed_immediately' => !empty($cancelEffectiveNow),
            ]
        );
        try {
            $this->sendCardCancellationConfirmation($userId, $employeeId, $card, $payload);
        } catch (\Throwable $e) {
            error_log('[CardsController::cancelCardSubmit confirmationMail] ' . $e->getMessage());
        }
        SessionHelper::set('flash.message', [
            'type' => 'success',
            'text' => !empty($cancelEffectiveNow)
                ? 'Card cancellation submitted and sent for processing.'
                : 'Card cancellation submitted and scheduled for the selected cancellation date.',
        ]);
        header('Location: index.php?route=home/index');
        exit;
    }

    public function processDueCancellations(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        if (function_exists('csrf_check') && !csrf_check($_POST['_csrf'] ?? '')) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Security check failed. Please try again.']);
            header('Location: index.php?route=admin/pending-card-cancellations');
            exit;
        }

        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $processed = 0;
        $skipped = 0;
        $errors = [];

        try {
            $dueRequests = $this->loadDueCancelRequests();
            foreach ($dueRequests as $request) {
                $requestId = (int)($request['RequestID'] ?? 0);
                $cardId = (int)($request['CardID'] ?? 0);
                if ($requestId <= 0 || $cardId <= 0) {
                    $skipped++;
                    continue;
                }

                if ($this->hasCapsExportForCancelRequest($requestId)) {
                    $skipped++;
                    continue;
                }

                $card = $this->loadPortalCardById($cardId);
                if (!$card) {
                    $skipped++;
                    $errors[] = 'Request ' . $requestId . ': card not found.';
                    continue;
                }

                $payload = json_decode((string)($request['PayloadJson'] ?? ''), true);
                $payload = is_array($payload) ? $payload : [];
                $groups = ['CancelCard'];

                try {
                    $this->exportCardRequestGroupsToCaps($requestId, $card, $groups, $payload, $userId);
                    $this->markCancelRequestProcessed($requestId, $userId);
                    $processed++;
                } catch (\Throwable $e) {
                    $errors[] = 'Request ' . $requestId . ': ' . $e->getMessage();
                }
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }

        $this->auditLog(
            'PROCESS',
            'CardChangeRequest',
            'due-cancellations',
            [
                'route' => 'cards/process-due-cancellations',
                'processed' => $processed,
                'skipped' => $skipped,
                'errors' => $errors,
            ]
        );

        if ($errors !== []) {
            SessionHelper::set('flash.message', [
                'type' => 'warning',
                'text' => 'Processed ' . $processed . ' due cancellation(s), skipped ' . $skipped . '. Some items had errors.',
            ]);
        } else {
            SessionHelper::set('flash.message', [
                'type' => 'success',
                'text' => 'Processed ' . $processed . ' due cancellation(s), skipped ' . $skipped . '.',
            ]);
        }

        header('Location: index.php?route=admin/pending-card-cancellations');
        exit;
    }

    private function validateCancelCardPayload(array $payload): array
    {
        $errors = [];
        $reason = trim((string)($payload['reason'] ?? ''));
        $reasonOther = trim((string)($payload['reason_other'] ?? ''));
        $cancelDate = trim((string)($payload['cancel_date'] ?? ''));
        $allowedReasons = array_map(
            static fn(array $row): string => trim((string)($row['ReasonLabel'] ?? '')),
            $this->loadCancelCardReasonOptions()
        );

        if ($reason === '') {
            $errors['reason'] = 'Cancel reason is required.';
        } elseif ($allowedReasons !== [] && !in_array($reason, $allowedReasons, true)) {
            $errors['reason'] = 'Cancel reason must be selected from the list.';
        }
        if (strcasecmp($reason, 'Other') === 0 && $reasonOther === '') {
            $errors['reason_other'] = 'Other reason is required when reason is Other.';
        }
        if ($cancelDate === '') {
            $errors['cancel_date'] = 'Cancellation date is required.';
            return $errors;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $cancelDate);
        $today = $this->getBusinessToday();
        $maxDate = $today->modify('+' . $this->getCancelCardMaxFutureMonths() . ' months');
        $isExact = $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $cancelDate;
        if (!$isExact) {
            $errors['cancel_date'] = 'Cancellation date must be a valid date.';
            return $errors;
        }
        if ($date < $today) {
            $errors['cancel_date'] = 'Cancellation date cannot be in the past.';
        } elseif ($date > $maxDate) {
            $errors['cancel_date'] = 'Cancellation date cannot be more than ' . $this->getCancelCardMaxFutureMonths() . ' month(s) from today.';
        }

        return $errors;
    }

    private function isCancelRequestEffectiveOnDate(string $cancelDate, \DateTimeImmutable $referenceDate): bool
    {
        $cancelDate = trim($cancelDate);
        if ($cancelDate === '') {
            return true;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $cancelDate);
        if (!$date || $date->format('Y-m-d') !== $cancelDate) {
            return true;
        }

        return $date <= $referenceDate;
    }

    private function getCancelCardMaxFutureMonths(): int
    {
        $defaultMonths = 6;
        if (!($this->db instanceof \PDO)) {
            return $defaultMonths;
        }

        try {
            $settings = new SystemSettingsModel($this->db);
            $raw = $settings->get('CANCEL_CARD_MAX_FUTURE_MONTHS');
            if ($raw === null || trim($raw) === '') {
                return $defaultMonths;
            }
            $months = (int)$raw;
            return $months >= 0 ? $months : $defaultMonths;
        } catch (\Throwable $e) {
            return $defaultMonths;
        }
    }

    private function loadCancelCardReasonOptions(): array
    {
        $fallback = [
            ['ReasonLabel' => 'Leaving Defence', 'SortOrder' => 10],
            ['ReasonLabel' => 'No Longer Required', 'SortOrder' => 20],
            ['ReasonLabel' => 'SERCAT 2', 'SortOrder' => 30],
            ['ReasonLabel' => 'Other', 'SortOrder' => 40],
        ];

        if (!($this->db instanceof \PDO)) {
            return $fallback;
        }

        try {
            $model = new CancelCardReasonModel($this->db);
            $rows = $model->listActive();
            return $rows !== [] ? $rows : $fallback;
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    private function getLimitChangeMaxCreditAmount(): float
    {
        $defaultAmount = 999900.0;
        if (!($this->db instanceof \PDO)) {
            return $defaultAmount;
        }

        try {
            $settings = new SystemSettingsModel($this->db);
            $raw = $settings->get('LIMIT_CHANGE_MAX_CREDIT_AMOUNT');
            if ($raw === null || trim((string)$raw) === '') {
                return $defaultAmount;
            }

            $value = $this->parseMoney((string)$raw);
            if ($value === null || $value <= 0) {
                return $defaultAmount;
            }

            return $value;
        } catch (\Throwable $e) {
            return $defaultAmount;
        }
    }

    private function getLimitChangeTemporaryPeriodMonths(): int
    {
        $defaultMonths = 48;
        if (!($this->db instanceof \PDO)) {
            return $defaultMonths;
        }

        try {
            $settings = new SystemSettingsModel($this->db);
            $raw = $settings->get('TEMP_LIMIT_PERIOD');
            if ($raw === null || trim((string)$raw) === '') {
                return $defaultMonths;
            }

            $months = (int)$raw;
            return $months >= 0 ? $months : $defaultMonths;
        } catch (\Throwable $e) {
            return $defaultMonths;
        }
    }

    private function getPortalContactMessage(): string
    {
        if (!($this->db instanceof \PDO)) {
            return '';
        }

        try {
            $settings = new SystemSettingsModel($this->db);
            return trim((string)($settings->get('PORTAL_CONTACT_MSG') ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function appendPortalContactMessage(string $message): string
    {
        $message = trim($message);
        $contactMessage = $this->getPortalContactMessage();
        if ($contactMessage === '') {
            return $message;
        }

        if ($message === '') {
            return $contactMessage;
        }

        return $message . ' ' . $contactMessage;
    }

    private function normalizeLimitChangePeriodPayload(array $payload, bool $isDtcCard): array
    {
        $scope = strtolower(trim((string)($payload['limit_change_scope'] ?? '')));
        if (!in_array($scope, ['both', 'credit_only', 'transaction_only'], true)) {
            $scope = 'both';
        }
        $payload['limit_change_scope'] = $scope;

        $legacyDurationType = strtolower(trim((string)($payload['limit_change_duration_type'] ?? 'permanent')));
        if (!in_array($legacyDurationType, ['permanent', 'temporary'], true)) {
            $legacyDurationType = 'permanent';
        }
        $legacyFrom = trim((string)($payload['period_change_from'] ?? ''));
        $legacyTo = trim((string)($payload['period_change_to'] ?? ''));

        $creditDurationType = strtolower(trim((string)($payload['credit_limit_change_duration_type'] ?? $legacyDurationType)));
        if (!in_array($creditDurationType, ['permanent', 'temporary'], true)) {
            $creditDurationType = 'permanent';
        }
        $payload['credit_limit_change_duration_type'] = $creditDurationType;
        $payload['credit_period_change_from'] = trim((string)($payload['credit_period_change_from'] ?? $legacyFrom));
        $payload['credit_period_change_to'] = trim((string)($payload['credit_period_change_to'] ?? $legacyTo));

        if ($isDtcCard) {
            $payload['transaction_limit_change_duration_type'] = 'permanent';
            $payload['transaction_period_change_from'] = '';
            $payload['transaction_period_change_to'] = '';
            $payload['limit_change_scope'] = 'credit_only';
        } else {
            $txnDurationType = strtolower(trim((string)($payload['transaction_limit_change_duration_type'] ?? $legacyDurationType)));
            if (!in_array($txnDurationType, ['permanent', 'temporary'], true)) {
                $txnDurationType = 'permanent';
            }
            $payload['transaction_limit_change_duration_type'] = $txnDurationType;
            $payload['transaction_period_change_from'] = trim((string)($payload['transaction_period_change_from'] ?? $legacyFrom));
            $payload['transaction_period_change_to'] = trim((string)($payload['transaction_period_change_to'] ?? $legacyTo));
        }

        if ($payload['limit_change_scope'] === 'credit_only') {
            $payload['transaction_limit_change_duration_type'] = 'permanent';
            $payload['transaction_period_change_from'] = '';
            $payload['transaction_period_change_to'] = '';
        } elseif ($payload['limit_change_scope'] === 'transaction_only') {
            $payload['credit_limit_change_duration_type'] = 'permanent';
            $payload['credit_period_change_from'] = '';
            $payload['credit_period_change_to'] = '';
        }

        // Keep legacy keys aligned with the active change scope for compatibility with older code paths.
        if ($payload['limit_change_scope'] === 'transaction_only') {
            $payload['limit_change_duration_type'] = $payload['transaction_limit_change_duration_type'];
            $payload['period_change_from'] = $payload['transaction_period_change_from'];
            $payload['period_change_to'] = $payload['transaction_period_change_to'];
        } else {
            $payload['limit_change_duration_type'] = $payload['credit_limit_change_duration_type'];
            $payload['period_change_from'] = $payload['credit_period_change_from'];
            $payload['period_change_to'] = $payload['credit_period_change_to'];
        }

        return $payload;
    }

    private function validateLimitChangePeriodFields(
        array &$errors,
        array $payload,
        string $durationKey,
        string $fromKey,
        string $toKey,
        string $labelPrefix,
        int $maxTemporaryMonths
    ): void {
        $durationType = strtolower(trim((string)($payload[$durationKey] ?? 'permanent')));
        if (!in_array($durationType, ['permanent', 'temporary'], true)) {
            $durationType = 'permanent';
        }
        if ($durationType !== 'temporary') {
            return;
        }

        $fromValue = trim((string)($payload[$fromKey] ?? ''));
        $toValue = trim((string)($payload[$toKey] ?? ''));
        $today = new \DateTimeImmutable('today');

        if ($fromValue === '') {
            $errors[$fromKey] = $labelPrefix . ' Period of Change From is required.';
        }
        if ($toValue === '') {
            $errors[$toKey] = $labelPrefix . ' Period of Change To is required.';
        }

        $fromDate = null;
        if ($fromValue !== '') {
            $fromDate = \DateTimeImmutable::createFromFormat('Y-m-d', $fromValue) ?: null;
            if (!$fromDate || $fromDate->format('Y-m-d') !== $fromValue) {
                $errors[$fromKey] = $labelPrefix . ' Period of Change From must be a valid date.';
            } elseif ($fromDate < $today) {
                $errors[$fromKey] = $labelPrefix . ' Period of Change From cannot be before today.';
            }
        }

        $toDate = null;
        if ($toValue !== '') {
            $toDate = \DateTimeImmutable::createFromFormat('Y-m-d', $toValue) ?: null;
            if (!$toDate || $toDate->format('Y-m-d') !== $toValue) {
                $errors[$toKey] = $labelPrefix . ' Period of Change To must be a valid date.';
            } elseif ($toDate < $today) {
                $errors[$toKey] = $labelPrefix . ' Period of Change To cannot be before today.';
            }
        }

        if ($fromValue !== '' && $toValue !== '' && $fromDate instanceof \DateTimeImmutable && $toDate instanceof \DateTimeImmutable) {
            if ($toDate < $fromDate) {
                $errors[$toKey] = $labelPrefix . ' Period of Change To must be on or after Period of Change From.';
                return;
            }
            $maxToDate = $fromDate->modify('+' . $maxTemporaryMonths . ' months');
            if ($toDate > $maxToDate) {
                $errors[$toKey] = $labelPrefix . ' Period of Change To cannot be more than ' . $maxTemporaryMonths . ' months after Period of Change From.';
            }
        }
    }

    private function getEditAddressMobileNumberHoverText(): string
    {
        if (!($this->db instanceof \PDO)) {
            return '';
        }

        try {
            $settings = new SystemSettingsModel($this->db);
            return trim((string)($settings->get('EDIT_CONTACT_MOBILE_NUMBER_HOVER_TEXT') ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function getEditAddressWorkPostalAddressHoverText(): string
    {
        if (!($this->db instanceof \PDO)) {
            return '';
        }

        try {
            $settings = new SystemSettingsModel($this->db);
            return trim((string)($settings->get('EDIT_CONTACT_WORK_POSTAL_ADDRESS_HOVER_TEXT') ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function getDdPostalAddressesLink(): string
    {
        if (!($this->db instanceof \PDO)) {
            return '';
        }

        try {
            $settings = new SystemSettingsModel($this->db);
            return trim((string)($settings->get('DDPostalAddresses') ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function getDdPostalAddressesLabel(): string
    {
        if (!($this->db instanceof \PDO)) {
            return 'DD Postal Addresses';
        }

        try {
            $settings = new SystemSettingsModel($this->db);
            $label = trim((string)($settings->get('DDPostalAddressesLabel') ?? ''));
            return $label !== '' ? $label : 'DD Postal Addresses';
        } catch (\Throwable $e) {
            return 'DD Postal Addresses';
        }
    }

    private function loadLimitChangeReasonOptions(int $applicationTypeId): array
    {
        $defaults = $this->legacyLimitChangeReasons();
        if ($applicationTypeId <= 0 || !($this->db instanceof \PDO)) {
            return $defaults;
        }

        try {
            $model = new LimitChangeReasonModel($this->db);
            $rows = $model->listActiveByApplicationType($applicationTypeId);
            if (!$rows) {
                return $defaults;
            }

            $out = [];
            foreach ($rows as $row) {
                $label = trim((string)($row['ReasonLabel'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $out[] = [
                    'value' => $label,
                    'label' => $label,
                ];
            }

            return $out ?: $defaults;
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    private function isAllowedLimitChangeReason(string $reason, int $applicationTypeId): bool
    {
        $reason = trim($reason);
        if ($reason === '') {
            return false;
        }

        foreach ($this->loadLimitChangeReasonOptions($applicationTypeId) as $option) {
            if ($reason === trim((string)($option['value'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    private function legacyLimitChangeReasons(): array
    {
        return [
            ['value' => 'Business requirement', 'label' => 'Business requirement'],
            ['value' => 'Role change', 'label' => 'Role change'],
            ['value' => 'Travel increase', 'label' => 'Travel increase'],
            ['value' => 'Project requirement', 'label' => 'Project requirement'],
            ['value' => 'Other', 'label' => 'Other'],
        ];
    }

    private function formatWholeDollarAmount(float $amount): string
    {
        return '$' . number_format($amount, 0);
    }

    /**
     * List change requests for the current user.
     * GET: index.php?route=cards/change-requests
     */
    public function changeRequests(): void
    {
        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($userId <= 0) {
            header('Location: index.php?route=auth/loginForm');
            exit;
        }

        $employeeId = $this->resolveEmployeeId($userId);
        if ($employeeId === '') {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Employee ID not found.']);
            header('Location: index.php?route=home/index');
            exit;
        }

        $cardId = (int)($_GET['card_id'] ?? 0);
        $selectedCard = null;
        if ($cardId > 0) {
            $selectedCard = $this->loadPortalCard($cardId, $employeeId);
            if (!$selectedCard) {
                SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Card not found.']);
                header('Location: index.php?route=home/index');
                exit;
            }
        }

        $rows = $this->loadCombinedChangeRequestSummaryRows($userId, $employeeId, $cardId);

        $this->render('cards/ChangeRequests', [
            'title' => 'Card Change Requests',
            'rows' => $rows,
            'selectedCardId' => $cardId,
            'selectedCard' => $selectedCard,
        ]);
    }

    private function resolveEmployeeId(int $userId): string
    {
        $employeeId = trim((string)(SessionHelper::get('portalcards.filters.employeeId') ?? (SessionHelper::get('auth.employee_id') ?? '')));
        if ($employeeId !== '') {
            return $employeeId;
        }

        $stmt = $this->db->prepare("
            SELECT EmployeeID
            FROM dbo.tblUsers
            WHERE UserID = :uid
        ");
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $employeeId = trim((string)($row['EmployeeID'] ?? ''));
        if ($employeeId !== '') {
            SessionHelper::set('auth.employee_id', $employeeId);
            SessionHelper::set('portalcards.filters.employeeId', $employeeId);
        }
        return $employeeId;
    }

    private function loadPortalCard(int $cardId, string $employeeId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM dbo.tblPORTALCards
            WHERE CardID = :cid
              AND EmployeeID = :emp
        ");
        $stmt->execute(['cid' => $cardId, 'emp' => $employeeId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function loadPortalCardById(int $cardId): ?array
    {
        if ($cardId <= 0) {
            return null;
        }
        $stmt = $this->db->prepare("
            SELECT *
            FROM dbo.tblPORTALCards
            WHERE CardID = :cid
        ");
        $stmt->execute(['cid' => $cardId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findPortalCardForOnBehalf(string $cardType, string $employeeId, string $last4): ?array
    {
        $cardType = strtoupper(trim($cardType));
        $employeeId = trim($employeeId);
        $last4 = trim($last4);
        if ($employeeId === '' || strlen($last4) !== 4) {
            return null;
        }

        if ($cardType === 'LODGE') {
            $sql = "
                SELECT TOP 1 *
                FROM dbo.tblPORTALCards
                WHERE EmployeeID = :emp
                  AND ISNULL(Status,'') = ''
                  AND RIGHT(LTRIM(RTRIM(ISNULL(CardNumber,''))), 4) = :last4
                  AND (
                        UPPER(ISNULL(CardType,'')) = 'LODGE'
                        OR (
                            UPPER(ISNULL(CardType,'')) = 'DTC'
                            AND UPPER(ISNULL(CardTypeSub,'')) LIKE '%LODGE%'
                        )
                      )
                ORDER BY CardID DESC
            ";
            $st = $this->db->prepare($sql);
            $st->execute([
                'emp' => $employeeId,
                'last4' => $last4,
            ]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        }

        $sql = "
            SELECT TOP 1 *
            FROM dbo.tblPORTALCards
            WHERE EmployeeID = :emp
              AND ISNULL(Status,'') = ''
              AND UPPER(ISNULL(CardType,'')) = :ctype
              AND RIGHT(LTRIM(RTRIM(ISNULL(CardNumber,''))), 4) = :last4
            ORDER BY CardID DESC
        ";
        $st = $this->db->prepare($sql);
        $st->execute([
            'emp' => $employeeId,
            'ctype' => $cardType,
            'last4' => $last4,
        ]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function isActivePortalCard(array $card): bool
    {
        $statusRaw = trim((string)($card['Status'] ?? ''));
        $status = strtolower($statusRaw);
        $activeFlag = strtolower(trim((string)($card['Active'] ?? '')));

        // Common "active" representations seen across environments.
        if ($statusRaw === '' || in_array($status, ['active', 'current', 'issued'], true)) {
            return true;
        }

        return in_array($activeFlag, ['y', 'yes', '1', 'true', 'active'], true);
    }

    private function validateAddressPayload(array $payload): array
    {
        $errors = [];
        if (trim((string)($payload['address1'] ?? '')) === '') {
            $errors['address1'] = 'Address Line 1 is required.';
        }
        if (trim((string)($payload['suburb'] ?? '')) === '') {
            $errors['suburb'] = 'Suburb is required.';
        }
        if (trim((string)($payload['state'] ?? '')) === '') {
            $errors['state'] = 'State is required.';
        }
        $postcode = trim((string)($payload['postcode'] ?? ''));
        if ($postcode === '' || !ctype_digit($postcode) || mb_strlen($postcode) > 4) {
            $errors['postcode'] = 'Postcode must be numeric and 4 digits or less.';
        }

        $a1 = (string)($payload['address1'] ?? '');
        $a2 = (string)($payload['address2'] ?? '');
        $a3 = (string)($payload['address3'] ?? '');
        $sub = (string)($payload['suburb'] ?? '');
        if (mb_strlen($a1) > 30 || mb_strlen($a2) > 30 || mb_strlen($a3) > 30) {
            $errors['address1'] = 'Each Address Line must be 30 characters or less.';
        }
        $mobile = trim((string)($payload['mobile'] ?? ''));
        $mobileCountryCode = trim((string)($payload['mobile_country_code'] ?? '+61'));
        if ($mobile === '' || !$this->isValidMobileByCountryCode($mobile, $mobileCountryCode)) {
            $errors['mobile'] = 'Mobile Number must be valid for country code ' . $mobileCountryCode . '.';
        }
        $workPhone = trim((string)($payload['work_phone'] ?? ''));
        if ($workPhone !== '' && !$this->isValidWorkPhone($workPhone)) {
            $errors['work_phone'] = 'Work Phone must be a valid non-mobile phone number.';
        }
        $email = trim((string)($payload['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email address is required and must be valid.';
        }

        return $errors;
    }

    private function isValidMobileByCountryCode(string $raw, string $countryCode): bool
    {
        $digits = preg_replace('/\D+/', '', trim($raw)) ?? '';
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

    private function isValidWorkPhone(string $raw): bool
    {
        $digits = preg_replace('/\D+/', '', trim($raw)) ?? '';
        if ($digits === '') {
            return false;
        }

        if ((bool)preg_match('/^(?:0?4\d{8}|614\d{8})$/', $digits)) {
            return false;
        }

        return (bool)preg_match('/^\d{6,14}$/', $digits);
    }

    private function insertCardChangeRequest(array $data): int
    {
        $payloadJson = json_encode($data['Payload'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $submittedAt = $data['SubmittedAt'] ?? null;

        $columns = ['CardID', 'EmployeeID', 'RequestType', 'Status', 'PayloadJson', 'SubmittedAt', 'UpdatedAt', 'UpdatedBy'];
        $values = [':cardId', ':emp', ':rtype', ':status', ':payload', ':submittedAt', 'SYSUTCDATETIME()', ':updatedBy'];
        $params = [
            'cardId' => (int)($data['CardID'] ?? 0),
            'emp' => (string)($data['EmployeeID'] ?? ''),
            'rtype' => (string)($data['RequestType'] ?? ''),
            'status' => (string)($data['Status'] ?? 'Draft'),
            'payload' => $payloadJson ?: null,
            'submittedAt' => $submittedAt === 'now' ? date('Y-m-d H:i:s') : $submittedAt,
            'updatedBy' => (int)($data['UpdatedBy'] ?? 0),
        ];

        if ($this->hasCardChangeRequestColumn('CancelDate')) {
            $columns[] = 'CancelDate';
            $values[] = ':cancelDate';
            $params['cancelDate'] = $this->normalizeSqlDateOrNull((string)(($data['Payload']['cancel_date'] ?? '')));
        }

        if ($this->hasCardChangeRequestColumn('ProcessedAt')) {
            $columns[] = 'ProcessedAt';
            $values[] = ':processedAt';
            $params['processedAt'] = null;
        }

        $stmt = $this->db->prepare("
            INSERT INTO dbo.tblCardChangeRequests
                (" . implode(', ', $columns) . ")
            OUTPUT INSERTED.RequestID
            VALUES
                (" . implode(', ', $values) . ")
        ");
        $stmt->execute($params);

        return (int)($stmt->fetchColumn() ?? 0);
    }

    private function loadActivePortalCardsByEmployee(string $employeeId): array
    {
        if ($employeeId === '' || !($this->db instanceof \PDO)) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT *
            FROM dbo.tblPORTALCards
            WHERE EmployeeID = :emp
            ORDER BY CardID ASC
        ");
        $stmt->execute(['emp' => $employeeId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_values(array_filter($rows, fn(array $row): bool => $this->isActivePortalCard($row)));
    }

    private function loadCombinedChangeRequestSummaryRows(int $userId, string $employeeId, int $cardId = 0): array
    {
        if (!($this->db instanceof \PDO)) {
            return [];
        }

        $rows = [];

        $sql = "
            SELECT *
            FROM dbo.tblCardChangeRequests
            WHERE EmployeeID = :emp
        ";
        $params = ['emp' => $employeeId];
        if ($cardId > 0) {
            $sql .= " AND CardID = :cid";
            $params['cid'] = $cardId;
        }
        $sql .= " ORDER BY CreatedAt DESC, RequestID DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        foreach (($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $row) {
            $row['RowSortAt'] = (string)($row['CreatedAt'] ?? $row['SubmittedAt'] ?? '');
            $row['RowSortId'] = (int)($row['RequestID'] ?? 0);
            $row['RowSource'] = 'card_change_request';
            $rows[] = $row;
        }

        $limitChangeSql = "
            SELECT
                a.ApplicationID,
                a.UserID,
                a.EmployeeID,
                a.Status,
                a.StartedAt,
                a.SubmittedAt,
                a.LastSavedAt,
                at.ApplicationTypeKey,
                at.ApplicationTypeName,
                step.DataJson,
                TRY_CONVERT(int, JSON_VALUE(step.DataJson, '$.card_id')) AS CardID
            FROM dbo.tblApplications a
            INNER JOIN dbo.tblApplicationTypes at
                ON at.ApplicationTypeID = a.ApplicationTypeID
            LEFT JOIN dbo.tblApplicationSteps step
                ON step.ApplicationID = a.ApplicationID
               AND step.StepKey = 'application'
            WHERE a.UserID = :uid
              AND (
                    LTRIM(RTRIM(ISNULL(CAST(a.EmployeeID AS NVARCHAR(50)), ''))) = :emp_app
                    OR LTRIM(RTRIM(ISNULL(JSON_VALUE(step.DataJson, '$.target_employee_id'), ''))) = :emp_payload
                  )
              AND LOWER(ISNULL(at.ApplicationTypeKey, '')) LIKE :type_key
        ";
        $limitParams = [
            'uid' => $userId,
            'emp_app' => $employeeId,
            'emp_payload' => $employeeId,
            'type_key' => '%limit_change',
        ];
        if ($cardId > 0) {
            $limitChangeSql .= " AND TRY_CONVERT(int, JSON_VALUE(step.DataJson, '$.card_id')) = :card_id";
            $limitParams['card_id'] = $cardId;
        }
        $limitChangeSql .= " ORDER BY ISNULL(a.SubmittedAt, ISNULL(a.LastSavedAt, a.StartedAt)) DESC, a.ApplicationID DESC";

        $limitStmt = $this->db->prepare($limitChangeSql);
        $limitStmt->execute($limitParams);
        foreach (($limitStmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $row) {
            $payload = json_decode((string)($row['DataJson'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
            $payload['application_type_key'] = (string)($row['ApplicationTypeKey'] ?? '');
            $payload['application_type_name'] = (string)($row['ApplicationTypeName'] ?? '');

            $rows[] = [
                'RequestID' => 'APP-' . (string)($row['ApplicationID'] ?? ''),
                'CardID' => (int)($row['CardID'] ?? 0),
                'RequestType' => 'LIMIT_CHANGE',
                'Status' => (string)($row['Status'] ?? ''),
                'CreatedAt' => (string)($row['StartedAt'] ?? ''),
                'SubmittedAt' => (string)($row['SubmittedAt'] ?? ''),
                'PayloadJson' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
                'RowSortAt' => (string)($row['SubmittedAt'] ?: ($row['LastSavedAt'] ?: ($row['StartedAt'] ?? ''))),
                'RowSortId' => (int)($row['ApplicationID'] ?? 0),
                'RowSource' => 'limit_change_application',
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $aSort = trim((string)($a['RowSortAt'] ?? ''));
            $bSort = trim((string)($b['RowSortAt'] ?? ''));
            if ($aSort !== $bSort) {
                return strcmp($bSort, $aSort);
            }
            return ((int)($b['RowSortId'] ?? 0)) <=> ((int)($a['RowSortId'] ?? 0));
        });

        return $rows;
    }

    private function hasBlockingAddressChangeRequest(int $cardId): bool
    {
        return $this->hasSubmittedChangeRequestForTypes(
            $cardId,
            $this->contactChangeRequestTypes(),
            [self::STATUS_ADDR_SUBMITTED]
        ) || $this->hasEffectiveCancelRequest($cardId);
    }

    private function contactChangeRequestTypes(): array
    {
        return [self::REQUEST_TYPE_CONTACT_CHANGE, 'ADDRESS_CHANGE'];
    }

    private function hasSubmittedChangeRequestForTypes(int $cardId, array $requestTypes, array $statuses): bool
    {
        if ($cardId <= 0 || $requestTypes === [] || $statuses === [] || !($this->db instanceof \PDO)) {
            return false;
        }

        $typePlaceholders = implode(',', array_fill(0, count($requestTypes), '?'));
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
        $sql = "
            SELECT TOP 1 1
            FROM dbo.tblCardChangeRequests
            WHERE CardID = ?
              AND RequestType IN ($typePlaceholders)
              AND Status IN ($statusPlaceholders)
        ";
        $stmt = $this->db->prepare($sql);
        $params = array_merge([$cardId], $requestTypes, $statuses);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    private function determineAddressChangeGroups(array $card, array $payload): array
    {
        $groups = [];

        $addressFields = [
            ['payload' => 'address1', 'card' => 'Address1'],
            ['payload' => 'address2', 'card' => 'Address2'],
            ['payload' => 'address3', 'card' => 'Address3'],
            ['payload' => 'suburb', 'card' => 'Suburb'],
            ['payload' => 'state', 'card' => 'State'],
            ['payload' => 'postcode', 'card' => 'PostCode'],
        ];
        foreach ($addressFields as $field) {
            $newValue = $this->normalizeComparableAddressValue($payload[$field['payload']] ?? '', $field['payload']);
            $oldValue = $this->normalizeComparableAddressValue($card[$field['card']] ?? '', $field['payload']);
            if ($newValue !== $oldValue) {
                $groups[] = 'Address';
                break;
            }
        }

        $newMobile = $this->normalizeComparableMobileValue(
            (string)($payload['mobile'] ?? ''),
            (string)($payload['mobile_country_code'] ?? '+61')
        );
        $oldMobile = $this->normalizeComparableMobileValue(
            (string)($card['MobilePhone'] ?? ''),
            (string)($payload['mobile_country_code'] ?? '+61')
        );
        if ($newMobile !== $oldMobile) {
            $groups[] = 'MobilePhone';
        }

        $newWorkPhone = $this->normalizeComparablePhoneValue($payload['work_phone'] ?? '');
        $oldWorkPhone = $this->normalizeComparablePhoneValue($card['WorkPhone'] ?? '');
        if ($newWorkPhone !== $oldWorkPhone) {
            $groups[] = 'WorkPhone';
        }

        $newEmail = $this->normalizeComparableEmailValue($payload['email'] ?? '');
        $oldEmail = $this->normalizeComparableEmailValue($this->firstNonEmpty($card, ['Email', 'Email_Address']));
        if ($newEmail !== $oldEmail) {
            $groups[] = 'EmailAddress';
        }

        return array_values(array_unique($groups));
    }

    private function hasOpenChangeRequest(int $cardId, string $requestType, array $statuses): bool
    {
        if ($cardId <= 0 || $requestType === '') return false;
        if (!$statuses) return false;

        $in = implode(',', array_fill(0, count($statuses), '?'));
        $sql = "
            SELECT TOP 1 1
            FROM dbo.tblCardChangeRequests
            WHERE CardID = ?
              AND RequestType = ?
              AND Status IN ($in)
        ";
        $stmt = $this->db->prepare($sql);
        $params = array_merge([$cardId, $requestType], $statuses);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    private function hasEffectiveCancelRequest(int $cardId): bool
    {
        if ($cardId <= 0 || !($this->db instanceof \PDO)) {
            return false;
        }

        $selectParts = ['PayloadJson'];
        if ($this->hasCardChangeRequestColumn('CancelDate')) {
            $selectParts[] = 'CancelDate';
        }
        if ($this->hasCardChangeRequestColumn('ProcessedAt')) {
            $selectParts[] = 'ProcessedAt';
        }

        $stmt = $this->db->prepare("
            SELECT " . implode(', ', $selectParts) . "
            FROM dbo.tblCardChangeRequests
            WHERE CardID = ?
              AND RequestType = ?
              AND Status = ?
            ORDER BY CreatedAt DESC, RequestID DESC
        ");
        $stmt->execute([$cardId, 'CANCEL_CARD', self::STATUS_CANCEL_SUBMITTED]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            if ($this->isCancelRequestEffectiveByRow($row)) {
                return true;
            }
        }
        return false;
    }

    private function isCancelRequestEffectiveNow(string $payloadJson): bool
    {
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return true;
        }

        $cancelDate = trim((string)($payload['cancel_date'] ?? ''));
        if ($cancelDate === '') {
            return true;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $cancelDate);
        if (!$date || $date->format('Y-m-d') !== $cancelDate) {
            return true;
        }

        $today = $this->getBusinessToday();
        return $date <= $today;
    }

    private function isCancelRequestEffectiveByRow(array $row): bool
    {
        $cancelDate = trim((string)($row['CancelDate'] ?? ''));
        if ($cancelDate !== '') {
            return $this->isCancelRequestEffectiveOnDate($cancelDate, $this->getBusinessToday());
        }

        return $this->isCancelRequestEffectiveNow((string)($row['PayloadJson'] ?? ''));
    }

    private function buildAddressChangeProgress(int $cardId): array
    {
        $request = null;
        if ($cardId > 0) {
            $typePlaceholders = implode(',', array_fill(0, count($this->contactChangeRequestTypes()), '?'));
            $stmt = $this->db->prepare("
                SELECT TOP 1 RequestID, Status, PayloadJson, SubmittedAt, EmployeeID
                FROM dbo.tblCardChangeRequests
                WHERE CardID = ?
                  AND RequestType IN ($typePlaceholders)
                ORDER BY CreatedAt DESC, RequestID DESC
            ");
            $stmt->execute(array_merge([$cardId], $this->contactChangeRequestTypes()));
            $request = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }
        $status = trim((string)($request['Status'] ?? ''));
        $hasCapsQueuedRow = $this->hasCapsQueuedAddressChangeRow($cardId, $request);

        // Default: no request yet
        $inProgress   = ['Label' => 'Update in Progress',              'IsActive' => true,  'Complete' => false];
        $submitted    = ['Label' => 'Change submitted by cardholder',  'IsActive' => false, 'Complete' => false];
        $bankFile     = ['Label' => 'Change added to bank file',       'IsActive' => false, 'Complete' => false];
        $changedAtBank = ['Label' => 'Changed at bank',                'IsActive' => false, 'Complete' => false];

        if ($status === self::STATUS_ADDR_SUBMITTED) {
            $inProgress['IsActive'] = false;
            $inProgress['Complete'] = true;
            $submitted['Complete'] = true;
            $submitted['IsActive'] = false;
            if ($hasCapsQueuedRow) {
                $bankFile['Complete'] = true;
                $changedAtBank['IsActive'] = true;
            } else {
                $bankFile['IsActive'] = true;
            }
        } elseif ($status === 'Addr Update Done') {
            $inProgress['IsActive'] = false;
            $inProgress['Complete'] = false;
            $submitted['Complete'] = false;
            $submitted['IsActive'] = false;
            $bankFile['Complete'] = false;
            $bankFile['IsActive'] = false;
            $changedAtBank['IsActive'] = true;
            $changedAtBank['Complete'] = true;
        }

        return [$inProgress, $submitted, $bankFile, $changedAtBank];
    }

    private function hasCapsQueuedAddressChangeRow(int $cardId, ?array $request): bool
    {
        global $capsConn;
        if ($cardId <= 0 || !is_array($request) || !($capsConn instanceof \PDO) || !($this->db instanceof \PDO)) {
            return false;
        }

        $card = $this->loadPortalCardById($cardId);
        if (!$card) {
            return false;
        }

        $payload = json_decode((string)($request['PayloadJson'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $groups = $payload['change_groups'] ?? $this->determineAddressChangeGroups($card, $payload);
        if (!is_array($groups) || $groups === []) {
            $groups = ['Address'];
        }
        $groups = array_values(array_filter(array_map(static fn($value): string => trim((string)$value), $groups)));
        if ($groups === []) {
            return false;
        }

        $employeeId = trim((string)($request['EmployeeID'] ?? ($card['EmployeeID'] ?? '')));
        $cardNumber = preg_replace('/\s+/', '', trim((string)($card['CardNumber'] ?? ''))) ?? '';
        if ($employeeId === '' || $cardNumber === '') {
            return false;
        }

        $submittedAt = trim((string)($request['SubmittedAt'] ?? ''));
        $groupPlaceholders = implode(',', array_fill(0, count($groups), '?'));
        $sql = "
            SELECT TOP 1 1
            FROM dbo.tblCAPSCSToDiners
            WHERE EIDNo = ?
              AND CardNo = ?
              AND ChangeGroup IN ($groupPlaceholders)
        ";
        $params = array_merge([$employeeId, $cardNumber], $groups);

        if ($submittedAt !== '') {
            $sql .= " AND DateUpdated >= DATEADD(day, -1, ?)";
            $params[] = $submittedAt;
        }

        $sql .= " ORDER BY DateUpdated DESC, CSToDinersID DESC";
        $stmt = $capsConn->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    private function savePortalDefaultAddressFromChangeRequest(string $employeeId, array $payload, int $sourceCardId, int $userId): void
    {
        $employeeId = trim($employeeId);
        if ($employeeId === '' || !($this->db instanceof \PDO)) {
            return;
        }

        $address = [
            'address1' => trim((string)($payload['address1'] ?? '')),
            'address2' => trim((string)($payload['address2'] ?? '')),
            'address3' => trim((string)($payload['address3'] ?? '')),
            'suburb' => $this->normalizeSuburbValue((string)($payload['suburb'] ?? '')),
            'state' => trim((string)($payload['state'] ?? '')),
            'postcode' => trim((string)($payload['postcode'] ?? '')),
        ];

        if ($address['address1'] === '' || $address['suburb'] === '' || $address['state'] === '' || $address['postcode'] === '') {
            return;
        }

        try {
            $update = $this->db->prepare("
                UPDATE dbo.tblPortalDefaultAddresses
                SET Address1 = :a1,
                    Address2 = :a2,
                    Address3 = :a3,
                    Suburb = :suburb,
                    State = :state,
                    PostCode = :postcode,
                    SourceApplicationID = :source_id,
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
                'source_id' => $sourceCardId > 0 ? $sourceCardId : null,
                'updated_by' => $userId > 0 ? $userId : null,
                'eid' => $employeeId,
            ]);

            if ($update->rowCount() > 0) {
                return;
            }

            $insert = $this->db->prepare("
                INSERT INTO dbo.tblPortalDefaultAddresses
                    (EmployeeID, Address1, Address2, Address3, Suburb, State, PostCode, SourceApplicationID, CreatedBy, UpdatedBy)
                VALUES
                    (:eid, :a1, :a2, :a3, :suburb, :state, :postcode, :source_id, :created_by, :updated_by)
            ");
            $insert->execute([
                'eid' => $employeeId,
                'a1' => $address['address1'],
                'a2' => $address['address2'] !== '' ? $address['address2'] : null,
                'a3' => $address['address3'] !== '' ? $address['address3'] : null,
                'suburb' => $address['suburb'],
                'state' => $address['state'],
                'postcode' => $address['postcode'],
                'source_id' => $sourceCardId > 0 ? $sourceCardId : null,
                'created_by' => $userId > 0 ? $userId : null,
                'updated_by' => $userId > 0 ? $userId : null,
            ]);
        } catch (\Throwable $e) {
            error_log('[CardsController::savePortalDefaultAddressFromChangeRequest] ' . $e->getMessage());
        }
    }

    private function exportCardRequestGroupsToCaps(int $requestId, array $card, array $groups, array $payload, int $userId): void
    {
        if (!$this->isCapsWriteEnabled()) {
            return;
        }

        global $capsConn;
        if (!($capsConn instanceof \PDO)) {
            throw new \RuntimeException('CAPS database connection is not available.');
        }
        if ($requestId <= 0) {
            throw new \RuntimeException('Address change request is missing RequestID for CAPS export.');
        }

        $request = $this->loadCardChangeRequestById($requestId);
        if (!$request) {
            throw new \RuntimeException('Address change request could not be reloaded for CAPS export.');
        }

        $insertedRows = 0;
        foreach ($groups as $group) {
            if ($group === 'CancelCard' && $this->hasCapsExportForCancelRequest($requestId)) {
                continue;
            }

            $row = $this->buildCapsCsToDinersRow($group, $card, $payload, $request, $userId);
            if ($row === []) {
                continue;
            }

            $columns = array_keys($row);
            $sql = sprintf(
                'INSERT INTO dbo.tblCAPSCSToDiners (%s) VALUES (%s)',
                implode(', ', $columns),
                implode(', ', array_map(static fn(string $column): string => ':' . $column, $columns))
            );
            $stmt = $capsConn->prepare($sql);
            $stmt->execute($row);
            $insertedRows++;
        }

        if ($insertedRows > 0) {
            $this->runCapsCardChangeProcess((string)($card['CardType'] ?? ''));
        }
    }

    private function loadCardChangeRequestById(int $requestId): ?array
    {
        if ($requestId <= 0 || !($this->db instanceof \PDO)) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT *
            FROM dbo.tblCardChangeRequests
            WHERE RequestID = :request_id
        ");
        $stmt->execute(['request_id' => $requestId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function buildCapsCsToDinersRow(string $group, array $card, array $payload, array $request, int $userId): array
    {
        $fileDateTime = '';
        $employeeId = $this->capsNullable($request['EmployeeID'] ?? ($card['EmployeeID'] ?? ''), 20);
        $cardNumber = $this->capsNullable(preg_replace('/\s+/', '', trim((string)($card['CardNumber'] ?? ''))) ?? '', 19);
        $cardExpiry = $this->capsNullableDateYmd($card['Expiry'] ?? '');
        $title = $this->capsNullable($card['Title'] ?? '', 12);
        $surname = $this->capsNullable($card['Surname'] ?? '', 25);
        $givenNames = $this->capsNullable(
            trim($this->firstNonEmpty($card, ['FirstName']) . ' ' . $this->firstNonEmpty($card, ['MiddleName'])),
            30
        );
        $nameOnCard = $this->capsNullable($card['NameOnCard'] ?? '', 26);
        $reportGroup = $this->capsNullable($this->firstNonEmpty($card, ['GroupName', 'ReportGroup']), 8);
        $creditLimit = $this->capsNullable($this->firstNonEmpty($card, ['ActiveCeiling', 'CreditLimit', 'CreditLimitAmount']), 11);

        $row = [
            'FileDateTime' => $fileDateTime,
            'FileSeqNum' => '',
            'EIDNo' => $employeeId,
            'CardNo' => $cardNumber,
            'CardUpdateInd' => '',
            'CardExpiryDate' => $cardExpiry,
            'CardStatus' => '',
            'Title' => $title,
            'Surname' => $surname,
            'GivenNames' => $givenNames,
            'NameOnCard' => $nameOnCard,
            'Address1' => '',
            'Address2' => '',
            'Address3' => '',
            'Suburb' => '',
            'State' => '',
            'PostCode' => '',
            'HomePhone' => '',
            'WorkPhone' => '',
            'MobilePhone' => '',
            'Email' => '',
            'ReportGroup' => $reportGroup,
            'CreditLimit' => $creditLimit,
            'Status' => self::CAPS_STATUS_AWAITING_EXPORT,
            'Notes' => '',
            'CardUpdated' => 0,
            'CSFromDinersID' => 0,
            'FileLoadID' => 0,
            'UpdatedBy' => $userId > 0 ? $userId : null,
            'DateUpdated' => gmdate('Y-m-d H:i:s'),
            'CardType' => $this->capsNullable($card['CardType'] ?? '', 20),
            'CardTypeSub' => $this->capsNullable($card['CardTypeSub'] ?? '', 20),
            'ChangeGroup' => $group,
        ];

        switch ($group) {
            case 'Address':
                $row['Address1'] = $this->capsNullable($payload['address1'] ?? '', 40);
                $row['Address2'] = $this->capsNullable($payload['address2'] ?? '', 40);
                $row['Address3'] = $this->capsNullable($payload['address3'] ?? '', 40);
                $row['Suburb'] = $this->capsNullable($payload['suburb'] ?? '', 25);
                $row['State'] = $this->capsNullable(strtoupper(trim((string)($payload['state'] ?? ''))), 4);
                $row['PostCode'] = $this->capsNullable($payload['postcode'] ?? '', 12);
                $row['Notes'] = 'Address change from DCCP';
                break;

            case 'MobilePhone':
                $row['MobilePhone'] = $this->capsNullable(
                    $this->normalizeCapsPhone((string)($payload['mobile'] ?? ''), 12),
                    12
                );
                $row['Notes'] = 'Mobile Phone change from DCCP';
                break;

            case 'WorkPhone':
                $row['WorkPhone'] = $this->capsNullable(
                    $this->normalizeCapsPhone((string)($payload['work_phone'] ?? ''), 12),
                    12
                );
                $row['Notes'] = 'Work Phone change from DCCP';
                break;

            case 'EmailAddress':
                $row['Email'] = $this->capsNullable($payload['email'] ?? '', 70);
                $row['Notes'] = 'Email change from DCCP';
                break;

            case 'CancelCard':
                $row['CardStatus'] = '01';
                $row['Address1'] = $this->capsNullable($card['Address1'] ?? '', 40);
                $row['Address2'] = $this->capsNullable($card['Address2'] ?? '', 40);
                $row['Address3'] = $this->capsNullable($card['Address3'] ?? '', 40);
                $row['Suburb'] = $this->capsNullable($card['Suburb'] ?? '', 25);
                $row['State'] = $this->capsNullable($card['State'] ?? '', 4);
                $row['PostCode'] = $this->capsNullable($card['PostCode'] ?? '', 12);
                $row['HomePhone'] = $this->capsNullable($this->normalizeCapsPhone((string)($card['HomePhone'] ?? ''), 12), 12);
                $row['WorkPhone'] = $this->capsNullable($this->normalizeCapsPhone((string)($card['WorkPhone'] ?? ''), 12), 12);
                $row['MobilePhone'] = $this->capsNullable($this->normalizeCapsPhone((string)($card['MobilePhone'] ?? ''), 12), 12);
                $row['Email'] = $this->capsNullable($this->firstNonEmpty($card, ['Email', 'Email_Address']), 70);
                $row['Notes'] = $this->buildCancelCardRequestMarker((int)($request['RequestID'] ?? 0));
                break;

            default:
                return [];
        }

        return $row;
    }

    private function runCapsCardChangeProcess(string $cardType): void
    {
        if (!$this->isCapsWriteEnabled()) {
            return;
        }

        global $capsConn;
        if (!($capsConn instanceof \PDO)) {
            throw new \RuntimeException('CAPS database connection is not available.');
        }

        $cardType = trim($cardType);
        if ($cardType === '') {
            throw new \RuntimeException('CardType is required to execute spCAPSNABCMProcess.');
        }

        $stmt = $capsConn->prepare("
            SET NOCOUNT ON;
            EXEC dbo.spCAPSNABCMProcess
                @CardType = :card_type
        ");
        $stmt->execute([
            'card_type' => mb_substr($cardType, 0, 50),
        ]);

        while ($stmt->nextRowset()) {
            // Consume any extra result sets/messages so pdo_sqlsrv does not
            // surface a false failure after the procedure has already processed.
        }
        $stmt->closeCursor();
    }

    private function buildCancelCardRequestMarker(int $requestId): string
    {
        if ($requestId <= 0) {
            return 'Cancel Card from DCCP';
        }

        return 'CCPORTAL_CANCEL_REQUEST_ID=' . $requestId;
    }

    private function hasCapsExportForCancelRequest(int $requestId): bool
    {
        global $capsConn;
        if ($requestId <= 0 || !($capsConn instanceof \PDO)) {
            return false;
        }

        $marker = $this->buildCancelCardRequestMarker($requestId);
        $stmt = $capsConn->prepare("
            SELECT TOP 1 1
            FROM dbo.tblCAPSCSToDiners
            WHERE Notes = :notes
        ");
        $stmt->execute(['notes' => $marker]);
        return (bool)$stmt->fetchColumn();
    }

    private function loadDueCancelRequests(): array
    {
        if (!($this->db instanceof \PDO)) {
            return [];
        }

        $selectParts = ['RequestID', 'CardID', 'EmployeeID', 'PayloadJson', 'SubmittedAt', 'UpdatedAt'];
        if ($this->hasCardChangeRequestColumn('CancelDate')) {
            $selectParts[] = 'CancelDate';
        }
        if ($this->hasCardChangeRequestColumn('ProcessedAt')) {
            $selectParts[] = 'ProcessedAt';
        }

        $where = "
            WHERE RequestType = :request_type
              AND Status = :status
        ";
        if ($this->hasCardChangeRequestColumn('ProcessedAt')) {
            $where .= "
              AND ProcessedAt IS NULL
            ";
        }
        if ($this->hasCardChangeRequestColumn('CancelDate')) {
            $where .= "
              AND CancelDate <= :today
            ";
        }

        $stmt = $this->db->prepare("
            SELECT " . implode(', ', $selectParts) . "
            FROM dbo.tblCardChangeRequests
            $where
            ORDER BY SubmittedAt ASC, RequestID ASC
        ");
        $params = [
            'request_type' => 'CANCEL_CARD',
            'status' => self::STATUS_CANCEL_SUBMITTED,
        ];
        if ($this->hasCardChangeRequestColumn('CancelDate')) {
            $params['today'] = $this->getBusinessToday()->format('Y-m-d');
        }
        $stmt->execute($params);

        $today = $this->getBusinessToday();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_values(array_filter($rows, function (array $row) use ($today): bool {
            if (!empty($row['ProcessedAt'] ?? null)) {
                return false;
            }
            $cancelDate = trim((string)($row['CancelDate'] ?? ''));
            if ($cancelDate !== '') {
                return $this->isCancelRequestEffectiveOnDate($cancelDate, $today);
            }
            $payload = json_decode((string)($row['PayloadJson'] ?? ''), true);
            if (!is_array($payload)) {
                return true;
            }
            return $this->isCancelRequestEffectiveOnDate((string)($payload['cancel_date'] ?? ''), $today);
        }));
    }

    private function markCancelRequestProcessed(int $requestId, int $userId): void
    {
        if ($requestId <= 0 || !($this->db instanceof \PDO)) {
            return;
        }

        $assignments = [
            'CompletedAt = SYSUTCDATETIME()',
            'UpdatedAt = SYSUTCDATETIME()',
            'UpdatedBy = :updated_by',
        ];

        if ($this->hasCardChangeRequestColumn('ProcessedAt')) {
            $assignments[] = 'ProcessedAt = SYSUTCDATETIME()';
        }

        $stmt = $this->db->prepare("
            UPDATE dbo.tblCardChangeRequests
            SET " . implode(', ', $assignments) . "
            WHERE RequestID = :request_id
        ");
        $stmt->execute([
            'updated_by' => $userId > 0 ? $userId : null,
            'request_id' => $requestId,
        ]);
    }

    private function hasCardChangeRequestColumn(string $columnName): bool
    {
        if (!($this->db instanceof \PDO) || trim($columnName) === '') {
            return false;
        }

        if ($this->cardChangeRequestColumnCache === null) {
            $stmt = $this->db->query("
                SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = 'dbo'
                  AND TABLE_NAME = 'tblCardChangeRequests'
            ");
            $this->cardChangeRequestColumnCache = [];
            foreach (($stmt ? ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) : []) as $name) {
                $this->cardChangeRequestColumnCache[strtolower((string)$name)] = true;
            }
        }

        return !empty($this->cardChangeRequestColumnCache[strtolower($columnName)]);
    }

    private function normalizeSqlDateOrNull(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $value;
    }

    private function getBusinessToday(): \DateTimeImmutable
    {
        if ($this->db instanceof \PDO) {
            try {
                $stmt = $this->db->query("SELECT CONVERT(varchar(10), CAST(SYSDATETIME() AS date), 23)");
                $value = trim((string)($stmt ? $stmt->fetchColumn() : ''));
                if ($value !== '') {
                    $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
                    if ($date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value) {
                        return $date;
                    }
                }
            } catch (\Throwable $e) {
                // Fall back to PHP local date if DB date lookup fails.
            }
        }

        return new \DateTimeImmutable('today');
    }

    private function supersedePendingCardChanges(array $card, int $userId): void
    {
        $cardId = (int)($card['CardID'] ?? 0);
        if ($cardId <= 0 || !($this->db instanceof \PDO)) {
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE dbo.tblCardChangeRequests
            SET Status = :status,
                UpdatedAt = SYSUTCDATETIME(),
                UpdatedBy = :updated_by
            WHERE CardID = :card_id
              AND RequestType <> 'CANCEL_CARD'
              AND Status = :current_status
        ");
        $stmt->execute([
            'status' => self::STATUS_SUPERSEDED_BY_CANCEL,
            'updated_by' => $userId > 0 ? $userId : null,
            'card_id' => $cardId,
            'current_status' => self::STATUS_ADDR_SUBMITTED,
        ]);

        if (!$this->isCapsWriteEnabled()) {
            return;
        }

        global $capsConn;
        if (!($capsConn instanceof \PDO)) {
            return;
        }

        $cardNumber = preg_replace('/\s+/', '', trim((string)($card['CardNumber'] ?? ''))) ?? '';
        $employeeId = trim((string)($card['EmployeeID'] ?? ''));
        if ($cardNumber === '' || $employeeId === '') {
            return;
        }

        $capsStmt = $capsConn->prepare("
            UPDATE dbo.tblCAPSCSToDiners
            SET Status = :status,
                UpdatedBy = :updated_by,
                DateUpdated = :updated_at
            WHERE EIDNo = :eid
              AND CardNo = :card_no
              AND Status = :current_status
              AND ChangeGroup IN ('Address', 'MobilePhone', 'WorkPhone', 'EmailAddress')
        ");
        $capsStmt->execute([
            'status' => self::STATUS_SUPERSEDED_BY_CANCEL,
            'updated_by' => $userId > 0 ? $userId : null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'eid' => $employeeId,
            'card_no' => $cardNumber,
            'current_status' => self::CAPS_STATUS_AWAITING_EXPORT,
        ]);
    }

    private function normalizeSuburbValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return mb_substr($value, 0, 21);
    }

    private function normalizeComparableAddressValue(mixed $value, string $field): ?string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }

        return match ($field) {
            'suburb' => strtoupper($this->normalizeSuburbValue($text)),
            'state' => strtoupper($text),
            'postcode' => preg_replace('/\D/', '', $text) ?: null,
            default => $text,
        };
    }

    private function normalizeComparableMobileValue(string $raw, string $countryCode): ?string
    {
        $digits = preg_replace('/\D/', '', trim($raw)) ?? '';
        if ($digits === '') {
            return null;
        }

        $codeDigits = ltrim(trim($countryCode), '+');
        if ($codeDigits !== '' && str_starts_with($digits, $codeDigits)) {
            $digits = substr($digits, strlen($codeDigits));
        }
        if ($countryCode === '+61' && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        return $digits !== '' ? $digits : null;
    }

    private function normalizeComparablePhoneValue(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', trim((string)$value)) ?? '';
        return $digits !== '' ? $digits : null;
    }

    private function normalizeComparableEmailValue(mixed $value): ?string
    {
        $text = strtolower(trim((string)$value));
        return $text !== '' ? $text : null;
    }

    private function ensureLimitChangeApplication(\PDO $db, int $userId, int $applicationTypeId, array $workflow, string $employeeId, int $cardId = 0): int
    {
        $row = $this->findOpenLimitChangeApplication($db, $userId, $applicationTypeId, $employeeId, $cardId);

        if ($row) {
            $applicationId = (int)$row['ApplicationID'];
        } else {
            $firstKey = '';
            if ($workflow) {
                $firstKey = (string)($workflow[0]['StepKey'] ?? '');
            }
            $employeeId = trim($employeeId);
            $stmt = $db->prepare("
                INSERT INTO dbo.tblApplications (UserID, ApplicationTypeID, Status, CurrentStepKey, StartedAt, Locked, EmployeeID)
                OUTPUT INSERTED.ApplicationID
                VALUES (:uid, :atid, 'Draft', :ck, SYSDATETIME(), 0, :emp)
            ");
            $stmt->execute([
                'uid' => $userId,
                'atid' => $applicationTypeId,
                'ck' => $firstKey !== '' ? $firstKey : null,
                'emp' => $employeeId !== '' ? $employeeId : null,
            ]);
            $applicationId = (int)($stmt->fetchColumn() ?? 0);
        }

        if ($applicationId <= 0) {
            throw new \RuntimeException('Unable to create or load limit change application.');
        }

        $this->ensureRuntimeSteps($db, $applicationId, $workflow);
        $this->ensurePayloadRow($db, $applicationId);

        return $applicationId;
    }

    private function findOpenLimitChangeApplication(\PDO $db, int $userId, int $applicationTypeId, string $employeeId = '', int $cardId = 0): ?array
    {
        $employeeId = trim($employeeId);
        $params = [
            'uid' => $userId,
            'atid' => $applicationTypeId,
        ];

        $employeeFilter = '';
        if ($employeeId !== '') {
            $employeeFilter = "
              AND (
                    LTRIM(RTRIM(ISNULL(CAST(a.EmployeeID AS NVARCHAR(50)), ''))) = :employee_id_app
                    OR LTRIM(RTRIM(ISNULL(JSON_VALUE(s.DataJson, '$.target_employee_id'), ''))) = :employee_id_payload
                  )";
            $params['employee_id_app'] = $employeeId;
            $params['employee_id_payload'] = $employeeId;
        }

        $cardFilter = '';
        if ($cardId > 0) {
            $cardFilter = "
              AND TRY_CONVERT(int, JSON_VALUE(s.DataJson, '$.card_id')) = :card_id";
            $params['card_id'] = $cardId;
        }

        $stmt = $db->prepare("
            SELECT TOP 1 a.ApplicationID, a.CurrentStepKey, a.Locked
            FROM dbo.tblApplications a
            LEFT JOIN dbo.tblApplicationSteps s
              ON s.ApplicationID = a.ApplicationID
             AND s.StepKey = 'application'
            WHERE a.UserID = :uid
              AND a.ApplicationTypeID = :atid
              AND a.Status IN ('Draft','InProgress')
              AND a.Locked = 0
              $employeeFilter
              $cardFilter
            ORDER BY a.ApplicationID DESC
        ");
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function loadApplicationById(\PDO $db, int $applicationId, int $userId): ?array
    {
        $stmt = $db->prepare("
            SELECT *
            FROM dbo.tblApplications
            WHERE ApplicationID = :aid
              AND UserID = :uid
        ");
        $stmt->execute(['aid' => $applicationId, 'uid' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function loadAnyApplicationById(\PDO $db, int $applicationId): ?array
    {
        $stmt = $db->prepare("
            SELECT *
            FROM dbo.tblApplications
            WHERE ApplicationID = :aid
        ");
        $stmt->execute(['aid' => $applicationId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
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

    private function applicationTypeRequiresPrivacyAgreement(array $type): bool
    {
        $required = !empty($type['PrivacyAgreementRequired']);
        $text = trim((string)($type['PrivacyAgreementText'] ?? ''));
        return $required && $text !== '';
    }

    private function buildLimitChangeRoute(string $typeKey, int $cardId, int $applicationId = 0, array $extra = []): string
    {
        $params = array_merge([
            'route' => 'cards/request-limit-change',
            'type' => $typeKey,
        ], $extra);

        if ($cardId > 0) {
            $params['id'] = (string)$cardId;
        }
        if ($applicationId > 0) {
            $params['application_id'] = (string)$applicationId;
        }

        return 'index.php?' . http_build_query($params);
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

    private function ensureRuntimeSteps(\PDO $db, int $applicationId, array $workflow): void
    {
        if (!$workflow) return;
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
            $k = (string)($ws['StepKey'] ?? '');
            if ($k === '') continue;
            $stmt->execute([$applicationId, $k, $applicationId, $k]);
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

    private function loadRuntimeSteps(\PDO $db, int $applicationId): array
    {
        $stmt = $db->prepare("
            SELECT StepKey, IsComplete, CompletedAt, LastSavedAt, DataJson, UpdatedBy
            FROM dbo.tblApplicationSteps
            WHERE ApplicationID = :aid
        ");
        $stmt->execute(['aid' => $applicationId]);

        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $key = strtolower(trim((string)($row['StepKey'] ?? '')));
            if ($key === '') {
                continue;
            }
            $map[$key] = $row;
        }
        return $map;
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

    private function savePayload(\PDO $db, int $applicationId, int $userId, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('json_encode failed: ' . json_last_error_msg());
        }

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

    private function upsertRuntimeStep(\PDO $db, int $applicationId, string $stepKey, int $isComplete, int $userId): void
    {
        $stepKey = trim($stepKey);
        if ($stepKey === '') return;

        $upd = $db->prepare("
            UPDATE dbo.tblApplicationSteps
            SET IsComplete = ?,
                LastSavedAt = SYSUTCDATETIME(),
                CompletedAt = CASE WHEN ? = 1 THEN SYSUTCDATETIME() ELSE NULL END,
                UpdatedBy = ?
            WHERE ApplicationID = ? AND StepKey = ?
        ");
        $upd->execute([
            $isComplete,
            $isComplete,
            $userId,
            $applicationId,
            $stepKey,
        ]);

        if ($upd->rowCount() === 0) {
            $ins = $db->prepare("
                INSERT INTO dbo.tblApplicationSteps
                    (ApplicationID, StepKey, IsComplete, LastSavedAt, CompletedAt, UpdatedBy)
                VALUES
                    (?, ?, ?, SYSUTCDATETIME(),
                     CASE WHEN ? = 1 THEN SYSUTCDATETIME() ELSE NULL END, ?)
            ");
            $ins->execute([
                $applicationId,
                $stepKey,
                $isComplete,
                $isComplete,
                $userId,
            ]);
        }
    }

    private function evaluateLimitChangeSteps(array $payload, string $cardType): array
    {
        $isDtc = $this->isDtcCardType($cardType);
        $typeKey = strtolower(trim((string)($payload['type_key'] ?? '')));
        $cardTypeSub = (string)($payload['card_type_sub'] ?? '');
        $isCreditOnlyLimitChange = $this->isCreditOnlyLimitChangeType($typeKey, $cardType, $cardTypeSub);
        $scope = strtolower(trim((string)($payload['limit_change_scope'] ?? ($isCreditOnlyLimitChange ? 'credit_only' : 'both'))));
        if (!in_array($scope, ['both', 'credit_only', 'transaction_only'], true)) {
            $scope = $isCreditOnlyLimitChange ? 'credit_only' : 'both';
        }
        $creditNew = trim((string)($payload['credit_limit_new'] ?? ''));
        $txnNew = $this->resolveTransactionLimitDisplayValue($payload);
        $payload = $this->normalizeLimitChangePeriodPayload($payload, $isCreditOnlyLimitChange);
        $creditDurationType = strtolower(trim((string)($payload['credit_limit_change_duration_type'] ?? 'permanent')));
        $creditPeriodFrom = trim((string)($payload['credit_period_change_from'] ?? ''));
        $creditPeriodTo = trim((string)($payload['credit_period_change_to'] ?? ''));
        $transactionDurationType = strtolower(trim((string)($payload['transaction_limit_change_duration_type'] ?? 'permanent')));
        $transactionPeriodFrom = trim((string)($payload['transaction_period_change_from'] ?? ''));
        $transactionPeriodTo = trim((string)($payload['transaction_period_change_to'] ?? ''));
        $reason = trim((string)($payload['limit_change_reason'] ?? ''));
        $reasonOther = trim((string)($payload['limit_change_reason_other'] ?? ''));
        $agedTxnConfirmed = ((string)($payload['aged_transactions_confirmed'] ?? '') === '1');
        $cardId = (int)($payload['card_id'] ?? 0);
        $creditAmount = $this->parseMoney($creditNew);
        $txnAmount = $this->parseMoney($txnNew);
        $creditLimitForTxnComparison = $scope === 'transaction_only'
            ? $this->parseMoney((string)($payload['credit_limit_current'] ?? ''))
            : $creditAmount;

        $cardDetailsOk = $cardId > 0;
        $temporaryOk = true;
        if ($scope !== 'transaction_only') {
            $temporaryOk = $this->isValidLimitChangePeriodRange($creditDurationType, $creditPeriodFrom, $creditPeriodTo, $this->getLimitChangeTemporaryPeriodMonths());
        }
        if (!$isCreditOnlyLimitChange && $scope !== 'credit_only') {
            $temporaryOk = $temporaryOk
                && $this->isValidLimitChangePeriodRange($transactionDurationType, $transactionPeriodFrom, $transactionPeriodTo, $this->getLimitChangeTemporaryPeriodMonths());
        }
        $creditOk = $scope === 'transaction_only'
            ? true
            : ($creditAmount !== null && $creditAmount > 0 && abs(fmod($creditAmount, 100.0)) <= 0.00001);
        $txnOk = ($isCreditOnlyLimitChange || $scope === 'credit_only')
            ? true
            : ($txnAmount !== null && $txnAmount > 0 && abs(fmod($txnAmount, 100.0)) <= 0.00001);
        $txnWithinCreditOk = ($isCreditOnlyLimitChange || $scope === 'credit_only')
            ? true
            : ($txnAmount === null || $creditLimitForTxnComparison === null ? true : $txnAmount <= $creditLimitForTxnComparison);
        $limitsOk = $creditOk && $txnOk && $txnWithinCreditOk && $temporaryOk;
        $justOk = $reason !== '' && $reasonOther !== '' && $agedTxnConfirmed;

        return [
            'card_details' => $cardDetailsOk,
            'limits_complete' => $limitsOk,
            'justification_complete' => $justOk,
        ];
    }

    private function isValidLimitChangePeriodRange(string $durationType, string $fromValue, string $toValue, int $maxTemporaryMonths): bool
    {
        $durationType = strtolower(trim($durationType));
        if ($durationType !== 'temporary') {
            return true;
        }

        $fromDate = $fromValue !== '' ? \DateTimeImmutable::createFromFormat('Y-m-d', $fromValue) : false;
        $toDate = $toValue !== '' ? \DateTimeImmutable::createFromFormat('Y-m-d', $toValue) : false;

        return $fromValue !== '' && $toValue !== ''
            && $fromDate instanceof \DateTimeImmutable
            && $toDate instanceof \DateTimeImmutable
            && $fromDate->format('Y-m-d') === $fromValue
            && $toDate->format('Y-m-d') === $toValue
            && $fromDate <= $toDate
            && $toDate <= $fromDate->modify('+' . $maxTemporaryMonths . ' months');
    }

    private function isDtcCardType(string $cardType): bool
    {
        $ct = strtoupper(trim($cardType));
        if ($ct === 'DTC') {
            return true;
        }
        return str_contains($ct, 'DTC');
    }

    private function isCreditOnlyLimitChangeType(string $typeKey, string $cardType, string $cardTypeSub = ''): bool
    {
        $typeKey = strtolower(trim($typeKey));
        if ($typeKey === 'lodge_limit_change') {
            return true;
        }

        $cardTypeSub = strtoupper(trim($cardTypeSub));
        if ($cardTypeSub !== '' && str_contains($cardTypeSub, 'LODGE')) {
            return true;
        }

        return $this->isDtcCardType($cardType);
    }

    private function resolveLimitChangeTypeKey(string $rawTypeKey, ?array $card): string
    {
        $typeKey = strtolower(trim($rawTypeKey));
        if (in_array($typeKey, ['dpc_limit_change', 'dtc_limit_change', 'lodge_limit_change'], true)) {
            return $typeKey;
        }

        $cardType = strtoupper(trim((string)($card['CardType'] ?? '')));
        $cardTypeSub = strtoupper(trim((string)($card['CardTypeSub'] ?? '')));
        $combinedType = trim($cardType . ' ' . $cardTypeSub);

        if ($cardType === 'DPC' || str_contains($combinedType, 'DPC')) {
            return 'dpc_limit_change';
        }
        if ($cardType === 'LODGE' || str_contains($combinedType, 'LODGE')) {
            return 'lodge_limit_change';
        }
        if ($this->isDtcCardType($cardType) || str_contains($combinedType, 'DTC')) {
            return 'dtc_limit_change';
        }

        return match ($typeKey) {
            'dpc' => 'dpc_limit_change',
            'dtc' => 'dtc_limit_change',
            'lodge' => 'lodge_limit_change',
            default => $typeKey,
        };
    }

    private function buildLimitChangeRuntimeSteps(array $workflow, array $payload, string $cardType, string $status): array
    {
        $steps = [];
        $now = date('Y-m-d H:i:s');
        $states = $this->evaluateLimitChangeSteps($payload, $cardType);
        foreach ($workflow as $ws) {
            $k = strtolower(trim((string)($ws['StepKey'] ?? '')));
            if ($k === '') continue;
            if ($k === 'application_submitted') {
                $isComplete = in_array(strtolower(trim($status)), ['submitted','tobeapproved','rejected','approved','senttobank','sent_to_bank','limitchanged','limit_changed'], true);
            } else {
                $isComplete = $states[$k] ?? false;
            }
            $steps[$k] = [
                'StepKey' => $k,
                'IsComplete' => $isComplete ? 1 : 0,
                'LastSavedAt' => ($payload ? $now : null),
            ];
        }
        return $steps;
    }

    private function exportLimitChangeApplicationToCaps(array $app, array $payload, ?array $card, int $userId): int
    {
        if (!$this->isCapsWriteEnabled()) {
            return 0;
        }

        global $capsConn;
        if (!($capsConn instanceof \PDO)) {
            throw new \RuntimeException('CAPS database connection is not available.');
        }

        $applicationId = (int)($app['ApplicationID'] ?? 0);
        if ($applicationId <= 0) {
            throw new \RuntimeException('Application is missing ApplicationID for CAPS export.');
        }

        $employeeId = $this->capsTrim(
            $app['EmployeeID']
                ?? ($payload['target_employee_id']
                ?? (SessionHelper::get('portalcards.filters.employeeId')
                ?? (SessionHelper::get('auth.employee_id') ?? ''))),
            12
        );
        $sourceMarker = $this->buildCapsSourceMarker($applicationId);
        $typeMeta = $this->loadApplicationTypeMetaById((int)($app['ApplicationTypeID'] ?? 0));

        $cardType = strtoupper(trim((string)($card['CardType'] ?? ($payload['card_type'] ?? ''))));
        $cardTypeSub = trim((string)($card['CardTypeSub'] ?? ($payload['card_type_sub'] ?? '')));
        $currentLimit = $this->parseMoney((string)($payload['credit_limit_current'] ?? ''));
        $newLimit = $this->parseMoney((string)($payload['credit_limit_new'] ?? ''));
        $txnLimit = $this->parseMoney($this->resolveTransactionLimitDisplayValue($payload));
        $reason = trim((string)($payload['limit_change_reason'] ?? ''));
        $reasonOther = trim((string)($payload['limit_change_reason_other'] ?? ''));
        $durationType = strtolower(trim((string)($payload['limit_change_duration_type'] ?? 'permanent')));
        $justification = $reason;
        if ($reasonOther !== '') {
            $justification = $reason !== '' ? ($reason . ': ' . $reasonOther) : $reasonOther;
        }
        $notes = $sourceMarker
            . '; SourceStatus=' . trim((string)($app['Status'] ?? 'Draft'))
            . '; ExportedAt=' . gmdate('Y-m-d H:i:s');
        $dateOfBirth = $this->capsNullableDate($this->firstNonEmpty($card, ['DateOfBirth', 'DateofBirth']));

        $row = [
            'EmployeeID' => $employeeId !== '' ? $employeeId : null,
            'CardID' => (int)($card['tblCardID'] ?? 0) ?: ((int)($card['CardID'] ?? ($payload['card_id'] ?? 0)) ?: null),
            'ApplicationType' => 'Portal',
            'CardType' => $cardType !== '' ? $this->capsTrim($cardType, 10) : null,
            'CardTypeSub' => $this->capsNullable($cardTypeSub, 20),
            'Title' => $this->capsNullable($card['Title'] ?? '', 50),
            'FirstName' => $this->capsNullable($card['FirstName'] ?? '', 50),
            'MiddleName' => $this->capsNullable($card['MiddleName'] ?? '', 50),
            'Surname' => $this->capsNullable($card['Surname'] ?? '', 50),
            'NameOnCard' => $this->capsNullable($card['NameOnCard'] ?? ($payload['name_on_card'] ?? ''), 50),
            'Gender' => $this->capsNullable($card['Gender'] ?? '', 10),
            'DateOfBirth' => $dateOfBirth,
            'Address1' => $this->capsNullable($card['Address1'] ?? '', 50),
            'Address2' => $this->capsNullable($card['Address2'] ?? '', 50),
            'Address3' => $this->capsNullable($card['Address3'] ?? '', 50),
            'Suburb' => $this->capsNullable($card['Suburb'] ?? '', 50),
            'State' => $this->capsNullable($card['State'] ?? '', 10),
            'PostCode' => $this->capsNullable($card['PostCode'] ?? '', 10),
            'HomePhone' => $this->capsNullable($card['HomePhone'] ?? '', 50),
            'WorkPhone' => $this->capsNullable($card['WorkPhone'] ?? '', 50),
            'MobilePhone' => $this->capsNullable($card['MobilePhone'] ?? '', 50),
            'Email' => $this->capsNullable($this->firstNonEmpty($card, ['Email', 'Email_Address']), 100),
            'CreditLimit' => $newLimit,
            'TransactionLimit' => $txnLimit,
            'CashDaily' => null,
            'CashOTC' => null,
            'BankCardType' => null,
            'ReportGroup' => null,
            'NationalityCode' => 'AU',
            'OccupationCode' => '1',
            'DefaultCompany' => null,
            'DefaultCC' => null,
            'DefaultWBS' => null,
            'DefaultIO' => null,
            'DefaultFund' => null,
            'CMSUser' => null,
            'CMSUserType' => null,
            'CMSUserTypeEID' => $employeeId !== '' ? $employeeId : null,
            'CMSUserName' => null,
            'Status' => 'Awaiting Review',
            'SignedApplication' => null,
            'Notes' => $notes,
            'DateExported' => null,
            'ExportBatch' => null,
            'EmailSent' => null,
            'CurrentLimit' => $currentLimit,
            'Justification' => $this->capsNullable($justification, 500),
            'ChangesPermanent' => $durationType === 'temporary' ? 'No' : 'Yes',
            'LimitDateFrom' => $this->capsNullableDateTime($payload['period_change_from'] ?? ''),
            'LimitDateTo' => $this->capsNullableDateTime($payload['period_change_to'] ?? ''),
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
            'ProChargeUserName' => null,
            'ErrorsChecked' => null,
            'LastFourDigits' => $this->capsNullable(substr(trim((string)($card['CardNumber'] ?? ($payload['card_number'] ?? ''))), -4), 6),
            'ApplicationTypeName' => $this->resolveCapsApplicationTypeName(
                (int)($app['ApplicationTypeID'] ?? 0),
                $typeMeta,
                $card,
                $payload
            ),
            'EmailErrorID' => null,
            'WarningDate' => null,
            'ErrorEmailSent' => null,
            'CreditLimitVarChar' => $newLimit !== null ? (string)((int)$newLimit == $newLimit ? (int)$newLimit : $newLimit) : null,
        ];

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
            throw new \RuntimeException('CAPS tblCAPSApplication insert did not return an ApplicationID.');
        }

        $approverNames = $this->buildCapsLimitDetailsApproverNames(
            $payload,
            trim((string)($payload['employee_group'] ?? ''))
        );

        $this->insertCapsLimitDetailsPortal(
            $capsConn,
            $capsApplicationId,
            $employeeId !== '' ? $employeeId : null,
            $applicationId,
            $approverNames['EMAIL'] ?? null,
            $approverNames['DIRECTOR'] ?? null,
            $approverNames['ASFIN'] ?? null,
            $approverNames['CFO'] ?? null,
            $this->capsNullableDateTime($payload['credit_period_change_from'] ?? ''),
            $this->capsNullableDate($payload['credit_period_change_to'] ?? ''),
            $this->parseMoney((string)($payload['credit_limit_current'] ?? '')),
            $this->parseMoney((string)($payload['credit_limit_new'] ?? '')),
            $this->capsNullableDateTime($payload['transaction_period_change_from'] ?? ''),
            $this->capsNullableDateTime($payload['transaction_period_change_to'] ?? ''),
            $this->parseMoney((string)($payload['transaction_limit_current'] ?? '')),
            $txnLimit
        );

        return $capsApplicationId;
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

    private function buildCapsSourceMarker(int $applicationId): string
    {
        return 'CCPORTAL_APPLICATION_ID=' . $applicationId;
    }

    private function deferLimitChangeCapsExportUntilApproval(int $applicationTypeId): bool
    {
        if (in_array($applicationTypeId, [5, 6, 7, 8, 9], true)) {
            return true;
        }

        $typeMeta = $this->loadApplicationTypeMetaById($applicationTypeId);
        $typeKey = strtolower(trim((string)($typeMeta['ApplicationTypeKey'] ?? '')));
        return in_array($typeKey, ['dpc_limit_change', 'dtc_limit_change', 'lodge_limit_change'], true);
    }

    private function resolveCapsApplicationTypeName(int $applicationTypeId, array $typeMeta, array $card = [], array $payload = []): string
    {
        $cardTypeSub = strtoupper(trim((string)($card['CardTypeSub'] ?? ($payload['card_type_sub'] ?? ''))));

        $label = match ($applicationTypeId) {
            1 => 'DPC NAB',
            2 => 'DTC NAB CiH',
            3 => 'DTC NAB Dual',
            4 => 'DTC NAB Lodge',
            5 => 'DPC Limit Change',
            6 => str_contains($cardTypeSub, 'LODGE') ? 'Lodge Limit Change' : 'DTC Limit Change',
            9 => 'Lodge Limit Change',
            default => (string)($typeMeta['ApplicationTypeName'] ?? ''),
        };

        return $this->capsTrim($label, 20);
    }

    private function capsNullable(mixed $value, int $maxLength): string
    {
        $trimmed = $this->capsTrim($value, $maxLength);
        return $trimmed;
    }

    private function capsTrim(mixed $value, int $maxLength): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }

        return mb_substr($text, 0, $maxLength);
    }

    private function capsNullableDateTime(mixed $value): ?string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        $ts = strtotime($text);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    private function capsNullableDate(mixed $value): ?string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        $ts = strtotime($text);
        return $ts === false ? null : date('Y-m-d', $ts);
    }

    private function capsNullableDateYmd(mixed $value): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }
        $ts = strtotime($text);
        return $ts === false ? '' : date('Ymd', $ts);
    }

    private function normalizeCapsPhone(string $value, int $maxLength = 12): string
    {
        $digits = preg_replace('/\D/', '', trim($value)) ?? '';
        if ($digits === '') {
            return '';
        }

        return substr($digits, 0, $maxLength);
    }


    private function runCapsLimitChangeApprovalInsert(int $capsApplicationId, int $updatedBy): int
    {
        if (!$this->isCapsWriteEnabled()) {
            return 0;
        }

        global $capsConn;
        if (!($capsConn instanceof \PDO)) {
            throw new \RuntimeException('CAPS database connection is not available.');
        }
        if ($capsApplicationId <= 0) {
            throw new \RuntimeException('ApplicationID is required for CAPS limit change approval.');
        }

        $stmt = $capsConn->prepare("
            SET NOCOUNT ON;
            DECLARE @LimitChangeAppOutputID int;
            EXEC dbo.spCAPSLimitChangeApplicationsInsert
                @ApplicationID = :application_id,
                @UpdatedBy = :updated_by,
                @LimitChangeAppOutputID = @LimitChangeAppOutputID OUTPUT;
            SELECT @LimitChangeAppOutputID AS LimitChangeAppOutputID;
        ");
        $stmt->execute([
            'application_id' => $capsApplicationId,
            'updated_by' => $updatedBy,
        ]);

        while ($stmt->columnCount() === 0 && $stmt->nextRowset()) {
            // Advance to the output SELECT result set.
        }

        $value = $stmt->columnCount() > 0 ? $stmt->fetchColumn() : null;
        if ($value === false || $value === null || $value === '') {
            return 0;
        }

        return (int)$value;
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

    private function buildCapsLimitDetailsApproverNames(array $payload, string $employeeGroup): array
    {
        $out = [
            'EMAIL' => null,
            'DIRECTOR' => null,
            'ASFIN' => null,
            'CFO' => null,
        ];

        $history = $payload['approval_history'] ?? [];
        if (!is_array($history)) {
            $history = [];
        }

        foreach ($history as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (strtolower(trim((string)($entry['decision'] ?? ''))) !== 'approve') {
                continue;
            }

            $selection = trim((string)($entry['approver'] ?? ''));
            if ($selection === '') {
                continue;
            }

            $parsed = $this->parseApproverSelection($selection);
            $type = strtoupper(trim((string)($parsed['type'] ?? '')));
            if ($type === 'SES') {
                $type = 'DIRECTOR';
            }
            if (!in_array($type, ['EMAIL', 'DIRECTOR', 'ASFIN', 'CFO'], true)) {
                continue;
            }

            $name = '';
            $actedByUserId = (int)($entry['acted_by_user_id'] ?? 0);
            if ($actedByUserId > 0) {
                $name = trim($this->loadUserDisplayName($actedByUserId));
            }
            if ($name === '') {
                $name = trim($this->resolveApproverDisplayFromSelection($selection, $employeeGroup));
            }
            if ($name === '') {
                continue;
            }

            $out[$type] = $this->capsNullable($name, 100);
        }

        return $out;
    }

    private function buildLimitChangeProgress(string $typeKey, int $applicationId): array
    {
        $stmt = $this->db->prepare("
            SELECT Status
            FROM dbo.tblApplications
            WHERE ApplicationID = :aid
        ");
        $stmt->execute(['aid' => $applicationId]);
        $status = strtolower(trim((string)($stmt->fetchColumn() ?? 'draft')));

        $steps = [
            ['key' => 'inprogress',  'label' => 'Application in Progress'],
            ['key' => 'submitted',   'label' => 'Application Submitted'],
            ['key' => 'tobeapproved','label' => 'Awaiting Approval'],
            ['key' => 'rejected',    'label' => 'Rejected'],
            ['key' => 'approved',    'label' => 'Approved'],
            ['key' => 'senttobank',  'label' => 'Sent to Bank'],
            ['key' => 'limitchanged','label' => 'Limit Changed'],
        ];

        $map = [
            'draft' => 'inprogress',
            'inprogress' => 'inprogress',
            'submitted' => 'submitted',
            'tobeapproved' => 'tobeapproved',
            'awaitingapproval' => 'tobeapproved',
            'rejected' => 'rejected',
            'approved' => 'approved',
            'senttobank' => 'senttobank',
            'sent_to_bank' => 'senttobank',
            'limitchanged' => 'limitchanged',
            'limit_changed' => 'limitchanged',
        ];
        $currentKey = $map[$status] ?? 'inprogress';

        $currentIdx = 0;
        foreach ($steps as $i => $s) {
            if ($s['key'] === $currentKey) {
                $currentIdx = $i;
                break;
            }
        }

        $out = [];
        foreach ($steps as $i => $s) {
            $out[] = [
                'Key' => $s['key'],
                'Label' => $s['label'],
                'IsActive' => ($i === $currentIdx),
                'Complete' => ($i <= $currentIdx),
            ];
        }
        return $out;
    }

    private function syncLimitChangeApprovalState(int $applicationId, int $userId, string $status): void
    {
        $statusKey = strtolower(trim($status));
        $currentStepKey = match ($statusKey) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            'senttobank', 'sent_to_bank' => 'senttobank',
            'limitchanged', 'limit_changed' => 'limitchanged',
            'submitted' => 'submitted',
            'tobeapproved', 'awaitingapproval' => 'tobeapproved',
            default => 'inprogress',
        };
        $locked = in_array($statusKey, ['approved', 'rejected', 'senttobank', 'sent_to_bank', 'limitchanged', 'limit_changed'], true) ? 1 : 0;

        $upd = $this->db->prepare("
            UPDATE dbo.tblApplications
            SET Status = :st,
                CurrentStepKey = :step_key,
                Locked = :locked,
                LastSavedAt = SYSDATETIME()
            WHERE ApplicationID = :aid
        ");
        $upd->execute([
            'st' => $status,
            'step_key' => $currentStepKey,
            'locked' => $locked,
            'aid' => $applicationId,
        ]);

        $this->upsertRuntimeStep($this->db, $applicationId, 'application_submitted', 1, $userId);
    }

    private function isLimitChangeLockedStatus(string $status): bool
    {
        $status = strtolower(trim($status));
        return !in_array($status, ['draft', 'inprogress'], true);
    }

    private function parseMoney(string $raw): ?float
    {
        $s = trim($raw);
        if ($s === '') {
            return null;
        }
        $s = str_replace([',', '$', ' '], '', $s);
        if (!is_numeric($s)) {
            return null;
        }
        return (float)$s;
    }

    private function normalizeMoneyString(float $amount): string
    {
        if (abs($amount - round($amount)) < 0.00001) {
            return (string)(int)round($amount);
        }

        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }

    private function hydrateTransactionLimitPayload(array $payload, array $options = []): array
    {
        $display = $this->resolveTransactionLimitDisplayValue($payload, $options);
        if ($display !== '') {
            $payload['transaction_limit_new_amount'] = $display;
        }

        $trackCode = trim((string)($payload['transaction_limit_new'] ?? ''));
        if ($trackCode !== '') {
            $selection = $this->resolveTransactionLimitSelection($trackCode, $options);
            if ($selection !== null) {
                $payload['transaction_limit_new_label'] = $selection['label'];
            }
        }

        return $payload;
    }

    private function resolveTransactionLimitDisplayValue(array $payload, array $options = []): string
    {
        $amount = trim((string)($payload['transaction_limit_new_amount'] ?? ''));
        if ($amount !== '') {
            return $amount;
        }

        $raw = trim((string)($payload['transaction_limit_new'] ?? ''));
        if ($raw === '') {
            return '';
        }
        if ($this->parseMoney($raw) !== null) {
            return $raw;
        }

        $selection = $this->resolveTransactionLimitSelection($raw, $options);
        if ($selection !== null) {
            return $this->normalizeMoneyString($selection['trans_limit']);
        }

        return '';
    }

    private function resolveTransactionLimitSelection(string $trackCode, array $options = []): ?array
    {
        $trackCode = strtoupper(trim($trackCode));
        if ($trackCode === '') {
            return null;
        }

        foreach ($options as $option) {
            if (strtoupper(trim((string)($option['track_code'] ?? ''))) !== $trackCode) {
                continue;
            }
            return $option;
        }

        global $capsConn;
        if (!($capsConn instanceof \PDO)) {
            return null;
        }

        $stmt = $capsConn->prepare("
            SELECT TOP 1
                TrackCode,
                Description,
                TransLimit
            FROM dbo.tblCAPSNABZCodes
            WHERE UPPER(LTRIM(RTRIM(TrackCode))) = :track_code
        ");
        $stmt->execute(['track_code' => $trackCode]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }

        $transLimit = isset($row['TransLimit']) ? (float)$row['TransLimit'] : null;
        if ($transLimit === null || $transLimit <= 0) {
            return null;
        }

        $description = trim((string)($row['Description'] ?? ''));
        $label = '$' . number_format($transLimit, 0);
        if ($description !== '') {
            $label .= ' - ' . $description;
        }

        return [
            'track_code' => $trackCode,
            'description' => $description,
            'trans_limit' => $transLimit,
            'label' => $label,
        ];
    }

    private function loadCapsTransactionLimitOptions(?array $card = null, array $payload = []): array
    {
        global $capsConn;
        if (!($capsConn instanceof \PDO)) {
            return [];
        }

        $cardTypeCandidates = $this->buildCapsNabzCardTypeCandidates($card, $payload);
        if ($cardTypeCandidates === []) {
            return [];
        }

        $conditions = [];
        $params = [];
        foreach ($cardTypeCandidates as $idx => $candidate) {
            $param = 'card_type_' . $idx;
            $conditions[] = "UPPER(LTRIM(RTRIM(ISNULL(CardType, '')))) = :{$param}";
            $params[$param] = $candidate;
        }

        $sql = "
            SELECT
                CardType,
                TrackCode,
                TransLimit
            FROM dbo.tblCAPSNABZCodes
            WHERE (" . implode(' OR ', $conditions) . ")
              AND ISNULL(CashLimit, 0) = 0
              AND UPPER(LTRIM(RTRIM(ISNULL(Active, '')))) = 'Y'
            ORDER BY TransLimit ASC, TrackCode ASC
        ";

        $stmt = $capsConn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $trackCode = strtoupper(trim((string)($row['TrackCode'] ?? '')));
            $transLimit = isset($row['TransLimit']) ? (float)$row['TransLimit'] : null;
            if ($trackCode === '' || $transLimit === null || $transLimit <= 0) {
                continue;
            }

            $label = '$' . number_format($transLimit, 0);

            $out[] = [
                'track_code' => $trackCode,
                'description' => '',
                'trans_limit' => $transLimit,
                'label' => $label,
            ];
        }

        return $out;
    }

    private function buildCapsNabzCardTypeCandidates(?array $card = null, array $payload = []): array
    {
        $candidates = [];
        foreach ([
            (string)($card['CardType'] ?? ''),
            (string)($card['CardTypeSub'] ?? ''),
            (string)($payload['card_type'] ?? ''),
            (string)($payload['card_type_sub'] ?? ''),
        ] as $value) {
            $normalized = strtoupper(trim($value));
            if ($normalized === '') {
                continue;
            }
            $candidates[] = $normalized;
        }

        return array_values(array_unique($candidates));
    }

    private function parseApproverSelection(string $selected): array
    {
        $selected = trim($selected);
        if ($selected === '') {
            return ['type' => '', 'position' => null, 'email' => ''];
        }
        $parts = explode('|', $selected, 2);
        $type = strtoupper(trim((string)($parts[0] ?? '')));
        $position = isset($parts[1]) ? trim((string)$parts[1]) : null;
        if ($position === '') {
            $position = null;
        }
        $email = $type === 'EMAIL' ? strtolower(trim((string)($parts[1] ?? ''))) : '';
        return ['type' => $type, 'position' => $position, 'email' => $email];
    }

    private function getLimitChangeManualApproverThreshold(): float
    {
        return 100000.0;
    }

    private function shouldUseManualApproverEmail(?float $creditAmount): bool
    {
        return false;
    }

    private function buildManualApproverSelection(string $email): string
    {
        $email = strtolower(trim($email));
        return $email !== '' ? ('EMAIL|' . $email) : '';
    }

    private function extractApproverEmailFromSelection(string $selection): string
    {
        $parsed = $this->parseApproverSelection($selection);
        return strtolower(trim((string)($parsed['email'] ?? '')));
    }

    private function loadSesApproverEmails(string $employeeGroup, bool $ignoreGroupFilter = false): array
    {
        $emails = [];
        $rows = $ignoreGroupFilter
            ? $this->loadAllSesApproversFromView()
            : $this->loadSesApproversFromView($employeeGroup);
        foreach ($rows as $row) {
            $email = $this->normalizeComparableEmailValue($row['Email'] ?? '');
            if ($email === null) {
                continue;
            }
            $emails[$email] = $email;
        }

        return array_values($emails);
    }

    private function isEmailInSesApproverView(string $email, string $employeeGroup): bool
    {
        $email = $this->normalizeComparableEmailValue($email);
        if ($email === null) {
            return false;
        }

        foreach ($this->loadSesApproverEmails($employeeGroup, true) as $candidate) {
            if ($candidate === $email) {
                return true;
            }
        }

        return false;
    }

    private function shouldRequireLimitChangeSesConfirmation(array $payload): bool
    {
        $currentApproverType = strtoupper(trim((string)($payload['selected_approver_type'] ?? '')));
        if ($currentApproverType === '') {
            $stages = $this->normalizeStoredApprovalStages($payload);
            $currentStageNumber = $this->resolveCurrentApprovalStageNumber($payload, $stages);
            $currentStage = $this->findStoredApprovalStage($stages, $currentStageNumber);
            if (is_array($currentStage)) {
                $currentApproverType = strtoupper(trim((string)($currentStage['approver_type'] ?? '')));
                if ($currentApproverType === '') {
                    $candidateApprovers = is_array($currentStage['candidate_approvers'] ?? null)
                        ? array_values(array_filter($currentStage['candidate_approvers'], static fn($row): bool => is_array($row)))
                        : [];
                    $candidateTypes = array_values(array_unique(array_filter(array_map(
                        static fn(array $row): string => strtoupper(trim((string)($row['type'] ?? ''))),
                        $candidateApprovers
                    ))));
                    if (count($candidateTypes) === 1) {
                        $currentApproverType = $candidateTypes[0];
                    }
                }
            }
        }

        if (in_array($currentApproverType, ['ASFIN', 'CFO'], true)) {
            return false;
        }

        $requestedLimit = $this->parseMoney((string)($payload['credit_limit_new'] ?? ''));
        if ($requestedLimit === null) {
            return true;
        }

        return $requestedLimit < 500000;
    }

    private function capsCdmcEmailExists(string $email): bool
    {
        $email = $this->normalizeComparableEmailValue($email);
        if ($email === null) {
            return false;
        }

        global $capsConn;
        if (!($capsConn instanceof \PDO)) {
            return false;
        }

        try {
            $stmt = $capsConn->prepare("
                SELECT TOP 1 1
                FROM dbo.tblCAPSCDMCPortal
                WHERE LOWER(LTRIM(RTRIM(ISNULL(Email_Address, '')))) = :email
            ");
            $stmt->execute(['email' => $email]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[CardsController::capsCdmcEmailExists] ' . $e->getMessage());
            return false;
        }
    }

    private function resolveApprovalEmployeeType(int $userId, string $employeeId, array $payload = [], ?array $card = null): string
    {
        $employeeType = trim((string)($payload['employee_type'] ?? ''));
        if ($employeeType !== '') {
            return $this->normalizeApprovalEmployeeType($employeeType);
        }

        $employeeId = trim($employeeId);
        if ($employeeId === '' && is_array($card)) {
            $employeeId = trim((string)($card['EmployeeID'] ?? ''));
        }
        if ($employeeId === '' && $userId > 0) {
            $employeeId = $this->resolveEmployeeId($userId);
        }

        $sessionEmployeeId = trim((string)SessionHelper::get('auth.employee_id', ''));
        $sessionEmployeeType = trim((string)SessionHelper::get('auth.employee_type', ''));
        if ($sessionEmployeeType !== '' && $employeeId !== '' && strcasecmp($sessionEmployeeId, $employeeId) === 0) {
            return $this->normalizeApprovalEmployeeType($sessionEmployeeType);
        }

        global $capsConn;
        if ($employeeId !== '' && ($capsConn instanceof \PDO)) {
            try {
                $st = $capsConn->prepare("
                    SELECT TOP 1 EmployeeType
                    FROM dbo.tblCAPSCDMCPortal
                    WHERE EmployeeID = :eid
                ");
                $st->execute(['eid' => $employeeId]);
                $employeeType = trim((string)($st->fetchColumn() ?? ''));
                if ($employeeType !== '') {
                    return $this->normalizeApprovalEmployeeType($employeeType);
                }
            } catch (\Throwable $e) {
                error_log('[CardsController::resolveApprovalEmployeeType] ' . $e->getMessage());
            }
        }

        if ($sessionEmployeeType !== '') {
            return $this->normalizeApprovalEmployeeType($sessionEmployeeType);
        }

        return '';
    }

    private function normalizeApprovalEmployeeType(string $employeeType): string
    {
        $employeeType = trim($employeeType);
        if ($employeeType === '') {
            return '';
        }

        $raw = 'ASA,ASD,ANNPSR';
        try {
            if ($this->db instanceof \PDO) {
                $settings = new SystemSettingsModel($this->db);
                $raw = trim((string)($settings->get('EMPLOYEE_TYPE_DIRECT_MATCHES') ?? $raw));
            }
        } catch (\Throwable $e) {
            error_log('[CardsController::normalizeApprovalEmployeeType] ' . $e->getMessage());
        }

        $allowed = preg_split('/[\s,;|]+/', strtoupper($raw)) ?: [];
        $allowed = array_values(array_filter(array_map('trim', $allowed), static fn(string $v): bool => $v !== ''));
        if (!$allowed) {
            $allowed = ['ASA', 'ASD', 'ANNPSR'];
        }

        return in_array(strtoupper($employeeType), $allowed, true) ? $employeeType : 'Defence';
    }

    private function resolveEmployeeGroup(int $userId, string $employeeId, array $payload = [], ?array $card = null): string
    {
        $payloadGroup = trim((string)($payload['employee_group'] ?? ''));
        $routingGroup = trim((string)$this->resolveApprovalEmployeeType($userId, $employeeId, $payload, $card));

        if (is_array($card)) {
            $group = $this->normalizeApprovalRoutingGroupLabel((string)($card['GroupName'] ?? ''));
            if ($group !== '') {
                return $group;
            }
        }

        if ($employeeId !== '') {
            $st = $this->db->prepare("
                SELECT TOP 1 GroupName
                FROM qryCAPSCDMCHistoryActive
                WHERE EmployeeID = :eid
            ");
            $st->execute(['eid' => $employeeId]);
            $group = $this->normalizeApprovalRoutingGroupLabel((string)($st->fetchColumn() ?? ''));
            if ($group !== '') {
                return $group;
            }
        }

        if ($employeeId !== '') {
            $group = $this->normalizeApprovalRoutingGroupLabel($this->loadCapsEmployeeGroupName($employeeId));
            if ($group !== '') {
                return $group;
            }
        }

        $sessionEmployeeId = trim((string)SessionHelper::get('auth.employee_id', ''));

        // Primary source requested: auth.EmployeeGroup, but only for the matching applicant.
        $group = $this->normalizeApprovalRoutingGroupLabel((string)SessionHelper::get('auth.EmployeeGroup', ''));
        if ($group !== '' && ($employeeId === '' || strcasecmp($sessionEmployeeId, $employeeId) === 0)) {
            return $group;
        }

        // Common key used by existing auth flow in this project.
        $group = $this->normalizeApprovalRoutingGroupLabel((string)SessionHelper::get('auth.employee_group', ''));
        if ($group !== '' && ($employeeId === '' || strcasecmp($sessionEmployeeId, $employeeId) === 0)) {
            return $group;
        }

        $payloadGroup = $this->normalizeApprovalRoutingGroupLabel($payloadGroup);
        if ($payloadGroup !== '') {
            return $payloadGroup;
        }

        // Approval routing is configured with values like ASA/ASD/ANNPSR/Defence.
        // If no explicit routing-group label is available, fall back to the
        // applicant's normalized approval classification so approver rules still match.
        if ($routingGroup !== '') {
            return $routingGroup;
        }

        $group = $this->normalizeApprovalRoutingGroupLabel((string)SessionHelper::get('auth.employee_group', ''));
        if ($group !== '') {
            return $group;
        }

        // Do not return generic labels (e.g. "Group A") because they break filtering.
        // Returning empty string allows caller to apply strict "global only" logic.
        if ($this->isGenericEmployeeGroupLabel($group)) {
            return '';
        }

        return $group;
    }

    private function normalizeApprovalRoutingGroupLabel(string $group): string
    {
        $group = trim($group);
        if ($group === '' || $this->isGenericEmployeeGroupLabel($group)) {
            return '';
        }

        if (strcasecmp($group, 'Defence') === 0) {
            return 'Defence';
        }

        $raw = 'ASA,ASD,ANNPSR';
        try {
            if ($this->db instanceof \PDO) {
                $settings = new SystemSettingsModel($this->db);
                $raw = trim((string)($settings->get('EMPLOYEE_TYPE_DIRECT_MATCHES') ?? $raw));
            }
        } catch (\Throwable $e) {
            error_log('[CardsController::normalizeApprovalRoutingGroupLabel] ' . $e->getMessage());
        }

        $allowed = preg_split('/[\s,;|]+/', strtoupper($raw)) ?: [];
        $allowed = array_values(array_filter(array_map('trim', $allowed), static fn(string $v): bool => $v !== ''));
        foreach ($allowed as $value) {
            if (strcasecmp($group, $value) === 0) {
                return $value;
            }
        }

        return '';
    }

    private function resolveEmployeeGroupDisplay(int $userId, string $employeeId, array $payload = [], ?array $card = null): string
    {
        if (is_array($card)) {
            $group = trim((string)($card['GroupName'] ?? ''));
            if ($group !== '' && !$this->isGenericEmployeeGroupLabel($group)) {
                return $group;
            }
        }

        $employeeId = trim($employeeId);
        if ($employeeId !== '') {
            $st = $this->db->prepare("
                SELECT TOP 1 GroupName
                FROM qryCAPSCDMCHistoryActive
                WHERE EmployeeID = :eid
            ");
            $st->execute(['eid' => $employeeId]);
            $group = trim((string)($st->fetchColumn() ?? ''));
            if ($group !== '' && !$this->isGenericEmployeeGroupLabel($group)) {
                return $group;
            }
        }

        if ($employeeId !== '') {
            $group = trim($this->loadCapsEmployeeGroupName($employeeId));
            if ($group !== '' && !$this->isGenericEmployeeGroupLabel($group)) {
                return $group;
            }
        }

        $sessionEmployeeId = trim((string)SessionHelper::get('auth.employee_id', ''));
        $group = trim((string)SessionHelper::get('auth.EmployeeGroup', ''));
        if ($group !== '' && !$this->isGenericEmployeeGroupLabel($group) && ($employeeId === '' || strcasecmp($sessionEmployeeId, $employeeId) === 0)) {
            return $group;
        }

        $group = trim((string)SessionHelper::get('auth.employee_group', ''));
        if ($group !== '' && !$this->isGenericEmployeeGroupLabel($group) && ($employeeId === '' || strcasecmp($sessionEmployeeId, $employeeId) === 0)) {
            return $group;
        }

        return trim((string)($payload['employee_group'] ?? ''));
    }

    private function loadCapsEmployeeGroupName(string $employeeId): string
    {
        $employeeId = trim($employeeId);
        if ($employeeId === '') {
            return '';
        }

        global $capsConn;
        if (!($capsConn instanceof \PDO)) {
            return '';
        }

        foreach (['GroupName', 'EmployeeGroup'] as $column) {
            try {
                $st = $capsConn->prepare("
                    SELECT TOP 1 NULLIF(LTRIM(RTRIM(" . $column . ")), '')
                    FROM dbo.tblCAPSCDMCPortal
                    WHERE EmployeeID = :eid
                ");
                $st->execute(['eid' => $employeeId]);
                $group = trim((string)($st->fetchColumn() ?? ''));
                if ($group !== '') {
                    return $group;
                }
            } catch (\Throwable $e) {
                error_log('[CardsController::loadCapsEmployeeGroupName] ' . $e->getMessage());
            }
        }

        return '';
    }

    private function isGenericEmployeeGroupLabel(string $group): bool
    {
        $g = strtolower(trim($group));
        if ($g === '') {
            return true;
        }
        return (bool)preg_match('/^group\s+[a-z0-9]+$/i', $group);
    }

    private function loadApprovalRules(int $applicationTypeId, string $employeeGroup): array
    {
        if ($applicationTypeId <= 0) {
            return [];
        }

        $employeeGroup = trim($employeeGroup);
        if ($employeeGroup === '') {
            $st = $this->db->prepare("
                SELECT
                    RuleID,
                    EmployeeGroup,
                    ApprovalStage,
                    MinLimit,
                    MaxLimit,
                    RequiredApproverType,
                    RequiredRank
                FROM dbo.tblWorkflowApprovalRules
                WHERE IsActive = 1
                  AND ApplicationTypeID = :atid
                  AND EmployeeGroup IS NULL
                ORDER BY ApprovalStage ASC, MinLimit ASC, RequiredApproverType ASC
            ");
            $st->execute(['atid' => $applicationTypeId]);
            return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }

        $sql = "
            SELECT
                RuleID,
                EmployeeGroup,
                ApprovalStage,
                MinLimit,
                MaxLimit,
                RequiredApproverType,
                RequiredRank
            FROM dbo.tblWorkflowApprovalRules
            WHERE IsActive = 1
              AND ApplicationTypeID = :atid
              AND (
                    LOWER(ISNULL(EmployeeGroup, '')) = LOWER(:grp_match)
                    OR EmployeeGroup IS NULL
                  )
            ORDER BY
              ApprovalStage ASC,
              CASE WHEN LOWER(ISNULL(EmployeeGroup, '')) = LOWER(:grp_order) THEN 0 ELSE 1 END,
              MinLimit ASC,
              RequiredApproverType ASC
        ";
        $st = $this->db->prepare($sql);
        $st->execute([
            'atid' => $applicationTypeId,
            'grp_match' => $employeeGroup,
            'grp_order' => $employeeGroup,
        ]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private function loadApproverDirectory(string $employeeGroup): array
    {
        $employeeGroup = trim($employeeGroup);
        if ($employeeGroup === '') {
            $st = $this->db->prepare("
                SELECT
                    ApproverType,
                    EmployeeGroup,
                    PositionNumber,
                    Email,
                    DisplayName
                FROM dbo.tblWorkflowApproverPositions
                WHERE IsActive = 1
                  AND EmployeeGroup IS NULL
                ORDER BY ApproverType ASC, PositionNumber ASC
            ");
            $st->execute();
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } else {
        $sql = "
            SELECT
                ApproverType,
                EmployeeGroup,
                PositionNumber,
                Email,
                DisplayName
            FROM dbo.tblWorkflowApproverPositions
            WHERE IsActive = 1
              AND (
                    LOWER(ISNULL(EmployeeGroup, '')) = LOWER(:grp_match)
                    OR EmployeeGroup IS NULL
                  )
            ORDER BY
              ApproverType ASC,
              CASE WHEN LOWER(ISNULL(EmployeeGroup, '')) = LOWER(:grp_order) THEN 0 ELSE 1 END,
              PositionNumber ASC
        ";
        $st = $this->db->prepare($sql);
        $st->execute([
            'grp_match' => $employeeGroup,
            'grp_order' => $employeeGroup,
        ]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }

        if (!$rows) {
            $rows = [];
        }

        $out = [];
        foreach ($rows as $r) {
            $type = strtoupper(trim((string)($r['ApproverType'] ?? '')));
            $pos = trim((string)($r['PositionNumber'] ?? ''));
            if ($type === '' || $pos === '') {
                continue;
            }
            $display = trim((string)($r['DisplayName'] ?? ''));
            $email = trim((string)($r['Email'] ?? ''));
            if ($display === '' && $email !== '') {
                $display = trim((string)strtok($email, '@'));
            }
            $meta = $type . ' - ' . $pos;
            $label = ($display !== '' ? ($display . ' (' . $meta . ')') : $meta);
            $out[$type][] = [
                'value' => $type . '|' . $pos,
                'label' => $label,
                'type' => $type,
                'position' => $pos,
                'email' => strtolower(trim($email)),
            ];
        }

        // Special case: Defence SES approvers can come from a dedicated CAPS-backed view.
        $sesRows = $this->loadSesApproversFromView($employeeGroup);
        foreach ($sesRows as $r) {
            $type = 'SES';
            $pos = trim((string)($r['PositionNumber'] ?? ''));
            if ($pos === '') {
                continue;
            }
            $display = trim((string)($r['DisplayName'] ?? ''));
            $email = trim((string)($r['Email'] ?? ''));
            if ($display === '' && $email !== '') {
                $display = trim((string)strtok($email, '@'));
            }
            $meta = $type . ' - ' . $pos;
            $label = ($display !== '' ? ($display . ' (' . $meta . ')') : $meta);
            $value = $type . '|' . $pos;
            $already = false;
            if (!empty($out[$type])) {
                foreach ($out[$type] as $existing) {
                    if ((string)($existing['value'] ?? '') === $value) {
                        $already = true;
                        break;
                    }
                }
            }
            if ($already) {
                continue;
            }
            $out[$type][] = [
                'value' => $value,
                'label' => $label,
                'type' => $type,
                'position' => $pos,
                'email' => strtolower(trim($email)),
            ];
        }

        return $out;
    }

    private function filterApprovalRulesForAmount(array $rules, float $amount): array
    {
        $matched = [];
        foreach ($rules as $rule) {
            $min = (float)($rule['MinLimit'] ?? 0);
            $max = $rule['MaxLimit'] ?? null;
            $maxVal = ($max === null || $max === '') ? null : (float)$max;
            if ($amount < $min) {
                continue;
            }
            if ($maxVal !== null && $amount > $maxVal) {
                continue;
            }
            $matched[] = $rule;
        }

        usort($matched, static function (array $a, array $b): int {
            $stageCmp = max(1, (int)($a['ApprovalStage'] ?? 1)) <=> max(1, (int)($b['ApprovalStage'] ?? 1));
            if ($stageCmp !== 0) {
                return $stageCmp;
            }
            $minCmp = ((float)($a['MinLimit'] ?? 0)) <=> ((float)($b['MinLimit'] ?? 0));
            if ($minCmp !== 0) {
                return $minCmp;
            }
            return strcmp(
                strtoupper(trim((string)($a['RequiredApproverType'] ?? ''))),
                strtoupper(trim((string)($b['RequiredApproverType'] ?? '')))
            );
        });

        return $matched;
    }

    private function buildApprovalStageDefinitions(array $rules, float $amount): array
    {
        $matched = $this->filterApprovalRulesForAmount($rules, $amount);
        if ($matched === []) {
            return [];
        }

        $grouped = [];
        foreach ($matched as $rule) {
            $stage = max(1, (int)($rule['ApprovalStage'] ?? 1));
            if (!isset($grouped[$stage])) {
                $grouped[$stage] = [
                    'stage' => $stage,
                    'rules' => [],
                ];
            }
            $grouped[$stage]['rules'][] = [
                'RequiredApproverType' => strtoupper(trim((string)($rule['RequiredApproverType'] ?? ''))),
                'RequiredRank' => trim((string)($rule['RequiredRank'] ?? '')),
                'EmployeeGroup' => trim((string)($rule['EmployeeGroup'] ?? '')),
                'ApprovalStage' => $stage,
            ];
        }

        ksort($grouped);
        return array_values($grouped);
    }

    private function stageUsesManualApproverEmail(array $rules): bool
    {
        foreach ($rules as $rule) {
            $type = strtoupper(trim((string)($rule['RequiredApproverType'] ?? '')));
            if (in_array($type, ['EMAIL', 'MANUAL'], true)) {
                return true;
            }
        }
        return false;
    }

    private function hydrateApprovalStagesWithCandidates(array $stages, array $directory): array
    {
        foreach ($stages as $idx => $stage) {
            $rules = is_array($stage['rules'] ?? null) ? $stage['rules'] : [];
            $selectionMode = $this->stageUsesManualApproverEmail($rules) ? 'manual' : 'resolved';
            $candidates = $selectionMode === 'manual'
                ? []
                : $this->buildApproverOptionsForRules($rules, $directory);
            $stages[$idx]['selection_mode'] = $selectionMode;
            $stages[$idx]['candidate_approvers'] = array_values(array_map(static function (array $row): array {
                return [
                    'value' => trim((string)($row['value'] ?? '')),
                    'label' => trim((string)($row['label'] ?? '')),
                    'type' => trim((string)($row['type'] ?? '')),
                    'position' => isset($row['position']) ? trim((string)$row['position']) : null,
                    'email' => strtolower(trim((string)($row['email'] ?? ''))),
                ];
            }, $candidates));
        }

        return $stages;
    }

    private function filterApplicantFromApproverOptions(
        int $userId,
        string $employeeId,
        array $payload,
        ?array $card,
        string $employeeGroup,
        array $options
    ): array {
        $filtered = [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            $selection = trim((string)($option['value'] ?? ''));
            $email = trim((string)($option['email'] ?? ''));
            $isApplicant = false;
            if ($selection !== '') {
                $isApplicant = $this->isApplicantApproverSelection($userId, $employeeId, $payload, $card, $selection, $employeeGroup);
            } elseif ($email !== '') {
                $isApplicant = $this->isApplicantApproverByEmail($userId, $employeeId, $payload, $card, $email);
            }
            if ($isApplicant) {
                continue;
            }
            $filtered[] = $option;
        }
        return array_values($filtered);
    }

    private function filterApplicantFromApprovalStages(
        int $userId,
        string $employeeId,
        array $payload,
        ?array $card,
        string $employeeGroup,
        array $stages
    ): array {
        foreach ($stages as $idx => $stage) {
            if (!is_array($stage)) {
                continue;
            }
            $selectionMode = trim((string)($stage['selection_mode'] ?? ''));
            if ($selectionMode !== 'resolved') {
                continue;
            }
            $stages[$idx]['candidate_approvers'] = $this->filterApplicantFromApproverOptions(
                $userId,
                $employeeId,
                $payload,
                $card,
                $employeeGroup,
                is_array($stage['candidate_approvers'] ?? null) ? $stage['candidate_approvers'] : []
            );
        }

        return $stages;
    }

    private function filterResolvableApproverOptions(array $options, string $employeeGroup): array
    {
        $resolved = [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $selection = trim((string)($option['value'] ?? ''));
            if ($selection === '') {
                continue;
            }

            $parsed = $this->parseApproverSelection($selection);
            $type = strtoupper(trim((string)($parsed['type'] ?? '')));
            if ($type === '') {
                continue;
            }

            if ($type === 'EMAIL') {
                $email = $this->normalizeComparableEmailValue($parsed['email'] ?? ($option['email'] ?? ''));
                if ($email !== null && $email !== '') {
                    $option['email'] = $email;
                    $resolved[] = $option;
                }
                continue;
            }

            $position = trim((string)($parsed['position'] ?? ($option['position'] ?? '')));
            if ($position === '') {
                continue;
            }

            $contact = $this->resolveApproverContact($type, $position, $employeeGroup);
            $email = $this->normalizeComparableEmailValue((string)($contact['email'] ?? ''));
            if ($email === null || $email === '') {
                continue;
            }

            $option['email'] = $email;
            $resolved[] = $option;
        }

        return array_values($resolved);
    }

    private function normalizeStoredApprovalStages(array $payload): array
    {
        $stages = $payload['approval_stages'] ?? null;
        if (!is_array($stages)) {
            return [];
        }

        $out = [];
        foreach ($stages as $stage) {
            if (!is_array($stage)) {
                continue;
            }
            $stageNumber = max(1, (int)($stage['stage'] ?? 1));
            $rules = [];
            foreach (($stage['rules'] ?? []) as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $type = strtoupper(trim((string)($rule['RequiredApproverType'] ?? '')));
                if ($type === '') {
                    continue;
                }
                $rules[] = [
                    'RequiredApproverType' => $type,
                    'RequiredRank' => trim((string)($rule['RequiredRank'] ?? '')),
                    'EmployeeGroup' => trim((string)($rule['EmployeeGroup'] ?? '')),
                    'ApprovalStage' => $stageNumber,
                ];
            }

            $out[] = [
                'stage' => $stageNumber,
                'rules' => $rules,
                'selection_mode' => trim((string)($stage['selection_mode'] ?? '')),
                'candidate_approvers' => is_array($stage['candidate_approvers'] ?? null) ? array_values($stage['candidate_approvers']) : [],
                'approver' => trim((string)($stage['approver'] ?? '')),
                'approver_type' => trim((string)($stage['approver_type'] ?? '')),
                'approver_position' => isset($stage['approver_position']) ? trim((string)$stage['approver_position']) : null,
                'approved_by_user_id' => (int)($stage['approved_by_user_id'] ?? 0),
                'approved_at' => trim((string)($stage['approved_at'] ?? '')),
                'forwarded_by_user_id' => (int)($stage['forwarded_by_user_id'] ?? 0),
                'forwarded_at' => trim((string)($stage['forwarded_at'] ?? '')),
            ];
        }

        usort($out, static fn(array $a, array $b): int => ((int)$a['stage']) <=> ((int)$b['stage']));
        return $out;
    }

    private function resolveCurrentApprovalStageNumber(array $payload, array $stages): int
    {
        $current = max(1, (int)($payload['approval_current_stage'] ?? 1));
        if ($stages === []) {
            return $current;
        }

        foreach ($stages as $stage) {
            if ((int)($stage['stage'] ?? 0) === $current) {
                return $current;
            }
        }

        return (int)($stages[0]['stage'] ?? 1);
    }

    private function findStoredApprovalStage(array $stages, int $stageNumber): ?array
    {
        foreach ($stages as $stage) {
            if ((int)($stage['stage'] ?? 0) === $stageNumber) {
                return $stage;
            }
        }
        return null;
    }

    private function updateStoredApprovalStage(array $stages, array $updatedStage): array
    {
        $targetStage = (int)($updatedStage['stage'] ?? 0);
        foreach ($stages as $idx => $stage) {
            if ((int)($stage['stage'] ?? 0) === $targetStage) {
                $stages[$idx] = $updatedStage;
                return $stages;
            }
        }
        $stages[] = $updatedStage;
        usort($stages, static fn(array $a, array $b): int => ((int)$a['stage']) <=> ((int)$b['stage']));
        return $stages;
    }

    private function appendLimitChangeApprovalHistory(array &$payload, array $entry): void
    {
        $history = $payload['approval_history'] ?? [];
        if (!is_array($history)) {
            $history = [];
        }
        $history[] = $entry;
        $payload['approval_history'] = array_values($history);
    }

    private function buildLimitChangeApprovalHistoryDisplay(array $payload, string $employeeGroup): array
    {
        $history = $payload['approval_history'] ?? [];
        if (!is_array($history) || $history === []) {
            return [];
        }

        $rows = [];
        foreach ($history as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $decision = strtolower(trim((string)($entry['decision'] ?? '')));
            if (!in_array($decision, ['approve', 'reject', 'forward'], true)) {
                continue;
            }

            $actedByUserId = (int)($entry['acted_by_user_id'] ?? 0);
            $approverSelection = trim((string)($entry['approver'] ?? ''));
            $forwardTo = trim((string)($entry['forward_to'] ?? ''));
            $rows[] = [
                'stage' => max(1, (int)($entry['stage'] ?? 1)),
                'decision' => $decision,
                'acted_at' => trim((string)($entry['acted_at'] ?? '')),
                'acted_by' => $actedByUserId > 0 ? $this->loadUserDisplayName($actedByUserId) : '',
                'approver_display' => $approverSelection !== '' ? $this->resolveApproverDisplayFromSelection($approverSelection, $employeeGroup) : '',
                'approver' => $approverSelection,
                'forward_to_display' => $forwardTo !== '' ? $this->resolveApproverDisplayFromSelection($forwardTo, $employeeGroup) : '',
                'forward_to' => $forwardTo,
                'reason' => trim((string)($entry['reason'] ?? '')),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $timeA = strtotime((string)($a['acted_at'] ?? '')) ?: 0;
            $timeB = strtotime((string)($b['acted_at'] ?? '')) ?: 0;
            if ($timeA !== $timeB) {
                return $timeB <=> $timeA;
            }
            return ((int)($b['stage'] ?? 0)) <=> ((int)($a['stage'] ?? 0));
        });

        return $rows;
    }

    /**
     * Optional view for Defence SES approvers.
     * Expected view columns:
     * - EmployeeGroup
     * - PositionNumber
     * - DisplayName
     * - Email
     */
    private function loadSesApproversFromView(string $employeeGroup): array
    {
        $employeeGroup = trim($employeeGroup);
        if (strcasecmp($employeeGroup, 'Defence') !== 0) {
            return [];
        }

        $rows = $this->loadAllSesApproversFromView();
        if (!$rows) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $grp = trim((string)($r['EmployeeGroup'] ?? ''));
            if ($grp !== '' && strcasecmp($grp, $employeeGroup) !== 0) {
                continue;
            }
            $out[] = $r;
        }

        return $out;
    }

    private function loadAllSesApproversFromView(): array
    {
        global $capsConn;
        if (!($capsConn instanceof \PDO)) {
            return [];
        }

        $exists = $capsConn->prepare("
            SELECT 1
            WHERE OBJECT_ID('dbo.vwWorkflowDefenceSesApprovers', 'V') IS NOT NULL
        ");
        $exists->execute();
        if (!(bool)$exists->fetchColumn()) {
            return [];
        }

        // Read all columns from the view and normalize in PHP to tolerate different view schemas.
        $sql = "
            SELECT *
            FROM dbo.vwWorkflowDefenceSesApprovers
        ";
        $st = $capsConn->prepare($sql);
        $st->execute();
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $pos = $this->firstNonEmpty($r, ['PositionNumber', 'PositionNo', 'Position', 'position_number']);
            if ($pos === '') {
                continue;
            }

            $grp = $this->firstNonEmpty($r, ['EmployeeGroup', 'GroupName', 'employee_group', 'group_name']);
            $display = $this->firstNonEmpty($r, ['DisplayName', 'ApproverName', 'Name', 'FullName', 'UserName', 'Username']);
            $email = $this->firstNonEmpty($r, ['Email', 'EmailAddress', 'ApproverEmail', 'email']);
            if ($display === '' && $email !== '') {
                $display = trim((string)strtok($email, '@'));
            }

            $out[] = [
                'EmployeeGroup' => $grp,
                'PositionNumber' => $pos,
                'DisplayName' => $display,
                'Email' => $email,
            ];
        }

        return $out;
    }

    private function firstNonEmpty(array $row, array $keys): string
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $row)) {
                continue;
            }
            $v = trim((string)$row[$k]);
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    private function buildApproverOptionsForRules(array $rules, array $directory, bool $fallbackToDirectory = false): array
    {
        $opts = [];
        $seen = [];
        $matchedRule = $rules !== [];

        foreach ($rules as $rule) {
            $type = strtoupper(trim((string)($rule['RequiredApproverType'] ?? '')));
            if ($type === '') {
                continue;
            }

            if (!empty($directory[$type])) {
                foreach ($directory[$type] as $row) {
                    $val = (string)($row['value'] ?? '');
                    if ($val === '' || isset($seen[$val])) {
                        continue;
                    }
                    $seen[$val] = true;
                    $opts[] = $row;
                }
                continue;
            }

            if ($type === 'SES') {
                // Try dedicated SES approver view first (e.g. Defence low-limit path).
                $sesGroup = trim((string)($rule['EmployeeGroup'] ?? ''));
                if ($sesGroup !== '') {
                    $sesRows = $this->loadSesApproversFromView($sesGroup);
                    foreach ($sesRows as $sr) {
                        $pos = trim((string)($sr['PositionNumber'] ?? ''));
                        if ($pos === '') {
                            continue;
                        }
                        $display = trim((string)($sr['DisplayName'] ?? ''));
                        $email = trim((string)($sr['Email'] ?? ''));
                        if ($display === '' && $email !== '') {
                            $display = trim((string)strtok($email, '@'));
                        }
                        $val = 'SES|' . $pos;
                        if (isset($seen[$val])) {
                            continue;
                        }
                        $seen[$val] = true;
                        $meta = 'SES - ' . $pos;
                        $label = ($display !== '' ? ($display . ' (' . $meta . ')') : $meta);
                        $opts[] = [
                            'value' => $val,
                            'label' => $label,
                            'type' => 'SES',
                            'position' => $pos,
                        ];
                    }
                    if (!empty($opts)) {
                        continue;
                    }
                }

                $rank = strtoupper(trim((string)($rule['RequiredRank'] ?? 'SES')));
                $val = 'SES|' . $rank;
                if (!isset($seen[$val])) {
                    $seen[$val] = true;
                    $opts[] = [
                        'value' => $val,
                        'label' => 'SES (' . $rank . ')',
                        'type' => 'SES',
                        'position' => null,
                    ];
                }
                continue;
            }

            $val = $type;
            if (!isset($seen[$val])) {
                $seen[$val] = true;
                $opts[] = [
                    'value' => $val,
                    'label' => $type,
                    'type' => $type,
                    'position' => null,
                ];
            }
        }

        // If no amount band matched (or rules are missing), still populate from approver positions table.
        if (!$matchedRule && $fallbackToDirectory && $directory) {
            foreach ($directory as $type => $rows) {
                foreach ($rows as $row) {
                    $val = (string)($row['value'] ?? '');
                    if ($val === '' || isset($seen[$val])) {
                        continue;
                    }
                    $seen[$val] = true;
                    $opts[] = $row;
                }
            }
        }

        return $opts;
    }

    private function buildApproverOptionsForAmount(array $rules, array $directory, float $amount): array
    {
        $matchedRules = $this->filterApprovalRulesForAmount($rules, $amount);
        return $this->buildApproverOptionsForRules($matchedRules, $directory, true);
    }

    private function loadCapsCompanies(): array
    {
        global $capsConn;
        if (!($capsConn instanceof \PDO)) {
            return [];
        }

        $stmt = $capsConn->prepare("
            SELECT DISTINCT CompanyCode
            FROM dbo.tblCAPSCostCentre
            WHERE CompanyCode IS NOT NULL AND LTRIM(RTRIM(CompanyCode)) <> ''
            ORDER BY CompanyCode
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $code = trim((string)($r['CompanyCode'] ?? ''));
            if ($code !== '') {
                $out[] = $code;
            }
        }
        return array_values(array_unique($out));
    }

    private function loadCapsCmsAccountHolders(string $employeeId = ''): array
    {
        $employeeId = trim((string)$employeeId);
        if ($employeeId === '') {
            return [];
        }

        global $capsConn;
        if (!($capsConn instanceof \PDO)) {
            return [];
        }

        $stmt = $capsConn->prepare("
            SELECT user_name
            FROM dbo.tblCAPSProMasterUser
            WHERE employee_id = :eid
              AND active_indicator = 'Y'
            ORDER BY user_name
        ");
        $stmt->execute(['eid' => $employeeId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $name = trim((string)($r['user_name'] ?? ''));
            if ($name === '') continue;
            $out[] = $name;
        }
        return $out;
    }

    private function loadUserEmail(int $userId): string
    {
        if ($userId <= 0) return '';
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
        if ($userId <= 0) {
            return '';
        }
        $stmt = $this->db->prepare("
            SELECT TOP 1
                LTRIM(RTRIM(ISNULL(NULLIF(DisplayName, ''), NULLIF(Username, '')))) AS DisplayName
            FROM dbo.tblUsers
            WHERE UserID = :uid
        ");
        $stmt->execute(['uid' => $userId]);
        return trim((string)($stmt->fetchColumn() ?? ''));
    }

    private function loadUserDisplayNameByEmployeeId(string $employeeId): string
    {
        $employeeId = trim($employeeId);
        if ($employeeId === '') {
            return '';
        }

        $stmt = $this->db->prepare("
            SELECT TOP 1
                LTRIM(RTRIM(ISNULL(NULLIF(DisplayName, ''), NULLIF(Username, '')))) AS DisplayName
            FROM dbo.tblUsers
            WHERE LTRIM(RTRIM(ISNULL(EmployeeID, ''))) = :employeeId
            ORDER BY UserID DESC
        ");
        $stmt->execute(['employeeId' => $employeeId]);
        return trim((string)($stmt->fetchColumn() ?? ''));
    }

    private function loadUserEmailByEmployeeId(string $employeeId): string
    {
        $employeeId = trim($employeeId);
        if ($employeeId === '') {
            return '';
        }

        $stmt = $this->db->prepare("
            SELECT TOP 1 LTRIM(RTRIM(ISNULL(Email, '')))
            FROM dbo.tblUsers
            WHERE LTRIM(RTRIM(ISNULL(EmployeeID, ''))) = :employeeId
            ORDER BY UserID DESC
        ");
        $stmt->execute(['employeeId' => $employeeId]);
        return trim((string)($stmt->fetchColumn() ?? ''));
    }

    private function resolveApplicantDisplayNameForApprovalRow(string $requestorName, string $requestorEmployeeId, string $applicantEmployeeId): string
    {
        $requestorEmployeeId = trim($requestorEmployeeId);
        $applicantEmployeeId = trim($applicantEmployeeId);
        if ($applicantEmployeeId === '' || strcasecmp($applicantEmployeeId, $requestorEmployeeId) === 0) {
            return trim($requestorName);
        }

        return $this->loadUserDisplayNameByEmployeeId($applicantEmployeeId);
    }

    private function resolveApplicantEmailForApprovalRow(string $requestorEmail, string $requestorEmployeeId, string $applicantEmployeeId): string
    {
        $requestorEmployeeId = trim($requestorEmployeeId);
        $applicantEmployeeId = trim($applicantEmployeeId);
        if ($applicantEmployeeId === '' || strcasecmp($applicantEmployeeId, $requestorEmployeeId) === 0) {
            return trim($requestorEmail);
        }

        return $this->loadUserEmailByEmployeeId($applicantEmployeeId);
    }

    private function sendAddressChangeConfirmation(int $userId, string $employeeId, ?array $card, array $payload): void
    {
        $email = $this->loadUserEmail($userId);
        if ($email === '') {
            return;
        }

        $displayName = (string)(SessionHelper::get('auth.display_name') ?? SessionHelper::get('auth.username') ?? 'User');
        $card = is_array($card) ? $card : [];
        $cardType = (string)($card['CardTypeSub'] ?? $card['CardType'] ?? '');
        $cardNumber = (string)($card['CardNumber'] ?? '');
        $cardNumberMasked = $cardNumber !== '' ? ('************' . substr($cardNumber, -4)) : '';
        $applyAll = !empty($payload['apply_all_cards']) ? 'Yes' : 'No';

        $addrLines = array_filter([
            trim((string)($payload['address1'] ?? '')),
            trim((string)($payload['address2'] ?? '')),
            trim((string)($payload['address3'] ?? '')),
        ], static fn($v) => $v !== '');
        $addressHtml = implode('<br>', array_map('htmlspecialchars', $addrLines));

        $suburb = htmlspecialchars((string)($payload['suburb'] ?? ''), ENT_QUOTES, 'UTF-8');
        $state = htmlspecialchars((string)($payload['state'] ?? ''), ENT_QUOTES, 'UTF-8');
        $postcode = htmlspecialchars((string)($payload['postcode'] ?? ''), ENT_QUOTES, 'UTF-8');
        $mobile = htmlspecialchars((string)($payload['mobile'] ?? ''), ENT_QUOTES, 'UTF-8');
        $phone = htmlspecialchars((string)($payload['phone'] ?? ''), ENT_QUOTES, 'UTF-8');

        $settings = new SystemSettingsModel($this->db);
        $appUrl = rtrim((string)($settings->get('APP_URL') ?? ''), '/');
        $appUrl = (string)preg_replace('#/backend-php/public$#i', '', $appUrl);
        $portalLink = $appUrl !== ''
            ? $appUrl . '/index.php?route=home/index'
            : 'index.php?route=home/index';

        $rendered = $this->renderEmailTemplate('address_change_submitted', [
            '{{display_name}}' => htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'),
            '{{employee_id}}' => htmlspecialchars($employeeId, ENT_QUOTES, 'UTF-8'),
            '{{card_type}}' => htmlspecialchars($cardType !== '' ? $cardType : '-', ENT_QUOTES, 'UTF-8'),
            '{{card_number_masked}}' => htmlspecialchars($cardNumberMasked !== '' ? $cardNumberMasked : '-', ENT_QUOTES, 'UTF-8'),
            '{{apply_all}}' => htmlspecialchars($applyAll, ENT_QUOTES, 'UTF-8'),
            '{{address_html}}' => $addressHtml !== '' ? $addressHtml : '-',
            '{{suburb}}' => $suburb,
            '{{state}}' => $state,
            '{{postcode}}' => $postcode,
            '{{mobile}}' => $mobile !== '' ? $mobile : '-',
            '{{phone}}' => $phone !== '' ? $phone : '-',
            '{{portal_link}}' => htmlspecialchars($portalLink, ENT_QUOTES, 'UTF-8'),
        ]);

        try {
            $mailer = new MailService($this->db);
            $mailer->sendEmail($email, $rendered['subject'], $rendered['body']);
        } catch (\Throwable $e) {
            error_log('[CardsController::sendAddressChangeConfirmation] ' . $e->getMessage());
        }
    }

    private function sendCardCancellationConfirmation(int $userId, string $employeeId, ?array $card, array $payload): void
    {
        $email = $this->loadUserEmail($userId);
        if ($email === '') {
            return;
        }

        $displayName = $this->loadUserDisplayName($userId);
        if ($displayName === '') {
            $displayName = (string)(SessionHelper::get('auth.display_name') ?? SessionHelper::get('auth.username') ?? 'User');
        }

        $card = is_array($card) ? $card : [];
        $cardType = trim((string)($card['CardTypeSub'] ?? $card['CardType'] ?? ($payload['card_type_sub'] ?? '')));
        $cardNumber = trim((string)($card['CardNumber'] ?? ($payload['card_number'] ?? '')));
        $cardLast4 = $cardNumber !== '' ? substr($cardNumber, -4) : '';
        $cancelReason = trim((string)($payload['reason'] ?? ''));
        $cancelReasonOther = trim((string)($payload['reason_other'] ?? ''));
        if (strcasecmp($cancelReason, 'Other') === 0 && $cancelReasonOther !== '') {
            $cancelReason = 'Other - ' . $cancelReasonOther;
        }
        $cancelDate = $this->formatEmailTokenDate((string)($payload['cancel_date'] ?? ''));
        $portalLink = $this->buildAbsoluteRouteUrl('home/index');

        $rendered = $this->renderEmailTemplate('card_cancel_submitted', [
            '{{display_name}}' => htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'),
            '{{employee_id}}' => htmlspecialchars($employeeId, ENT_QUOTES, 'UTF-8'),
            '{{card_type}}' => htmlspecialchars($cardType !== '' ? $cardType : '-', ENT_QUOTES, 'UTF-8'),
            '{{card_last4}}' => htmlspecialchars($cardLast4 !== '' ? $cardLast4 : '-', ENT_QUOTES, 'UTF-8'),
            '{{cancel_date}}' => htmlspecialchars($cancelDate !== '' ? $cancelDate : '-', ENT_QUOTES, 'UTF-8'),
            '{{cancel_reason}}' => htmlspecialchars($cancelReason !== '' ? $cancelReason : '-', ENT_QUOTES, 'UTF-8'),
            '{{portal_link}}' => htmlspecialchars($portalLink, ENT_QUOTES, 'UTF-8'),
        ]);

        $mailer = new MailService($this->db);
        $ok = $mailer->sendEmail($email, $rendered['subject'], $rendered['body']);
        if (!$ok) {
            error_log('[CardsController::sendCardCancellationConfirmation] MailService returned false for to=' . $email . ', employee=' . $employeeId);
        }
    }

    private function resolveLimitChangeApprovalRecipients(array $payload, string $employeeGroup): array
    {
        $stages = $this->normalizeStoredApprovalStages($payload);
        $currentStageNumber = $this->resolveCurrentApprovalStageNumber($payload, $stages);
        $currentStage = $this->findStoredApprovalStage($stages, $currentStageNumber);
        $recipients = [];

        if ($currentStage !== null) {
            $candidateApprovers = is_array($currentStage['candidate_approvers'] ?? null)
                ? $currentStage['candidate_approvers']
                : [];
            foreach ($candidateApprovers as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $email = $this->normalizeComparableEmailValue($candidate['email'] ?? '');
                if ($email === null || isset($recipients[$email])) {
                    continue;
                }
                $recipients[$email] = [
                    'email' => $email,
                    'display_name' => trim((string)($candidate['label'] ?? $email)),
                ];
            }
        }

        if ($recipients !== []) {
            return array_values($recipients);
        }

        $approver = $this->parseApproverSelection((string)($payload['approver'] ?? ''));
        $approverType = strtoupper(trim((string)($approver['type'] ?? '')));
        $position = trim((string)($approver['position'] ?? ''));
        if ($approverType === '') {
            return [];
        }

        $contact = $this->resolveApproverContact($approverType, $position, $employeeGroup);
        $toEmail = $this->normalizeComparableEmailValue((string)($contact['email'] ?? ''));
        if ($toEmail === null) {
            return [];
        }

        return [[
            'email' => $toEmail,
            'display_name' => trim((string)($contact['display_name'] ?? $toEmail)),
        ]];
    }

    private function sendLimitChangeApprovalEmail(int $requestorUserId, int $applicationId, array $payload, string $employeeGroup): void
    {
        if ($applicationId <= 0) {
            error_log('[CardsController::sendLimitChangeApprovalEmail] Skip: invalid application id');
            return;
        }

        $recipients = $this->resolveLimitChangeApprovalRecipients($payload, $employeeGroup);
        if ($recipients === []) {
            error_log('[CardsController::sendLimitChangeApprovalEmail] Skip: no approval recipients resolved for application=' . $applicationId);
            return;
        }

        $requestorName = $this->loadUserDisplayName($requestorUserId);
        if ($requestorName === '') {
            $requestorName = (string)(SessionHelper::get('auth.display_name') ?? SessionHelper::get('auth.username') ?? 'User');
        }
        $creditNew = trim((string)($payload['credit_limit_new'] ?? ''));
        $txnNew = $this->resolveTransactionLimitDisplayValue($payload);
        $reason = trim((string)($payload['limit_change_reason'] ?? ''));

        $approvalLink = $this->buildAbsoluteRouteUrl(
            'cards/limit-change-approve&id=' . urlencode((string)$applicationId)
        );

        $rendered = $this->renderEmailTemplate('limit_change_approval_required', array_merge(
            $this->buildLimitChangeEmailTokens($applicationId, $payload),
            [
                '{{requestor_name}}' => htmlspecialchars($requestorName, ENT_QUOTES, 'UTF-8'),
                '{{employee_group}}' => htmlspecialchars($employeeGroup !== '' ? $employeeGroup : '-', ENT_QUOTES, 'UTF-8'),
                '{{credit_limit_new}}' => htmlspecialchars($creditNew !== '' ? $creditNew : '-', ENT_QUOTES, 'UTF-8'),
                '{{transaction_limit_new}}' => htmlspecialchars($txnNew !== '' ? $txnNew : '-', ENT_QUOTES, 'UTF-8'),
                '{{reason}}' => htmlspecialchars($reason !== '' ? $reason : '-', ENT_QUOTES, 'UTF-8'),
                '{{approval_link}}' => htmlspecialchars($approvalLink, ENT_QUOTES, 'UTF-8'),
            ]
        ), [
            'application_type_key' => (string)($payload['type_key'] ?? ''),
        ]);

        $mailer = new MailService($this->db);
        foreach ($recipients as $recipient) {
            $toEmail = trim((string)($recipient['email'] ?? ''));
            if ($toEmail === '') {
                continue;
            }
            $ok = $mailer->sendEmail($toEmail, $rendered['subject'], $rendered['body']);
            if (!$ok) {
                error_log('[CardsController::sendLimitChangeApprovalEmail] MailService returned false for to=' . $toEmail . ', application=' . $applicationId);
            }
        }
    }

    private function rememberLimitChangeApprovalRoute(int $applicationId): void
    {
        if ($applicationId <= 0) {
            return;
        }

        SessionHelper::set('auth.intended_route', 'cards/limit-change-approve&id=' . $applicationId);
    }

    private function sendLimitChangeSubmittedConfirmation(int $userId, int $applicationId, array $payload, ?array $card): void
    {
        if ($applicationId <= 0) {
            return;
        }

        $toEmail = $this->loadUserEmail($userId);
        if ($toEmail === '') {
            return;
        }

        $displayName = $this->loadUserDisplayName($userId);
        if ($displayName === '') {
            $displayName = (string)(SessionHelper::get('auth.display_name') ?? SessionHelper::get('auth.username') ?? 'User');
        }

        $card = is_array($card) ? $card : [];
        $cardType = trim((string)($card['CardTypeSub'] ?? ($card['CardType'] ?? ($payload['card_type_sub'] ?? ($payload['card_type'] ?? '')))));
        $cardNumber = trim((string)($card['CardNumber'] ?? ($payload['card_number'] ?? '')));
        $cardLast4 = $cardNumber !== '' ? substr($cardNumber, -4) : '';
        $applicationLink = $this->buildAbsoluteRouteUrl(
            'cards/request-limit-change&application_id=' . urlencode((string)$applicationId)
        );

        $txnNew = $this->resolveTransactionLimitDisplayValue($payload);

        $rendered = $this->renderEmailTemplate('limit_change_submitted', array_merge(
            $this->buildLimitChangeEmailTokens($applicationId, $payload, $card),
            [
                '{{display_name}}' => htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'),
                '{{card_type}}' => htmlspecialchars($cardType !== '' ? $cardType : '-', ENT_QUOTES, 'UTF-8'),
                '{{card_last4}}' => htmlspecialchars($cardLast4 !== '' ? $cardLast4 : '-', ENT_QUOTES, 'UTF-8'),
                '{{credit_limit_new}}' => htmlspecialchars((string)($payload['credit_limit_new'] ?? '-'), ENT_QUOTES, 'UTF-8'),
                '{{transaction_limit_new}}' => htmlspecialchars($txnNew !== '' ? $txnNew : '-', ENT_QUOTES, 'UTF-8'),
                '{{application_link}}' => htmlspecialchars($applicationLink, ENT_QUOTES, 'UTF-8'),
            ]
        ), [
            'application_type_key' => (string)($payload['type_key'] ?? ''),
        ]);

        $mailer = new MailService($this->db);
        $ok = $mailer->sendEmail($toEmail, $rendered['subject'], $rendered['body']);
        if (!$ok) {
            error_log('[CardsController::sendLimitChangeSubmittedConfirmation] MailService returned false for to=' . $toEmail . ', application=' . $applicationId);
        }
    }

    private function resolveApproverContact(string $approverType, string $position, string $employeeGroup): array
    {
        $approverType = strtoupper(trim($approverType));
        $position = trim($position);
        $employeeGroup = trim($employeeGroup);

        if ($approverType === 'EMAIL') {
            return [
                'email' => strtolower($position),
                'display_name' => strtolower($position),
                'position' => '',
            ];
        }

        // Defence SES can come from CAPS view.
        if ($approverType === 'SES' && strcasecmp($employeeGroup, 'Defence') === 0) {
            $sesRows = $this->loadSesApproversFromView($employeeGroup);
            foreach ($sesRows as $r) {
                $pos = trim((string)($r['PositionNumber'] ?? ''));
                if ($position !== '' && $pos !== '' && $pos !== $position) {
                    continue;
                }
                return [
                    'email' => trim((string)($r['Email'] ?? '')),
                    'display_name' => trim((string)($r['DisplayName'] ?? '')),
                    'position' => $pos,
                ];
            }
        }

        $sql = "
            SELECT TOP 1 Email, DisplayName, PositionNumber
            FROM dbo.tblWorkflowApproverPositions
            WHERE IsActive = 1
              AND UPPER(ApproverType) = :atype
              AND (
                    LOWER(ISNULL(EmployeeGroup, '')) = LOWER(:grp_match)
                    OR EmployeeGroup IS NULL
                  )
              AND (:pos_match = '' OR PositionNumber = :pos_filter)
            ORDER BY
              CASE WHEN LOWER(ISNULL(EmployeeGroup, '')) = LOWER(:grp_order) THEN 0 ELSE 1 END,
              ApproverPositionID ASC
        ";
        $st = $this->db->prepare($sql);
        $st->execute([
            'atype' => $approverType,
            'grp_match' => $employeeGroup,
            'grp_order' => $employeeGroup,
            'pos_match' => $position,
            'pos_filter' => $position,
        ]);
        $row = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'email' => trim((string)($row['Email'] ?? '')),
            'display_name' => trim((string)($row['DisplayName'] ?? '')),
            'position' => trim((string)($row['PositionNumber'] ?? $position)),
        ];
    }

    private function resolveLimitChangeApplicantEmail(int $userId, string $employeeId, array $payload, ?array $card = null): string
    {
        $email = $this->normalizeComparableEmailValue($this->firstNonEmpty((array)$payload, ['email', 'applicant_email', 'cardholder_email']));
        if ($email !== null) {
            return $email;
        }

        if (is_array($card)) {
            $email = $this->normalizeComparableEmailValue($this->firstNonEmpty($card, ['Email', 'Email_Address']));
            if ($email !== null) {
                return $email;
            }
        }

        $employeeId = trim($employeeId);
        global $capsConn;
        if ($employeeId !== '' && ($capsConn instanceof \PDO)) {
            try {
                $st = $capsConn->prepare("
                    SELECT TOP 1 NULLIF(LTRIM(RTRIM(Email_Address)), '')
                    FROM dbo.tblCAPSCDMCPortal
                    WHERE EmployeeID = :eid
                ");
                $st->execute(['eid' => $employeeId]);
                $email = $this->normalizeComparableEmailValue((string)($st->fetchColumn() ?? ''));
                if ($email !== null) {
                    return $email;
                }
            } catch (\Throwable $e) {
                error_log('[CardsController::resolveLimitChangeApplicantEmail] ' . $e->getMessage());
            }
        }

        return $this->normalizeComparableEmailValue($this->loadUserEmail($userId)) ?? '';
    }

    private function resolveLimitChangeApplicantEmployeeId(int $userId, string $employeeId, array $payload, ?array $card = null): string
    {
        $employeeId = trim($employeeId);
        if ($employeeId !== '') {
            return $employeeId;
        }

        $payloadEmployeeId = trim((string)($payload['target_employee_id'] ?? ''));
        if ($payloadEmployeeId !== '') {
            return $payloadEmployeeId;
        }

        if (is_array($card)) {
            $cardEmployeeId = trim((string)($card['EmployeeID'] ?? ''));
            if ($cardEmployeeId !== '') {
                return $cardEmployeeId;
            }
        }

        return $this->resolveEmployeeId($userId);
    }

    private function loadCapsEmployeeIdByEmail(string $email): string
    {
        $email = $this->normalizeComparableEmailValue($email);
        if ($email === null) {
            return '';
        }

        global $capsConn;
        if (!($capsConn instanceof \PDO)) {
            return '';
        }

        try {
            $st = $capsConn->prepare("
                SELECT TOP 1 NULLIF(LTRIM(RTRIM(EmployeeID)), '')
                FROM dbo.tblCAPSCDMCPortal
                WHERE LOWER(LTRIM(RTRIM(ISNULL(Email_Address, '')))) = :email
            ");
            $st->execute(['email' => $email]);
            return trim((string)($st->fetchColumn() ?? ''));
        } catch (\Throwable $e) {
            error_log('[CardsController::loadCapsEmployeeIdByEmail] ' . $e->getMessage());
            return '';
        }
    }

    private function isApplicantApproverByEmail(int $userId, string $employeeId, array $payload, ?array $card, string $approverEmail): bool
    {
        $approverEmail = $this->normalizeComparableEmailValue($approverEmail);
        if ($approverEmail === null) {
            return false;
        }

        $applicantEmail = $this->resolveLimitChangeApplicantEmail($userId, $employeeId, $payload, $card);
        if ($applicantEmail !== '' && hash_equals($applicantEmail, $approverEmail)) {
            return true;
        }

        $applicantEmployeeId = strtolower(trim($this->resolveLimitChangeApplicantEmployeeId($userId, $employeeId, $payload, $card)));
        $matchedEmployeeId = strtolower(trim($this->loadCapsEmployeeIdByEmail($approverEmail)));
        return $applicantEmployeeId !== '' && $matchedEmployeeId !== '' && hash_equals($applicantEmployeeId, $matchedEmployeeId);
    }

    private function isApplicantApproverSelection(int $userId, string $employeeId, array $payload, ?array $card, string $selection, string $employeeGroup): bool
    {
        $parsed = $this->parseApproverSelection($selection);
        $type = strtoupper(trim((string)($parsed['type'] ?? '')));
        if ($type === '') {
            return false;
        }

        $email = '';
        if ($type === 'EMAIL') {
            $email = (string)($parsed['email'] ?? '');
        } else {
            $contact = $this->resolveApproverContact($type, trim((string)($parsed['position'] ?? '')), $employeeGroup);
            $email = (string)($contact['email'] ?? '');
        }

        return $this->isApplicantApproverByEmail($userId, $employeeId, $payload, $card, $email);
    }

    private function resolveApproverDisplayFromSelection(string $selection, string $employeeGroup): string
    {
        $selection = trim($selection);
        if ($selection === '') {
            return '';
        }
        $p = $this->parseApproverSelection($selection);
        $type = strtoupper(trim((string)($p['type'] ?? '')));
        $position = trim((string)($p['position'] ?? ''));
        if ($type === '') {
            return $selection;
        }
        if ($type === 'EMAIL') {
            return trim((string)($p['email'] ?? $position));
        }

        $contact = $this->resolveApproverContact($type, $position, $employeeGroup);
        $name = trim((string)($contact['display_name'] ?? ''));
        $pos = trim((string)($contact['position'] ?? $position));
        if ($name !== '') {
            return $pos !== '' ? ($name . ' (' . $type . ' - ' . $pos . ')') : $name;
        }
        return $selection;
    }

    private function resolveLimitChangeCurrentApproverDisplay(array $payload, string $employeeGroup): string
    {
        $display = $this->resolveApproverDisplayFromSelection((string)($payload['approver'] ?? ''), $employeeGroup);
        if ($display !== '') {
            return $display;
        }

        $stages = $this->normalizeStoredApprovalStages($payload);
        $currentStageNumber = $this->resolveCurrentApprovalStageNumber($payload, $stages);
        $currentStage = $this->findStoredApprovalStage($stages, $currentStageNumber);
        if ($currentStage === null) {
            return '';
        }

        $candidateApprovers = is_array($currentStage['candidate_approvers'] ?? null)
            ? array_values(array_filter($currentStage['candidate_approvers'], static fn($row): bool => is_array($row)))
            : [];
        $count = count($candidateApprovers);
        if ($count <= 0) {
            return '';
        }
        if ($count === 1) {
            $candidate = $candidateApprovers[0];
            return trim((string)($candidate['label'] ?? $candidate['value'] ?? ''));
        }

        return 'Multiple approvers notified (' . $count . ')';
    }

    private function isCurrentUserAssignedApprover(int $userId, array $payload, string $employeeGroup): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $userEmail = strtolower(trim($this->loadUserEmail($userId)));
        if ($userEmail === '') {
            return false;
        }

        $stages = $this->normalizeStoredApprovalStages($payload);
        $currentStageNumber = $this->resolveCurrentApprovalStageNumber($payload, $stages);
        $currentStage = $this->findStoredApprovalStage($stages, $currentStageNumber);
        if ($currentStage !== null) {
            $candidateApprovers = is_array($currentStage['candidate_approvers'] ?? null)
                ? $currentStage['candidate_approvers']
                : [];
            foreach ($candidateApprovers as $candidate) {
                $candidateEmail = strtolower(trim((string)($candidate['email'] ?? '')));
                if ($candidateEmail !== '' && hash_equals($candidateEmail, $userEmail)) {
                    return true;
                }
            }
        }

        $selection = trim((string)($payload['approver'] ?? ''));
        if ($selection === '') {
            return false;
        }
        $p = $this->parseApproverSelection($selection);
        $type = strtoupper(trim((string)($p['type'] ?? '')));
        $position = trim((string)($p['position'] ?? ''));
        if ($type === '') {
            return false;
        }

        $contact = $this->resolveApproverContact($type, $position, $employeeGroup);
        $approverEmail = strtolower(trim((string)($contact['email'] ?? '')));
        if ($approverEmail === '') {
            return false;
        }

        return hash_equals($approverEmail, $userEmail);
    }

    private function isLimitChangeSelfRequest(int $userId, array $app, array $payload, ?array $card = null): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if ((int)($app['UserID'] ?? 0) === $userId) {
            return true;
        }

        $currentEmployeeId = strtolower(trim($this->resolveEmployeeId($userId)));
        $appEmployeeId = strtolower(trim((string)($app['EmployeeID'] ?? '')));
        $targetEmployeeId = strtolower(trim((string)($payload['target_employee_id'] ?? '')));
        $cardEmployeeId = strtolower(trim((string)($card['EmployeeID'] ?? '')));
        foreach ([$appEmployeeId, $targetEmployeeId, $cardEmployeeId] as $candidateEmployeeId) {
            if ($currentEmployeeId !== '' && $candidateEmployeeId !== '' && hash_equals($currentEmployeeId, $candidateEmployeeId)) {
                return true;
            }
        }

        $currentUserEmail = $this->normalizeComparableEmailValue($this->loadUserEmail($userId));
        $applicantEmail = $this->normalizeComparableEmailValue(
            $this->resolveLimitChangeApplicantEmail(
                $userId,
                (string)($app['EmployeeID'] ?? ($payload['target_employee_id'] ?? '')),
                $payload,
                $card
            )
        );

        return $currentUserEmail !== null
            && $currentUserEmail !== ''
            && $applicantEmail !== null
            && $applicantEmail !== ''
            && hash_equals($currentUserEmail, $applicantEmail);
    }

    private function resolveLimitChangeDecisionRecipients(int $userId, array $payload, string $decision, ?array $card = null): array
    {
        $recipients = [];

        $requestorEmail = $this->normalizeComparableEmailValue($this->loadUserEmail($userId));
        if ($requestorEmail !== null) {
            $recipients[$requestorEmail] = $requestorEmail;
        }

        $targetEmployeeId = trim((string)($payload['target_employee_id'] ?? ''));
        $applicantEmail = $this->normalizeComparableEmailValue(
            $this->resolveLimitChangeApplicantEmail($userId, $targetEmployeeId, $payload, $card)
        );
        if ($applicantEmail !== null) {
            $recipients[$applicantEmail] = $applicantEmail;
        }

        if (strtolower(trim($decision)) !== 'approve') {
            return array_values($recipients);
        }

        $stages = $this->normalizeStoredApprovalStages($payload);
        foreach ($stages as $stage) {
            $candidateApprovers = is_array($stage['candidate_approvers'] ?? null)
                ? array_values(array_filter($stage['candidate_approvers'], static fn($row): bool => is_array($row)))
                : [];
            foreach ($candidateApprovers as $candidate) {
                $email = $this->normalizeComparableEmailValue($candidate['email'] ?? '');
                if ($email === null) {
                    continue;
                }
                $recipients[$email] = $email;
            }
        }

        if (count($recipients) > 1) {
            return array_values($recipients);
        }

        $employeeGroup = trim((string)($payload['employee_group'] ?? ''));
        foreach ($this->resolveLimitChangeApprovalRecipients($payload, $employeeGroup) as $recipient) {
            $email = $this->normalizeComparableEmailValue($recipient['email'] ?? '');
            if ($email === null) {
                continue;
            }
            $recipients[$email] = $email;
        }

        return array_values($recipients);
    }

    private function sendLimitChangeDecisionEmail(int $applicationId, string $decision, string $rejectReason = ''): void
    {
        $decision = strtolower(trim($decision));
        if ($applicationId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
            return;
        }

        $stmt = $this->db->prepare("
            SELECT a.ApplicationID, a.UserID, a.ApplicationTypeID, at.ApplicationTypeKey
            FROM dbo.tblApplications a
            LEFT JOIN dbo.tblApplicationTypes at ON at.ApplicationTypeID = a.ApplicationTypeID
            WHERE a.ApplicationID = :aid
        ");
        $stmt->execute(['aid' => $applicationId]);
        $app = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$app) {
            return;
        }
        $payload = $this->loadPayload($this->db, $applicationId);
        $cardId = (int)($payload['card_id'] ?? 0);
        $card = $cardId > 0 ? $this->loadPortalCardById($cardId) : null;

        $userId = (int)($app['UserID'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $recipients = $this->resolveLimitChangeDecisionRecipients($userId, $payload, $decision, $card);
        if ($recipients === []) {
            error_log('[CardsController::sendLimitChangeDecisionEmail] Skip: applicant email missing for user=' . $userId);
            return;
        }

        $displayName = trim((string)(SessionHelper::get('auth.display_name') ?? SessionHelper::get('auth.username') ?? ''));
        $actor = $displayName !== '' ? $displayName : 'Approver';
        $decisionText = $decision === 'approve' ? 'approved' : 'rejected';

        $typeKey = trim((string)($payload['type_key'] ?? ''));
        if ($typeKey !== '' && $cardId > 0) {
            $link = $this->buildAbsoluteRouteUrl(
                'cards/request-limit-change&type=' . urlencode($typeKey)
                . '&id=' . urlencode((string)$cardId)
                . '&application_id=' . urlencode((string)$applicationId)
            );
        } else {
            $link = $this->buildAbsoluteRouteUrl('cards/request-limit-change&application_id=' . urlencode((string)$applicationId));
        }
        $extra = '';
        if ($decision === 'reject' && trim($rejectReason) !== '') {
            $extra = '<p><strong>Rejection reason:</strong> ' . htmlspecialchars(trim($rejectReason), ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $rendered = $this->renderEmailTemplate('limit_change_decision', array_merge(
            $this->buildLimitChangeEmailTokens($applicationId, $payload, $card),
            [
                '{{decision_text}}' => htmlspecialchars($decisionText, ENT_QUOTES, 'UTF-8'),
                '{{decision_text_ucfirst}}' => htmlspecialchars(ucfirst($decisionText), ENT_QUOTES, 'UTF-8'),
                '{{actor}}' => htmlspecialchars($actor, ENT_QUOTES, 'UTF-8'),
                '{{decision_extra_html}}' => $extra,
                '{{application_link}}' => htmlspecialchars($link, ENT_QUOTES, 'UTF-8'),
            ]
        ), [
            'application_type_id' => (int)($app['ApplicationTypeID'] ?? 0),
            'application_type_key' => (string)($payload['type_key'] ?? ''),
        ]);

        $mailer = new MailService($this->db);
        foreach ($recipients as $toEmail) {
            $ok = $mailer->sendEmail($toEmail, $rendered['subject'], $rendered['body']);
            if (!$ok) {
                error_log('[CardsController::sendLimitChangeDecisionEmail] MailService returned false for to=' . $toEmail . ', application=' . $applicationId);
            }
        }
    }

    private function sendOnBehalfOwnerSubmittedEmail(int $requestorUserId, int $applicationId, array $payload, ?array $card): array
    {
        $onBehalfFlag = trim((string)($payload['on_behalf'] ?? '')) === '1';
        $targetEmployeeId = trim((string)($payload['target_employee_id'] ?? ''));
        if (!$onBehalfFlag && $targetEmployeeId === '') {
            error_log('[CardsController::sendOnBehalfOwnerSubmittedEmail] Skip: target_employee_id missing');
            return ['attempted' => false, 'sent_to' => '', 'reason' => 'target employee not provided'];
        }

        $requestorEmployeeId = trim((string)SessionHelper::get('auth.employee_id', ''));
        if ($requestorEmployeeId === '') {
            $requestorEmployeeId = $this->resolveEmployeeId($requestorUserId);
        }
        if (!$onBehalfFlag && ($requestorEmployeeId === '' || strcasecmp($requestorEmployeeId, $targetEmployeeId) === 0)) {
            // Not an on-behalf submission.
            error_log('[CardsController::sendOnBehalfOwnerSubmittedEmail] Skip: not on-behalf, requestor=' . $requestorEmployeeId . ', target=' . $targetEmployeeId);
            return ['attempted' => false, 'sent_to' => '', 'reason' => 'not on-behalf submission'];
        }

        $cardId = (int)($payload['card_id'] ?? 0);
        if ($cardId <= 0) {
            return ['attempted' => true, 'sent_to' => '', 'reason' => 'card id missing'];
        }

        if ($targetEmployeeId === '') {
            $stEmp = $this->db->prepare("
                SELECT TOP 1 EmployeeID
                FROM dbo.tblPORTALCards
                WHERE CardID = :cid
            ");
            $stEmp->execute(['cid' => $cardId]);
            $targetEmployeeId = trim((string)($stEmp->fetchColumn() ?? ''));
        }

        $ownerEmail = '';
        if (is_array($card)) {
            $ownerEmail = trim((string)($card['Email'] ?? ($card['Email_Address'] ?? '')));
        }
        if ($ownerEmail === '') {
            $st = $this->db->prepare("
                SELECT TOP 1
                    ISNULL(NULLIF(LTRIM(RTRIM(Email)),''), NULLIF(LTRIM(RTRIM(Email_Address)),'')) AS OwnerEmail
                FROM dbo.tblPORTALCards
                WHERE CardID = :cid
            ");
            $st->execute(['cid' => $cardId]);
            $ownerEmail = trim((string)($st->fetchColumn() ?? ''));
        }
        if ($ownerEmail === '') {
            error_log('[CardsController::sendOnBehalfOwnerSubmittedEmail] Skip: owner email missing for CardID=' . $cardId);
            return ['attempted' => true, 'sent_to' => '', 'reason' => 'owner email missing in tblPORTALCards'];
        }

        $requestorName = (string)(SessionHelper::get('auth.display_name') ?? SessionHelper::get('auth.username') ?? 'User');
        $cardType = trim((string)($payload['card_type'] ?? ($card['CardType'] ?? '')));
        $last4 = '';
        $cardNo = trim((string)($card['CardNumber'] ?? ($payload['card_number'] ?? '')));
        if ($cardNo !== '') {
            $last4 = substr($cardNo, -4);
        }

        $rendered = $this->renderEmailTemplate('limit_change_on_behalf_submitted', array_merge(
            $this->buildLimitChangeEmailTokens($applicationId, $payload, $card),
            [
                '{{requestor_name}}' => htmlspecialchars($requestorName, ENT_QUOTES, 'UTF-8'),
                '{{target_employee_id}}' => htmlspecialchars($targetEmployeeId, ENT_QUOTES, 'UTF-8'),
                '{{card_type}}' => htmlspecialchars($cardType !== '' ? $cardType : '-', ENT_QUOTES, 'UTF-8'),
                '{{card_last4}}' => htmlspecialchars($last4 !== '' ? $last4 : '-', ENT_QUOTES, 'UTF-8'),
            ]
        ), [
            'application_type_key' => (string)($payload['type_key'] ?? ''),
        ]);

        $mailer = new MailService($this->db);
        $ok = $mailer->sendEmail($ownerEmail, $rendered['subject'], $rendered['body']);
        if (!$ok) {
            error_log('[CardsController::sendOnBehalfOwnerSubmittedEmail] MailService returned false for to=' . $ownerEmail . ', application=' . $applicationId);
            return ['attempted' => true, 'sent_to' => $ownerEmail, 'reason' => 'mailer returned false'];
        }
        return ['attempted' => true, 'sent_to' => $ownerEmail, 'reason' => ''];
    }

    private function renderEmailTemplate(string $templateId, array $tokens, array $context = []): array
    {
        try {
            $service = new EmailTemplateService($this->db);
            return $service->renderTemplate($templateId, $tokens, $context);
        } catch (\Throwable $e) {
            error_log('[CardsController::renderEmailTemplate] ' . $e->getMessage());
            return [
                'subject' => '',
                'body' => '',
            ];
        }
    }

    private function buildLimitChangeEmailTokens(int $applicationId, array $payload, ?array $card = null): array
    {
        $card = is_array($card) ? $card : [];
        $payload = $this->normalizeLimitChangePeriodPayload($payload, $this->isDtcCardType((string)($card['CardType'] ?? ($payload['card_type'] ?? ''))));
        $employeeGroup = trim((string)($payload['employee_group'] ?? ''));
        $reason = trim((string)($payload['limit_change_reason'] ?? ''));
        $reasonOther = trim((string)($payload['limit_change_reason_other'] ?? ''));
        $creditDurationType = strtolower(trim((string)($payload['credit_limit_change_duration_type'] ?? 'permanent')));
        $currentCreditLimit = trim((string)($payload['credit_limit_current'] ?? ($this->firstNonEmpty($card, ['ActiveCeiling', 'CreditLimitAmount', 'CreditLimit']) ?? '')));
        $creditLimitNew = trim((string)($payload['credit_limit_new'] ?? ''));
        $approver = $this->resolveLimitChangeCurrentApproverDisplay($payload, $employeeGroup);

        return [
            '{{application_id}}' => htmlspecialchars((string)$applicationId, ENT_QUOTES, 'UTF-8'),
            '{{application_date_submitted}}' => htmlspecialchars($this->loadApplicationSubmittedAtLabel($applicationId), ENT_QUOTES, 'UTF-8'),
            '{{credit_limit_new}}' => htmlspecialchars($creditLimitNew !== '' ? $creditLimitNew : '-', ENT_QUOTES, 'UTF-8'),
            '{{credit_limit_change_type}}' => htmlspecialchars($this->formatLimitChangeDurationTypeLabel($creditDurationType), ENT_QUOTES, 'UTF-8'),
            '{{current_credit_limit}}' => htmlspecialchars($currentCreditLimit !== '' ? $currentCreditLimit : '-', ENT_QUOTES, 'UTF-8'),
            '{{approver}}' => htmlspecialchars($approver !== '' ? $approver : '-', ENT_QUOTES, 'UTF-8'),
            '{{justification_reason}}' => htmlspecialchars($reason !== '' ? $reason : '-', ENT_QUOTES, 'UTF-8'),
            '{{additional_justification}}' => htmlspecialchars($reasonOther !== '' ? $reasonOther : '-', ENT_QUOTES, 'UTF-8'),
            '{{credit_period_change_from}}' => htmlspecialchars($this->formatEmailTokenDate((string)($payload['credit_period_change_from'] ?? '')), ENT_QUOTES, 'UTF-8'),
            '{{credit_period_change_to}}' => htmlspecialchars($this->formatEmailTokenDate((string)($payload['credit_period_change_to'] ?? '')), ENT_QUOTES, 'UTF-8'),
            '{{transaction_period_change_from}}' => htmlspecialchars($this->formatEmailTokenDate((string)($payload['transaction_period_change_from'] ?? '')), ENT_QUOTES, 'UTF-8'),
            '{{transaction_period_change_to}}' => htmlspecialchars($this->formatEmailTokenDate((string)($payload['transaction_period_change_to'] ?? '')), ENT_QUOTES, 'UTF-8'),
            '{{application_status}}' => htmlspecialchars($this->loadApplicationStatusLabel($applicationId), ENT_QUOTES, 'UTF-8'),
            '{{approved_at}}' => htmlspecialchars($this->formatEmailTokenDateTime((string)($payload['approved_at'] ?? '')), ENT_QUOTES, 'UTF-8'),
        ];
    }

    private function formatLimitChangeDurationTypeLabel(string $value): string
    {
        return match (strtolower(trim($value))) {
            'temporary' => 'Temporary',
            default => 'Permanent',
        };
    }

    private function formatEmailTokenDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '-';
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value) {
            return $date->format('d/m/Y');
        }

        try {
            return (new \DateTimeImmutable($value))->format('d/m/Y');
        } catch (\Throwable $e) {
            return $value;
        }
    }

    private function formatEmailTokenDateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '-';
        }

        try {
            $dt = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            return $dt->setTimezone(new \DateTimeZone((string)date_default_timezone_get()))->format('d/m/Y g:i A');
        } catch (\Throwable $e) {
            return $value;
        }
    }

    private function loadApplicationStatusLabel(int $applicationId): string
    {
        if ($applicationId <= 0) {
            return '-';
        }

        $stmt = $this->db->prepare("
            SELECT TOP 1 Status
            FROM dbo.tblApplications
            WHERE ApplicationID = :application_id
        ");
        $stmt->execute(['application_id' => $applicationId]);
        $status = trim((string)($stmt->fetchColumn() ?? ''));
        if ($status === '') {
            return '-';
        }

        return match (strtolower($status)) {
            'tobeapproved' => 'To Be Approved',
            'senttobank' => 'Sent To Bank',
            'sent_to_bank' => 'Sent To Bank',
            'limitchanged' => 'Limit Changed',
            'limit_changed' => 'Limit Changed',
            default => ucwords(str_replace('_', ' ', $status)),
        };
    }

    private function loadApplicationSubmittedAtLabel(int $applicationId): string
    {
        if ($applicationId <= 0) {
            return '-';
        }

        $stmt = $this->db->prepare("
            SELECT TOP 1 SubmittedAt
            FROM dbo.tblApplications
            WHERE ApplicationID = :application_id
        ");
        $stmt->execute(['application_id' => $applicationId]);
        return $this->formatEmailTokenDateTime((string)($stmt->fetchColumn() ?? ''));
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
            error_log('[CardsController::auditLog] ' . $e->getMessage());
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
            $basePath = (string)preg_replace('#/backend-php/public$#i', '', $basePath);
            return $scheme . '://' . $host . $basePath . '/' . $query;
        }

        // Fallback to configured APP_URL if server vars are unavailable.
        $settings = new SystemSettingsModel($this->db);
        $appUrl = rtrim((string)($settings->get('APP_URL') ?? ''), '/');
        if ($appUrl !== '') {
            $appUrl = (string)preg_replace('#/backend-php/public$#i', '', $appUrl);
            return $appUrl . '/' . $query;
        }

        return $query;
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
            error_log('[CardsController::getSubmitAgreementText] ' . $e->getMessage());
        }

        return 'By submitting this application, you confirm the details provided are true and correct.';
    }
}
