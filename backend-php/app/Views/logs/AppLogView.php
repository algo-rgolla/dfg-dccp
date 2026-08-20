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
      <h3 class="mb-1">Application Log</h3>
      <div class="text-muted">Showing the latest 400 lines from the selected application log file.</div>
    </div>
    <div class="d-flex gap-2">
      <?php if (!empty($fileName)): ?>
        <a class="btn btn-outline-primary btn-sm" href="index.php?route=log-maintenance/download&file=<?= urlencode((string)$fileName) ?>">Download</a>
      <?php endif; ?>
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=log-maintenance/list">All Logs</a>
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">Back</a>
    </div>
  </div>

  <div class="alert alert-info">
    <strong>About this screen:</strong> This is the full Application Log for one file. It may include routine portal activity as well as warnings and errors. Use <a href="index.php?route=error-log/errors" class="alert-link">Application Errors</a> for the filtered error-only view.
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong><?= h((string)($fileName ?? '')) ?></strong>
      <?php if ($availableFiles): ?>
        <form method="get" class="d-flex align-items-center gap-2 mb-0">
          <input type="hidden" name="route" value="log-maintenance/view">
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
      <?php if (!empty($missing)): ?>
        <div class="alert alert-warning mb-0">The requested log file could not be found.</div>
      <?php else: ?>
        <pre class="bg-light border rounded p-3 mb-0" style="max-height:70vh; overflow:auto; white-space:pre-wrap;"><?= h((string)($content ?? '')) ?></pre>
      <?php endif; ?>
    </div>
  </div>
</div>
