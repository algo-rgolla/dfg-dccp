<?php
declare(strict_types=1);
/** @var array $data */

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$status   = (string)($data['status'] ?? 'degraded');
$env      = (string)($data['env'] ?? 'local');
$version  = (string)($data['version'] ?? 'dev');
$time     = (string)($data['time'] ?? '');
$rid      = (string)($data['rid'] ?? '');

$dbOk  = (bool)($data['checks']['db']['ok'] ?? false);
$dbErr = isset($data['checks']['db']['error']) && $data['checks']['db']['error'] !== null
       ? (string)$data['checks']['db']['error']
       : '';

$extras = is_array($data['extraChecks'] ?? null) ? $data['extraChecks'] : [];

$badgeClass = match ($status) {
  'ok'       => 'bg-success',
  'degraded' => 'bg-warning text-dark',
  default    => 'bg-danger',
};
?>
<div class="container mt-4">

  <div class="card shadow-sm">
    <!-- Header (consistent with list screens) -->
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong><i class="bi bi-activity me-2"></i><?= __t('health_check') ?></strong>
      <div class="d-flex gap-2">
        <a href="index.php?route=health/index" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-arrow-clockwise me-1"></i><?= __t('refresh') ?>
        </a>
        <a href="index.php?route=health/ping" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">
          <i class="bi bi-braces me-1"></i><?= __t('view_json') ?>
        </a>
        <a href="index.php?route=diagnostics/index" class="btn btn-sm btn-outline-dark">
          <i class="bi bi-heart-pulse me-1"></i><?= __t('menu_diagnostics') ?>
        </a>
      </div>
    </div>

    <div class="card-body">
      <!-- Top status strip -->
      <div class="row g-3 align-items-center mb-2">
        <div class="col-auto">
          <span class="badge <?= $badgeClass ?>"><?= h(strtoupper($status)) ?></span>
        </div>
        <div class="col-auto"><strong><?= __t('environment') ?>:</strong> <?= h($env) ?></div>
        <div class="col-auto"><strong><?= __t('version') ?>:</strong> <?= h($version) ?></div>
        <div class="col-auto"><strong><?= __t('server_time') ?>:</strong> <?= h($time) ?></div>
        <?php if ($rid): ?>
          <div class="col-auto"><strong><?= __t('reference_id') ?>:</strong> <?= h($rid) ?></div>
        <?php endif; ?>
      </div>

      <div class="row g-3">
        <!-- Database status -->
        <div class="col-12 col-lg-6">
          <div class="card h-100">
            <div class="card-header"><strong><?= __t('database_status') ?></strong></div>
            <div class="card-body">
              <?php if ($dbOk): ?>
                <span class="badge bg-success"><?= __t('healthy') ?></span>
              <?php else: ?>
                <span class="badge bg-danger"><?= __t('unhealthy') ?></span>
                <?php if ($dbErr !== ''): ?>
                  <div class="mt-2 text-danger small"><?= h($dbErr) ?></div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Quick actions (kept, but compact buttons) -->
        <div class="col-12 col-lg-6">
          <div class="card h-100">
            <div class="card-header"><strong><?= __t('actions') ?></strong></div>
            <div class="card-body d-flex flex-wrap gap-2">
              <a href="index.php?route=health/index" class="btn btn-sm btn-primary">
                <i class="bi bi-arrow-repeat me-1"></i> <?= __t('refresh') ?>
              </a>
              <a href="index.php?route=health/ping" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">
                <i class="bi bi-braces me-1"></i> <?= __t('view_json') ?>
              </a>
              <a href="index.php?route=diagnostics/index" class="btn btn-sm btn-outline-dark">
                <i class="bi bi-heart-pulse me-1"></i> <?= __t('menu_diagnostics') ?>
              </a>
            </div>
          </div>
        </div>
      </div>

      <!-- Additional checks -->
      <?php if (!empty($extras)): ?>
        <div class="card mt-3">
          <div class="card-header"><strong><?= __t('checks') ?></strong></div>
          <div class="card-body p-0">
            <ul class="list-group list-group-flush">
              <?php foreach ($extras as $c):
                $ok      = (bool)($c['ok'] ?? false);
                $label   = (string)($c['label'] ?? ($c['id'] ?? 'Check'));
                $message = (string)($c['message'] ?? '');
                $meta    = $c['meta'] ?? null;
                $badge   = $ok ? 'bg-success' : 'bg-danger';
                $badgeTx = $ok ? __t('ok') : __t('fail');
              ?>
                <li class="list-group-item d-flex justify-content-between align-items-start">
                  <div class="me-3">
                    <div class="fw-semibold"><?= h($label) ?></div>
                    <?php if ($message !== ''): ?>
                      <div class="small text-muted"><?= h($message) ?></div>
                    <?php endif; ?>
                    <?php if (is_array($meta) && !empty($meta)): ?>
                      <details class="mt-1">
                        <summary class="small"><?= __t('details') ?></summary>
                        <pre class="small bg-light p-2 mb-0"><?= h(json_encode($meta, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) ?></pre>
                      </details>
                    <?php endif; ?>
                  </div>
                  <span class="badge <?= $badge ?> align-self-center"><?= h($badgeTx) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      <?php endif; ?>

      <!-- Footer hint (consistent muted line) -->
      <hr class="mt-4 mb-2">
      <p class="text-muted small mb-0">
        <?= __t('overall_status') ?>: <strong><?= h(strtoupper($status)) ?></strong>.
        <?= __t('refresh') ?> <?= strtolower(__t('to')) ?: 'to' ?> <?= strtolower(__t('view')) ?: 'view' ?> <?= strtolower(__t('latest')) ?: 'latest' ?>.
      </p>
    </div>
  </div>
</div>
