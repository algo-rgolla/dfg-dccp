<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('mask_pending_cancel_card')) {
    function mask_pending_cancel_card(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '-';
        }
        $last4 = substr($value, -4);
        return $last4 !== false && $last4 !== '' ? ('************' . $last4) : '-';
    }
}

$rows = is_array($rows ?? null) ? $rows : [];
$dueCount = (int)($dueCount ?? 0);
$scheduledCount = (int)($scheduledCount ?? 0);
$csrf = h((string)($_csrf ?? csrf_token()));
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Pending Card Cancellations</h3>
      <div class="text-muted">View scheduled card cancellations and manually process due items if needed.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=admin/change-requests">
        <i class="bi bi-list-check me-1"></i>All Change Requests
      </a>
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
        <i class="bi bi-arrow-left me-1"></i>Back
      </a>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small">Total Pending</div>
          <div class="fs-4 fw-semibold"><?= h((string)count($rows)) ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card shadow-sm h-100 border-warning-subtle">
        <div class="card-body">
          <div class="text-muted small">Due Now</div>
          <div class="fs-4 fw-semibold text-warning-emphasis"><?= h((string)$dueCount) ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card shadow-sm h-100 border-info-subtle">
        <div class="card-body">
          <div class="text-muted small">Scheduled Future</div>
          <div class="fs-4 fw-semibold text-info-emphasis"><?= h((string)$scheduledCount) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <div class="fw-semibold">Manual Processing</div>
        <div class="text-muted small">Processes only due cancellations whose cancellation date is today or earlier.</div>
      </div>
      <form method="post" action="index.php?route=cards/process-due-cancellations" onsubmit="return confirm('Process all due card cancellations now?');">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <button type="submit" class="btn btn-primary" <?= $dueCount <= 0 ? 'disabled' : '' ?>>
          <i class="bi bi-play-circle me-1"></i>Process Due Cancellations
        </button>
      </form>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Pending Cancellation Requests</strong>
      <span class="text-muted small">Oldest cancellation date first</span>
    </div>
    <div class="card-body p-0">
      <?php if (!$rows): ?>
        <div class="p-4 text-center text-muted">No pending card cancellations found.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm table-striped table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Request</th>
                <th>Card</th>
                <th>Employee</th>
                <th>Cancellation Date</th>
                <th>State</th>
                <th>Reason</th>
                <th>Submitted</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <?php
                  $name = trim((string)($r['FirstName'] ?? '') . ' ' . (string)($r['Surname'] ?? ''));
                  $cardLabel = trim((string)($r['CardType'] ?? '') . ' ' . (string)($r['CardTypeSub'] ?? ''));
                  $reason = trim((string)($r['ReasonDisplay'] ?? ''));
                  $reasonOther = trim((string)($r['ReasonOtherDisplay'] ?? ''));
                  if ($reason === 'Other' && $reasonOther !== '') {
                      $reason = 'Other: ' . $reasonOther;
                  }
                  $isDueNow = !empty($r['IsDueNow']);
                ?>
                <tr>
                  <td>
                    <div class="fw-semibold">#<?= h((string)($r['RequestID'] ?? '')) ?></div>
                    <div class="small text-muted"><?= h((string)($r['Status'] ?? '')) ?></div>
                  </td>
                  <td>
                    <div><?= h((string)($r['CardID'] ?? '')) ?></div>
                    <div class="small text-muted"><?= h($cardLabel !== '' ? $cardLabel : '-') ?></div>
                    <div class="small text-muted"><?= h(mask_pending_cancel_card((string)($r['CardNumber'] ?? ''))) ?></div>
                  </td>
                  <td>
                    <div><?= h((string)($r['EmployeeID'] ?? '')) ?></div>
                    <div class="small text-muted"><?= h($name !== '' ? $name : '-') ?></div>
                  </td>
                  <td><?= h((string)($r['CancelDateDisplay'] ?? '-')) ?></td>
                  <td>
                    <?php if ($isDueNow): ?>
                      <span class="badge text-bg-warning">Due Now</span>
                    <?php else: ?>
                      <span class="badge text-bg-info">Scheduled</span>
                    <?php endif; ?>
                  </td>
                  <td><?= h($reason !== '' ? $reason : '-') ?></td>
                  <td><?= h((string)($r['SubmittedAt'] ?? '-')) ?></td>
                  <td class="text-end">
                    <form method="post" action="index.php?route=admin/change-requests-delete" class="d-inline" onsubmit="return confirm('Delete this pending cancellation request?');">
                      <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                      <input type="hidden" name="id" value="<?= h((string)($r['RequestID'] ?? 0)) ?>">
                      <input type="hidden" name="return_route" value="admin/pending-card-cancellations">
                      <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
