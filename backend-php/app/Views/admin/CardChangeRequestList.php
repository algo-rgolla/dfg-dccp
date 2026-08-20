<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('pretty_label_admin_cr')) {
    function pretty_label_admin_cr(string $key): string
    {
        return ucwords(str_replace('_', ' ', trim($key)));
    }
}
if (!function_exists('format_payload_value_admin_cr')) {
    function format_payload_value_admin_cr($value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }
        $s = trim((string)$value);
        return $s === '' ? '-' : $s;
    }
}
if (!function_exists('mask_card_number_admin_cr')) {
    function mask_card_number_admin_cr(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '-';
        }
        $last4 = substr($value, -4);
        return $last4 !== false && $last4 !== '' ? ('************' . $last4) : '-';
    }
}
if (!function_exists('fmt_dt_admin_cr')) {
    function fmt_dt_admin_cr(?string $value): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return '-';
        }
        $ts = strtotime($text);
        return $ts === false ? $text : date('d-m-Y H:i', $ts);
    }
}
if (!function_exists('fmt_dt_admin_cr_utc')) {
    function fmt_dt_admin_cr_utc(?string $value): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return '-';
        }
        try {
            $utc = new DateTimeZone('UTC');
            $localTz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $text, $utc);
            if (!$dt) {
                $ts = strtotime($text);
                return $ts === false ? $text : date('d-m-Y H:i', $ts);
            }
            return $dt->setTimezone($localTz)->format('d-m-Y H:i');
        } catch (Throwable $e) {
            $ts = strtotime($text);
            return $ts === false ? $text : date('d-m-Y H:i', $ts);
        }
    }
}
if (!function_exists('admin_cancel_state_meta')) {
    function admin_cancel_state_meta(array $row, array $payload): array
    {
        $requestType = strtoupper(trim((string)($row['RequestType'] ?? '')));
        if ($requestType !== 'CANCEL_CARD') {
            return ['label' => '-', 'badge' => 'bg-light text-dark border'];
        }

        $cancelDate = trim((string)($row['CancelDate'] ?? ''));
        if ($cancelDate === '') {
            $cancelDate = trim((string)($payload['cancel_date'] ?? ''));
        }
        $processedAt = trim((string)($row['ProcessedAt'] ?? ''));
        if ($processedAt === '') {
            $processedAt = trim((string)($row['CompletedAt'] ?? ''));
        }

        if ($processedAt !== '') {
            return [
                'label' => 'Processed' . ($cancelDate !== '' ? ' (' . $cancelDate . ')' : ''),
                'badge' => 'bg-success',
            ];
        }

        if ($cancelDate === '') {
            return ['label' => 'Pending', 'badge' => 'bg-warning text-dark'];
        }

        $today = date('Y-m-d');
        if ($cancelDate > $today) {
            return ['label' => 'Scheduled (' . $cancelDate . ')', 'badge' => 'bg-info text-dark'];
        }

        return ['label' => 'Due Now (' . $cancelDate . ')', 'badge' => 'bg-warning text-dark'];
    }
}

