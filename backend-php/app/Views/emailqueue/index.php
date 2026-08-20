<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$rows = is_array($rows ?? null) ? $rows : [];
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Email Queue</h3>
      <div class="text-muted">System message emails are queued here first. They are only sent when you process the queue.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-primary btn-sm" href="index.php?route=emailqueue/recipients">View Recipients</a>
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=systemmessages/index">Back to System Messages</a>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Queued Emails</strong>
      <div class="d-flex gap-2">
        <form method="post" action="index.php?route=emailqueue/send" class="d-inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="all">
          <button type="submit" class="btn btn-outline-primary btn-sm">Send All Pending</button>
        </form>
      </div>
    </div>
    <div class="card-body">
      <form method="post" action="index.php?route=emailqueue/send">
        <?= csrf_field() ?>

        <div class="mb-3">
          <button type="submit" name="action" value="selected" class="btn btn-primary btn-sm">Send Selected</button>
        </div>

        <div class="table-responsive">
          <table class="table table-sm table-striped align-middle">
            <thead>
              <tr>
                <th style="width:40px;">
                  <input type="checkbox" id="selectAllPending">
                </th>
                <th>ID</th>
                <th>Status</th>
                <th>To</th>
                <th>Subject</th>
                <th>Send At (UTC)</th>
                <th>Sent At (UTC)</th>
                <th>Attempts</th>
                <th>Last Error</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr>
                  <td colspan="10" class="text-center text-muted">No queued emails found.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($rows as $row): ?>
                  <?php
                    $status = (string)($row['QueueStatus'] ?? 'pending');
                    $isPending = $status === 'pending' || $status === 'failed';
                    $badgeClass = $status === 'sent'
                        ? 'bg-success'
                        : ($status === 'failed' ? 'bg-danger' : 'bg-warning text-dark');
                  ?>
                  <tr>
                    <td>
                      <?php if ($isPending): ?>
                        <input type="checkbox" class="pending-email-checkbox" name="email_ids[]" value="<?= (int)($row['EmailID'] ?? 0) ?>">
                      <?php endif; ?>
                    </td>
                    <td><?= (int)($row['EmailID'] ?? 0) ?></td>
                    <td><span class="badge <?= h($badgeClass) ?>"><?= h(ucfirst($status)) ?></span></td>
                    <td><?= h((string)($row['ToEmail'] ?? '')) ?></td>
                    <td><?= h((string)($row['Subject'] ?? '')) ?></td>
                    <td><?= h((string)($row['SendAtUTC'] ?? '')) ?></td>
                    <td><?= h((string)($row['SentAtUTC'] ?? '')) ?></td>
                    <td><?= (int)($row['Attempts'] ?? 0) ?></td>
                    <td><?= h((string)($row['LastError'] ?? '')) ?></td>
                    <td class="text-end">
                      <?php if (!empty($row['MessageID'])): ?>
                        <a class="btn btn-outline-secondary btn-sm" href="index.php?route=emailqueue/recipients&MessageID=<?= (int)($row['MessageID'] ?? 0) ?>">Recipients</a>
                      <?php else: ?>
                        <span class="text-muted small">No message</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var selectAll = document.getElementById('selectAllPending');
  if (!selectAll) {
    return;
  }

  selectAll.addEventListener('change', function () {
    document.querySelectorAll('.pending-email-checkbox').forEach(function (checkbox) {
      checkbox.checked = selectAll.checked;
    });
  });
});
</script>
