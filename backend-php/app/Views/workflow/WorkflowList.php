<?php
declare(strict_types=1);

/**
 * Expected from controller:
 * - $tasks (array of rows)
 * - $total, $totalPages, $page, $pageSize (ints)
 * - $q (string), $typeID (?int), $statusID (?int)
 * - $types, $statuses (either id=>name OR array-of-rows)
 * - $flash (optional flash array from SessionHelper)
 * - (optional) $title
 */

if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('t_or')) {
    function t_or(string $key, string $fallback): string {
        $t = __t($key);
        return $t === $key ? $fallback : $t;
    }
}

// Safe fallbacks
$title      = $title ?? __t('workflow_tasks');
$tasks      = is_array($tasks   ?? null) ? $tasks   : [];
$total      = (int)($total      ?? 0);
$totalPages = (int)($totalPages ?? 0);
$page       = max(1, (int)($page ?? 1));
$pageSize   = max(1, (int)($pageSize ?? 25));
$q          = (string)($q ?? '');
$typeID     = isset($typeID)   && $typeID   !== '' ? (int)$typeID   : null;
$statusID   = isset($statusID) && $statusID !== '' ? (int)$statusID : null;

// Some pages (home widget) pass ?iframe=1 and possibly ?status=open
$isIframe   = !empty($_GET['iframe']);
$status     = (string)($_GET['status'] ?? ''); // purely for optional label

// Normalize $types / $statuses to id=>name maps
$typesRaw    = is_array($types    ?? null) ? $types    : [];
$statusesRaw = is_array($statuses ?? null) ? $statuses : [];

$typesMap = [];
foreach ($typesRaw as $k => $v) {
    if (is_array($v)) {
        $id   = (int)($v['TaskTypeID']   ?? $v['TypeID']   ?? $v['ID'] ?? $k);
        $name = (string)($v['TaskTypeName'] ?? $v['Name'] ?? $v['TypeName'] ?? (reset($v) ?: ''));
    } else {
        $id   = (int)$k;
        $name = (string)$v;
    }
    if ($id > 0 && $name !== '') $typesMap[$id] = $name;
}

$statusesMap = [];
foreach ($statusesRaw as $k => $v) {
    if (is_array($v)) {
        $id   = (int)($v['StatusID']    ?? $v['ID'] ?? $k);
        $name = (string)($v['StatusName'] ?? $v['Name'] ?? (reset($v) ?: ''));
    } else {
        $id   = (int)$k;
        $name = (string)$v;
    }
    if ($id > 0 && $name !== '') $statusesMap[$id] = $name;
}

function wf_format_date(?string $dt): string {
    if (!$dt) return '';
    $ts = strtotime($dt);
    return $ts ? date('d/m/Y', $ts) : '';
}
function wf_build_query(array $params): string {
    return 'index.php?' . http_build_query($params);
}
?>

<?php if ($isIframe): ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= h($title) ?></title>
  <link href="assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/icons/bootstrap-icons.css" rel="stylesheet">
  <script src="assets/js/bootstrap.bundle.min.js"></script>
</head>
<body class="bg-light p-3">
<div class="container-fluid">
<?php endif; ?>

<!-- Safety net: inject Bootstrap if partial rendered without layout -->
<script>
(function () {
  if (!window.bootstrap) {
    var head = document.head || document.getElementsByTagName('head')[0];
    var css = document.createElement('link'); css.rel='stylesheet'; css.href='assets/css/bootstrap.min.css'; head.appendChild(css);
    var icons = document.createElement('link'); icons.rel='stylesheet'; icons.href='assets/icons/bootstrap-icons.css'; head.appendChild(icons);
    var js = document.createElement('script'); js.src='assets/js/bootstrap.bundle.min.js'; head.appendChild(js);
  }
})();
</script>

