<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$rows = is_array($rows ?? null) ? $rows : [];
$messageId = isset($messageId) && (int)$messageId > 0 ? (int)$messageId : null;
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Email Queue Recipients</h3>
      <div class="text-muted">
        <?php if ($messageId !== null): ?>
          Grouped list of recipients for system message ID <?= (int)$messageId ?>.
        <?php else: ?>
          Grouped list of all recipients currently in the email queue.
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>
        <?php if ($messageId !== null): ?>
          Recipient Summary For Message <?= (int)$messageId ?>
        <?php else: ?>
          Recipient Summary
        <?php endif; ?>
      </strong>
      <div class="d-flex gap-2">
        <?php if ($messageId !== null): ?>
          <a class="btn btn-outline-primary btn-sm" href="index.php?route=emailqueue/recipients">All Recipients</a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="index.php?route=emailqueue/index">Back to Email Queue</a>
      </div>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>Recipient</th>
              <?php if ($messageId === null): ?>
                <th class="text-end">Message ID</th>
              <?php endif; ?>
              <th class="text-end">Total Queued</th>
              <th class="text-end">Pending</th>
              <th class="text-end">Sent</th>
              <th class="text-end">Failed</th>
              <th>Next Send At (UTC)</th>
              <th>Last Sent At (UTC)</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr>
                <td colspan="<?= $messageId === null ? '8' : '7' ?>" class="text-center text-muted">No email recipients found.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= h((string)($row['ToEmail'] ?? '')) ?></td>
                  <?php if ($messageId === null): ?>
                    <td class="text-end"><?= (int)($row['MessageID'] ?? 0) ?></td>
                  <?php endif; ?>
                  <td class="text-end"><?= (int)($row['QueueCount'] ?? 0) ?></td>
                  <td class="text-end"><?= (int)($row['PendingCount'] ?? 0) ?></td>
                  <td class="text-end"><?= (int)($row['SentCount'] ?? 0) ?></td>
                  <td class="text-end"><?= (int)($row['FailedCount'] ?? 0) ?></td>
                  <td><?= h((string)($row['NextSendAtUTC'] ?? '')) ?></td>
                  <td><?= h((string)($row['LastSentAtUTC'] ?? '')) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
