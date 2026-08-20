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
      <h3 class="mb-1">Application Logs</h3>
      <div class="text-muted">View daily application log files written by the portal logger.</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
      <i class="bi bi-arrow-left me-1"></i>Back
    </a>
  </div>

  <div class="alert alert-info">
    <strong>About this screen:</strong> The Application Log is the portal's main structured log. It includes normal operational events, warnings, and application errors written through the portal logger.
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Log Files</strong>
      <a class="btn btn-outline-primary btn-sm" href="index.php?route=log-maintenance/view">Open Latest</a>
    </div>
    <div class="card-body">
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
              <tr><td colspan="4" class="text-center text-muted">No application log files found.</td></tr>
            <?php else: ?>
              <?php foreach ($files as $file): ?>
                <tr>
                  <td><?= h((string)$file['name']) ?></td>
                  <td class="text-end"><?= number_format((int)$file['size']) ?></td>
                  <td><?= h((string)$file['modified_at']) ?></td>
                  <td class="text-end">
                    <div class="btn-group btn-group-sm">
                      <a class="btn btn-outline-primary" href="index.php?route=log-maintenance/view&file=<?= urlencode((string)$file['name']) ?>">View</a>
                      <a class="btn btn-outline-secondary" href="index.php?route=log-maintenance/download&file=<?= urlencode((string)$file['name']) ?>">Download</a>
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
