<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Shared\SessionHelper;
use App\Models\PortalCardModel;
use App\Models\AuditModel;
use App\Models\CancelCardReasonModel;
use App\Models\SystemSettingsModel;

require_once __DIR__ . '/../../shared/csrf.php';

final class PortalCardsController extends BaseController
{
    protected array $acl = [
        '*'         => ['auth' => true, 'permsAny' => ['PORTALCARDS_VIEW','ADMIN_ALL']],
        'list'      => ['auth' => true, 'permsAny' => ['PORTALCARDS_VIEW','ADMIN_ALL']],
        'adminList' => ['auth' => true, 'permsAny' => ['PORTALCARDS_VIEW','ADMIN_ALL']],
        'edit'      => ['auth' => true, 'permsAny' => ['PORTALCARDS_EDIT','ADMIN_ALL']],
        'adminEdit' => ['auth' => true, 'permsAny' => ['PORTALCARDS_EDIT','ADMIN_ALL']],
        'save'      => ['auth' => true, 'permsAny' => ['PORTALCARDS_EDIT','ADMIN_ALL']],
        'adminSave' => ['auth' => true, 'permsAny' => ['PORTALCARDS_EDIT','ADMIN_ALL']],
        'delete'    => ['auth' => true, 'permsAny' => ['PORTALCARDS_EDIT','ADMIN_ALL']],
        'adminDelete' => ['auth' => true, 'permsAny' => ['PORTALCARDS_EDIT','ADMIN_ALL']],
    ];

    public function __construct()
    {
        parent::__construct();
    }

    public function adminList(): void
    {
        require __DIR__ . '/../../config/db.php';
        $model = new PortalCardModel($conn);

        $q          = trim((string)($_GET['q'] ?? ''));
        $employeeId = trim((string)($_GET['employeeId'] ?? ''));
        $cardTypeSub = trim((string)($_GET['cardTypeSub'] ?? ''));
        $status     = trim((string)($_GET['status'] ?? ''));
        $active     = ($_GET['active'] ?? '') !== '' ? (string)$_GET['active'] : '';

        $filters = [
            'q'          => $q,
            'employeeId' => $employeeId,
            'cardTypeSub' => $cardTypeSub,
            'status'     => $status,
            'active'     => $active,
        ];
        SessionHelper::set('portalcards.admin.filters', $filters);

        $hasFilters = false;
        foreach ($filters as $value) {
            if (trim((string)$value) !== '') {
                $hasFilters = true;
                break;
            }
        }

        $perPage     = 25;
        $currentPage = max(1, (int)($_GET['page'] ?? 1));
        $offset      = ($currentPage - 1) * $perPage;

        $totalCount = 0;
        $rows = [];
        $totalPages = 1;
        if ($hasFilters) {
            $totalCount = $model->countFiltered($q, $employeeId, null, $status, $active, $cardTypeSub);
            $rows       = $model->listFiltered($q, $employeeId, null, $status, $active, $cardTypeSub, $offset, $perPage);
            $totalPages = max(1, (int)ceil(max(1, $totalCount) / $perPage));
        }

        $cardTypeSubs = $hasFilters ? $model->listDistinctCardTypeSubs() : [];
        $statuses = $hasFilters ? $model->listDistinctStatuses() : [];

        $this->render('portalcards/PortalCardsAdminList', [
            'title'       => 'Portal Cards Admin',
            'rows'        => $rows,
            'currentPage' => $currentPage,
            'totalPages'  => $totalPages,
            'totalCount'  => $totalCount,
            'filters'     => $filters,
            'hasFilters'  => $hasFilters,
            'cardTypeSubs' => $cardTypeSubs,
            'statuses'    => $statuses,
            '_csrf'       => csrf_token(),
        ]);
    }

    public function adminEdit(): void
    {
        require __DIR__ . '/../../config/db.php';

        $id    = (int)($_GET['id'] ?? 0);
        $model = new PortalCardModel($conn);
        $row   = $id > 0 ? $model->find($id) : null;

        if ($id > 0 && $row === null) {
            $this->flashError('Portal card not found.');
            $this->redirectAdminList();
        }

        $this->render('portalcards/PortalCardForm', [
            'title' => $id > 0 ? 'Edit Portal Card' : 'Create Portal Card',
            'row'   => $row,
            '_csrf' => csrf_token(),
        ]);
    }

    public function adminSave(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }
        if (!csrf_check($_POST['_csrf'] ?? '')) {
            $this->flashError('Security check failed.');
            $this->redirectAdminList();
        }

        require __DIR__ . '/../../config/db.php';
        $model = new PortalCardModel($conn);
        $audit = class_exists(AuditModel::class) ? new AuditModel($conn) : null;

        $id = (int)($_POST['CardID'] ?? 0);
        $data = $this->collectAdminFormData();

        try {
            if ($id > 0) {
                $ok = $model->update($id, $data);
                if ($ok) {
                    $this->flashSuccess('Portal card updated.');
                    if ($audit) {
                        $audit->insert([
                            'UserID'       => SessionHelper::get('auth.user_id'),
                            'Username'     => SessionHelper::get('auth.username', 'guest'),
                            'Action'       => 'UPDATE',
                            'Entity'       => 'PortalCard',
                            'EntityKey'    => (string)$id,
                            'IPAddress'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                            'Details'      => json_encode(['EmployeeID' => $data['EmployeeID'] ?? null, 'CardType' => $data['CardType'] ?? null]),
                            'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                            'VersionID'    => SessionHelper::get('VersionID'),
                        ]);
                    }
                } else {
                    $this->flashError('Save failed: ' . $model->getLastError());
                }
            } else {
                $newId = $model->create($data);
                if ($newId > 0) {
                    $this->flashSuccess('Portal card created.');
                    if ($audit) {
                        $audit->insert([
                            'UserID'       => SessionHelper::get('auth.user_id'),
                            'Username'     => SessionHelper::get('auth.username', 'guest'),
                            'Action'       => 'CREATE',
                            'Entity'       => 'PortalCard',
                            'EntityKey'    => (string)$newId,
                            'IPAddress'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                            'Details'      => json_encode(['EmployeeID' => $data['EmployeeID'] ?? null, 'CardType' => $data['CardType'] ?? null]),
                            'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                            'VersionID'    => SessionHelper::get('VersionID'),
                        ]);
                    }
                } else {
                    $this->flashError('Create failed: ' . $model->getLastError());
                }
            }
        } catch (\Throwable $e) {
            $this->flashError('Save failed: ' . $e->getMessage());
        }

