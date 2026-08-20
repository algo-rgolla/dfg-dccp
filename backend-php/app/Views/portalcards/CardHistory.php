<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$rows = (isset($rows) && is_array($rows)) ? $rows : [];
$typeKey = (string)($typeKey ?? '');

function type_label(string $typeKey): string
{
    return match (strtolower(trim($typeKey))) {
        'dtc' => 'Defence Travel Card (DTC)',
        'dpc' => 'Defence Purchasing Card (DPC)',
        'lodge' => 'Defence Travel Lodge Card',
        default => 'All Cards',
    };
}

function is_active_history_status($status): bool
{
    $value = strtolower(trim((string)$status));
    return $value === '' || $value === 'active';
}

function status_badge_class($status): string
{
    $value = strtolower(trim((string)$status));
    if ($value === '' || $value === 'active') {
        return 'bg-success-subtle text-success-emphasis border border-success-subtle';
    }
    if ($value === 'vx') {
        return 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
    }
    if (str_contains($value, 'cancel')) {
        return 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
    }
    return 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle';
}

function render_history_rows(array $rows): void
{
    foreach ($rows as $r):
        $name = trim((string)($r['FirstName'] ?? '') . ' ' . (string)($r['Surname'] ?? ''));
        $addrParts = array_filter([
            (string)($r['Address1'] ?? ''),
            (string)($r['Address2'] ?? ''),
            (string)($r['Address3'] ?? ''),
            trim((string)($r['Suburb'] ?? '') . ' ' . (string)($r['State'] ?? '') . ' ' . (string)($r['PostCode'] ?? '')),
        ], fn($v) => trim((string)$v) !== '');
        $addr = $addrParts ? implode(', ', $addrParts) : '-';
        $status = (string)($r['Status'] ?? '');
        $displayStatus = $status === '' ? 'Active' : (strcasecmp(trim($status), 'VX') === 0 ? 'Cancelled' : $status);
        $statusDescription = trim((string)($r['StatusDescription'] ?? ''));
        $cardNumberShort = trim((string)($r['CardNumberShort'] ?? ''));
        $cardNumberFull = trim((string)($r['CardNumber'] ?? ''));
        $cardDigits = preg_replace('/\D+/', '', $cardNumberFull) ?? '';
        if ($cardDigits === '' && $cardNumberShort !== '') {
            $cardDigits = preg_replace('/\D+/', '', $cardNumberShort) ?? '';
        }
        $cardNum = '';
        if ($cardDigits !== '') {
            $last4 = substr($cardDigits, -4);
            $cardNum = $last4 !== false && $last4 !== '' ? ('************' . $last4) : '';
        }
        $employeeId = trim((string)($r['EmployeeID'] ?? ''));
        $email = trim((string)($r['Email'] ?? ($r['Email_Address'] ?? '')));
        $limit = $r['ActiveCeiling'] ?? ($r['CreditLimitAmount'] ?? null);
        $expiry = $r['Expiry'] ?? null;
        $expiryStr = $expiry ? date('Y-m', strtotime((string)$expiry)) : '-';
        ?>
        <tr>
          <td>
            <span class="badge rounded-pill <?= h(status_badge_class($status)) ?>"><?= h($displayStatus) ?></span>
            <?php if ($statusDescription !== ''): ?>
              <div class="small text-muted mt-1"><?= h($statusDescription) ?></div>
            <?php endif; ?>
          </td>
          <td class="text-nowrap"><?= h($employeeId !== '' ? $employeeId : '-') ?></td>
          <td class="text-nowrap"><?= h($email !== '' ? $email : '-') ?></td>
          <td><?= h($name !== '' ? $name : '-') ?></td>
          <td class="text-nowrap"><?= h($cardNum !== '' ? $cardNum : '-') ?></td>
          <td class="text-nowrap"><?= h($expiryStr) ?></td>
          <td class="text-nowrap"><?= $limit === null ? '-' : h('$' . number_format((float)$limit, 0)) ?></td>
          <td class="small"><?= h($addr) ?></td>
        </tr>
        <?php
    endforeach;
}

function render_history_table(array $rows): void
{
    ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Status</th>
            <th>EmployeeID</th>
            <th>Email Address</th>
            <th>Name</th>
            <th>Card #</th>
            <th>Expiry</th>
            <th>Limit</th>
            <th>Address</th>
          </tr>
        </thead>
        <tbody>
          <?php render_history_rows($rows); ?>
        </tbody>
      </table>
    </div>
    <?php
}

