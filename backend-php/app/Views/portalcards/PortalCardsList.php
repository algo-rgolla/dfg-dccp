<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';
require_once __DIR__ . '/../../../shared/rich_text.php';

use App\Shared\SessionHelper;

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('format_portal_card_expiry')) {
    function format_portal_card_expiry(?string $value): string
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
}

if (!function_exists('format_portal_card_date')) {
    function format_portal_card_date(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '—';
        }
        $ts = strtotime($value);
        return $ts ? date('d/m/Y', $ts) : $value;
    }
}

$cardStatusLabel = static function (string $status, bool $pendingCancel): string {
    if ($pendingCancel) {
        return 'Active Card - Cancellation Submitted';
    }
    $trimmed = trim($status);
    if ($trimmed === '') {
        return 'Active';
    }
    if (strcasecmp($trimmed, 'Not held') === 0) {
        return 'Currently not held';
    }
    if (strcasecmp($trimmed, 'VX') === 0) {
        return 'Cancelled';
    }
    if (str_starts_with(strtoupper($trimmed), 'XS')) {
        return 'Temporary Lost / Stolen';
    }
    return $trimmed;
};

$cardImagePath = static function (string $baseName): string {
    $png = 'assets/img/' . $baseName . '.png';
    $jpg = 'assets/img/' . $baseName . '.jpg';
    $pngFs = __DIR__ . '/../../../public/' . $png;
    return is_file($pngFs) ? $png : $jpg;
};

$displayName = (string)(SessionHelper::get('auth.display_name') ?? '');
$displayName = h($displayName);

// CSRF token (even if not used on this page today, it’s handy for modals/forms)
$csrf = h(csrf_token());
$onBehalfError = (string)(SessionHelper::get('onBehalf.error') ?? '');
$onBehalfOld = SessionHelper::get('onBehalf.old');
SessionHelper::forget('onBehalf.error');
SessionHelper::forget('onBehalf.old');
if (!is_array($onBehalfOld)) {
    $onBehalfOld = [];
}
$cancelModalOld = SessionHelper::get('cards.cancel.modal_reopen');
SessionHelper::forget('cards.cancel.modal_reopen');
if (!is_array($cancelModalOld)) {
    $cancelModalOld = [];
}
$cancelOldCardId = (int)($cancelModalOld['card_id'] ?? 0);
$cancelOldReason = (string)($cancelModalOld['cancel_reason'] ?? '');
$cancelOldReasonOther = (string)($cancelModalOld['cancel_reason_other'] ?? '');
$cancelOldDate = (string)($cancelModalOld['cancel_date'] ?? '');
$cancelReasonOptions = is_array($cancelReasonOptions ?? null) ? $cancelReasonOptions : [];
$onBehalfCardType = strtoupper(trim((string)($onBehalfOld['card_type'] ?? '')));
$onBehalfEmployeeId = (string)($onBehalfOld['employee_id'] ?? '');
$onBehalfLast4 = (string)($onBehalfOld['last4'] ?? '');
$cancelDateMin = (string)($cancelDateMin ?? date('Y-m-d'));
$cancelDateMax = (string)($cancelDateMax ?? date('Y-m-d'));
$cancelMaxMonths = (int)($cancelMaxMonths ?? 6);
$openLimitChangeByCardId = (isset($openLimitChangeByCardId) && is_array($openLimitChangeByCardId)) ? $openLimitChangeByCardId : [];
$lostStolenMessage = trim((string)($lostStolenMessage ?? ''));
$limitChangeDebug = ((string)($_GET['limit_change_debug'] ?? '') === '1');
$loggedInUserId = (int)(SessionHelper::get('auth.user_id') ?? 0);

/**
 * EXPECTED INPUT:
 *   - $cards : array provided by controller (active cards for this employee)
 *
 * BEHAVIOUR:
 *   - If $cards is empty, show the 3 card types so user can apply.
 */

// Ensure $cards is always an array
$cards = (isset($cards) && is_array($cards)) ? $cards : [];
$cards = array_values(array_filter($cards, static function (array $row): bool {
    $isHeld = (int)($row['IsHeld'] ?? 0) === 1;
    if (!$isHeld) {
        return true;
    }

    $status = trim((string)($row['Status'] ?? ''));
    $statusUpper = strtoupper($status);
    return $status === ''
        || strcasecmp($status, 'Active') === 0
        || str_starts_with($statusUpper, 'XS');
}));
$loggedInEmployeeId = trim((string)(SessionHelper::get('auth.employee_id') ?? ''));
$allowTravelCards = (strlen($loggedInEmployeeId) === 7);

// Fallback "apply" cards (shown only when there are NO cards for the user)
$applyCards = [
    [
        'CardType'           => 'Defence Travel Card in hand (DTC-in-hand)',
        'ApplicationTypeID'  => 2,
        'ApplicationTypeKey' => 'dtc',
        'IsHeld'             => 0,
        'Status'             => 'Not held',
        'Image'              => $cardImagePath('GenCardDTC'),
    ],
    [
        'CardType'           => 'Defence Purchasing Card',
        'ApplicationTypeID'  => 1,
        'ApplicationTypeKey' => 'dpc',
        'IsHeld'             => 0,
        'Status'             => 'Not held',
        'Image'              => $cardImagePath('GenCardDPC'),
    ],
    [
        'CardType'           => 'Defence Travel Cards (Lodge Card and DTC-in-hand)',
        'ApplicationTypeID'  => 3,
        'ApplicationTypeKey' => 'dual',
        'IsHeld'             => 0,
        'Status'             => 'Not held',
        'Image'              => $cardImagePath('GenCardDTC'),
    ],
    [
        'CardType'           => 'Defence Travel Lodge Card (Virtual)',
        'ApplicationTypeID'  => 4,
        'ApplicationTypeKey' => 'lodge',
        'IsHeld'             => 0,
        'Status'             => 'Not held',
        'Image'              => $cardImagePath('GenCardLodge'),
    ],
];

if (!$allowTravelCards) {
    $isNonDpcType = static function (array $row): bool {
        $typeKey = strtolower(trim((string)($row['ApplicationTypeKey'] ?? '')));
        if ($typeKey !== '' && $typeKey !== 'dpc') {
            return true;
        }
        $cardType = strtolower(trim((string)($row['CardType'] ?? '')));
        return str_contains($cardType, 'defence travel');
    };

    $applyCards = array_values(array_filter($applyCards, static fn(array $row): bool => !$isNonDpcType($row)));
    $cards = array_values(array_filter($cards, static fn(array $row): bool => !$isNonDpcType($row)));
}

$hasDtcCard = false;
foreach ($cards as $row) {
    $typeKey = strtolower(trim((string)($row['ApplicationTypeKey'] ?? '')));
    $cardType = strtolower(trim((string)($row['CardType'] ?? '')));
    if ($typeKey === 'dtc' || (str_contains($cardType, 'defence travel card') && !str_contains($cardType, 'lodge'))) {
        $hasDtcCard = true;
        break;
    }
}

// Track if user actually has cards after filtering
$hasCards = !empty($cards);

$allowedTypeIds = $hasDtcCard ? [1, 4] : [1, 3];

$applyCards = array_values(array_filter(
    $applyCards,
    static fn(array $row): bool => in_array((int)($row['ApplicationTypeID'] ?? 0), $allowedTypeIds, true)
));

