<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$files = is_array($files ?? null) ? $files : [];
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">PHP Error Logs</h3>
      <div class="text-muted">View the configured PHP error log and any matching files in the same directory.</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
      <i class="bi bi-arrow-left me-1"></i>Back
    </a>
  </div>

  <div class="alert alert-secondary">
    <strong>About this screen:</strong> The PHP Error Log is the raw PHP/runtime log from the server's configured <code>error_log</code> setting. It is separate from the portal's Application Log and is used for low-level PHP and direct <code>error_log(...)</code> writes.
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Log Files</strong>
      <a class="btn btn-outline-primary btn-sm" href="index.php?route=php-error-log/view">Open Current</a>
    </div>
    <div class="card-body">
      <div class="small text-muted mb-3">Configured `error_log`: <?= h((string)($configuredPath ?: '(not configured)')) ?></div>
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>File</th>
              <th class="text-end">Size (bytes)</th>
              <th>Modified</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$files): ?>
              <tr><td colspan="4" class="text-center text-muted">No PHP error log files found.</td></tr>
            <?php else: ?>
              <?php foreach ($files as $file): ?>
                <tr>
                  <td><?= h((string)$file['name']) ?></td>
                  <td class="text-end"><?= number_format((int)$file['size']) ?></td>
                  <td><?= h((string)$file['modified_at']) ?></td>
                  <td class="text-end">
                    <div class="btn-group btn-group-sm">
                      <a class="btn btn-outline-primary" href="index.php?route=php-error-log/view&file=<?= urlencode((string)$file['name']) ?>">View</a>
                      <a class="btn btn-outline-secondary" href="index.php?route=php-error-log/download&file=<?= urlencode((string)$file['name']) ?>">Download</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