        $this->redirectAdminList();
    }

    public function adminDelete(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }
        if (!csrf_check($_POST['_csrf'] ?? '')) {
            $this->flashError('Security check failed.');
            $this->redirectAdminList();
        }

        require __DIR__ . '/../../config/db.php';
        $model = new PortalCardModel($conn);
        $audit = class_exists(AuditModel::class) ? new AuditModel($conn) : null;

        $id = (int)($_POST['CardID'] ?? 0);
        if ($id <= 0) {
            $this->flashError('Missing portal card ID.');
            $this->redirectAdminList();
        }

        $existing = $model->find($id);
        if (!$existing) {
            $this->flashError('Portal card not found.');
            $this->redirectAdminList();
        }

        try {
            if ($model->delete($id)) {
                $this->flashSuccess('Portal card deleted.');
                if ($audit) {
                    $audit->insert([
                        'UserID'       => SessionHelper::get('auth.user_id'),
                        'Username'     => SessionHelper::get('auth.username', 'guest'),
                        'Action'       => 'DELETE',
                        'Entity'       => 'PortalCard',
                        'EntityKey'    => (string)$id,
                        'IPAddress'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'Details'      => json_encode(['EmployeeID' => $existing['EmployeeID'] ?? null, 'CardType' => $existing['CardType'] ?? null]),
                        'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                        'VersionID'    => SessionHelper::get('VersionID'),
                    ]);
                }
            } else {
                $this->flashError('Delete failed: ' . $model->getLastError());
            }
        } catch (\Throwable $e) {
            $this->flashError('Delete failed: ' . $e->getMessage());
        }

        $this->redirectAdminList();
    }

    /** Show list of cards with filters + pagination */
    public function list(): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        require __DIR__ . '/../../config/db.php';
        $model = new PortalCardModel($conn);
        $sessionEmployeeId = trim((string)SessionHelper::get('auth.employee_id', ''));
        $applicationBlacklist = $this->buildApplicationBlacklistState($sessionEmployeeId);
        $applicationEntitlement = $this->buildApplicationEntitlementState($sessionEmployeeId);

        $q          = trim((string)($_GET['q'] ?? ''));
        $employeeId = $sessionEmployeeId;
        $cardType   = trim((string)($_GET['cardType'] ?? ''));
        $status     = trim((string)($_GET['status'] ?? ''));
        $active     = ($_GET['active'] ?? '') !== '' ? (string)$_GET['active'] : ''; // '1'/'0' or ''

        $filters = [
            'q'          => $q,
            'employeeId' => $employeeId,
            'cardType'   => $cardType,
            'status'     => $status,
            'active'     => $active,
        ];
        SessionHelper::set('portalcards.filters', $filters);
        SessionHelper::set('portalcards.filters.employeeId', $employeeId);

        $perPage     = 25;
        $currentPage = max(1, (int)($_GET['page'] ?? 1));
        $offset      = ($currentPage - 1) * $perPage;

        $totalCount = 0;
        $rows = [];
        $totalPages = 1;
        if ($employeeId !== '') {
            $totalCount = $model->countFiltered($q, $employeeId, $cardType, $status, $active);
            $rows = $model->listFiltered($q, $employeeId, $cardType, $status, $active, null, $offset, $perPage);
            $totalPages = max(1, (int)ceil(max(1, $totalCount) / $perPage));
        }
        $cardIds = array_map(
            static fn(array $r): int => (int)($r['CardID'] ?? 0),
            $rows
        );
        $linkedApplicationIds = $this->loadExistingApplicationIdMap(
            $conn,
            array_map(static fn(array $r): int => (int)($r['ApplicationID'] ?? 0), $rows)
        );
        $pendingCancelByCardId = $this->loadPendingCancelMap($cardIds);
    $openLimitChangeByCardId = $this->loadOpenLimitChangeApplicationMap($conn, $cardIds, $employeeId);

        // Map rows to cards for PortalCardsList view
        $cards = [];
        $issuedByType = [];
        foreach ($rows as $r) {
            $linkedApplicationId = $linkedApplicationIds[(int)($r['ApplicationID'] ?? 0)] ?? 0;
            $cardType = (string)($r['CardType'] ?? '');
            $cardTypeSub = (string)($r['CardTypeSub'] ?? '');
            $typeKey = $this->mapCardTypeToKey($cardType, $cardTypeSub);
            $title = $this->mapCardTypeToTitle($cardType, $cardTypeSub);
            $name  = trim((string)($r['FirstName'] ?? '') . ' ' . (string)($r['Surname'] ?? ''));
            $rawStatus = trim((string)($r['Status'] ?? ''));
            $statusUpper = strtoupper($rawStatus);
            $countsAsIssued = $rawStatus === ''
                || strcasecmp($rawStatus, 'Active') === 0
                || str_starts_with($statusUpper, 'XS');
            $addrParts = array_filter([
                (string)($r['Address1'] ?? ''),
                (string)($r['Address2'] ?? ''),
                (string)($r['Address3'] ?? ''),
                trim((string)($r['Suburb'] ?? '') . ' ' . (string)($r['State'] ?? '') . ' ' . (string)($r['PostCode'] ?? '')),
            ], fn($v) => trim((string)$v) !== '');

            $cards[] = [
                'CardID'             => (int)($r['CardID'] ?? 0),
                'CardType'           => $title !== '' ? $title : 'Card',
                'CardTypeSub'        => (string)($r['CardTypeSub'] ?? ''),
                'ApplicationTypeID'  => $this->mapTypeKeyToApplicationTypeId($typeKey),
                'ApplicationTypeKey' => $typeKey,
                'IsHeld'             => $countsAsIssued ? 1 : 0,
                'Status'             => $countsAsIssued ? $rawStatus : ((strtoupper($rawStatus) === 'VX') ? 'VX' : 'Not held'),
                'Name'               => ($name !== '' ? $name : '—'),
                'Address'            => ($addrParts ? implode(', ', $addrParts) : '—'),
                'Limit'              => $r['ActiveCeiling'] ?? ($r['CreditLimitAmount'] ?? null),
                'DateIssued'         => (string)($r['DateIssued'] ?? ''),
                'DateIssuedDisplay'  => $this->formatCardDateIssued((string)($r['DateIssued'] ?? '')),
                'CardNumber'         => (string)($r['CardNumber'] ?? ''),
                'Expiry'             => (string)($r['Expiry'] ?? ''),
                'ExpiryDisplay'      => $this->formatCardExpiry((string)($r['Expiry'] ?? '')),
                'NameOnCard'         => (string)($r['NameOnCard'] ?? ''),
                'Image'              => $this->cardImagePath('GenCard'),
                'ApplicationID'      => $linkedApplicationId,
                'PendingCancel'      => !empty($pendingCancelByCardId[(int)($r['CardID'] ?? 0)]),
            ];

            if ($typeKey !== '' && $countsAsIssued) {
                $issuedByType[strtolower($typeKey)] = [
                    'ApplicationID' => $linkedApplicationId,
                    'CardID' => (int)($r['CardID'] ?? 0),
                    'Status' => $rawStatus !== '' ? $rawStatus : 'Active',
                ];
            }
        }

        $applicationsByType = $this->loadApplicationsByTypeForEmployee($conn, $employeeId);
        $applicationsByTypeId = [];
        foreach ($applicationsByType as $row) {
            $typeId = (int)($row['ApplicationTypeID'] ?? 0);
            if ($typeId > 0 && !isset($applicationsByTypeId[$typeId])) {
                $applicationsByTypeId[$typeId] = $row;
            }
        }

        $cards = $this->sortPortalCardsForDisplay($cards);

        $flash = SessionHelper::get('flash.message', null);
        $cancelMaxMonths = $this->getCancelCardMaxFutureMonths($conn);
        $today = new \DateTimeImmutable('today');
        $noCardHeldMessages = $this->loadNoCardHeldMessages($conn);
        $portalCardHoverMessages = $this->loadPortalCardHoverMessages($conn);
        $portalCardsHandyLink = $this->loadPortalCardsHandyLink($conn);
        $lostStolenMessage = $this->loadLostStolenMessage($conn);

        $this->render('portalcards/PortalCardsList', [
            'title'       => 'Portal Cards',
            'rows'        => $rows,
            'cards'       => $cards,
            'currentPage' => $currentPage,
            'totalPages'  => $totalPages,
            'totalCount'  => $totalCount,
            'filters'     => $filters,
            'flash'       => $flash,
            'applicationsByType' => $applicationsByType,
            'applicationsByTypeId' => $applicationsByTypeId,
            'openLimitChangeByCardId' => $openLimitChangeByCardId,
            'issuedByType' => $issuedByType,
            'applicationBlacklist' => $applicationBlacklist,
            'applicationEntitlement' => $applicationEntitlement,
            'noCardHeldMessages' => $noCardHeldMessages,
            'portalCardHoverMessages' => $portalCardHoverMessages,
            'portalCardsHandyLink' => $portalCardsHandyLink,
            'lostStolenMessage' => $lostStolenMessage,
            'cancelDateMin' => $today->format('Y-m-d'),
            'cancelDateMax' => $today->modify('+' . $cancelMaxMonths . ' months')->format('Y-m-d'),
            'cancelMaxMonths' => $cancelMaxMonths,
        ]);

        if ($flash !== null) {
            SessionHelper::forget('flash.message');
        }
    }

    /** Edit existing card or show blank form */
    public function edit(): void
    {
        require __DIR__ . '/../../config/db.php';

        $id    = (int)($_GET['id'] ?? 0);
        $model = new PortalCardModel($conn);

        $row = $id > 0 ? $model->find($id) : null;

        $flash = SessionHelper::get('flash.message', null);

        $this->render('portalcards/PortalCardForm', [
            'title' => $id > 0 ? 'Edit Portal Card' : 'Create Portal Card',
            'row'   => $row,
            'flash' => $flash,
        ]);

        if ($flash !== null) {
            SessionHelper::forget('flash.message');
        }
    }

    /** Save card changes (create or update) */
    public function save(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        require __DIR__ . '/../../config/db.php';
        $model = new PortalCardModel($conn);

        // Audit is optional; only used if your project has AuditModel wired like UsersController
        $audit = class_exists(AuditModel::class) ? new AuditModel($conn) : null;

        $id = (int)($_POST['CardID'] ?? 0);

        // Collect (a sensible subset) + allow model to whitelist & ignore unknown fields.
        // Add more fields any time — model will safely accept only allowed columns.
        $data = [
            'tblCardID'      => ($_POST['tblCardID'] ?? '') !== '' ? (int)$_POST['tblCardID'] : null,
            'EmployeeID'     => trim((string)($_POST['EmployeeID'] ?? '')),
            'ApplicationID'  => ($_POST['ApplicationID'] ?? '') !== '' ? (int)$_POST['ApplicationID'] : null,
            'CardType'       => trim((string)($_POST['CardType'] ?? '')),
            'CardTypeSub'    => trim((string)($_POST['CardTypeSub'] ?? '')),
            'Title'          => trim((string)($_POST['Title'] ?? '')),
            'FirstName'      => trim((string)($_POST['FirstName'] ?? '')),
            'MiddleName'     => trim((string)($_POST['MiddleName'] ?? '')),
            'Surname'        => trim((string)($_POST['Surname'] ?? '')),
            'NameOnCard'     => trim((string)($_POST['NameOnCard'] ?? '')),
            'Email'          => trim((string)($_POST['Email'] ?? '')),
            'HomePhone'      => trim((string)($_POST['HomePhone'] ?? '')),
            'WorkPhone'      => trim((string)($_POST['WorkPhone'] ?? '')),
            'MobilePhone'    => trim((string)($_POST['MobilePhone'] ?? '')),

            'Address1'       => trim((string)($_POST['Address1'] ?? '')),
            'Address2'       => trim((string)($_POST['Address2'] ?? '')),
            'Address3'       => trim((string)($_POST['Address3'] ?? '')),
            'Suburb'         => trim((string)($_POST['Suburb'] ?? '')),
            'State'          => trim((string)($_POST['State'] ?? '')),
            'PostCode'       => trim((string)($_POST['PostCode'] ?? '')),

            'Status'         => trim((string)($_POST['Status'] ?? '')),
            'ProcessStatus'  => trim((string)($_POST['ProcessStatus'] ?? '')),
            'PortalScreenProgress' => trim((string)($_POST['PortalScreenProgress'] ?? '')),

            'CardNumber'     => trim((string)($_POST['CardNumber'] ?? '')),
            'CardNumberShort'=> trim((string)($_POST['CardNumberShort'] ?? '')),
            'AccountNumber'  => trim((string)($_POST['AccountNumber'] ?? '')),

            'DefaultCompany'    => trim((string)($_POST['DefaultCompany'] ?? '')),
            'DefaultCostCentre' => trim((string)($_POST['DefaultCostCentre'] ?? '')),
            'ActiveCeiling'   => $this->nullIfBlankNumber($_POST['ActiveCeiling'] ?? null),

            'Notes'         => trim((string)($_POST['Notes'] ?? '')),

            'Active'        => isset($_POST['Active']) ? 'Y' : 'N',
            'OnHold'        => isset($_POST['OnHold']) ? 'Y' : 'N',
            'ValidAddress'  => isset($_POST['ValidAddress']) ? 'Y' : 'N',

            // Dates (accept blank -> null; model stores as datetime)
            'Expiry'                 => $this->nullIfBlank($_POST['Expiry'] ?? null),
            'PortalInviteSent'       => $this->nullIfBlank($_POST['PortalInviteSent'] ?? null),
            'LoggedOntoPortal'       => $this->nullIfBlank($_POST['LoggedOntoPortal'] ?? null),
            'TermsAndConditions'     => $this->nullIfBlank($_POST['TermsAndConditions'] ?? null),
            'AddressConfirmedInPortal'=> $this->nullIfBlank($_POST['AddressConfirmedInPortal'] ?? null),
            'AddressChangedInPortal' => $this->nullIfBlank($_POST['AddressChangedInPortal'] ?? null),

            'UpdatedBy'   => (int)SessionHelper::get('auth.user_id', 0),
            // DateUpdated is set by model to SYSUTCDATETIME()
        ];

        try {
            if ($id > 0) {
                $ok = $model->update($id, $data);
                if ($ok) {
                    $this->flashSuccess('Portal card updated.');
                    if ($audit) {
                        $audit->insert([
                            'UserID'       => SessionHelper::get('auth.user_id'),
                            'Username'     => SessionHelper::get('auth.username', 'guest'),
                            'Action'       => 'UPDATE',
                            'Entity'       => 'PortalCard',
                            'EntityKey'    => (string)$id,
                            'IPAddress'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                            'Details'      => json_encode(['EmployeeID' => $data['EmployeeID'] ?? null, 'CardType' => $data['CardType'] ?? null]),
                            'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                            'VersionID'    => SessionHelper::get('VersionID'),
                        ]);
                    }
                } else {
                    $this->flashError('Save failed: ' . $model->getLastError());
                }
            } else {
                $newId = $model->create($data);
                if ($newId > 0) {
                    $this->flashSuccess('Portal card created.');
                    if ($audit) {
                        $audit->insert([
                            'UserID'       => SessionHelper::get('auth.user_id'),
                            'Username'     => SessionHelper::get('auth.username', 'guest'),
                            'Action'       => 'CREATE',
                            'Entity'       => 'PortalCard',
                            'EntityKey'    => (string)$newId,
                            'IPAddress'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                            'Details'      => json_encode(['EmployeeID' => $data['EmployeeID'] ?? null, 'CardType' => $data['CardType'] ?? null]),
                            'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                            'VersionID'    => SessionHelper::get('VersionID'),
                        ]);
                    }
                } else {
                    $this->flashError('Create failed: ' . $model->getLastError());
                }
            }
        } catch (\Throwable $e) {
            $this->flashError('Save failed: ' . $e->getMessage());
        }

        header('Location: index.php?route=portalcards/list');
        exit;
    }

