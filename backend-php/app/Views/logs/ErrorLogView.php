<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$entries = is_array($entries ?? null) ? $entries : [];
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Application Error Log</h3>
      <div class="text-muted">Recent error and critical entries extracted from the application log files.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=log-maintenance/list">Application Logs</a>
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">Back</a>
    </div>
  </div>

  <div class="alert alert-warning">
    <strong>About this screen:</strong> Application Errors is not a separate log file. It is a filtered view of the Application Log that only shows entries classified as <code>ERROR</code> or <code>CRITICAL</code>.
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Recent Error Entries</strong>
      <span class="text-muted small"><?= count($entries) ?> entry(s)</span>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>Source File</th>
              <th>Severity</th>
              <th>Entry</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$entries): ?>
              <tr><td colspan="3" class="text-center text-muted">No application error entries found.</td></tr>
            <?php else: ?>
              <?php foreach ($entries as $entry): ?>
                <tr>
                  <td class="text-nowrap">
                    <a href="index.php?route=log-maintenance/view&file=<?= urlencode((string)$entry['file']) ?>"><?= h((string)$entry['file']) ?></a>
                  </td>
                  <td>
                    <?php $severity = (string)($entry['severity'] ?? ''); ?>
                    <span class="badge <?= $severity === 'CRITICAL' ? 'bg-danger' : 'bg-warning text-dark' ?>"><?= h($severity) ?></span>
                  </td>
                  <td><code><?= h((string)$entry['line']) ?></code></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
