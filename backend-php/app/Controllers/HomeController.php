<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\SystemSettingsModel;
use App\Services\ApprovalInboxService;
use App\Shared\SessionHelper;

final class HomeController extends BaseController
{
    protected array $acl = [
        '*' => ['auth' => true],
        'index' => ['auth' => true]
    ];

    public function __construct()
    {
        parent::__construct();
    }

    public function index(): void
    {
        $userId = (int) SessionHelper::get('auth.user_id', 0);
        $username = (string) SessionHelper::get('auth.username', 'guest');
        $roles = SessionHelper::get('auth.roles', []);
        $perms = SessionHelper::get('auth.perms', []);
        $employeeId = trim((string)SessionHelper::get('auth.employee_id', ''));
        if ($employeeId === '' && $userId > 0 && $this->db instanceof \PDO) {
            try {
                $stmt = $this->db->prepare("
                    SELECT EmployeeID
                    FROM dbo.tblUsers
                    WHERE UserID = :uid
                ");
                $stmt->execute(['uid' => $userId]);
                $employeeId = trim((string)($stmt->fetchColumn() ?? ''));
                if ($employeeId !== '') {
                    SessionHelper::set('auth.employee_id', $employeeId);
                }
            } catch (\Throwable $e) {
                app_log('Home employee lookup failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ], 'warn');
            }
        }
        $applicationBlacklist = $this->buildApplicationBlacklistState($employeeId);
        $applicationEntitlement = $this->buildApplicationEntitlementState($employeeId);

        // Application status by type (for PortalCardsList)
        $applicationsByType = [];
        $applicationsByTypeId = [];
        if ($this->db instanceof \PDO && ($employeeId !== '' || $userId > 0)) {
            $where = "LTRIM(RTRIM(ISNULL(CAST(a.EmployeeID AS NVARCHAR(50)), ''))) = :employeeId";
            $params = ['employeeId' => $employeeId];
            if ($userId > 0) {
                $where = "(
                    LTRIM(RTRIM(ISNULL(CAST(a.EmployeeID AS NVARCHAR(50)), ''))) = :employeeId
                    OR (
                        a.UserID = :userId
                        AND LTRIM(RTRIM(ISNULL(CAST(a.EmployeeID AS NVARCHAR(50)), ''))) = ''
                    )
                )";
                $params['userId'] = $userId;
            }

            $stmt = $this->db->prepare("
                SELECT
                    a.ApplicationTypeID,
                    at.ApplicationTypeKey,
                    a.ApplicationID,
                    a.Status,
                    a.Locked
                FROM dbo.tblApplications a
                JOIN dbo.tblApplicationTypes at
                  ON at.ApplicationTypeID = a.ApplicationTypeID
                WHERE $where
                ORDER BY a.ApplicationID DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $r) {
                $k = strtolower(trim((string)($r['ApplicationTypeKey'] ?? '')));
                if ($k !== '' && !isset($applicationsByType[$k])) {
                    $applicationsByType[$k] = $r;
                }

                $typeId = (int)($r['ApplicationTypeID'] ?? 0);
                if ($typeId > 0 && !isset($applicationsByTypeId[$typeId])) {
                    $applicationsByTypeId[$typeId] = $r;
                }
            }
        }

        // Issued cards for home PortalCardsList include
        $cards = [];
        if ($this->db instanceof \PDO && $employeeId !== '') {
            $st = $this->db->prepare("
                SELECT p.*, at.ApplicationTypeKey
                FROM dbo.tblPORTALCards p
                LEFT JOIN dbo.tblApplications a
                  ON a.ApplicationID = p.ApplicationID
                LEFT JOIN dbo.tblApplicationTypes at
                  ON at.ApplicationTypeID = a.ApplicationTypeID
                WHERE p.EmployeeID = :emp
                  AND (
                        ISNULL(p.Status,'') = ''
                        OR UPPER(LTRIM(RTRIM(ISNULL(p.Status,'')))) LIKE 'XS%'
                      )
            ");
            $st->execute(['emp' => $employeeId]);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $cardIds = array_map(
                static fn(array $r): int => (int)($r['CardID'] ?? 0),
                $rows
            );
            $linkedApplicationIds = $this->loadExistingApplicationIdMap(
                $this->db,
                array_map(static fn(array $r): int => (int)($r['ApplicationID'] ?? 0), $rows)
            );
            $pendingCancelByCardId = $this->loadPendingCancelMap($cardIds);
            $openLimitChangeByCardId = $this->loadOpenLimitChangeApplicationMap($this->db, $cardIds);

            foreach ($rows as $r) {
                $linkedApplicationId = $linkedApplicationIds[(int)($r['ApplicationID'] ?? 0)] ?? 0;
                $cardType = (string)($r['CardType'] ?? '');
                $cardTypeSub = (string)($r['CardTypeSub'] ?? '');
                $appTypeKey = strtolower(trim((string)($r['ApplicationTypeKey'] ?? '')));
                if ($appTypeKey !== '') {
                    $typeKey = $appTypeKey;
                    $title = match ($appTypeKey) {
                        'dtc' => 'Defence Travel Card in hand (DTC-in-hand)',
                        'dpc' => 'Defence Purchasing Card (DPC)',
                        'lodge' => 'Defence Travel Lodge Card (Virtual)',
                        default => $appTypeKey,
                    };
                } else {
                    $t = strtoupper(trim($cardType));
                    $tNorm = preg_replace('/\s+/', ' ', $t) ?: $t;
                    $sub = strtoupper(trim($cardTypeSub));
                    $subNorm = preg_replace('/\s+/', ' ', $sub) ?: $sub;
                    if ($t === 'DTC') {
                        $typeKey = str_contains($subNorm, 'LODGE') ? 'lodge' : 'dtc';
                        $title = str_contains($subNorm, 'LODGE') ? 'Defence Travel Lodge Card (Virtual)' : 'Defence Travel Card in hand (DTC-in-hand)';
                    } elseif ($t === 'DPC') {
                        $typeKey = 'dpc';
                        $title = 'Defence Purchasing Card (DPC)';
                    } elseif (in_array($t, ['LODGE', 'DTL', 'DTLC', 'LDC', 'DTCLODGE'], true)) {
                        $typeKey = 'lodge';
                        $title = 'Defence Travel Lodge Card (Virtual)';
                    } else {
                        if (str_contains($tNorm, 'DPC')) {
                            $typeKey = 'dpc';
                            $title = 'Defence Purchasing Card (DPC)';
                        } elseif (str_contains($subNorm, 'LODGE') || str_contains($tNorm, 'LODGE')) {
                            $typeKey = 'lodge';
                            $title = 'Defence Travel Lodge Card (Virtual)';
                        } elseif (str_contains($tNorm, 'DTC')) {
                            $typeKey = 'dtc';
                            $title = 'Defence Travel Card in hand (DTC-in-hand)';
                        } else {
                            $typeKey = '';
                            $title = $cardType !== '' ? $cardType : 'Card';
                        }
                    }
                }
                $name  = trim((string)($r['FirstName'] ?? '') . ' ' . (string)($r['Surname'] ?? ''));

                $addrParts = array_filter([
                    (string)($r['Address1'] ?? ''),
                    (string)($r['Address2'] ?? ''),
                    (string)($r['Address3'] ?? ''),
                    trim((string)($r['Suburb'] ?? '') . ' ' . (string)($r['State'] ?? '') . ' ' . (string)($r['PostCode'] ?? '')),
                ], fn($v) => trim((string)$v) !== '');

                $rawStatus = trim((string)($r['Status'] ?? ''));
                $isActive = ($rawStatus === '' || str_starts_with(strtoupper($rawStatus), 'XS'));
                $cardImage = match (strtolower($typeKey)) {
                    'dtc' => $this->cardImagePath('GenCardDTC'),
                    'dpc' => $this->cardImagePath('GenCardDPC'),
                    'lodge' => $this->cardImagePath('GenCardDTC'),
                    default => $this->cardImagePath('GenCard'),
                };
                $cards[] = [
                    'CardID'             => (int)($r['CardID'] ?? 0),
                    'CardType'           => $title,
                    'CardTypeSub'        => (string)($r['CardTypeSub'] ?? ''),
                    'ApplicationTypeKey' => $typeKey,
                    'IsHeld'             => $isActive ? 1 : 0,
                    'Status'             => $rawStatus !== '' ? $rawStatus : ($isActive ? 'Active' : 'Inactive'),
                    'Name'               => ($name !== '' ? $name : '—'),
                    'Address'            => ($addrParts ? implode(', ', $addrParts) : '—'),
                    'Limit'              => $r['ActiveCeiling'] ?? ($r['CreditLimitAmount'] ?? null),
                    'DateIssued'         => (string)($r['DateIssued'] ?? ''),
                    'CardNumber'         => (string)($r['CardNumber'] ?? ''),
                    'Expiry'             => (string)($r['Expiry'] ?? ''),
                    'NameOnCard'         => (string)($r['NameOnCard'] ?? ''),
                    'Image'              => $cardImage,
                    'ApplicationID'      => $linkedApplicationId,
                    'PendingCancel'      => !empty($pendingCancelByCardId[(int)($r['CardID'] ?? 0)]),
                ];
            }
        }

        $cancelMaxMonths = $this->getCancelCardMaxFutureMonths();
        $noCardHeldMessages = $this->loadNoCardHeldMessages();
        $portalCardHoverMessages = $this->loadPortalCardHoverMessages();
        $portalCardsHandyLink = $this->loadPortalCardsHandyLink();
        $lostStolenMessage = $this->loadLostStolenMessage();
        $pendingApprovals = [
            'totalCount' => 0,
            'dpcCount' => 0,
            'limitChangeCount' => 0,
            'dpcListRoute' => 'applications/my-dpc-approvals',
            'limitChangeListRoute' => 'cards/my-limit-change-approvals',
        ];
        if ($this->db instanceof \PDO && $userId > 0) {
            try {
                $pendingApprovals = (new ApprovalInboxService($this->db))->getPendingApprovalSummary($userId);
            } catch (\Throwable $e) {
                app_log('Home pending approvals load failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ], 'warn');
            }
        }
        $today = new \DateTimeImmutable('today');

        $this->render('home/HomeIndexView', [
            'title' => __t('home_title'),
            'userId' => $userId,
            'username' => $username,
            'roles' => $roles,
            'perms' => $perms,
            'applicationsByType' => $applicationsByType,
            'applicationsByTypeId' => $applicationsByTypeId,
            'openLimitChangeByCardId' => $openLimitChangeByCardId ?? [],
            'cards' => $cards,
            'applicationBlacklist' => $applicationBlacklist,
            'applicationEntitlement' => $applicationEntitlement,
            'noCardHeldMessages' => $noCardHeldMessages,
            'portalCardHoverMessages' => $portalCardHoverMessages,
            'portalCardsHandyLink' => $portalCardsHandyLink,
            'lostStolenMessage' => $lostStolenMessage,
            'pendingApprovals' => $pendingApprovals,
            'cancelDateMin' => $today->format('Y-m-d'),
            'cancelDateMax' => $today->modify('+' . $cancelMaxMonths . ' months')->format('Y-m-d'),
            'cancelMaxMonths' => $cancelMaxMonths,
            'cancelReasonOptions' => $this->loadCancelCardReasonOptions(),
            'employeeIdAvailable' => $employeeId !== '',
        ]);
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
            $model = new \App\Models\CancelCardReasonModel($this->db);
            $rows = $model->listActive();
            return $rows !== [] ? $rows : $fallback;
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    private function cardImagePath(string $baseName): string
    {
        $png = 'assets/img/' . $baseName . '.png';
        $jpg = 'assets/img/' . $baseName . '.jpg';
        $pngFs = __DIR__ . '/../../public/' . $png;
        return is_file($pngFs) ? $png : $jpg;
    }

    private function loadPendingCancelMap(array $cardIds): array
    {
        $cardIds = array_values(array_filter(array_map(static fn($v): int => (int)$v, $cardIds), static fn(int $v): bool => $v > 0));
        if (!$cardIds || !($this->db instanceof \PDO)) {
            return [];
        }

        $placeholders = [];
        $params = [
            ':rtype' => 'CANCEL_CARD',
            ':status' => 'Cancel Subm',
        ];
        foreach ($cardIds as $i => $id) {
            $key = ':c' . $i;
            $placeholders[] = $key;
            $params[$key] = $id;
        }

        $sql = "
            SELECT CardID, PayloadJson
            FROM dbo.tblCardChangeRequests
            WHERE RequestType = :rtype
              AND Status = :status
              AND CardID IN (" . implode(',', $placeholders) . ")
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $cardId = (int)($row['CardID'] ?? 0);
            if ($cardId <= 0 || !$this->isCancelRequestEffectiveNow((string)($row['PayloadJson'] ?? ''))) {
                continue;
            }
            $out[$cardId] = true;
        }
        return $out;
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

    private function loadExistingApplicationIdMap(\PDO $db, array $applicationIds): array
    {
        $applicationIds = array_values(array_filter(
            array_map(static fn($v): int => (int)$v, $applicationIds),
            static fn(int $v): bool => $v > 0
        ));
        if (!$applicationIds) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($applicationIds), '?'));
        $stmt = $db->prepare("
            SELECT ApplicationID
            FROM dbo.tblApplications
            WHERE ApplicationID IN ($placeholders)
        ");
        $stmt->execute($applicationIds);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $applicationId) {
            $applicationId = (int)$applicationId;
            if ($applicationId > 0) {
                $out[$applicationId] = $applicationId;
            }
        }

        return $out;
    }

    private function loadOpenLimitChangeApplicationMap(\PDO $db, array $cardIds): array
    {
        $cardIds = array_values(array_filter(
            array_map(static fn($v): int => (int)$v, $cardIds),
            static fn(int $v): bool => $v > 0
        ));
        if (!$cardIds) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($cardIds), '?'));
        $params = array_merge(
            ['application', 'dtc_limit_change', 'dpc_limit_change', 'lodge_limit_change'],
            $cardIds
        );

        $stmt = $db->prepare("
            SELECT
                a.ApplicationID,
                a.Status,
                a.SubmittedAt,
                a.LastSavedAt,
                at.ApplicationTypeKey,
                TRY_CONVERT(int, JSON_VALUE(s.DataJson, '$.card_id')) AS CardID
            FROM dbo.tblApplications a
            INNER JOIN dbo.tblApplicationTypes at
                ON at.ApplicationTypeID = a.ApplicationTypeID
            INNER JOIN dbo.tblApplicationSteps s
                ON s.ApplicationID = a.ApplicationID
               AND s.StepKey = ?
            WHERE at.ApplicationTypeKey IN (?, ?, ?)
              AND LOWER(LTRIM(RTRIM(ISNULL(a.Status, '')))) IN ('draft', 'inprogress', 'submitted', 'tobeapproved', 'approved', 'senttobank', 'sent_to_bank')
              AND TRY_CONVERT(int, JSON_VALUE(s.DataJson, '$.card_id')) IN ($placeholders)
            ORDER BY ISNULL(a.SubmittedAt, a.LastSavedAt) DESC, a.ApplicationID DESC
        ");
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $cardId = (int)($row['CardID'] ?? 0);
            if ($cardId <= 0 || isset($out[$cardId])) {
                continue;
            }
            $out[$cardId] = [
                'ApplicationID' => (int)($row['ApplicationID'] ?? 0),
                'Status' => (string)($row['Status'] ?? ''),
                'ApplicationTypeKey' => (string)($row['ApplicationTypeKey'] ?? ''),
            ];
        }

        return $out;
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

    private function loadNoCardHeldMessages(): array
    {
        $defaults = [
            'dtc' => '',
            'dpc' => '',
            'dual' => '',
            'lodge' => '',
        ];

        if (!($this->db instanceof \PDO)) {
            return $defaults;
        }

        try {
            $settings = new SystemSettingsModel($this->db);
            return [
                'dtc' => trim((string)($settings->get('PORTAL_NO_CARD_HELD_TEXT_DTC') ?? '')),
                'dpc' => trim((string)($settings->get('PORTAL_NO_CARD_HELD_TEXT_DPC') ?? '')),
                'dual' => trim((string)($settings->get('PORTAL_NO_CARD_HELD_TEXT_DUAL') ?? '')),
                'lodge' => trim((string)($settings->get('PORTAL_NO_CARD_HELD_TEXT_LODGE') ?? '')),
            ];
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    private function loadPortalCardHoverMessages(): array
    {
        $defaults = [
            'dtc' => '',
            'dpc' => '',
            'dual' => '',
            'lodge' => '',
        ];

        if (!($this->db instanceof \PDO)) {
            return $defaults;
        }

        try {
            $settings = new SystemSettingsModel($this->db);
            return [
                'dtc' => trim((string)($settings->get('PORTAL_CARD_HOVER_TEXT_DTC') ?? '')),
                'dpc' => trim((string)($settings->get('PORTAL_CARD_HOVER_TEXT_DPC') ?? '')),
                'dual' => trim((string)($settings->get('PORTAL_CARD_HOVER_TEXT_DUAL') ?? '')),
                'lodge' => trim((string)($settings->get('PORTAL_CARD_HOVER_TEXT_LODGE') ?? '')),
            ];
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    private function loadPortalCardsHandyLink(): array
    {
        $defaults = [
            'text' => '',
            'url' => '',
        ];

        if (!($this->db instanceof \PDO)) {
            return $defaults;
        }

        try {
            $settings = new SystemSettingsModel($this->db);
            return [
                'text' => trim((string)($settings->get('PORTAL_CARDS_HANDY_LINK_TEXT') ?? '')),
                'url' => trim((string)($settings->get('PORTAL_CARDS_HANDY_LINK_URL') ?? '')),
            ];
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    private function loadLostStolenMessage(): string
    {
        if (!($this->db instanceof \PDO)) {
            return '';
        }

        try {
            $settings = new SystemSettingsModel($this->db);
            return trim((string)($settings->get('PORTAL_LOST_STOLEN_MESSAGE') ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function buildApplicationBlacklistState(string $employeeId): array
    {
        $employeeId = trim($employeeId);
        $state = [
            'blocked_any' => false,
            'message' => '',
            'by_type_id' => [],
        ];
        if ($employeeId === '' || !($this->db instanceof \PDO)) {
            return $state;
        }

        $typeIds = [1, 2, 3, 4];
        foreach ($typeIds as $typeId) {
            $match = $this->getBlacklistMatch($employeeId, $typeId);
            if (!empty($match['blocked'])) {
                $state['blocked_any'] = true;
                $state['by_type_id'][$typeId] = true;
                if ($state['message'] === '') {
                    $state['message'] = $this->buildBlacklistMessage($match);
                }
            }
        }

        return $state;
    }

    private function getBlacklistMatch(string $employeeId, int $applicationTypeId): array
    {
        try {
            $stmt = $this->db->prepare("
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
            ");
            $stmt->execute([
                'eid' => $employeeId,
                'atid' => $applicationTypeId,
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
            error_log('[HomeController::getBlacklistMatch] ' . $e->getMessage());
            return ['blocked' => false, 'reason' => ''];
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

    private function buildApplicationEntitlementState(string $employeeId): array
    {
        $employeeType = $this->resolveEmployeeType($employeeId);
        $state = [
            'employee_type' => $employeeType,
            'by_type_id' => [],
        ];

        foreach ([1, 2, 3, 4] as $typeId) {
            $state['by_type_id'][$typeId] = $this->isEmployeeTypeEntitledForType($employeeId, $employeeType, $typeId);
        }

        return $state;
    }

    private function resolveEmployeeType(string $employeeId): string
    {
        $employeeType = trim((string)SessionHelper::get('auth.employee_type', ''));
        if ($employeeType !== '' || $employeeId === '') {
            return $employeeType;
        }

        global $capsConn;
        if (!($capsConn instanceof \PDO)) {
            return '';
        }

        try {
            $stmt = $capsConn->prepare("
                SELECT TOP 1 EmployeeType
                FROM dbo.tblCAPSCDMCPortal
                WHERE EmployeeID = :eid
            ");
            $stmt->execute(['eid' => $employeeId]);
            $employeeType = trim((string)($stmt->fetchColumn() ?? ''));
            return $this->normalizeEmployeeType($employeeType);
        } catch (\Throwable $e) {
            error_log('[HomeController::resolveEmployeeType] ' . $e->getMessage());
            return '';
        }
    }

    private function normalizeEmployeeType(string $employeeType): string
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
            error_log('[HomeController::normalizeEmployeeType] ' . $e->getMessage());
        }

        $allowed = preg_split('/[\s,;|]+/', strtoupper($raw)) ?: [];
        $allowed = array_values(array_filter(array_map('trim', $allowed), static fn(string $v): bool => $v !== ''));
        if (!$allowed) {
            $allowed = ['ASA', 'ASD', 'ANNPSR'];
        }

        return in_array(strtoupper($employeeType), $allowed, true) ? $employeeType : 'Defence';
    }

    private function isEmployeeTypeEntitledForType(string $employeeId, string $employeeType, int $applicationTypeId): bool
    {
        if ($this->hasPositionTypeOverride($employeeId, $applicationTypeId)) {
            return true;
        }

        $employeeType = trim($employeeType);
        if ($employeeType === '') {
            return $this->isEmployeeIdEntitledFallback($employeeId, $applicationTypeId);
        }

        $col = null;
        if ($applicationTypeId === 1) {
            $col = 'DPCEntitled';
        } elseif (in_array($applicationTypeId, [2, 3, 4], true)) {
            $col = 'DTCEntitled';
        }
        if ($col === null) {
            return true;
        }

        global $capsConn;
        if (!($capsConn instanceof \PDO)) {
            return true;
        }

        try {
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
        } catch (\Throwable $e) {
            error_log('[HomeController::isEmployeeTypeEntitledForType] ' . $e->getMessage());
            return $this->isEmployeeIdEntitledFallback($employeeId, $applicationTypeId);
        }
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
                error_log('[HomeController::buildEntitlementEmployeeTypeCandidates] ' . $e->getMessage());
            }
        }

        $normalized = $employeeType !== '' ? $this->normalizeEmployeeType($employeeType) : '';
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

    private function hasPositionTypeOverride(string $employeeId, int $applicationTypeId): bool
    {
        $employeeId = trim($employeeId);
        if ($employeeId === '' || !($this->db instanceof \PDO)) {
            return false;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT TOP 1 1
                FROM dbo.tblApplicationEligibilityOverride
                WHERE EmployeeID = :eid
                  AND OverrideType = 'POSITION_TYPE_CHECK'
                  AND IsActive = 1
                  AND (AppliesToApplicationTypeID IS NULL OR AppliesToApplicationTypeID = :atid)
                  AND (EffectiveFrom IS NULL OR EffectiveFrom <= SYSUTCDATETIME())
                  AND (EffectiveTo IS NULL OR EffectiveTo >= SYSUTCDATETIME())
            ");
            $stmt->execute([
                'eid' => $employeeId,
                'atid' => $applicationTypeId,
            ]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[HomeController::hasPositionTypeOverride] ' . $e->getMessage());
            return false;
        }
    }
}
