<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

use App\Shared\SessionHelper;

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$cardId = (int)($cardId ?? 0);
$cardTypeSub = (string)($cardTypeSub ?? '');
$cardNumber = trim((string)($cardNumber ?? ''));
$cardNumberMasked = $cardNumber !== '' ? ('************' . substr($cardNumber, -4)) : '';
$cardExpiry = (string)($cardExpiry ?? '');
$nameOnCard = (string)($nameOnCard ?? '');
$cancelDateMin = (string)($cancelDateMin ?? date('Y-m-d'));
$cancelDateMax = (string)($cancelDateMax ?? date('Y-m-d'));
$cancelMaxMonths = (int)($cancelMaxMonths ?? 6);
$csrf = h(csrf_token());
$old = SessionHelper::get('cards.cancel.old_input.' . $cardId);
SessionHelper::forget('cards.cancel.old_input.' . $cardId);
if (!is_array($old)) {
    $old = [];
}
$cancelReason = (string)($old['cancel_reason'] ?? '');
$cancelReasonOther = (string)($old['cancel_reason_other'] ?? '');
$cancelDate = (string)($old['cancel_date'] ?? $cancelDateMin);
$cancelReasonOptions = is_array($cancelReasonOptions ?? null) ? $cancelReasonOptions : [];
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Cancel Card</h3>
      <div class="text-muted">Please review the card details below before proceeding.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
        <i class="bi bi-arrow-left me-1"></i>Back to Cards
      </a>
    </div>
  </div>

  <form method="post" action="index.php?route=cards/cancel-card-submit" class="js-submit-feedback-form">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
    <input type="hidden" name="card_id" value="<?= h((string)$cardId) ?>">

  <div class="card shadow-sm mb-3">
    <div class="card-header">
      <strong>Card Details</strong>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <div class="text-muted small">Card Type</div>
          <div class="fw-semibold"><?= h($cardTypeSub) ?></div>
        </div>
        <div class="col-md-6">
          <div class="text-muted small">Card Number</div>
          <div class="fw-semibold"><?= h($cardNumberMasked) ?></div>
        </div>
        <div class="col-md-6">
          <div class="text-muted small">Card Expiry</div>
          <div class="fw-semibold"><?= h($cardExpiry) ?></div>
        </div>
        <div class="col-md-6">
          <div class="text-muted small">Name on Card</div>
          <div class="fw-semibold"><?= h($nameOnCard) ?></div>
        </div>
        <div class="col-12">
          <div class="alert alert-warning mb-0">
            You are about to cancel this card. If you proceed, the card will be cancelled and may not be usable.
          </div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Reason</label>
          <select class="form-select" id="cancelReason" name="cancel_reason" required>
            <option value="">Select a reason</option>
            <?php foreach ($cancelReasonOptions as $option): ?>
              <?php $reasonLabel = trim((string)($option['ReasonLabel'] ?? '')); ?>
              <?php if ($reasonLabel === '') { continue; } ?>
              <option value="<?= h($reasonLabel) ?>" <?= $cancelReason === $reasonLabel ? 'selected' : '' ?>><?= h($reasonLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6" id="cancelReasonOtherWrap" style="display:none;">
          <label class="form-label">Other Reason</label>
          <input class="form-control" type="text" id="cancelReasonOther" name="cancel_reason_other" value="<?= h($cancelReasonOther) ?>" placeholder="Enter reason">
        </div>
        <div class="col-md-6">
          <label class="form-label">Cancellation Date</label>
          <input class="form-control"
                 type="text"
                 id="cancelDate"
                 name="cancel_date"
                 value="<?= h($cancelDate) ?>"
                 min="<?= h($cancelDateMin) ?>"
                 max="<?= h($cancelDateMax) ?>"
                 data-min-date="<?= h($cancelDateMin) ?>"
                 data-max-date="<?= h($cancelDateMax) ?>"
                 required>
          <div class="form-text">You can select a date from today up to <?= h((string)$cancelMaxMonths) ?> month(s) in the future.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex justify-content-end gap-2">
    <a class="btn btn-outline-secondary" href="index.php?route=home/index">Keep Card</a>
    <button type="submit" class="btn btn-danger">Confirm Cancel</button>
  </div>
  </form>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
  (function () {
    const reason = document.getElementById('cancelReason');
    const otherWrap = document.getElementById('cancelReasonOtherWrap');
    const otherInput = document.getElementById('cancelReasonOther');
    const cancelDate = document.getElementById('cancelDate');
    if (!reason || !otherWrap || !otherInput) return;

    function updateOther() {
      const isOther = reason.value === 'Other';
      otherWrap.style.display = isOther ? '' : 'none';
      otherInput.required = isOther;
      if (!isOther) otherInput.value = '';
    }

    function clampCancelDate() {
      if (!cancelDate) return;
      const min = cancelDate.getAttribute('min') || '';
      const max = cancelDate.getAttribute('max') || '';
      const value = cancelDate.value || '';
      if (value !== '' && min !== '' && value < min) {
        cancelDate.value = min;
        return;
      }
      if (value !== '' && max !== '' && value > max) {
        cancelDate.value = max;
      }
    }

    reason.addEventListener('change', updateOther);
    if (cancelDate && typeof flatpickr === 'function') {
      const minDate = cancelDate.getAttribute('data-min-date') || null;
      const maxDate = cancelDate.getAttribute('data-max-date') || null;
      const maxMonthKey = maxDate ? maxDate.slice(0, 7) : '';
      const minMonthKey = minDate ? minDate.slice(0, 7) : '';
      flatpickr(cancelDate, {
        dateFormat: 'Y-m-d',
        defaultDate: cancelDate.value || null,
        minDate: minDate,
        maxDate: maxDate,
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
      });

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
    } else if (cancelDate) {
      cancelDate.addEventListener('input', clampCancelDate);
      cancelDate.addEventListener('change', clampCancelDate);
      cancelDate.addEventListener('blur', clampCancelDate);
    }
    updateOther();
    clampCancelDate();
  })();
</script>

<?php require __DIR__ . '/../shared/submit_feedback.php'; ?>