$rows = is_array($rows ?? null) ? $rows : [];
$filters = is_array($filters ?? null) ? $filters : [];
$requestTypes = is_array($requestTypes ?? null) ? $requestTypes : [];
$statuses = is_array($statuses ?? null) ? $statuses : [];
$csrf = h((string)($_csrf ?? csrf_token()));
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">All Change Requests</h3>
      <div class="text-muted">Administrative view of all card address change and cancellation requests.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-primary btn-sm" href="index.php?route=admin/cancel-card-reasons">
        <i class="bi bi-list-check me-1"></i>Cancel Card Reasons
      </a>
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
        <i class="bi bi-arrow-left me-1"></i>Back
      </a>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Filters</strong>
      <span class="text-muted small"><?= count($rows) ?> result<?= count($rows) === 1 ? '' : 's' ?></span>
    </div>
    <div class="card-body">
      <form method="get" action="index.php" class="row g-3 align-items-end">
        <input type="hidden" name="route" value="admin/change-requests">
        <div class="col-md-4">
          <label class="form-label">Search</label>
          <input class="form-control" name="q" value="<?= h((string)($filters['q'] ?? '')) ?>" placeholder="Request ID, Card ID, employee, type, status">
        </div>
        <div class="col-md-2">
          <label class="form-label">EmployeeID</label>
          <input class="form-control" name="employee_id" value="<?= h((string)($filters['employee_id'] ?? '')) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Request Type</label>
          <select class="form-select" name="request_type">
            <option value="">All request types</option>
            <?php foreach ($requestTypes as $type): ?>
              <option value="<?= h((string)$type) ?>" <?= ((string)($filters['request_type'] ?? '') === (string)$type) ? 'selected' : '' ?>>
                <?= h((string)$type) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select class="form-select" name="status">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $status): ?>
              <option value="<?= h((string)$status) ?>" <?= ((string)($filters['status'] ?? '') === (string)$status) ? 'selected' : '' ?>>
                <?= h((string)$status) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-12">
          <button type="submit" class="btn btn-primary">Search</button>
          <a class="btn btn-outline-secondary" href="index.php?route=admin/change-requests">Clear</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Change Request Results</strong>
      <span class="text-muted small">Latest first</span>
    </div>
    <div class="card-body p-0">
      <?php if (!$rows): ?>
        <div class="p-4 text-center text-muted">No change requests found.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm table-striped table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Request</th>
                <th>Card</th>
                <th>Employee</th>
                <th>Type</th>
                <th>Status</th>
                <th>Cancellation State</th>
                <th>Submitted</th>
                <th>Completed</th>
                <th>Details</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <?php
                  $payloadRaw = (string)($r['PayloadJson'] ?? '');
                  $payload = json_decode($payloadRaw, true);
                  $payload = is_array($payload) ? $payload : [];
                  $createdAt = trim((string)($r['CreatedAt'] ?? ''));
                  $submittedAt = trim((string)($r['SubmittedAt'] ?? ''));
                  $submittedDisplay = ($submittedAt !== '' && $submittedAt !== $createdAt) ? $submittedAt : '-';
                  $name = trim((string)($r['FirstName'] ?? '') . ' ' . (string)($r['Surname'] ?? ''));
                  $cardLabel = trim((string)($r['CardType'] ?? '') . ' ' . (string)($r['CardTypeSub'] ?? ''));
                  $requestType = strtoupper(trim((string)($r['RequestType'] ?? '')));
                  $cancelState = admin_cancel_state_meta($r, $payload);
                  $cardId = (int)($r['CardID'] ?? 0);
                  $openFormUrl = '';
                  if ($cardId > 0 && in_array($requestType, ['ADDRESS_CHANGE', 'CONTACT_CHANGE'], true)) {
                      $openFormUrl = 'index.php?route=cards/change-address&id=' . urlencode((string)$cardId) . '&admin=1';
                  } elseif ($cardId > 0 && $requestType === 'CANCEL_CARD') {
                      $openFormUrl = 'index.php?route=cards/cancel-card&id=' . urlencode((string)$cardId) . '&admin=1';
                  }
                ?>
                <tr>
                  <td>
                    <div class="fw-semibold">#<?= h((string)($r['RequestID'] ?? '')) ?></div>
                    <div class="small text-muted">Created: <?= h(fmt_dt_admin_cr($createdAt)) ?></div>
                  </td>
                  <td>
                    <div><?= h((string)($r['CardID'] ?? '')) ?></div>
                    <div class="small text-muted"><?= h($cardLabel !== '' ? $cardLabel : '-') ?></div>
                    <div class="small text-muted"><?= h(mask_card_number_admin_cr((string)($r['CardNumber'] ?? ''))) ?></div>
                  </td>
                  <td>
                    <div><?= h((string)($r['EmployeeID'] ?? '')) ?></div>
                    <div class="small text-muted"><?= h($name !== '' ? $name : '-') ?></div>
                  </td>
                  <td><?= h((string)($r['RequestType'] ?? '')) ?></td>
                  <td>
                    <div><span class="badge bg-light text-dark border"><?= h((string)($r['Status'] ?? '')) ?></span></div>
                    <?php if ($requestType === 'CANCEL_CARD'): ?>
                      <div class="mt-1"><span class="badge <?= h((string)($cancelState['badge'] ?? 'bg-light text-dark border')) ?>"><?= h((string)($cancelState['label'] ?? '-')) ?></span></div>
                    <?php endif; ?>
                  </td>
                  <td><?= $requestType === 'CANCEL_CARD' ? h((string)($cancelState['label'] ?? '-')) : '-' ?></td>
                  <td><?= h($submittedDisplay === '-' ? '-' : fmt_dt_admin_cr($submittedDisplay)) ?></td>
                  <td><?= h(fmt_dt_admin_cr_utc((string)($r['CompletedAt'] ?? ''))) ?></td>
                  <td>
                    <?php if (!$payload): ?>
                      <span class="text-muted">No details</span>
                    <?php else: ?>
                      <div class="small">
                        <?php foreach ($payload as $k => $v): ?>
                          <div><strong><?= h(pretty_label_admin_cr((string)$k)) ?>:</strong> <?= h(format_payload_value_admin_cr($v)) ?></div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td class="text-end">
                    <div class="btn-group btn-group-sm">
                      <?php if ($openFormUrl !== ''): ?>
                        <a class="btn btn-outline-primary" href="<?= h($openFormUrl) ?>">Open Form</a>
                      <?php endif; ?>
                      <form method="post" action="index.php?route=admin/change-requests-delete" class="d-inline" onsubmit="return confirm('Delete this change request?');">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="id" value="<?= h((string)($r['RequestID'] ?? 0)) ?>">
                        <button type="submit" class="btn btn-outline-danger">Delete</button>
                      </form>
                    </div>
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