$activeRows = array_values(array_filter($rows, static fn(array $r): bool => is_active_history_status($r['Status'] ?? '')));
$otherRows = array_values(array_filter($rows, static fn(array $r): bool => !is_active_history_status($r['Status'] ?? '')));
$defaultActiveTab = !empty($activeRows) || empty($otherRows);
?>

<style>
  .card-history-shell .card-header {
    background: linear-gradient(180deg, #f8fafc 0%, #eef3f8 100%);
  }
  .card-history-shell .history-subtitle {
    font-size: 0.875rem;
  }
  .card-history-shell .nav-tabs {
    border-bottom: 1px solid #dee2e6;
    gap: 0.5rem;
  }
  .card-history-shell .nav-tabs .nav-link {
    border: 0;
    border-radius: 999px;
    color: #495057;
    font-weight: 600;
    padding: 0.55rem 0.9rem;
    background-color: #f8f9fa;
  }
  .card-history-shell .nav-tabs .nav-link:hover {
    background-color: #eef3f8;
    color: #212529;
  }
  .card-history-shell .nav-tabs .nav-link.active {
    background-color: #0d6efd;
    color: #fff;
    box-shadow: 0 0 0 1px rgba(13, 110, 253, 0.15);
  }
  .card-history-shell .nav-tabs .nav-link.active .badge {
    background-color: rgba(255, 255, 255, 0.2) !important;
    color: #fff;
  }
  .card-history-shell .tab-pane {
    background-color: #fff;
  }
  .card-history-shell .table thead th {
    font-size: 0.8rem;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    white-space: nowrap;
  }
</style>

<div class="card shadow-sm mt-4 card-history-shell">
  <div class="card-header d-flex justify-content-between align-items-center">
    <div>
      <strong>
        <i class="bi bi-clock-history me-2"></i>
        Card History
      </strong>
      <div class="text-muted history-subtitle mt-1">
        Showing <?= h((string)count($rows)) ?> records for <?= h(type_label($typeKey)) ?>
      </div>
    </div>
    <a href="index.php?route=portalcards/list" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i>Back to Cards
    </a>
  </div>

  <div class="card-body p-0">
    <?php if (empty($rows)): ?>
      <div class="p-3 text-muted">No card history found.</div>
    <?php else: ?>
      <ul class="nav nav-tabs px-3 pt-3 pb-2" id="cardHistoryTabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link <?= $defaultActiveTab ? 'active' : '' ?>"
                  id="active-cards-tab"
                  data-bs-toggle="tab"
                  data-bs-target="#active-cards-pane"
                  type="button"
                  role="tab"
                  aria-controls="active-cards-pane"
                  aria-selected="<?= $defaultActiveTab ? 'true' : 'false' ?>">
            Active Cards
            <span class="badge bg-secondary ms-1"><?= h((string)count($activeRows)) ?></span>
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link <?= !$defaultActiveTab ? 'active' : '' ?>"
                  id="other-cards-tab"
                  data-bs-toggle="tab"
                  data-bs-target="#other-cards-pane"
                  type="button"
                  role="tab"
                  aria-controls="other-cards-pane"
                  aria-selected="<?= !$defaultActiveTab ? 'true' : 'false' ?>">
            Other Card Statuses
            <span class="badge bg-secondary ms-1"><?= h((string)count($otherRows)) ?></span>
          </button>
        </li>
      </ul>
      <div class="tab-content">
        <div class="tab-pane fade <?= $defaultActiveTab ? 'show active' : '' ?>" id="active-cards-pane" role="tabpanel" aria-labelledby="active-cards-tab" tabindex="0">
          <?php if (empty($activeRows)): ?>
            <div class="p-4 text-muted">No active cards found.</div>
          <?php else: ?>
            <?php render_history_table($activeRows); ?>
          <?php endif; ?>
        </div>
        <div class="tab-pane fade <?= !$defaultActiveTab ? 'show active' : '' ?>" id="other-cards-pane" role="tabpanel" aria-labelledby="other-cards-tab" tabindex="0">
          <?php if (empty($otherRows)): ?>
            <div class="p-4 text-muted">No non-active cards found.</div>
          <?php else: ?>
            <?php render_history_table($otherRows); ?>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