public function index(): void
{
    require __DIR__ . '/../../config/db.php'; // gives $conn and $capsConn

    $employeeId = trim((string)SessionHelper::get('auth.employee_id', ''));
    if ($employeeId === '') {
        $this->flashError('EmployeeID missing in session. Please log in again.');
        header('Location: index.php?route=auth/loginForm');
        exit;
    }
    $applicationBlacklist = $this->buildApplicationBlacklistState($employeeId);
    $applicationEntitlement = $this->buildApplicationEntitlementState($employeeId);

    // Pull issued cards from CCPortal (tblPORTALCards)
    $st = $conn->prepare("
        SELECT p.*, at.ApplicationTypeKey
        FROM dbo.tblPORTALCards p
        LEFT JOIN dbo.tblApplications a
          ON a.ApplicationID = p.ApplicationID
        LEFT JOIN dbo.tblApplicationTypes at
          ON at.ApplicationTypeID = a.ApplicationTypeID
        WHERE p.EmployeeID = :emp
          AND ISNULL(p.Status,'') = ''
    ");
    $st->execute(['emp' => $employeeId]);
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    $cardIds = array_map(
        static fn(array $r): int => (int)($r['CardID'] ?? 0),
        $rows
    );
    $linkedApplicationIds = $this->loadExistingApplicationIdMap(
        $conn,
        array_map(static fn(array $r): int => (int)($r['ApplicationID'] ?? 0), $rows)
    );
    $pendingCancelByCardId = $this->loadPendingCancelMap($cardIds);
    $openLimitChangeByCardId = $this->loadOpenLimitChangeApplicationMap($conn, $cardIds, $employeeId);

    // Map into what your PortalCardsList.php expects
    $cards = [];
    $issuedByType = [];
    foreach ($rows as $r) {
        $linkedApplicationId = $linkedApplicationIds[(int)($r['ApplicationID'] ?? 0)] ?? 0;
        $cardType = (string)($r['CardType'] ?? 'Card');
        $cardTypeSub = (string)($r['CardTypeSub'] ?? '');
        $appTypeKey = strtolower(trim((string)($r['ApplicationTypeKey'] ?? '')));
        $typeKey = $appTypeKey !== '' ? $appTypeKey : $this->mapCardTypeToKey($cardType, $cardTypeSub);
        $title = $appTypeKey !== '' ? $this->mapTypeKeyToTitle($appTypeKey) : $this->mapCardTypeToTitle($cardType, $cardTypeSub);
        $name  = trim((string)($r['FirstName'] ?? '') . ' ' . (string)($r['Surname'] ?? ''));

        $addrParts = array_filter([
            (string)($r['Address1'] ?? ''),
            (string)($r['Address2'] ?? ''),
            (string)($r['Address3'] ?? ''),
            trim((string)($r['Suburb'] ?? '') . ' ' . (string)($r['State'] ?? '') . ' ' . (string)($r['PostCode'] ?? '')),
        ], fn($v) => trim((string)$v) !== '');

        $rawStatus = trim((string)($r['Status'] ?? ''));
        $statusUpper = strtoupper($rawStatus);
        $countsAsIssued = $rawStatus === ''
            || strcasecmp($rawStatus, 'Active') === 0
            || str_starts_with($statusUpper, 'XS');
        $cards[] = [
            'CardID'             => (int)($r['CardID'] ?? 0),
            'CardType'           => $title,
            'CardTypeSub'        => (string)($r['CardTypeSub'] ?? ''),
            'ApplicationTypeID'  => $this->mapTypeKeyToApplicationTypeId($typeKey),
            'ApplicationTypeKey' => $typeKey,
            'IsHeld'             => $countsAsIssued ? 1 : 0,
            'Status'             => $countsAsIssued ? ($rawStatus !== '' ? $rawStatus : 'Active') : ((strtoupper($rawStatus) === 'VX') ? 'VX' : 'Not held'),
            'Name'               => ($name !== '' ? $name : '—'),
            'Address'            => ($addrParts ? implode(', ', $addrParts) : '—'),
            'Limit'              => $r['ActiveCeiling'] ?? ($r['CreditLimitAmount'] ?? null),
            'DateIssued'         => (string)($r['DateIssued'] ?? ''),
            'DateIssuedDisplay'  => $this->formatCardDateIssued((string)($r['DateIssued'] ?? '')),
            'CardNumber'         => (string)($r['CardNumber'] ?? ''),
            'Expiry'             => (string)($r['Expiry'] ?? ''),
            'ExpiryDisplay'      => $this->formatCardExpiry((string)($r['Expiry'] ?? '')),
            'NameOnCard'         => (string)($r['NameOnCard'] ?? ''),
            'Image'              => $this->cardImagePath('GenCard'),
            'ApplicationID'      => $linkedApplicationId,
            'PendingCancel'      => !empty($pendingCancelByCardId[(int)($r['CardID'] ?? 0)]),
        ];

        if ($typeKey !== '' && $countsAsIssued) {
            $issuedByType[strtolower($typeKey)] = [
                'ApplicationID' => $linkedApplicationId,
                'CardID' => (int)($r['CardID'] ?? 0),
                'Status' => $rawStatus !== '' ? $rawStatus : 'Active',
            ];
        }
    }

    // Application status by type for apply cards
    $applicationsByType = $this->loadApplicationsByTypeForEmployee($conn, $employeeId);
    $applicationsByTypeId = [];
    foreach ($applicationsByType as $row) {
        $typeId = (int)($row['ApplicationTypeID'] ?? 0);
        if ($typeId > 0 && !isset($applicationsByTypeId[$typeId])) {
            $applicationsByTypeId[$typeId] = $row;
        }
    }
    $cards = $this->sortPortalCardsForDisplay($cards);
    $noCardHeldMessages = $this->loadNoCardHeldMessages($conn);
    $portalCardHoverMessages = $this->loadPortalCardHoverMessages($conn);
    $portalCardsHandyLink = $this->loadPortalCardsHandyLink($conn);
    $lostStolenMessage = $this->loadLostStolenMessage($conn);

    $this->render('portalcards/PortalCardsList', [
        'title' => 'My Cards',
        'cards' => $cards,
        'applicationsByType' => $applicationsByType,
        'applicationsByTypeId' => $applicationsByTypeId,
        'openLimitChangeByCardId' => $openLimitChangeByCardId,
        'issuedByType' => $issuedByType,
        'applicationBlacklist' => $applicationBlacklist,
        'applicationEntitlement' => $applicationEntitlement,
        'noCardHeldMessages' => $noCardHeldMessages,
        'portalCardHoverMessages' => $portalCardHoverMessages,
        'portalCardsHandyLink' => $portalCardsHandyLink,
        'lostStolenMessage' => $lostStolenMessage,
        'cancelReasonOptions' => $this->loadCancelCardReasonOptions($conn),
    ]);
}

    private function loadCancelCardReasonOptions(\PDO $conn): array
    {
        $fallback = [
            ['ReasonLabel' => 'Leaving Defence', 'SortOrder' => 10],
            ['ReasonLabel' => 'No Longer Required', 'SortOrder' => 20],
            ['ReasonLabel' => 'SERCAT 2', 'SortOrder' => 30],
            ['ReasonLabel' => 'Other', 'SortOrder' => 40],
        ];

        try {
            $model = new CancelCardReasonModel($conn);
            $rows = $model->listActive();
            return $rows !== [] ? $rows : $fallback;
        } catch (\Throwable $e) {
            return $fallback;
        }
    }



    private function nullIfBlank($v): ?string
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    }

    private function nullIfBlankNumber($v): ?float
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string)$v);
        if ($s === '') {
            return null;
        }
        return is_numeric($s) ? (float)$s : null;
    }

    private function collectAdminFormData(): array
    {
        return [
            'tblCardID'      => ($_POST['tblCardID'] ?? '') !== '' ? (int)$_POST['tblCardID'] : null,
            'EmployeeID'     => trim((string)($_POST['EmployeeID'] ?? '')),
            'ApplicationID'  => ($_POST['ApplicationID'] ?? '') !== '' ? (int)$_POST['ApplicationID'] : null,
            'CardType'       => trim((string)($_POST['CardType'] ?? '')),
            'CardTypeSub'    => trim((string)($_POST['CardTypeSub'] ?? '')),
            'Title'          => trim((string)($_POST['Title'] ?? '')),
            'FirstName'      => trim((string)($_POST['FirstName'] ?? '')),
            'MiddleName'     => trim((string)($_POST['MiddleName'] ?? '')),
            'Surname'        => trim((string)($_POST['Surname'] ?? '')),
            'NameOnCard'     => trim((string)($_POST['NameOnCard'] ?? '')),
            'Email'          => trim((string)($_POST['Email'] ?? '')),
            'HomePhone'      => trim((string)($_POST['HomePhone'] ?? '')),
            'WorkPhone'      => trim((string)($_POST['WorkPhone'] ?? '')),
            'MobilePhone'    => trim((string)($_POST['MobilePhone'] ?? '')),
            'Address1'       => trim((string)($_POST['Address1'] ?? '')),
            'Address2'       => trim((string)($_POST['Address2'] ?? '')),
            'Address3'       => trim((string)($_POST['Address3'] ?? '')),
            'Suburb'         => trim((string)($_POST['Suburb'] ?? '')),
            'State'          => trim((string)($_POST['State'] ?? '')),
            'PostCode'       => trim((string)($_POST['PostCode'] ?? '')),
            'Status'         => trim((string)($_POST['Status'] ?? '')),
            'ProcessStatus'  => trim((string)($_POST['ProcessStatus'] ?? '')),
            'PortalScreenProgress' => trim((string)($_POST['PortalScreenProgress'] ?? '')),
            'CardNumber'     => trim((string)($_POST['CardNumber'] ?? '')),
            'CardNumberShort'=> trim((string)($_POST['CardNumberShort'] ?? '')),
            'AccountNumber'  => trim((string)($_POST['AccountNumber'] ?? '')),
            'CreditLimitAmount' => $this->nullIfBlankNumber($_POST['CreditLimitAmount'] ?? null),
            'ActiveCeiling'   => $this->nullIfBlankNumber($_POST['ActiveCeiling'] ?? null),
            'TransactionLimit' => $this->nullIfBlankNumber($_POST['TransactionLimit'] ?? null),
            'ATMLimit'         => $this->nullIfBlankNumber($_POST['ATMLimit'] ?? null),
            'OTCLimit'         => $this->nullIfBlankNumber($_POST['OTCLimit'] ?? null),
            'DefaultCompany'    => trim((string)($_POST['DefaultCompany'] ?? '')),
            'DefaultCostCentre' => trim((string)($_POST['DefaultCostCentre'] ?? '')),
            'CMSUser'        => trim((string)($_POST['CMSUser'] ?? '')),
            'Notes'          => trim((string)($_POST['Notes'] ?? '')),
            'Active'         => isset($_POST['Active']) ? 'Y' : 'N',
            'OnHold'         => isset($_POST['OnHold']) ? 'Y' : 'N',
            'ValidAddress'   => isset($_POST['ValidAddress']) ? 'Y' : 'N',
            'Expiry'                 => $this->nullIfBlank($_POST['Expiry'] ?? null),
            'PortalInviteSent'       => $this->nullIfBlank($_POST['PortalInviteSent'] ?? null),
            'LoggedOntoPortal'       => $this->nullIfBlank($_POST['LoggedOntoPortal'] ?? null),
            'TermsAndConditions'     => $this->nullIfBlank($_POST['TermsAndConditions'] ?? null),
            'AddressConfirmedInPortal' => $this->nullIfBlank($_POST['AddressConfirmedInPortal'] ?? null),
            'AddressChangedInPortal' => $this->nullIfBlank($_POST['AddressChangedInPortal'] ?? null),
            'UpdatedBy' => (int)SessionHelper::get('auth.user_id', 0),
        ];
    }

    private function redirectAdminList(): void
    {
        header('Location: index.php?route=admin/portal-cards');
        exit;
    }

    private function mapCardTypeToKey(string $cardType, string $cardTypeSub = ''): string
    {
        $t = strtoupper(trim($cardType));
        $tNorm = preg_replace('/\s+/', ' ', $t) ?: $t;
        $sub = strtoupper(trim($cardTypeSub));
        $subNorm = preg_replace('/\s+/', ' ', $sub) ?: $sub;

        if ($t === 'DTC') {
            return str_contains($subNorm, 'LODGE') ? 'lodge' : 'dtc';
        }
        if ($t === 'DPC') {
            return 'dpc';
        }
        if (in_array($t, ['LODGE', 'DTL', 'DTLC', 'LDC', 'DTCLODGE'], true)) {
            return 'lodge';
        }
        if (str_contains($tNorm, 'DPC')) {
            return 'dpc';
        }
        if (str_contains($subNorm, 'LODGE') || str_contains($tNorm, 'LODGE')) {
            return 'lodge';
        }
        if (str_contains($tNorm, 'DTC')) {
            return 'dtc';
        }
        return '';
    }

    private function mapCardTypeToTitle(string $cardType, string $cardTypeSub = ''): string
    {
        $t = strtoupper(trim($cardType));
        $tNorm = preg_replace('/\s+/', ' ', $t) ?: $t;
        $sub = strtoupper(trim($cardTypeSub));
        $subNorm = preg_replace('/\s+/', ' ', $sub) ?: $sub;

        if ($t === 'DTC') {
            return str_contains($subNorm, 'LODGE') ? 'Defence Travel Lodge Card (Virtual)' : 'Defence Travel Card in hand (DTC-in-hand)';
        }
        if ($t === 'DPC') {
            return 'Defence Purchasing Card (DPC)';
        }
        if (in_array($t, ['LODGE', 'DTL', 'DTLC', 'LDC', 'DTCLODGE'], true)) {
            return 'Defence Travel Lodge Card (Virtual)';
        }
        if (str_contains($tNorm, 'DPC')) {
            return 'Defence Purchasing Card (DPC)';
        }
        if (str_contains($subNorm, 'LODGE') || str_contains($tNorm, 'LODGE')) {
            return 'Defence Travel Lodge Card (Virtual)';
        }
        if (str_contains($tNorm, 'DTC')) {
            return 'Defence Travel Card in hand (DTC-in-hand)';
        }
        return $cardType !== '' ? $cardType : 'Card';
    }

    private function mapTypeKeyToTitle(string $typeKey): string
    {
        $k = strtolower(trim($typeKey));
        if ($k === 'dtc') {
            return 'Defence Travel Card in hand (DTC-in-hand)';
        }
        if ($k === 'dpc') {
            return 'Defence Purchasing Card (DPC)';
        }
        if ($k === 'lodge') {
            return 'Defence Travel Lodge Card (Virtual)';
        }
        return $typeKey !== '' ? $typeKey : 'Card';
    }

    private function mapTypeKeyToApplicationTypeId(string $typeKey): int
    {
        return match (strtolower(trim($typeKey))) {
            'dpc' => 1,
            'dtc' => 2,
            'dual' => 3,
            'lodge' => 4,
            default => 0,
        };
    }

    private function sortPortalCardsForDisplay(array $cards): array
    {
        usort($cards, static function (array $a, array $b): int {
            $priorityFor = static function (array $row): int {
                $typeId = (int)($row['ApplicationTypeID'] ?? 0);
                if ($typeId > 0) {
                    return match ($typeId) {
                        2 => 10,
                        3 => 20,
                        4 => 30,
                        1 => 40,
                        default => 100,
                    };
                }

                $typeKey = strtolower(trim((string)($row['ApplicationTypeKey'] ?? '')));
                if ($typeKey !== '') {
                    return match ($typeKey) {
                        'dtc' => 10,
                        'dual' => 20,
                        'lodge' => 30,
                        'dpc' => 40,
                        default => 100,
                    };
                }

                $cardTypeSub = strtolower(trim((string)($row['CardTypeSub'] ?? '')));
                if ($cardTypeSub !== '') {
                    if (str_contains($cardTypeSub, 'dtc') && !str_contains($cardTypeSub, 'lodge')) {
                        return 10;
                    }
                    if (str_contains($cardTypeSub, 'dual')) {
                        return 20;
                    }
                    if (str_contains($cardTypeSub, 'lodge')) {
                        return 30;
                    }
                    if (str_contains($cardTypeSub, 'dpc')) {
                        return 40;
                    }
                }

                $cardType = strtolower(trim((string)($row['CardType'] ?? '')));
                if (str_contains($cardType, 'dtc') || (str_contains($cardType, 'defence travel card') && !str_contains($cardType, 'lodge'))) {
                    return 10;
                }
                if (str_contains($cardType, 'dual')) {
                    return 20;
                }
                if (str_contains($cardType, 'lodge')) {
                    return 30;
                }
                if (str_contains($cardType, 'dpc') || str_contains($cardType, 'defence purchasing card')) {
                    return 40;
                }

                return 100;
            };

            $priorityCompare = $priorityFor($a) <=> $priorityFor($b);
            if ($priorityCompare !== 0) {
                return $priorityCompare;
            }

            return strcasecmp((string)($a['CardType'] ?? ''), (string)($b['CardType'] ?? ''));
        });

        return $cards;
    }

    private function getCancelCardMaxFutureMonths(\PDO $db): int
    {
        $defaultMonths = 6;
        try {
            $settings = new SystemSettingsModel($db);
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

    private function loadNoCardHeldMessages(\PDO $db): array
    {
        $defaults = [
            'dtc' => '',
            'dpc' => '',
            'dual' => '',
            'lodge' => '',
        ];

        try {
            $settings = new SystemSettingsModel($db);
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

    private function loadPortalCardHoverMessages(\PDO $db): array
    {
        $defaults = [
            'dtc' => '',
            'dpc' => '',
            'dual' => '',
            'lodge' => '',
        ];

        try {
            $settings = new SystemSettingsModel($db);
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

    private function loadPortalCardsHandyLink(\PDO $db): array
    {
        $defaults = [
            'text' => '',
            'url' => '',
        ];

        try {
            $settings = new SystemSettingsModel($db);
            return [
                'text' => trim((string)($settings->get('PORTAL_CARDS_HANDY_LINK_TEXT') ?? '')),
                'url' => trim((string)($settings->get('PORTAL_CARDS_HANDY_LINK_URL') ?? '')),
            ];
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    private function loadLostStolenMessage(\PDO $db): string
    {
        try {
            $settings = new SystemSettingsModel($db);
            return trim((string)($settings->get('PORTAL_LOST_STOLEN_MESSAGE') ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function cardImagePath(string $baseName): string
    {
        $png = 'assets/img/' . $baseName . '.png';
        $jpg = 'assets/img/' . $baseName . '.jpg';
        $pngFs = __DIR__ . '/../../public/' . $png;
        return is_file($pngFs) ? $png : $jpg;
    }

    private function formatCardExpiry(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '-';
        }

        $ts = strtotime($value);
        if ($ts !== false) {
            return date('Y/m', $ts);
        }

        if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})$~', $value, $m)) {
            return sprintf('%04d/%02d', (int)$m[3], (int)$m[2]);
        }

        if (preg_match('~^(\d{4})-(\d{1,2})-(\d{1,2})~', $value, $m)) {
            return sprintf('%04d/%02d', (int)$m[1], (int)$m[2]);
        }

        return $value;
    }

    private function formatCardDateIssued(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '-';
        }

        $ts = strtotime($value);
        if ($ts !== false) {
            return date('d/m/Y', $ts);
        }

        if (preg_match('~^(\d{4})-(\d{1,2})-(\d{1,2})~', $value, $m)) {
            return sprintf('%02d/%02d/%04d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }

        if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})$~', $value, $m)) {
            return sprintf('%02d/%02d/%04d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }

        return $value;
    }

    private function loadPendingCancelMap(array $cardIds): array
    {
        $cardIds = array_values(array_filter(array_map(static fn($v): int => (int)$v, $cardIds), static fn(int $v): bool => $v > 0));
        if (!$cardIds) {
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

    private function loadOpenLimitChangeApplicationMap(\PDO $db, array $cardIds, string $employeeId = ''): array
    {
        $employeeId = trim($employeeId);
        $cardIds = array_values(array_filter(
            array_map(static fn($v): int => (int)$v, $cardIds),
            static fn(int $v): bool => $v > 0
        ));
        if (!$cardIds) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($cardIds), '?'));
        $params = array_merge(
            ['application', 'dtc_limit_change', 'dpc_limit_change', 'lodge_limit_change', $employeeId, $employeeId],
            $cardIds
        );

        $stmt = $db->prepare("
            SELECT
                a.ApplicationID,
                a.EmployeeID,
                a.Status,
                a.SubmittedAt,
                a.LastSavedAt,
                at.ApplicationTypeKey,
                LTRIM(RTRIM(ISNULL(JSON_VALUE(s.DataJson, '$.target_employee_id'), ''))) AS TargetEmployeeID,
                TRY_CONVERT(int, JSON_VALUE(s.DataJson, '$.card_id')) AS CardID
            FROM dbo.tblApplications a
            INNER JOIN dbo.tblApplicationTypes at
                ON at.ApplicationTypeID = a.ApplicationTypeID
            INNER JOIN dbo.tblApplicationSteps s
                ON s.ApplicationID = a.ApplicationID
               AND s.StepKey = ?
            WHERE at.ApplicationTypeKey IN (?, ?, ?)
              AND LOWER(LTRIM(RTRIM(ISNULL(a.Status, '')))) IN ('draft', 'inprogress', 'submitted', 'tobeapproved', 'senttobank', 'sent_to_bank')
              AND (
                    LTRIM(RTRIM(ISNULL(CAST(a.EmployeeID AS NVARCHAR(50)), ''))) = ?
                    OR LTRIM(RTRIM(ISNULL(JSON_VALUE(s.DataJson, '$.target_employee_id'), ''))) = ?
                  )
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
                'EmployeeID' => trim((string)($row['EmployeeID'] ?? '')),
                'TargetEmployeeID' => trim((string)($row['TargetEmployeeID'] ?? '')),
                'CardID' => $cardId,
                'Status' => (string)($row['Status'] ?? ''),
                'ApplicationTypeKey' => (string)($row['ApplicationTypeKey'] ?? ''),
            ];
        }

        return $out;
    }

    private function loadApplicationsByTypeForEmployee(\PDO $db, string $employeeId): array
    {
        $employeeId = trim($employeeId);
        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($employeeId === '' && $userId <= 0) {
            return [];
        }

        if ($employeeId !== '') {
            $where = "LTRIM(RTRIM(ISNULL(CAST(a.EmployeeID AS NVARCHAR(50)), ''))) = :employeeId";
            $params = ['employeeId' => $employeeId];
        } else {
            $where = "(
                a.UserID = :userId
                AND LTRIM(RTRIM(ISNULL(CAST(a.EmployeeID AS NVARCHAR(50)), ''))) = ''
            )";
            $params = ['userId' => $userId];
        }

        $st = $db->prepare("
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
        $st->execute($params);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $applicationsByType = [];
        foreach ($rows as $r) {
            $k = strtolower(trim((string)($r['ApplicationTypeKey'] ?? '')));
            if ($k === '' || isset($applicationsByType[$k])) {
                continue;
            }
            $applicationsByType[$k] = $r;
        }

        return $applicationsByType;
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

        foreach ([1, 2, 3, 4] as $typeId) {
            $match = $this->getBlacklistMatch($employeeId, $typeId);
            if (empty($match['blocked'])) {
                continue;
            }
            $state['blocked_any'] = true;
            $state['by_type_id'][$typeId] = true;
            if ($state['message'] === '') {
                $state['message'] = $this->buildBlacklistMessage($match);
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
            error_log('[PortalCardsController::getBlacklistMatch] ' . $e->getMessage());
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
            error_log('[PortalCardsController::resolveEmployeeType] ' . $e->getMessage());
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
            error_log('[PortalCardsController::normalizeEmployeeType] ' . $e->getMessage());
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
            error_log('[PortalCardsController::isEmployeeTypeEntitledForType] ' . $e->getMessage());
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
                error_log('[PortalCardsController::buildEntitlementEmployeeTypeCandidates] ' . $e->getMessage());
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
            error_log('[PortalCardsController::hasPositionTypeOverride] ' . $e->getMessage());
            return false;
        }
    }
}