<div class="card shadow-sm mt-4">
  <!-- Header (matches DataObjectCodesList style) -->
  <div class="card-header d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center">
      <i class="bi bi-clipboard-check me-2"></i>
      <strong><?= h($title) ?></strong>
      <?php if ($status === 'open'): ?>
        <small class="text-muted ms-2">— <?= t_or('open_only', 'Open only') ?></small>
      <?php endif; ?>
    </div>
    <?php
      $createQs = ['route' => 'workflow/edit'];
      if ($isIframe) $createQs['iframe'] = '1';
      $createUrl = wf_build_query($createQs);
    ?>
    <a href="<?= h($createUrl) ?>" class="btn btn-sm btn-primary">
      <i class="bi bi-plus-circle me-1"></i><?= t_or('create_task', 'Create Task') ?>
    </a>
  </div>

  <div class="card-body">
    <!-- Flash -->
    <?php if (!empty($flash) && is_array($flash) && !empty($flash['text'])): ?>
      <div class="alert alert-<?= h($flash['type'] ?? 'info') ?> alert-dismissible fade show mb-3" role="alert">
        <?= $flash['text'] /* controller controls content */ ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= __t('close') ?>"></button>
      </div>
    <?php endif; ?>

    <!-- Filters (compact, consistent with DataObjectCodesList) -->
    <?php
      $filterBase = ['route' => 'workflow/list', 'page' => 1, 'pageSize' => $pageSize];
      if ($status !== '') $filterBase['status'] = $status;
      if ($isIframe)      $filterBase['iframe'] = '1';
    ?>
    <form method="get" action="index.php" class="row g-2 align-items-end mb-3">
      <?php foreach ($filterBase as $k => $v): ?>
        <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
      <?php endforeach; ?>

      <div class="col-md-4">
        <label for="q" class="form-label"><?= __t('search') ?></label>
        <input type="text" id="q" name="q" class="form-control" value="<?= h($q) ?>"
               placeholder="<?= t_or('search_placeholder', 'Search...') ?>">
      </div>

      <div class="col-md-3">
        <label for="typeID" class="form-label"><?= t_or('type', 'Type') ?></label>
        <select id="typeID" name="typeID" class="form-select">
          <option value=""><?= t_or('all_types', 'All Types') ?></option>
          <?php foreach ($typesMap as $id => $name): ?>
            <option value="<?= h((string)$id) ?>" <?= ($typeID !== null && (int)$typeID === (int)$id) ? 'selected' : '' ?>>
              <?= h($name) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label for="statusID" class="form-label"><?= t_or('status', 'Status') ?></label>
        <select id="statusID" name="statusID" class="form-select">
          <option value=""><?= t_or('all_statuses', 'All Statuses') ?></option>
          <?php foreach ($statusesMap as $id => $name): ?>
            <option value="<?= h((string)$id) ?>" <?= ($statusID !== null && (int)$statusID === (int)$id) ? 'selected' : '' ?>>
              <?= h($name) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <label for="pageSize" class="form-label"><?= __t('page_size') ?></label>
        <select id="pageSize" name="pageSize" class="form-select" onchange="this.form.submit()">
          <?php foreach ([10,25,50,100,200] as $ps): ?>
            <option value="<?= $ps ?>" <?= $pageSize === $ps ? 'selected' : '' ?>><?= $ps ?>/page</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-12 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm">
          <i class="bi bi-search me-1"></i><?= __t('filter') ?>
        </button>
        <a href="<?= h(wf_build_query(['route'=>'workflow/list'] + ($isIframe?['iframe'=>1]:[]))) ?>"
           class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-arrow-repeat me-1"></i><?= __t('reset') ?>
        </a>
      </div>
    </form>

    <!-- Table -->
    <div class="table-responsive">
      <table class="table table-striped table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th><?= __t('id') ?></th>
            <th><?= t_or('title', 'Title') ?></th>
            <th><?= t_or('type', 'Type') ?></th>
            <th><?= t_or('status', 'Status') ?></th>
            <th><?= t_or('assigned_to', 'Assigned To') ?></th>
            <th><?= t_or('related_entity', 'Related Entity') ?></th>
            <th><?= t_or('due_date', 'Due Date') ?></th>
            <th><?= t_or('completed_at', 'Completed') ?></th>
            <th class="text-end"><?= __t('actions') ?></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!empty($tasks)): ?>
          <?php foreach ($tasks as $t): ?>
            <?php
              $tid = (int)($t['WorkflowTaskID'] ?? 0);
              $editQs = [
                'route'    => 'workflow/edit',
                'id'       => $tid,
                // Preserve context so back works from form
                'q'        => $q,
                'typeID'   => $typeID,
                'statusID' => $statusID,
                'status'   => $status,
                'page'     => $page,
                'pageSize' => $pageSize,
              ];
              if ($isIframe) $editQs['iframe'] = '1';
              // Remove null/empty (except 0)
              $editQs = array_filter($editQs, fn($v) => $v !== null && $v !== '');
              $editUrl = wf_build_query($editQs);
            ?>
            <tr>
              <td><?= h((string)$tid) ?></td>
              <td><?= h((string)($t['Title'] ?? '')) ?></td>
              <td><?= h((string)($t['TaskTypeName'] ?? $typesMap[(int)($t['TaskTypeID'] ?? 0)] ?? '')) ?></td>
              <td><?= h((string)($t['StatusName']   ?? $statusesMap[(int)($t['StatusID'] ?? 0)] ?? '')) ?></td>
              <td><?= h((string)($t['AssignedToName'] ?? $t['AssignedTo'] ?? '')) ?></td>
              <td><?= h((string)($t['RelatedEntity'] ?? '')) ?></td>
              <td><?= h(wf_format_date($t['DueDate']      ?? null)) ?></td>
              <td><?= h(wf_format_date($t['CompletedAt']  ?? null)) ?></td>
              <td class="text-end">
                <div class="btn-group btn-group-sm" role="group">
                  <a href="<?= h($editUrl) ?>" class="btn btn-outline-secondary" title="<?= __t('edit') ?>">
                    <i class="bi bi-pencil-square"></i>
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="9" class="text-center text-muted py-3"><?= t_or('no_tasks_found', 'No tasks found.') ?></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination (centered, compact like other lists) -->
    <?php if ($totalPages > 1): ?>
      <?php
        $pageBase = [
          'route'    => 'workflow/list',
          'q'        => $q,
          'typeID'   => $typeID,
          'statusID' => $statusID,
          'status'   => $status,
          'pageSize' => $pageSize,
        ];
        if ($isIframe) $pageBase['iframe'] = '1';
        $prevQs = $pageBase; $prevQs['page'] = max(1, $page - 1);
        $nextQs = $pageBase; $nextQs['page'] = min($totalPages, $page + 1);
      ?>
      <nav class="mt-3" aria-label="Workflow pagination">
        <ul class="pagination justify-content-center pagination-sm">
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= h(wf_build_query($prevQs)) ?>">&laquo; <?= __t('prev') ?></a>
          </li>
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php $pi = $pageBase; $pi['page'] = $i; ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
              <a class="page-link" href="<?= h(wf_build_query($pi)) ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= h(wf_build_query($nextQs)) ?>"><?= __t('next') ?> &raquo;</a>
          </li>
        </ul>
        <p class="text-center text-muted small">
          <?= __t('showing') ?> <?= count($tasks) ?> <?= __t('of') ?> <?= $total ?> <?= t_or('entries', 'entries') ?>
        </p>
      </nav>
    <?php endif; ?>
  </div>
</div>

<?php if ($isIframe): ?>
</div>
</body>
</html>
<?php endif; ?>