// Optional: application status by type (passed in by controller)
$applicationsByType = (isset($applicationsByType) && is_array($applicationsByType)) ? $applicationsByType : [];
$applicationsByTypeId = (isset($applicationsByTypeId) && is_array($applicationsByTypeId)) ? $applicationsByTypeId : [];
// Issued cards by type (passed in by controller)
$issuedByType = (isset($issuedByType) && is_array($issuedByType)) ? $issuedByType : [];
$applicationBlacklist = (isset($applicationBlacklist) && is_array($applicationBlacklist)) ? $applicationBlacklist : [];
$blacklistByTypeId = (isset($applicationBlacklist['by_type_id']) && is_array($applicationBlacklist['by_type_id'])) ? $applicationBlacklist['by_type_id'] : [];
$blacklistMessage = trim((string)($applicationBlacklist['message'] ?? ''));
$isAnyApplicationBlocked = !empty($applicationBlacklist['blocked_any']);
$applicationEntitlement = (isset($applicationEntitlement) && is_array($applicationEntitlement)) ? $applicationEntitlement : [];
$entitlementByTypeId = (isset($applicationEntitlement['by_type_id']) && is_array($applicationEntitlement['by_type_id'])) ? $applicationEntitlement['by_type_id'] : [];
$hasEntitlementRestrictions = in_array(false, $entitlementByTypeId, true);
$employeeTypeEligibilityMessage = 'Some application types are unavailable based on your employee type entitlement.';
$noCardHeldMessages = (isset($noCardHeldMessages) && is_array($noCardHeldMessages)) ? $noCardHeldMessages : [];
$portalCardHoverMessages = (isset($portalCardHoverMessages) && is_array($portalCardHoverMessages)) ? $portalCardHoverMessages : [];
$portalCardsHandyLink = (isset($portalCardsHandyLink) && is_array($portalCardsHandyLink)) ? $portalCardsHandyLink : [];
$handyLinkText = trim((string)($portalCardsHandyLink['text'] ?? ''));
$handyLinkUrl = trim((string)($portalCardsHandyLink['url'] ?? ''));
$applyCards = array_values(array_filter(
    $applyCards,
    static fn(array $row): bool => !array_key_exists((int)($row['ApplicationTypeID'] ?? 0), $entitlementByTypeId)
        || !empty($entitlementByTypeId[(int)($row['ApplicationTypeID'] ?? 0)])
));

// If no cards, show the apply options. If some cards exist, add apply cards for missing types.
if (!$hasCards) {
    $cards = $applyCards;
} else {
    $existingTypes = [];
    foreach ($cards as $c) {
        $k = strtolower(trim((string)($c['ApplicationTypeKey'] ?? '')));
        if ($k !== '') {
            $existingTypes[$k] = true;
        }
    }
    foreach ($applyCards as $ac) {
        $k = strtolower(trim((string)($ac['ApplicationTypeKey'] ?? '')));
        if ($k === '' || isset($existingTypes[$k])) {
            continue;
        }
        $cards[] = $ac;
    }
}

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

?>
<style>
.portal-alert-blocked {
  background: #fdecec;
  border: 1px solid #f3c4c2;
  color: #7a271a;
}

.portal-inline-info {
  color: #12344d;
}

.portal-inline-info a {
  color: #0b5cad;
}

.portal-badge-attention {
  background: #0b5cad;
  color: #ffffff;
}

.portal-panel-info {
  background: #e8f1fb;
  border-color: #b8d0ee;
  color: #12344d;
}

.card-details-grid {
  width: 100%;
}

.card-details-grid .detail-row {
  display: grid;
  grid-template-columns: 160px 1fr; /* label | value */
  align-items: center;
  padding: 4px 0;
  column-gap: 12px;
}

.card-details-grid .detail-label {
  font-size: 0.85rem;
  color: #6c757d; /* Bootstrap muted */
  white-space: nowrap;
}

.card-details-grid .detail-value {
  font-weight: 500;
  color: #212529;
  white-space: nowrap;          /* prevents wrapping */
  overflow: hidden;
  text-overflow: ellipsis;      /* graceful cut-off if needed */
  margin: 0;
}

.card-details-grid dt,
.card-details-grid dd {
  margin-bottom: 0;
}

