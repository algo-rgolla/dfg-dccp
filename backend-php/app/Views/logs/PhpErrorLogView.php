<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$availableFiles = is_array($availableFiles ?? null) ? $availableFiles : [];
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">PHP Error Log</h3>
      <div class="text-muted">Showing the latest 400 lines from the selected PHP error log file.</div>
    </div>
    <div class="d-flex gap-2">
      <?php if (!empty($fileName)): ?>
        <a class="btn btn-outline-primary btn-sm" href="index.php?route=php-error-log/download&file=<?= urlencode((string)$fileName) ?>">Download</a>
      <?php endif; ?>
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=php-error-log/list">All PHP Logs</a>
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">Back</a>
    </div>
  </div>

  <div class="alert alert-secondary">
    <strong>About this screen:</strong> This is the raw PHP Error Log. It may contain PHP runtime errors, fatal conditions, and direct <code>error_log(...)</code> output. It is separate from the portal's Application Log.
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong><?= h((string)($fileName ?? '')) ?></strong>
      <?php if ($availableFiles): ?>
        <form method="get" class="d-flex align-items-center gap-2 mb-0">
          <input type="hidden" name="route" value="php-error-log/view">
          <select class="form-select form-select-sm" name="file" onchange="this.form.submit()">
            <?php foreach ($availableFiles as $file): ?>
              <?php $name = (string)$file['name']; ?>
              <option value="<?= h($name) ?>" <?= $name === (string)($fileName ?? '') ? 'selected' : '' ?>><?= h($name) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <div class="small text-muted mb-3">Configured `error_log`: <?= h((string)($configuredPath ?: '(not configured)')) ?></div>
      <?php if (!empty($missing)): ?>
        <div class="alert alert-warning mb-0">The requested PHP error log file could not be found.</div>
      <?php else: ?>
        <pre class="bg-light border rounded p-3 mb-0" style="max-height:70vh; overflow:auto; white-space:pre-wrap;"><?= h((string)($content ?? '')) ?></pre>
      <?php endif; ?>
    </div>
  </div>
</div>
