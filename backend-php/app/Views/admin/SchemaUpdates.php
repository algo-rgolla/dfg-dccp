<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$scripts = is_array($scripts ?? null) ? $scripts : [];
$selectedScript = trim((string)($selectedScript ?? ''));
$previewSql = (string)($previewSql ?? '');
$csrf = h((string)($_csrf ?? csrf_token()));
$flash = \App\Shared\SessionHelper::get('flash.message');
if ($flash) {
    \App\Shared\SessionHelper::forget('flash.message');
}
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Schema Updates</h3>
      <div class="text-muted">Run approved SQL update scripts from inside the portal.</div>
    </div>
  </div>

  <?php if (is_array($flash) && !empty($flash['text'])): ?>
    <div class="alert alert-<?= h((string)($flash['type'] ?? 'info')) ?> py-2"><?= h((string)$flash['text']) ?></div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="alert alert-warning py-2">
        Only approved scripts can be run here, and this screen is intended for controlled admin use.
      </div>

      <div class="table-responsive">
        <table class="table table-striped align-middle">
          <thead>
            <tr>
              <th>Script</th>
              <th>Description</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($scripts as $file => $meta): ?>
              <tr>
                <td>
                  <div class="fw-semibold"><?= h((string)($meta['label'] ?? $file)) ?></div>
                  <div class="small text-muted"><?= h($file) ?></div>
                </td>
                <td><?= h((string)($meta['description'] ?? '')) ?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-secondary" href="index.php?route=admin/schema-updates&script=<?= urlencode($file) ?>">Preview</a>
                  <form method="post" action="index.php?route=admin/schema-updates-run" class="d-inline" onsubmit="return confirm('Run this schema update now?');">
                    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="script_file" value="<?= h($file) ?>">
                    <button type="submit" class="btn btn-sm btn-primary">Run</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($selectedScript !== '' && $previewSql !== ''): ?>
        <hr>
        <div class="fw-semibold mb-2">Preview: <?= h($selectedScript) ?></div>
        <pre class="border rounded p-3 bg-light" style="max-height: 420px; overflow: auto; white-space: pre-wrap;"><?= h($previewSql) ?></pre>
      <?php endif; ?>
    </div>
  </div>
</div>
