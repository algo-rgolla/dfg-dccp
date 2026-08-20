<?php
declare(strict_types=1);
/** @var array  $rows */
/** @var int    $total */
/** @var int    $page */
/** @var int    $pageSize */
/** @var string $q */
/** @var array  $entities */
/** @var string $entityFilter */
/** @var string $userFilter */
/** @var string $actionFilter */
/** @var string $startDate */
/** @var string $endDate */
/** @var string $sort (optional) */
/** @var string $dir  (optional) */

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

function renderDetails(?string $json): string {
  if (!$json) return '<span class="text-muted">—</span>';
  $decoded = json_decode($json, true);
  if ($decoded === null) {
    return '<pre class="small bg-light p-2 mb-0">'.h($json).'</pre>';
  }
  return '<pre class="small bg-light p-2 mb-0">'.h(json_encode($decoded, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre>';
}

function renderActionBadge(string $action): string {
  $map = [
    'UPDATE' => 'primary',
    'CREATE' => 'success',
    'DELETE' => 'danger',
    'DENIED' => 'dark',
    'UNLOCK' => 'warning',
  ];
  $label = strtoupper($action);
  $class = $map[$label] ?? 'secondary';
  return '<span class="badge bg-' . $class . '">' . h($label) . '</span>';
}

// defaults + pagination
$rows      = $rows ?? [];
$total     = (int)($total ?? 0);
$page      = max(1, (int)($page ?? 1));
$pageSize  = max(1, min(200, (int)($pageSize ?? 25)));
$pages     = (int)ceil(($total ?: 0) / max(1, $pageSize));
if ($pages < 1) $pages = 1;

// sorting (links only; controller should honor)
$sort = $sort ?? (string)($_GET['sort'] ?? 'EventTime');
$dir  = strtoupper($dir ?? (string)($_GET['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

// build querystring helpers
$baseParams = [
  'route'      => 'audit/list',
  'q'          => $q ?? '',
  'entity'     => $entityFilter ?? '',
  'userFilter' => $userFilter ?? '',
  'actionFilter'=> $actionFilter ?? '',
  'startDate'  => $startDate ?? '',
  'endDate'    => $endDate ?? '',
  'pageSize'   => $pageSize,
  'sort'       => $sort,
  'dir'        => $dir,
];

$qs = function(array $extra = []) use ($baseParams): string {
  return 'index.php?' . http_build_query(array_replace($baseParams, $extra));
};
$toggleSort = function (string $col) use ($sort, $dir, $qs): string {
  $newDir = ($sort === $col && $dir === 'ASC') ? 'DESC' : 'ASC';
  return $qs(['sort' => $col, 'dir' => $newDir, 'page' => 1]);
};
?>
<div class="container mt-4">

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong><i class="bi bi-journal-text me-2"></i><?= __t('audit_log_title') ?></strong>
      <a href="index.php?route=audit/list" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-clockwise me-1"></i><?= __t('refresh') ?>
      </a>
    </div>

    <div class="card-body">
      <!-- Filters (consistent with DataObjectCodesList) -->
      <form method="get" action="index.php" class="row g-2 mb-3">
        <input type="hidden" name="route" value="audit/list">

        <div class="col-md-3">
          <input type="text" name="q" value="<?= h($q ?? '') ?>" class="form-control"
                 placeholder="<?= __t('search') ?>...">
        </div>

        <div class="col-md-2">
          <select name="entity" class="form-select">
            <option value=""><?= __t('all') ?></option>
            <?php foreach ($entities as $ent): ?>
              <option value="<?= h($ent) ?>" <?= ($entityFilter===$ent?'selected':'') ?>>
                <?= h($ent) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-2">
          <input type="text" name="userFilter" class="form-control"
                 value="<?= h($userFilter ?? '') ?>" placeholder="<?= __t('user') ?>...">
        </div>

        <div class="col-md-2">
          <select name="actionFilter" class="form-select">
            <option value=""><?= __t('all') ?></option>
            <?php foreach (['CREATE','UPDATE','DELETE','DENIED','UNLOCK'] as $act): ?>
              <option value="<?= $act ?>" <?= ($actionFilter===$act?'selected':'') ?>><?= $act ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-1">
          <input class="form-control" type="date" name="startDate" value="<?= h($startDate ?? '') ?>"
                 title="<?= __t('start_date') ?>">
        </div>

        <div class="col-md-1">
          <input class="form-control" type="date" name="endDate" value="<?= h($endDate ?? '') ?>"
                 title="<?= __t('end_date') ?>">
        </div>

        <div class="col-md-1">
          <select class="form-select" name="pageSize" onchange="this.form.submit()">
            <?php foreach ([10,25,50,100,200] as $ps): ?>
              <option value="<?= $ps ?>" <?= $pageSize === $ps ? 'selected' : '' ?>><?= $ps ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-12 d-flex justify-content-end gap-2">
          <a class="btn btn-sm btn-outline-secondary" href="index.php?route=audit/list">
            <?= __t('reset') ?>
          </a>
          <button type="submit" class="btn btn-sm btn-primary">
            <i class="bi bi-search me-1"></i><?= __t('apply') ?>
          </button>
        </div>
      </form>

      <!-- Table -->
      <div class="table-responsive">
        <table class="table table-striped table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th><a class="text-decoration-none" href="<?= $toggleSort('EventTime') ?>">
                <?= __t('event_time') ?><?= $sort==='EventTime' ? ' '.h($dir) : '' ?></a></th>
              <th><a class="text-decoration-none" href="<?= $toggleSort('Username') ?>">
                <?= __t('user') ?><?= $sort==='Username' ? ' '.h($dir) : '' ?></a></th>
              <th><a class="text-decoration-none" href="<?= $toggleSort('Action') ?>">
                <?= __t('action') ?><?= $sort==='Action' ? ' '.h($dir) : '' ?></a></th>
              <th><a class="text-decoration-none" href="<?= $toggleSort('Entity') ?>">
                <?= __t('entity') ?><?= $sort==='Entity' ? ' '.h($dir) : '' ?></a></th>
              <th><?= __t('key') ?></th>
              <th><a class="text-decoration-none" href="<?= $toggleSort('IPAddress') ?>">
                <?= __t('ip') ?><?= $sort==='IPAddress' ? ' '.h($dir) : '' ?></a></th>
              <th><?= __t('details') ?></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!empty($rows)): ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= h((string)($r['EventTime'] ?? '')) ?></td>
                <td><?= h((string)($r['Username'] ?? '')) ?></td>
                <td><?= renderActionBadge((string)($r['Action'] ?? '')) ?></td>
                <td><?= h((string)($r['Entity'] ?? '')) ?></td>
                <td><?= h((string)($r['EntityKey'] ?? '')) ?></td>
                <td><?= h((string)($r['IPAddress'] ?? '')) ?></td>
                <td>
                  <?php if (!empty($r['Details'])): ?>
                    <details class="small mb-0">
                      <summary><?= __t('view') ?></summary>
                      <?= renderDetails((string)$r['Details']) ?>
                    </details>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7" class="text-center text-muted py-3"><?= __t('no_records_found') ?></td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($pages > 1): ?>
        <nav aria-label="Audit pagination" class="mt-3">
          <ul class="pagination justify-content-center">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $qs(['page' => max(1, $page - 1)]) ?>">&laquo; <?= __t('prev') ?></a>
            </li>
            <?php for ($i = 1; $i <= $pages; $i++): ?>
              <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= $qs(['page' => $i]) ?>"><?= $i ?></a>
              </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $qs(['page' => min($pages, $page + 1)]) ?>"><?= __t('next') ?> &raquo;</a>
            </li>
          </ul>
          <p class="text-center text-muted small mb-0">
            <?= __t('showing') ?> <?= count($rows) ?> <?= __t('of') ?> <?= $total ?> records
          </p>
        </nav>
      <?php endif; ?>
    </div>
  </div>
</div>
