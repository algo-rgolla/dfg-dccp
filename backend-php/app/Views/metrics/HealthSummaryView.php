<?php
declare(strict_types=1);
/** @var array $data */

if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$status   = (string)($data['status'] ?? 'unknown');
$env      = (string)($data['env'] ?? 'local');
$version  = (string)($data['version'] ?? 'dev');
$time     = (string)($data['time'] ?? '');
$checks   = $data['extraChecks'] ?? [];
?>

<div class="container mt-4">
  <h2><i class="bi bi-heart-pulse me-2"></i> System Health Summary</h2>

  <!-- Overall status -->
  <div class="card shadow-sm mb-4">
    <div class="card-body d-flex flex-wrap gap-3 align-items-center">
      <span class="badge <?= $status === 'ok' ? 'bg-success' : ($status === 'degraded' ? 'bg-warning text-dark' : 'bg-danger') ?>">
        <?= h(strtoupper($status)) ?>
      </span>
      <div><strong>Environment:</strong> <?= h($env) ?></div>
      <div><strong>Version:</strong> <?= h($version) ?></div>
      <div><strong>Server Time:</strong> <?= h($time) ?></div>
    </div>
  </div>

  <!-- Checks table -->
  <div class="card shadow-sm">
    <div class="card-header">
      <strong>Health Checks</strong>
    </div>
    <div class="table-responsive">
      <table class="table table-striped table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>Check</th>
            <th>Status</th>
            <th>Message</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($checks)): ?>
            <?php foreach ($checks as $c): ?>
              <tr>
                <td><?= h($c['label'] ?? $c['id'] ?? 'Check') ?></td>
                <td>
                  <span class="badge <?= !empty($c['ok']) ? 'bg-success' : 'bg-danger' ?>">
                    <?= !empty($c['ok']) ? 'OK' : 'Fail' ?>
                  </span>
                </td>
                <td><?= h((string)($c['message'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="3" class="text-muted">No checks found</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