.card-action-group {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

/* Allow wrapping on small screens */
@media (max-width: 768px) {
  .card-details-grid .detail-row {
    grid-template-columns: 1fr;
  }
  .card-details-grid .detail-value {
    white-space: normal;
  }
}
</style>

<section class="card shadow-sm mt-4" aria-labelledby="myCardsHeading">

  <div class="card-header d-flex justify-content-between align-items-center">
    <h1 class="h5 mb-0">
      <i class="bi bi-credit-card-2-front me-2" aria-hidden="true"></i>
      <span id="myCardsHeading"><?= __t('my_cards') ?? 'My Cards' ?></span>
      <?php if ($displayName !== ''): ?>
        <span class="text-muted fw-normal ms-2">
          <?= $displayName ?>
        </span>
      <?php endif; ?>
    </h1>

    <div class="btn-group">
      <button
        type="button"
        class="btn btn-sm btn-outline-secondary"
        data-bs-toggle="modal"
        data-bs-target="#supportModal"
      >
        <i class="bi bi-life-preserver me-1"></i><?= __t('support') ?? 'Support' ?>
      </button>

      <button
        type="button"
        class="btn btn-sm btn-outline-primary"
        data-bs-toggle="modal"
        data-bs-target="#onBehalfModal"
      >
        <i class="bi bi-people me-1"></i>Apply for Limit Change on behalf of another cardholder
      </button>
    </div>
  </div>

  <div class="card-body border-bottom pb-0">
    <div id="systemMessages" class="mb-3" role="status" aria-live="polite" aria-atomic="true"></div>
  </div>

  <div class="card-body">

    <p class="text-muted mb-3">
      <?= __t('cards_intro') ?? 'Manage your existing cards or apply for new ones.' ?>
    </p>
    <?php if ($handyLinkText !== '' && $handyLinkUrl !== ''): ?>
      <p class="mb-3">
        <a href="<?= h($handyLinkUrl) ?>" target="_blank" rel="noopener noreferrer">
          <?= h($handyLinkText) ?>
        </a>
      </p>
    <?php endif; ?>

    <?php if (!$hasCards): ?>
      <div class="alert alert-info">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
        <?= __t('no_cards_found') ?? 'No active cards were found. You can apply for a card below.' ?>
      </div>
    <?php endif; ?>

    <?php if ($isAnyApplicationBlocked): ?>
      <div class="alert portal-alert-blocked">
        <i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i>
        <?= h($blacklistMessage !== '' ? $blacklistMessage : 'You are not eligible to apply for one or more card types.') ?>
      </div>
    <?php endif; ?>

    <?php if ($hasEntitlementRestrictions): ?>
      <div class="alert portal-alert-blocked">
        <i class="bi bi-person-x me-1" aria-hidden="true"></i>
        <?= h($employeeTypeEligibilityMessage) ?>
      </div>
    <?php endif; ?>

    <?php if ($limitChangeDebug): ?>
      <div class="alert alert-secondary small">
        <div><strong>Limit Change Debug</strong></div>
        <div>Logged-in UserID: <?= h((string)$loggedInUserId) ?></div>
        <div>Logged-in EmployeeID: <?= h($loggedInEmployeeId) ?></div>
        <div>Open limit change card keys: <?= h(implode(', ', array_map('strval', array_keys($openLimitChangeByCardId)))) ?></div>
      </div>
    <?php endif; ?>

    <?php foreach ($cards as $c): ?>
      <?php
        $isHeld = (int)($c['IsHeld'] ?? 0) === 1;
        $status = (string)($c['Status'] ?? ($isHeld ? 'Active' : 'Not held'));
        $statusIsXs = str_starts_with(strtoupper(trim($status)), 'XS');
        $statusIsCancelled = strcasecmp(trim($status), 'VX') === 0 || strcasecmp(trim($status), 'Cancelled') === 0;
        $showHeldDetails = $isHeld || $statusIsXs || $statusIsCancelled;
        $cardTitle  = (string)($c['CardType'] ?? 'Card');
        $typeKey = trim((string)($c['ApplicationTypeKey'] ?? ''));
        $typeId = (int)($c['ApplicationTypeID'] ?? 0);
        $applyBlocked = (!$isHeld && $typeId > 0 && !empty($blacklistByTypeId[$typeId]));
        $img    = (string)($c['Image'] ?? $cardImagePath('GenCard'));
        $typeKeyLower = strtolower($typeKey);
        $cardTypeLower = strtolower($cardTitle);
        $showDualImages = ($typeId === 3 || $typeKeyLower === 'dual');
        if ($typeId === 4 || $typeKeyLower === 'lodge') {
            $img = $cardImagePath('GenCardLodge');
        } elseif ($typeId === 2 || $typeKeyLower === 'dtc') {
            $img = $cardImagePath('GenCardDTC');
        } elseif ($typeId === 1 || $typeKeyLower === 'dpc') {
            $img = $cardImagePath('GenCardDPC');
        }
        $appInfo = $typeKey !== '' ? ($applicationsByType[strtolower($typeKey)] ?? null) : null;
        if (!$appInfo && $typeId > 0) {
            $appInfo = $applicationsByTypeId[$typeId] ?? null;
        }
        $issuedInfo = $typeKey !== '' ? ($issuedByType[strtolower($typeKey)] ?? null) : null;
        $appStatus = strtolower(trim((string)($appInfo['Status'] ?? '')));
        $appId = (int)($appInfo['ApplicationID'] ?? 0);
        $appInProgress = in_array($appStatus, ['draft', 'inprogress'], true);
        $appSubmitted = ($appStatus !== '' && !$appInProgress);
        $pendingCancel = !empty($c['PendingCancel']);
        $openLimitChangeInfo = $openLimitChangeByCardId[(int)($c['CardID'] ?? 0)] ?? null;
        $openLimitChangeStatus = strtolower(trim((string)($openLimitChangeInfo['Status'] ?? '')));
        $cardTypeRaw = strtoupper(trim((string)($c['CardTypeSub'] ?? $c['CardType'] ?? '')));
        if ($typeKey === 'dtc' || str_contains($cardTypeRaw, 'DTC')) {
          $limitTypeKey = 'dtc_limit_change';
        } elseif ($typeKey === 'dpc' || str_contains($cardTypeRaw, 'DPC')) {
          $limitTypeKey = 'dpc_limit_change';
        } elseif ($typeKey === 'lodge') {
          $limitTypeKey = 'lodge_limit_change';
        } else {
          $limitTypeKey = $typeKey;
        }
        $openLimitChangeMatchesType = is_array($openLimitChangeInfo)
          && strcasecmp(trim((string)($openLimitChangeInfo['ApplicationTypeKey'] ?? '')), trim((string)$limitTypeKey)) === 0;
        $hasOpenLimitChange = $openLimitChangeMatchesType && (int)($openLimitChangeInfo['ApplicationID'] ?? 0) > 0;
        $actionBlocked = $pendingCancel || $statusIsXs || $statusIsCancelled;
        $actionBlockedMessage = $pendingCancel
            ? 'Changes are unavailable while cancellation is submitted.'
            : ($statusIsXs ? 'Changes are unavailable while the card is temporary lost / stolen.' : ($statusIsCancelled ? 'Changes are unavailable because the card is cancelled.' : ''));
        $limitChangeBlocked = $actionBlocked || $hasOpenLimitChange;
        $limitChangeBlockedMessage = $actionBlockedMessage;
        if ($hasOpenLimitChange) {
            $limitChangeBlockedMessage = in_array($openLimitChangeStatus, ['draft', 'inprogress'], true)
                ? 'A limit change application is already in progress for this card.'
                : 'A limit change application already exists for this card and must be processed before another can be created.';
        }
        $displayStatus = $cardStatusLabel($status, $pendingCancel);
        $showLostStolenAction = !($typeId === 4 || $typeKeyLower === 'lodge' || str_contains($cardTypeLower, 'lodge'));

        // badge style
        $badgeClass = $pendingCancel ? 'portal-badge-attention' : ($statusIsXs ? 'portal-badge-attention' : ($statusIsCancelled ? 'bg-secondary' : ($showHeldDetails ? 'bg-success' : 'bg-secondary')));
        $noCardHeldText = trim((string)($noCardHeldMessages[strtolower($typeKey)] ?? ''));
        $typeHoverText = trim((string)($portalCardHoverMessages[strtolower($typeKey)] ?? ''));

        // Optional: a friendly line under the card title
        $hintText = $showHeldDetails
            ? (__t('cards_active_hint') ?? 'Your active card — manage your details or request changes.')
            : (__t('cards_not_held') ?? 'Currently not held');
        if ($statusIsXs) {
            $hintText = 'This card is temporarily lost / stolen.';
        }
        if ($statusIsCancelled) {
            $hintText = 'This card is cancelled.';
        }
        $cardKey = (string)($c['CardID'] ?? ($typeId > 0 ? $typeId : md5($cardTitle . '|' . $typeKey)));
        $cardKey = preg_replace('/[^A-Za-z0-9_-]/', '-', $cardKey);
        $cardHeadingId = 'cardTitle-' . $cardKey;
        $cardStatusId = 'cardStatus-' . $cardKey;
        $cardHintId = 'cardHint-' . $cardKey;
        $cardInfoId = 'cardInfo-' . $cardKey;
        $cardActionsId = 'cardActions-' . $cardKey;
      ?>

      <article class="card mb-3 <?= $showHeldDetails ? '' : 'opacity-75' ?>" aria-labelledby="<?= h($cardHeadingId) ?>" aria-describedby="<?= h($cardStatusId) ?> <?= h($cardHintId) ?>">
        <div class="card-body">

          <div class="row g-3 align-items-stretch">

            <div class="col-md-3">
              <?php if ($showDualImages): ?>
                <div class="row g-2">
                  <div class="col-6">
                    <img
                      src="<?= h($cardImagePath('GenCardDTC')) ?>"
                      alt="Defence Travel Card in hand (DTC-in-hand)"
                      class="img-fluid rounded border w-100 bg-white"
                      style="object-fit: contain; height: 160px; padding: 4px;"
                    >
                  </div>
                  <div class="col-6">
                    <img
                      src="<?= h($cardImagePath('GenCardLodge')) ?>"
                      alt="Defence Travel Lodge Card (Virtual)"
                      class="img-fluid rounded border w-100 bg-white"
                      style="object-fit: contain; height: 160px; padding: 4px;"
                    >
                  </div>
                </div>
              <?php else: ?>
                <img
                  src="<?= h($img) ?>"
                alt="<?= h($cardTitle) ?>"
                  class="img-fluid rounded border w-100"
                  style="object-fit: cover; min-height: 160px;"
                >
              <?php endif; ?>
            </div>

            <div class="col-md-9">

              <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                  <h2 class="h5 mb-1 d-flex align-items-center gap-2 flex-wrap" id="<?= h($cardHeadingId) ?>">
                    <span><?= h($cardTitle) ?></span>
                    <?php if ($typeHoverText !== ''): ?>
                      <span
                        class="text-info"
                        tabindex="0"
                        role="button"
                        data-bs-toggle="tooltip"
                        data-bs-placement="top"
                        title="<?= h($typeHoverText) ?>">
                        <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                      </span>
                    <?php endif; ?>
                  </h2>
                  <?php if ($typeHoverText !== ''): ?>
                    <div class="visually-hidden" id="<?= h($cardInfoId) ?>"><?= h($typeHoverText) ?></div>
                  <?php endif; ?>
                  <div class="text-muted small mb-2" id="<?= h($cardHintId) ?>"><?= $hintText ?></div>
                  <?php if (!$showHeldDetails && $noCardHeldText !== ''): ?>
                    <div class="small text-muted mb-2"><?= nl2br(h($noCardHeldText), false) ?></div>
                  <?php endif; ?>
                </div>

                <span class="badge <?= h($badgeClass) ?>" id="<?= h($cardStatusId) ?>"><?= h($displayStatus) ?></span>
              </div>

              <?php if ($showHeldDetails): ?>
                <dl class="card-details-grid mb-2" aria-label="Card details">

                  <div class="detail-row">
                    <dt class="detail-label">
                      <i class="bi bi-person me-1" aria-hidden="true"></i><?= __t('name') ?? 'Name' ?>
                    </dt>
                    <dd class="detail-value">
                      <?= h((string)($c['Name'] ?? '—')) ?>
                    </dd>
                  </div>

                  <div class="detail-row">
                    <dt class="detail-label">
                      <i class="bi bi-geo-alt me-1" aria-hidden="true"></i><?= __t('address') ?? 'Address' ?>
                    </dt>
                    <dd class="detail-value">
                      <?= h((string)($c['Address'] ?? '—')) ?>
                    </dd>
                  </div>

                  <div class="detail-row">
                    <dt class="detail-label">
                      <i class="bi bi-cash-stack me-1" aria-hidden="true"></i><?= __t('limit') ?? 'Limit' ?>
                    </dt>
                    <dd class="detail-value">
                      <?php
                        $limit = $c['Limit'] ?? null;
                        echo $limit === null ? '—' : h('$' . number_format((float)$limit, 0));
                      ?>
                    </dd>
                  </div>

                  <div class="detail-row">
                    <dt class="detail-label">
                      <i class="bi bi-calendar-check me-1" aria-hidden="true"></i>Date Issued
                    </dt>
                    <dd class="detail-value">
                      <?= h((string)($c['DateIssuedDisplay'] ?? format_portal_card_date((string)($c['DateIssued'] ?? '')))) ?>
                    </dd>
                  </div>

                  <div class="detail-row">
                    <dt class="detail-label">
                      <i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= __t('status') ?? 'Status' ?>
                    </dt>
                    <dd class="detail-value">
                      <span class="fw-semibold"><?= h($displayStatus) ?></span>
                    </dd>
                  </div>

                </dl>

                <div class="btn-group btn-group-sm card-action-group" role="group" aria-label="Actions for <?= h($cardTitle) ?>" id="<?= h($cardActionsId) ?>">
                  <?php if (!empty($c['ApplicationID'])): ?>
                    <a href="index.php?route=applications/edit&id=<?= urlencode((string)$c['ApplicationID']) ?>" class="btn btn-outline-secondary">
                      <i class="bi bi-eye me-1" aria-hidden="true"></i><?= __t('view_application') ?? 'View Application' ?>
                    </a>
                  <?php endif; ?>
                  <!-- Edit Address -->
                  <?php if ($actionBlocked): ?>
                    <span class="d-inline-block" tabindex="0" aria-describedby="<?= h($cardActionsId) ?>-edit-help">
                      <a
                        href="#"
                        class="btn btn-primary disabled"
                        aria-disabled="true"
                        tabindex="-1"
                        aria-describedby="<?= h($cardActionsId) ?>-edit-help"
                      >
                        <i class="bi bi-geo-alt me-1" aria-hidden="true"></i>Edit Contact Details
                      </a>
                    </span>
                    <span class="visually-hidden" id="<?= h($cardActionsId) ?>-edit-help"><?= h($actionBlockedMessage) ?></span>
                  <?php else: ?>
                    <a
                      href="index.php?route=cards/change-address&id=<?= urlencode((string)($c['CardID'] ?? 0)) ?>"
                      class="btn btn-primary"
                    >
                      <i class="bi bi-geo-alt me-1" aria-hidden="true"></i>Edit Contact Details
                    </a>
                  <?php endif; ?>

                  <!-- Request Limit Change -->
                  <?php ?>
                  <?php if ($limitChangeBlocked): ?>
                    <span class="d-inline-block" tabindex="0" aria-describedby="<?= h($cardActionsId) ?>-limit-help">
                      <a
                        href="#"
                        class="btn btn-outline-primary disabled"
                        aria-disabled="true"
                        tabindex="-1"
                        aria-describedby="<?= h($cardActionsId) ?>-limit-help"
                      >
                        <i class="bi bi-cash-stack me-1" aria-hidden="true"></i><?= __t('request_limit_change') ?? 'Request Limit Change' ?>
                      </a>
                    </span>
                    <span class="visually-hidden" id="<?= h($cardActionsId) ?>-limit-help"><?= h($limitChangeBlockedMessage) ?></span>
                  <?php else: ?>
                    <a
                      href="index.php?route=cards/request-limit-change&type=<?= urlencode($limitTypeKey) ?>&id=<?= urlencode((string)($c['CardID'] ?? 0)) ?>"
                      class="btn btn-outline-primary"
                    >
                      <i class="bi bi-cash-stack me-1" aria-hidden="true"></i><?= __t('request_limit_change') ?? 'Request Limit Change' ?>
                    </a>
                  <?php endif; ?>

                  <!-- View History -->
                  <a href="index.php?route=cards/history&type=<?= urlencode($typeKey) ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-clock-history me-1" aria-hidden="true"></i><?= __t('view_history') ?? 'View History' ?>
                  </a>

                  <!-- View Change Requests -->
                  <a href="index.php?route=cards/change-requests&card_id=<?= urlencode((string)($c['CardID'] ?? 0)) ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-list-check me-1" aria-hidden="true"></i>Change Requests
                  </a>

                  <?php if ($showLostStolenAction): ?>
                    <button
                      type="button"
                      class="btn btn-outline-primary"
                      data-bs-toggle="modal"
                      data-bs-target="#lostStolenModal"
                    >
                      <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Card Not Received/Lost/Stolen/Damaged
                    </button>
                  <?php endif; ?>

                  <!-- Cancel -->
                  <?php if ($actionBlocked): ?>
                    <span class="d-inline-block" tabindex="0" aria-describedby="<?= h($cardActionsId) ?>-cancel-help">
                      <button
                        type="button"
                        class="btn btn-outline-danger"
                        disabled
                        aria-describedby="<?= h($cardActionsId) ?>-cancel-help"
                        data-cardid="<?= h((string)($c['CardID'] ?? 0)) ?>"
                        data-cardtype="<?= h((string)($c['CardTypeSub'] ?? $cardTitle)) ?>"
                        data-cardnumber="<?= h((string)($c['CardNumber'] ?? '')) ?>"
                        data-cardexpiry="<?= h((string)($c['ExpiryDisplay'] ?? format_portal_card_expiry((string)($c['Expiry'] ?? '')))) ?>"
                        data-nameoncard="<?= h((string)($c['NameOnCard'] ?? '')) ?>"
                      >
                        <i class="bi bi-x-circle me-1" aria-hidden="true"></i><?= __t('cancel_card') ?? 'Cancel' ?>
                      </button>
                    </span>
                    <span class="visually-hidden" id="<?= h($cardActionsId) ?>-cancel-help"><?= h($actionBlockedMessage) ?></span>
                  <?php else: ?>
                    <button
                      type="button"
                      class="btn btn-outline-danger"
                      data-bs-toggle="modal"
                      data-bs-target="#cancelCardModal"
                      data-cardid="<?= h((string)($c['CardID'] ?? 0)) ?>"
                      data-cardtype="<?= h((string)($c['CardTypeSub'] ?? $cardTitle)) ?>"
                      data-cardnumber="<?= h((string)($c['CardNumber'] ?? '')) ?>"
                      data-cardexpiry="<?= h((string)($c['ExpiryDisplay'] ?? format_portal_card_expiry((string)($c['Expiry'] ?? '')))) ?>"
                      data-nameoncard="<?= h((string)($c['NameOnCard'] ?? '')) ?>"
                    >
                      <i class="bi bi-x-circle me-1" aria-hidden="true"></i><?= __t('cancel_card') ?? 'Cancel' ?>
                    </button>
                  <?php endif; ?>
                </div>
                <?php if ($pendingCancel): ?>
                  <div class="text-muted small mt-2">
                    <i class="bi bi-lock me-1" aria-hidden="true"></i>A cancel request is already submitted for this card.
                  </div>
                <?php endif; ?>
                <?php if ($hasOpenLimitChange): ?>
                  <div class="portal-inline-info small mt-2">
                    <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>
                    <?= h(in_array($openLimitChangeStatus, ['draft', 'inprogress'], true)
                      ? 'A limit change application is already in progress for this card.'
                      : 'A limit change application already exists for this card and is awaiting processing.') ?>
                    <span class="text-muted">·</span>
                    <a href="index.php?route=cards/request-limit-change&type=<?= urlencode($limitTypeKey) ?>&id=<?= urlencode((string)($c['CardID'] ?? 0)) ?>&application_id=<?= urlencode((string)($openLimitChangeInfo['ApplicationID'] ?? 0)) ?>" class="text-decoration-none">
                      <i class="bi bi-eye me-1" aria-hidden="true"></i><?= __t('view_application') ?? 'View Application' ?>
                    </a>
                  </div>
                <?php endif; ?>
                <?php if ($limitChangeDebug): ?>
                  <?php
                    $debugLink = 'index.php?route=cards/request-limit-change&type=' . urlencode($limitTypeKey) . '&id=' . urlencode((string)($c['CardID'] ?? 0)) . '&application_id=' . urlencode((string)($openLimitChangeInfo['ApplicationID'] ?? 0));
                    $debugInfoJson = json_encode($openLimitChangeInfo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                  ?>
                  <div class="border rounded bg-light p-2 mt-2 small text-muted">
                    <div><strong>Debug</strong></div>
                    <div>CardID: <?= h((string)($c['CardID'] ?? 0)) ?></div>
                    <div>Card Status: <?= h((string)$status) ?></div>
                    <div>ApplicationTypeKey: <?= h((string)$typeKey) ?></div>
                    <div>Limit Type Key: <?= h((string)$limitTypeKey) ?></div>
                    <div>Pending Cancel: <?= $pendingCancel ? 'yes' : 'no' ?></div>
                    <div>Has Open Limit Change: <?= $hasOpenLimitChange ? 'yes' : 'no' ?></div>
                    <div>Open Limit Change Status: <?= h((string)$openLimitChangeStatus) ?></div>
                    <div>Open Limit Change ApplicationID: <?= h((string)($openLimitChangeInfo['ApplicationID'] ?? 0)) ?></div>
                    <div>Open Limit Change ApplicationTypeKey: <?= h((string)($openLimitChangeInfo['ApplicationTypeKey'] ?? '')) ?></div>
                    <div>Open Limit Change EmployeeID: <?= h((string)($openLimitChangeInfo['EmployeeID'] ?? '')) ?></div>
                    <div>Open Limit Change Target EmployeeID: <?= h((string)($openLimitChangeInfo['TargetEmployeeID'] ?? '')) ?></div>
                    <div>Open Limit Change CardID: <?= h((string)($openLimitChangeInfo['CardID'] ?? 0)) ?></div>
                    <div>Generated View Link: <?= h($debugLink) ?></div>
                    <div>Raw Open Limit Change Info: <code><?= h($debugInfoJson !== false ? $debugInfoJson : 'null') ?></code></div>
                  </div>
                <?php endif; ?>

              <?php else: ?>

                <div class="btn-group btn-group-sm" role="group">
                  <?php if ($issuedInfo): ?>
                    <button type="button" class="btn btn-outline-secondary" disabled>
                      <i class="bi bi-check2-circle me-1"></i><?= __t('card_issued') ?? 'Card Issued' ?>
                    </button>
                  <?php elseif ($appInProgress && $appId > 0): ?>
                    <a href="index.php?route=applications/edit&id=<?= urlencode((string)$appId) ?>" class="btn btn-outline-primary">
                      <i class="bi bi-arrow-repeat me-1"></i><?= __t('continue_application') ?? 'Continue Application' ?>
                    </a>
                  <?php elseif ($appSubmitted): ?>
                    <button type="button" class="btn btn-outline-secondary" disabled>
                      <i class="bi bi-lock me-1"></i><?= __t('application_submitted') ?? 'Application Submitted' ?>
                    </button>
                  <?php elseif ($applyBlocked): ?>
                    <span class="d-inline-block" tabindex="0" title="<?= h($blacklistMessage !== '' ? $blacklistMessage : 'You are not eligible to apply for this card.') ?>">
                      <button type="button" class="btn btn-outline-secondary" disabled>
                        <i class="bi bi-slash-circle me-1"></i><?= __t('apply_now') ?? 'Apply Now' ?>
                      </button>
                    </span>
                  <?php else: ?>
                    <a href="index.php?route=applications/start&type=<?= urlencode($typeKey) ?>" class="btn btn-outline-primary">
                      <i class="bi bi-plus-circle me-1"></i><?= __t('apply_now') ?? 'Apply Now' ?>
                    </a>
                  <?php endif; ?>
                </div>

                <?php if ($issuedInfo): ?>
                  <div class="text-muted small mt-2">
                    <?= __t('card_already_issued') ?? 'A card has been issued for this application type.' ?>
                    <?php if (!empty($issuedInfo['ApplicationID'])): ?>
                      <span class="text-muted">·</span>
                      <a href="index.php?route=applications/edit&id=<?= urlencode((string)$issuedInfo['ApplicationID']) ?>" class="text-decoration-none">
                        <i class="bi bi-eye me-1" aria-hidden="true"></i><?= __t('view_application') ?? 'View Application' ?>
                      </a>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

                <?php if ($appInProgress): ?>
                  <div class="portal-inline-info small mt-2">
                    <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i><?= __t('application_in_progress') ?? 'Application in progress.' ?>
                  </div>
                <?php elseif ($appSubmitted): ?>
                  <div class="text-muted small mt-2">
                    <?= __t('application_already_submitted') ?? 'An application has been submitted. You cannot lodge another one at this time.' ?>
                    <?php if ($appId > 0): ?>
                      <span class="text-muted">·</span>
                      <a href="index.php?route=applications/edit&id=<?= urlencode((string)$appId) ?>" class="text-decoration-none">
                        <i class="bi bi-eye me-1" aria-hidden="true"></i><?= __t('view_application') ?? 'View Application' ?>
                      </a>
                    <?php endif; ?>
                  </div>
                <?php elseif ($applyBlocked): ?>
                  <div class="text-muted small mt-2">
                    <i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i>
                    <?= h($blacklistMessage !== '' ? $blacklistMessage : 'You are not eligible to apply for this card.') ?>
                  </div>
                <?php endif; ?>

              <?php endif; ?>

            </div>
          </div>

        </div>
      </article>
    <?php endforeach; ?>

  </div>
</section>

<!-- Cancel Card Modal -->
<div class="modal fade" id="cancelCardModal" tabindex="-1" aria-labelledby="cancelCardLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="cancelCardLabel">Cancel Card</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2" id="cancelCardIntro">You are about to cancel the following card:</p>
        <div class="border rounded p-3 bg-light">
          <div class="text-muted small">Card Type</div>
          <div class="fw-semibold" id="cancelCardType">—</div>
          <div class="text-muted small mt-2">Card Number</div>
          <div class="fw-semibold" id="cancelCardNumber">—</div>
          <div class="text-muted small mt-2">Card Expiry</div>
          <div class="fw-semibold" id="cancelCardExpiry">—</div>
          <div class="text-muted small mt-2">Name on Card</div>
          <div class="fw-semibold" id="cancelCardName">—</div>
        </div>
        <div class="mt-3">
          <label class="form-label" for="cancelReason">Reason</label>
          <select class="form-select" id="cancelReason" name="cancel_reason" aria-describedby="cancelReasonError">
            <option value="">Select a reason</option>
            <?php foreach ($cancelReasonOptions as $option): ?>
              <?php $reasonLabel = trim((string)($option['ReasonLabel'] ?? '')); ?>
              <?php if ($reasonLabel === '') { continue; } ?>
              <option value="<?= h($reasonLabel) ?>"><?= h($reasonLabel) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="invalid-feedback" id="cancelReasonError">Please select a reason.</div>
        </div>
        <div class="mt-3">
          <label class="form-label" for="cancelDate">Cancellation Date</label>
          <input class="form-control"
                 type="text"
                 id="cancelDate"
                 name="cancel_date"
                 value="<?= h($cancelDateMin) ?>"
                 data-min-date="<?= h($cancelDateMin) ?>"
                 data-max-date="<?= h($cancelDateMax) ?>"
                 aria-describedby="cancelDateHelp cancelDateError">
          <div class="invalid-feedback" id="cancelDateError">Please enter a valid cancellation date.</div>
          <div class="form-text" id="cancelDateHelp">Default is today. You can select a date up to <?= h((string)$cancelMaxMonths) ?> month(s) in the future.</div>
        </div>
        <div class="mt-3" id="cancelReasonOtherWrap" style="display:none;">
          <label class="form-label" for="cancelReasonOther">Other Reason</label>
          <input class="form-control" type="text" id="cancelReasonOther" name="cancel_reason_other" placeholder="Enter reason" aria-describedby="cancelReasonOtherError">
          <div class="invalid-feedback" id="cancelReasonOtherError">Please enter the other reason.</div>
        </div>
        <div class="alert alert-danger mt-3 mb-0 d-none" id="cancelValidationAlert" role="alert" aria-live="assertive">
          Please correct the highlighted fields before continuing.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep Card</button>
        <form method="post" action="index.php?route=cards/cancel-card-submit" class="d-inline" id="cancelCardForm">
          <input type="hidden" name="_csrf" value="<?= $csrf ?>">
          <input type="hidden" name="card_id" id="cancelCardId" value="">
          <input type="hidden" name="cancel_reason" id="cancelReasonInput" value="">
          <input type="hidden" name="cancel_reason_other" id="cancelReasonOtherInput" value="">
          <input type="hidden" name="cancel_date" id="cancelDateInput" value="">
          <button
            type="button"
            class="btn btn-danger"
            id="openFinalCancelConfirm"
            data-bs-toggle="modal"
            data-bs-target="#cancelCardFinalConfirmModal"
            disabled
          >
            Confirm Cancel
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="assets/js/system_messages.js"></script>

<!-- Cancel Card Final Confirmation Modal -->
<div class="modal fade" id="cancelCardFinalConfirmModal" tabindex="-1" aria-labelledby="cancelCardFinalConfirmLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="cancelCardFinalConfirmLabel">Final Confirmation</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-danger mb-0">
          Once cancelled, this cannot be reversed. If done in error, new cards will need to be applied for.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Go Back</button>
        <button type="submit" class="btn btn-danger" id="confirmFinalCancelBtn" form="cancelCardForm">Yes, Cancel Card</button>
      </div>
    </div>
  </div>
</div>

<script>
  (function () {
    const modalEl = document.getElementById('cancelCardModal');
    if (!modalEl) return;

    modalEl.addEventListener('show.bs.modal', function (event) {
      const btn = event.relatedTarget;
      if (!btn) return;

      const cardId = btn.getAttribute('data-cardid') || '';
      const type = btn.getAttribute('data-cardtype') || '—';
      let number = (btn.getAttribute('data-cardnumber') || '—').trim();
      let expiry = btn.getAttribute('data-cardexpiry') || '—';
      const name = btn.getAttribute('data-nameoncard') || '—';

      const typeEl = document.getElementById('cancelCardType');
      const numEl = document.getElementById('cancelCardNumber');
      const expEl = document.getElementById('cancelCardExpiry');
      const nameEl = document.getElementById('cancelCardName');

      if (typeEl) typeEl.textContent = type;
      const dateEl = document.getElementById('cancelDate');

      if (number && number !== '—') {
        const last4 = number.slice(-4);
        number = '************' + last4;
      }
      if (numEl) numEl.textContent = number;
      if (expEl) expEl.textContent = expiry;
      if (nameEl) nameEl.textContent = name;
      const idEl = document.getElementById('cancelCardId');
      if (idEl) idEl.value = cardId;
      if (dateEl) {
        const today = new Date();
        const yyyy = today.getFullYear();
        const mm = String(today.getMonth() + 1).padStart(2, '0');
        const dd = String(today.getDate()).padStart(2, '0');
        const todayStr = `${yyyy}-${mm}-${dd}`;
        if (!dateEl.value) {
          dateEl.value = todayStr;
        }
        dateEl.min = todayStr;
      }
    });

    const reason = document.getElementById('cancelReason');
    const otherWrap = document.getElementById('cancelReasonOtherWrap');
    const otherInput = document.getElementById('cancelReasonOther');
    const reasonInput = document.getElementById('cancelReasonInput');
    const reasonOtherInput = document.getElementById('cancelReasonOtherInput');
    const dateInput = document.getElementById('cancelDate');
    const dateHidden = document.getElementById('cancelDateInput');
    const cancelCardIdInput = document.getElementById('cancelCardId');
    const openFinalBtn = document.getElementById('openFinalCancelConfirm');
    const finalConfirmBtn = document.getElementById('confirmFinalCancelBtn');
    const validationAlert = document.getElementById('cancelValidationAlert');

    function updateOther() {
      const isOther = reason && reason.value === 'Other';
      if (otherWrap) otherWrap.style.display = isOther ? '' : 'none';
      if (otherInput) {
        otherInput.required = isOther;
      }
      if (!isOther && otherInput) otherInput.value = '';
      if (!isOther && otherInput) otherInput.classList.remove('is-invalid');
    }

    function syncCancelFields() {
      if (reasonInput && reason) reasonInput.value = reason.value || '';
      if (reasonOtherInput && otherInput) reasonOtherInput.value = otherInput.value || '';
      if (dateHidden && dateInput) dateHidden.value = dateInput.value || '';
    }

    function setFieldValidity(field, isValid) {
      if (!field) return;
      field.classList.toggle('is-invalid', !isValid);
      field.setAttribute('aria-invalid', isValid ? 'false' : 'true');
    }

    function validateCancelModal() {
      const reasonValue = reason ? String(reason.value || '').trim() : '';
      const otherValue = otherInput ? String(otherInput.value || '').trim() : '';
      const dateValue = dateInput ? String(dateInput.value || '').trim() : '';
      const minDate = dateInput ? String(dateInput.getAttribute('data-min-date') || '') : '';
      const maxDate = dateInput ? String(dateInput.getAttribute('data-max-date') || '') : '';

      let isValid = true;

      const reasonOk = reasonValue !== '';
      setFieldValidity(reason, reasonOk);
      isValid = reasonOk && isValid;

      const otherRequired = reasonValue === 'Other';
      const otherOk = !otherRequired || otherValue !== '';
      setFieldValidity(otherInput, otherOk);
      isValid = otherOk && isValid;

      const dateOk = dateValue !== '' && (!minDate || dateValue >= minDate) && (!maxDate || dateValue <= maxDate);
      setFieldValidity(dateInput, dateOk);
      isValid = dateOk && isValid;

      if (validationAlert) {
        validationAlert.classList.toggle('d-none', isValid);
      }

      if (openFinalBtn) {
        openFinalBtn.disabled = !reasonOk || !otherOk;
      }

      if (isValid) {
        syncCancelFields();
      }

      return isValid;
    }

    if (reason) {
      reason.addEventListener('change', function () {
        updateOther();
        validateCancelModal();
      });
      updateOther();
    }

    if (otherInput) {
      otherInput.addEventListener('input', validateCancelModal);
    }

    if (dateInput) {
      dateInput.addEventListener('input', validateCancelModal);
      dateInput.addEventListener('change', validateCancelModal);
    }

    if (openFinalBtn) {
      openFinalBtn.addEventListener('click', function (event) {
        if (!validateCancelModal()) {
          event.preventDefault();
          event.stopPropagation();
          return;
        }
        syncCancelFields();
      });
    }

    if (finalConfirmBtn) {
      finalConfirmBtn.addEventListener('click', syncCancelFields);
    }

    const reopenCancelModal = <?= $cancelModalOld !== [] ? 'true' : 'false' ?>;
    const reopenCardId = <?= (int)$cancelOldCardId ?>;
    const reopenReason = <?= json_encode($cancelOldReason, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const reopenReasonOther = <?= json_encode($cancelOldReasonOther, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const reopenDate = <?= json_encode($cancelOldDate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    function applyCancelModalState() {
      if (cancelCardIdInput && reopenCardId > 0) cancelCardIdInput.value = String(reopenCardId);
      if (reason) reason.value = reopenReason || '';
      if (otherInput) otherInput.value = reopenReasonOther || '';
      if (dateInput && reopenDate) dateInput.value = reopenDate;
      updateOther();
      syncCancelFields();
    }

    if (reopenCancelModal && modalEl && typeof bootstrap !== 'undefined') {
      window.addEventListener('load', function () {
        applyCancelModalState();
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
      });
    }

    modalEl.addEventListener('show.bs.modal', function () {
      if (validationAlert) {
        validationAlert.classList.add('d-none');
      }
      if (reason) reason.classList.remove('is-invalid');
      if (otherInput) otherInput.classList.remove('is-invalid');
      if (dateInput) dateInput.classList.remove('is-invalid');
      validateCancelModal();
    });
  })();
</script>
<script>
  (function () {
    const modalEl = document.getElementById('cancelCardModal');
    const dateInput = document.getElementById('cancelDate');
    const dateHidden = document.getElementById('cancelDateInput');
    const openFinalBtn = document.getElementById('openFinalCancelConfirm');
    const finalConfirmBtn = document.getElementById('confirmFinalCancelBtn');
    if (!modalEl || !dateInput || typeof flatpickr !== 'function') return;

    const minDate = dateInput.getAttribute('data-min-date') || '';
    const maxDate = dateInput.getAttribute('data-max-date') || '';
    const maxMonthKey = maxDate ? maxDate.slice(0, 7) : '';
    const minMonthKey = minDate ? minDate.slice(0, 7) : '';

    const picker = flatpickr(dateInput, {
      dateFormat: 'Y-m-d',
      defaultDate: dateInput.value || minDate || null,
      minDate: minDate || null,
      maxDate: maxDate || null,
      allowInput: true,
      disableMobile: true,
      onReady: function (selectedDates, dateStr, instance) {
        updateMonthNavState(instance);
      },
      onOpen: function (selectedDates, dateStr, instance) {
        updateMonthNavState(instance);
      },
      onMonthChange: function (selectedDates, dateStr, instance) {
        updateMonthNavState(instance);
      },
      onYearChange: function (selectedDates, dateStr, instance) {
        updateMonthNavState(instance);
      },
      onChange: function () {
        syncHiddenDate();
      },
    });

    function syncHiddenDate() {
      if (dateHidden) {
        dateHidden.value = dateInput.value || '';
      }
    }

    function updateMonthNavState(instance) {
      if (!instance) return;
      const currentMonth = String(instance.currentMonth + 1).padStart(2, '0');
      const currentMonthKey = String(instance.currentYear) + '-' + currentMonth;
      if (maxMonthKey !== '' && currentMonthKey > maxMonthKey) {
        instance.jumpToDate(maxDate);
        instance.redraw();
        return updateMonthNavState(instance);
      }
      if (instance.nextMonthNav) {
        const disableNext = maxMonthKey !== '' && currentMonthKey >= maxMonthKey;
        instance.nextMonthNav.disabled = disableNext;
        instance.nextMonthNav.style.pointerEvents = disableNext ? 'none' : '';
        instance.nextMonthNav.style.opacity = disableNext ? '0.35' : '';
      }
      if (instance.prevMonthNav) {
        const disablePrev = minMonthKey !== '' && currentMonthKey <= minMonthKey;
        instance.prevMonthNav.disabled = disablePrev;
        instance.prevMonthNav.style.pointerEvents = disablePrev ? 'none' : '';
        instance.prevMonthNav.style.opacity = disablePrev ? '0.35' : '';
      }
    }

    modalEl.addEventListener('show.bs.modal', function () {
      const todayStr = minDate || '';
      dateInput.value = todayStr;
      picker.setDate(todayStr, true);
      picker.jumpToDate(todayStr);
      picker.redraw();
      syncHiddenDate();
    });

    dateInput.addEventListener('input', syncHiddenDate);
    dateInput.addEventListener('change', syncHiddenDate);
    if (openFinalBtn) openFinalBtn.addEventListener('click', syncHiddenDate);
    if (finalConfirmBtn) finalConfirmBtn.addEventListener('click', syncHiddenDate);

    syncHiddenDate();
  })();
</script>
<script>
  (function () {
    window.addEventListener('load', function () {
      const form = document.getElementById('onBehalfForm');
      const cardType = document.getElementById('obCardType');
      const employeeId = document.getElementById('obEmployeeId');
      const last4 = document.getElementById('obLast4');
      if (!form || !cardType || !employeeId || !last4) return;

      function setInvalid(el, invalid) {
        if (!el) return;
        el.classList.toggle('is-invalid', invalid);
        el.setAttribute('aria-invalid', invalid ? 'true' : 'false');
      }

      function validateOnBehalfForm() {
        const ctVal = String(cardType.value || '').trim();
        const empVal = String(employeeId.value || '').trim();
        const last4Val = String(last4.value || '').replace(/\D/g, '');
        const last4Ok = /^\d{4}$/.test(last4Val);

        setInvalid(cardType, ctVal === '');
        setInvalid(employeeId, empVal === '');
        setInvalid(last4, !last4Ok);

        return ctVal !== '' && empVal !== '' && last4Ok;
      }

      last4.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 4);
        validateOnBehalfForm();
      });
      cardType.addEventListener('change', validateOnBehalfForm);
      employeeId.addEventListener('input', validateOnBehalfForm);

      form.addEventListener('submit', function (event) {
        if (!validateOnBehalfForm()) {
          event.preventDefault();
        }
      });
    });
  })();
</script>
<script>
  (function () {
    const openOnBehalf = <?= $onBehalfError !== '' ? 'true' : 'false' ?>;
    if (!openOnBehalf) return;
    window.addEventListener('load', function () {
      const modalEl = document.getElementById('onBehalfModal');
      if (!modalEl || typeof bootstrap === 'undefined') return;
      const modal = new bootstrap.Modal(modalEl);
      modal.show();
    });
  })();
</script>

<!-- On Behalf Of Modal -->
<div class="modal fade" id="onBehalfModal" tabindex="-1" aria-labelledby="onBehalfModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow-sm">
      <form method="get" action="index.php" id="onBehalfForm" novalidate>
        <input type="hidden" name="route" value="cards/on-behalf-limit-change-start">
        <div class="modal-header">
          <h5 class="modal-title" id="onBehalfModalLabel">
            <i class="bi bi-people me-2" aria-hidden="true"></i>Apply for Limit Change on behalf of another cardholder
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php if ($onBehalfError !== ''): ?>
            <div class="alert alert-danger py-2" role="alert"><?= h($onBehalfError) ?></div>
          <?php endif; ?>
          <div class="mb-3">
            <label for="obCardType" class="form-label">Card Type</label>
            <select class="form-select" id="obCardType" name="card_type" required aria-describedby="obCardTypeError">
              <option value="">Select card type</option>
              <option value="DTC" <?= $onBehalfCardType === 'DTC' ? 'selected' : '' ?>>DTC</option>
              <option value="DPC" <?= $onBehalfCardType === 'DPC' ? 'selected' : '' ?>>DPC</option>
              <option value="LODGE" <?= $onBehalfCardType === 'LODGE' ? 'selected' : '' ?>>Lodge</option>
            </select>
            <div class="invalid-feedback" id="obCardTypeError">Card Type is required.</div>
          </div>
          <div class="mb-3">
            <label for="obEmployeeId" class="form-label">EmployeeID</label>
            <input
              type="text"
              class="form-control"
              id="obEmployeeId"
              name="employee_id"
              maxlength="50"
              value="<?= h($onBehalfEmployeeId) ?>"
              aria-describedby="obEmployeeIdError"
              required
            >
            <div class="invalid-feedback" id="obEmployeeIdError">EmployeeID is required.</div>
          </div>
          <div class="mb-1">
            <label for="obLast4" class="form-label">Last four digits of the card</label>
            <input
              type="text"
              class="form-control"
              id="obLast4"
              name="last4"
              inputmode="numeric"
              pattern="\d{4}"
              maxlength="4"
              value="<?= h($onBehalfLast4) ?>"
              aria-describedby="obLast4Help obLast4Error"
              required
            >
            <div class="invalid-feedback" id="obLast4Error">Enter exactly 4 digits.</div>
            <div class="form-text" id="obLast4Help">Enter exactly 4 digits.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Continue</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Support Modal -->
<div class="modal fade" id="supportModal" tabindex="-1" aria-labelledby="supportModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-md">
    <div class="modal-content shadow-sm">

      <div class="modal-header">
        <h5 class="modal-title" id="supportModalLabel">
          <i class="bi bi-life-preserver me-2" aria-hidden="true"></i>
          <?= __t('support_contact') ?? 'Support Contact Details' ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __t('close') ?? 'Close' ?>"></button>
      </div>

      <div class="modal-body">
       <div class="row g-2">
          <div class="col-12">
            <div class="border rounded p-3 d-flex align-items-center gap-3 bg-light">
              <div class="rounded-circle bg-primary bg-opacity-10 p-2">
                <i class="bi bi-telephone text-primary" aria-hidden="true"></i>
              </div>
              <div>
                <div class="small text-muted"><?= __t('phone') ?? 'Phone' ?></div>
                <div class="fw-semibold">1800DEFENCE (1800 333 362)</div>
              </div>
            </div>
          </div>

          <div class="col-12">
            <div class="border rounded p-3 d-flex align-items-center gap-3 bg-light">
              <div class="rounded-circle bg-primary bg-opacity-10 p-2">
                <i class="bi bi-envelope text-primary" aria-hidden="true"></i>
              </div>
              <div>
                <div class="small text-muted"><?= __t('email') ?? 'Email' ?></div>
                <a href="mailto:support@example.gov.au" class="fw-semibold text-decoration-none">
                  Yourcustomer.service@defence.gov.au
                </a>
              </div>
            </div>
          </div>

       
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <?= __t('close') ?? 'Close' ?>
        </button>
      </div>

    </div>
  </div>
</div>

<div class="modal fade" id="lostStolenModal" tabindex="-1" aria-labelledby="lostStolenModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow-sm">
      <div class="modal-header">
        <h5 class="modal-title" id="lostStolenModalLabel">
          <i class="bi bi-exclamation-triangle me-2 text-primary" aria-hidden="true"></i>Card Not Received/Lost/Stolen/Damaged
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __t('close') ?? 'Close' ?>"></button>
      </div>
      <div class="modal-body">
        <div class="border rounded p-3 portal-panel-info text-start"><?= renderAgreementText($lostStolenMessage !== '' ? $lostStolenMessage : 'Lost/Stolen instructions are not configured yet. Please contact the portal administrator.') ?></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <?= __t('close') ?? 'Close' ?>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Optional: Cancel Modal placeholder (if your app uses it elsewhere) -->
<!--
<div class="modal fade" id="cancelCardModal" tabindex="-1" aria-hidden="true">
  ...
</div>
-->

