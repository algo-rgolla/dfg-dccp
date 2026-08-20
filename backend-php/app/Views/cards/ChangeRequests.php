<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('pretty_label')) {
    function pretty_label(string $key): string
    {
        return ucwords(str_replace('_', ' ', trim($key)));
    }
}
if (!function_exists('fmt_dt_change_request')) {
    function fmt_dt_change_request(?string $value): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return '-';
        }
        $ts = strtotime($text);
        return $ts === false ? $text : date('d-m-Y H:i', $ts);
    }
}
if (!function_exists('format_payload_value')) {
    function format_payload_value(string $key, $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }
        $s = trim((string)$value);
        if ($s === '') {
            return '-';
        }
        $normalizedKey = strtolower(trim($key));
        if (in_array($normalizedKey, ['cardnumber', 'card_number'], true)) {
            $digits = preg_replace('/\s+/', '', $s) ?? '';
            $last4 = $digits !== '' ? substr($digits, -4) : '';
            return $last4 !== '' ? ('************' . $last4) : '-';
        }
        return $s;
    }
}
if (!function_exists('request_cancel_state_meta')) {
    function request_cancel_state_meta(array $row, array $payload): array
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

$rows = $rows ?? [];
$selectedCardId = (int)($selectedCardId ?? 0);
$selectedCard = (isset($selectedCard) && is_array($selectedCard)) ? $selectedCard : null;
$requestCount = is_array($rows) ? count($rows) : 0;
?>
<style>
  .request-details-toggle summary {
    cursor: pointer;
    color: var(--bs-primary);
    user-select: none;
    list-style: none;
  }
  .request-details-toggle summary::-webkit-details-marker {
    display: none;
  }
  .request-details-toggle summary .label-hide {
    display: none;
  }
  .request-details-toggle[open] summary .label-show {
    display: none;
  }
  .request-details-toggle[open] summary .label-hide {
    display: inline;
  }
</style>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Card Change Requests</h3>
      <?php if ($selectedCardId > 0): ?>
        <div class="text-muted">
          Showing requests for 
          <?php if ($selectedCard): ?>
            <?php $selectedCardNumber = trim((string)($selectedCard['CardNumber'] ?? '')); ?>
            (<?= h((string)($selectedCard['CardType'] ?? 'Card')) ?><?= $selectedCardNumber !== '' ? (' - ****' . h(substr($selectedCardNumber, -4))) : '' ?>)
          <?php endif; ?>.
        </div>
      <?php else: ?>
        <div class="text-muted">History of address changes, cancellations, and limit change requests.</div>
      <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
      <?php if ($selectedCardId > 0): ?>
        <a class="btn btn-outline-primary btn-sm" href="index.php?route=cards/change-requests">
          <i class="bi bi-list-ul me-1"></i>All Requests
        </a>
      <?php endif; ?>
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
        <i class="bi bi-arrow-left me-1"></i>Back to Cards
      </a>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div>
        <strong>Request History</strong>
        <div class="text-muted small">Submitted address change, cancel card, and limit change requests.</div>
      </div>
      <span class="badge bg-secondary"><?= h((string)$requestCount) ?></span>
    </div>
    <div class="card-body p-0">
      <?php if (!$rows): ?>
        <div class="p-4 text-muted text-center">No requests found.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-striped table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Request ID</th>
              <th>Card ID</th>
              <th>Type</th>
              <th>Status</th>
              <th>Cancellation</th>
              <th>Created</th>
              <th>Submitted</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <?php
                $payloadRaw = (string)($r['PayloadJson'] ?? '');
                $payload = json_decode($payloadRaw, true);
                $payload = is_array($payload) ? $payload : [];
                $cancelState = request_cancel_state_meta($r, $payload);
                $createdAt = trim((string)($r['CreatedAt'] ?? ''));
                $submittedAt = trim((string)($r['SubmittedAt'] ?? ''));
                $submittedDisplay = ($submittedAt !== '' && $submittedAt !== $createdAt) ? $submittedAt : '-';
                $requestType = strtoupper(trim((string)($r['RequestType'] ?? '')));
              ?>
              <tr>
                <td class="text-nowrap fw-semibold"><?= h((string)($r['RequestID'] ?? '')) ?></td>
                <td class="text-nowrap"><?= h((string)($r['CardID'] ?? '')) ?></td>
                <td><?= h((string)($r['RequestType'] ?? '')) ?></td>
                <td><span class="badge bg-light text-dark border"><?= h((string)($r['Status'] ?? '')) ?></span></td>
                <td>
                  <?php if ($requestType === 'CANCEL_CARD'): ?>
                    <span class="badge <?= h((string)($cancelState['badge'] ?? 'bg-light text-dark border')) ?>"><?= h((string)($cancelState['label'] ?? '-')) ?></span>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </td>
                <td class="text-nowrap"><?= h(fmt_dt_change_request($createdAt)) ?></td>
                <td class="text-nowrap"><?= h($submittedDisplay === '-' ? '-' : fmt_dt_change_request($submittedDisplay)) ?></td>
                <td>
                  <?php if (!$payload): ?>
                    <span class="text-muted">No details</span>
                  <?php else: ?>
                    <details class="request-details-toggle small">
                      <summary>
                        <span class="label-show">Show details</span>
                        <span class="label-hide">Hide details</span>
                      </summary>
                      <div class="mt-2">
                        <?php foreach ($payload as $k => $v): ?>
                          <div><strong><?= h(pretty_label((string)$k)) ?>:</strong> <?= h(format_payload_value((string)$k, $v)) ?></div>
                        <?php endforeach; ?>
                      </div>
                    </details>
                  <?php endif; ?>
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
