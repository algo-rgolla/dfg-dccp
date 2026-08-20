<?php declare(strict_types=1); ?>
<?php
if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$msg = is_array($msg ?? null) ? $msg : [];
$counts = is_array($counts ?? null) ? $counts : [];
$sample = is_array($sample ?? null) ? $sample : [];

$severityRaw = strtolower(trim((string)($msg['Severity'] ?? 'info')));
$severityMap = [
    '1' => 'info',
    '2' => 'warning',
    '3' => 'danger',
    '4' => 'success',
];
$severity = $severityMap[$severityRaw] ?? $severityRaw;
$severityClassMap = [
    'info' => 'alert-info',
    'warning' => 'alert-warning',
    'danger' => 'alert-danger',
    'success' => 'alert-success',
];
$alertClass = $severityClassMap[$severity] ?? 'alert-info';

$titleText = (string)($msg['Title'] ?? '');
$bodyHtml = (string)($msg['BodyHtml'] ?? '');
$emailEnabled = !empty($msg['EmailAlso']);
$emailSubject = trim((string)($msg['EmailSubject'] ?? ''));
$emailBodyHtml = (string)($msg['EmailBodyHtml'] ?? $bodyHtml);
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1"><?= h((string)($title ?? 'Audience Preview')) ?></h3>
      <div class="text-muted">Preview the message content and the resolved audience before relying on it.</div>
    </div>
    <div class="d-flex gap-2">
      <a href="index.php?route=systemmessages/editForm&MessageID=<?= (int)($msg['MessageID'] ?? 0) ?>" class="btn btn-outline-primary btn-sm">Edit Message</a>
      <a href="index.php?route=systemmessages/index" class="btn btn-outline-secondary btn-sm">Back to List</a>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-header">
          <strong>Portal Message Preview</strong>
        </div>
        <div class="card-body">
          <div class="alert <?= h($alertClass) ?> mb-0">
            <div class="fw-semibold mb-2"><?= h($titleText) ?></div>
            <div><?= $bodyHtml !== '' ? $bodyHtml : '<span class="text-muted">No message body provided.</span>' ?></div>
          </div>
        </div>
      </div>

      <div class="card shadow-sm mt-3">
        <div class="card-header">
          <strong>Email Preview</strong>
        </div>
        <div class="card-body">
          <?php if ($emailEnabled): ?>
            <div class="mb-2"><strong>Subject:</strong> <?= h($emailSubject !== '' ? $emailSubject : $titleText) ?></div>
            <div class="border rounded p-3 bg-light">
              <?= $emailBodyHtml !== '' ? $emailBodyHtml : '<span class="text-muted">No email body provided.</span>' ?>
            </div>
          <?php else: ?>
            <div class="text-muted">Email delivery is not enabled for this message.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-header">
          <strong>Message Details</strong>
        </div>
        <div class="card-body">
          <div class="mb-2"><strong>Message ID:</strong> <?= (int)($msg['MessageID'] ?? 0) ?></div>
          <div class="mb-2"><strong>Window (UTC):</strong> <?= h((string)($msg['DeliveryStartUTC'] ?? '')) ?> - <?= h((string)($msg['DeliveryEndUTC'] ?? 'Open-ended')) ?></div>
          <div class="mb-2"><strong>Severity:</strong> <?= h($severity) ?></div>
          <div class="mb-2"><strong>Requires Ack:</strong> <?= !empty($msg['RequireAck']) || !empty($msg['RequiresAck']) ? 'Yes' : 'No' ?></div>
          <div class="mb-2"><strong>Scope Group:</strong> <?= h((string)((($msg['ScopeGroupName'] ?? '') !== '') ? $msg['ScopeGroupName'] : 'All Groups')) ?></div>
          <div class="mb-2"><strong>Status:</strong> <?= h((string)($msg['Status'] ?? 'draft')) ?></div>
          <div><strong>Send Email:</strong> <?= $emailEnabled ? 'Yes' : 'No' ?></div>
        </div>
      </div>

      <div class="card shadow-sm mt-3">
        <div class="card-header">
          <strong>Audience Summary</strong>
        </div>
        <div class="card-body">
          <div class="mb-2"><strong>Total users (unique):</strong> <?= (int)($counts['users'] ?? 0) ?></div>
          <div class="mb-3"><strong>Total emails:</strong> <?= (int)($counts['emails'] ?? 0) ?></div>

          <h6 class="mb-2">Sample recipients</h6>
          <?php if ($sample): ?>
            <div class="border rounded p-2 bg-light" style="max-height: 260px; overflow-y: auto;">
              <ul class="small mb-0 ps-3">
                <?php foreach ($sample as $email): ?>
                  <li><?= h((string)$email) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php else: ?>
            <div class="text-muted">No recipients resolved.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
