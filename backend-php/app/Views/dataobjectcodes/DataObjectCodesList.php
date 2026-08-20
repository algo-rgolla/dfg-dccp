<?php declare(strict_types=1);
/** @var string $title */
/** @var array  $rows */
/** @var int    $total */
/** @var int    $page */
/** @var int    $pageSize */
/** @var string $q */
/** @var ?int   $typeId */
/** @var string $status */
/** @var string $sort */
/** @var string $dir */
/** @var array  $types */
/** @var string $_csrf */

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$rows     = $rows     ?? [];
$total    = (int)($total ?? 0);
$page     = max(1, (int)($page ?? 1));
$pageSize = max(1, (int)($pageSize ?? 25));
$sort     = $sort ?? 'DataObjectCode';
$dir      = strtoupper($dir ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
$q        = $q ?? '';
$status   = $status ?? '';
$typeId   = isset($typeId) && $typeId !== '' ? (int)$typeId : null;

$pages = (int)ceil(($total ?: 0) / max(1, $pageSize));
if ($pages < 1) { $pages = 1; }

$baseParams = [
  'route'    => 'dataobjectcodes/index',
  'q'        => $q,
  'typeId'   => $typeId ?? '',
  'status'   => $status,
  'sort'     => $sort,
  'dir'      => $dir,
  'pageSize' => $pageSize,
];

$qs = fn(array $extra = []) => 'index.php?' . http_build_query(array_replace($baseParams, $extra));
$toggleSort = function (string $col) use ($sort, $dir, $qs): string {
  $newDir = ($sort === $col && $dir === 'ASC') ? 'DESC' : 'ASC';
  return $qs(['sort' => $col, 'dir' => $newDir, 'page' => 1]);
};

$printMode = ($_GET['print'] ?? '') === '1';
$csrfToken = $printMode ? '' : h($_csrf ?? csrf_token());
?>
<div class="card shadow-sm mt-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>
      <i class="bi bi-collection me-2"></i>
      <?= htmlspecialchars(__t($title ?? ($isEdit ? 'docodes_edit_title' : 'docodes_add_title')), ENT_QUOTES, 'UTF-8') ?>
    </strong>

    <?php if (!$printMode): ?>
    <p>
      <a href="index.php?route=dataobjectcodes/create" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-circle me-1"></i> <?= __t('add') ?>
      </a>
      <?php
      $exportUrl = 'index.php?' . http_build_query([
        'route'  => 'dataobjectcodes/export',
        'q'      => $q,
        'typeId' => $typeId ?? '',
        'status' => $status,
        'sort'   => $sort,
        'dir'    => $dir,
      ]);
      ?>
      <a href="<?= h($exportUrl) ?>" class="btn btn-sm btn-outline-success">
        <i class="bi bi-file-earmark-excel me-1"></i><?= __t('export_excel') ?>
      </a>
      <a href="<?= $qs(['route' => 'dataobjectcodes/exportPdf']) ?>"
         class="btn btn-sm btn-outline-dark"  target="_blank">
        <i class="bi bi-filetype-pdf me-1"></i><?= __t('export_pdf') ?>
      </a>
    </p>
    <?php endif; ?>
  </div>

  <div class="card-body">
    <?php if (!$printMode): ?>
    <!-- Filters -->
    <form method="get" action="index.php" class="row mb-3">
      <input type="hidden" name="route" value="dataobjectcodes/index">
      <div class="col-md-4 mb-2">
        <input type="text" name="q" value="<?= h($q) ?>"
               class="form-control" placeholder="<?= __t('docodes_search_ph') ?>">
      </div>
      <div class="col-md-3 mb-2">
        <select name="typeId" class="form-select">
          <option value=""><?= __t('all_types') ?></option>
          <?php foreach ($types as $t): ?>
            <?php $id = (int)($t['DataObjectTypeID'] ?? 0); $label = (string)($t['TypeName'] ?? $id); ?>
            <option value="<?= $id ?>" <?= ($typeId === $id) ? 'selected' : '' ?>>
              <?= h($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3 mb-2">
        <select name="status" class="form-select">
          <option value=""><?= __t('all_status') ?></option>
          <option value="Active"   <?= $status === 'Active'   ? 'selected' : '' ?>><?= __t('active') ?></option>
          <option value="Inactive" <?= $status === 'Inactive' ? 'selected' : '' ?>><?= __t('inactive') ?></option>
        </select>
      </div>
      <div class="col-md-2 mb-2 d-grid">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-search me-1"></i> <?= __t('filter') ?>
        </button>
      </div>
      <div class="col-md-2 mb-2">
        <select class="form-select" name="pageSize" onchange="this.form.submit()">
          <?php foreach ([10,25,50,100,200] as $ps): ?>
            <option value="<?= $ps ?>" <?= $pageSize === $ps ? 'selected' : '' ?>><?= $ps ?>/<?= __t('page') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 mb-2 d-grid">
        <a class="btn btn-outline-secondary" href="index.php?route=dataobjectcodes/index"><?= __t('reset') ?></a>
      </div>
    </form>
    <?php endif; ?>

    <!-- Table -->
    <div class="table-responsive">
      <table class="table table-striped table-hover align-middle table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th><a class="text-decoration-none" href="<?= $toggleSort('DataObjectCode') ?>"><?= __t('code') ?><?= $sort==='DataObjectCode' ? ' '.h($dir) : '' ?></a></th>
            <th><a class="text-decoration-none" href="<?= $toggleSort('DataObjectName') ?>"><?= __t('name') ?><?= $sort==='DataObjectName' ? ' '.h($dir) : '' ?></a></th>
            <th><a class="text-decoration-none" href="<?= $toggleSort('DataObjectCodeParent') ?>"><?= __t('parent') ?><?= $sort==='DataObjectCodeParent' ? ' '.h($dir) : '' ?></a></th>
            <th><a class="text-decoration-none" href="<?= $toggleSort('DataObjectTypeID') ?>"><?= __t('type') ?><?= $sort==='DataObjectTypeID' ? ' '.h($dir) : '' ?></a></th>
            <th><a class="text-decoration-none" href="<?= $toggleSort('DataObjectCodeStatus') ?>"><?= __t('status') ?><?= $sort==='DataObjectCodeStatus' ? ' '.h($dir) : '' ?></a></th>
            <th class="text-nowrap"><a class="text-decoration-none" href="<?= $toggleSort('DateUpdated') ?>"><?= __t('updated') ?><?= $sort==='DateUpdated' ? ' '.h($dir) : '' ?></a></th>
            <?php if (!$printMode): ?>
            <th class="text-end text-nowrap"><?= __t('actions') ?></th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr>
              <td colspan="<?= $printMode ? '6' : '7' ?>" class="text-center text-muted py-3"><?= __t('no_records_found') ?></td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= h((string)$r['DataObjectCode']) ?></td>
                <td><?= h((string)$r['DataObjectName']) ?></td>
                <td><?= h((string)($r['DataObjectCodeParent'] ?? '')) ?></td>
                <td><?= h($r['DataObjectTypeName'] ?? $r['DataObjectTypeID'] ?? '') ?></td>
                <td>
                  <?php if (($r['DataObjectCodeStatus'] ?? '') === 'Active'): ?>
                    <span class="badge bg-success"><?= __t('active') ?></span>
                  <?php elseif (($r['DataObjectCodeStatus'] ?? '') === 'Inactive'): ?>
                    <span class="badge bg-danger"><?= __t('inactive') ?></span>
                  <?php else: ?>
                    <span class="badge bg-secondary"><?= h((string)($r['DataObjectCodeStatus'] ?? '')) ?></span>
                  <?php endif; ?>
                </td>
                <td class="text-nowrap"><?= h((string)($r['DateUpdated'] ?? '')) ?></td>
                <?php if (!$printMode): ?>
                <td class="text-end text-nowrap">
                  <div class="btn-group btn-group-sm" role="group">
                 <a href="index.php?route=dataobjectcodes/edit&DataObjectCode=<?= urlencode((string)$r['DataObjectCode']) ?>"
   class="btn btn-sm btn-outline-secondary" title="<?= __t('edit') ?>">
  <i class="bi bi-pencil-square"></i>
</a>
                    <button type="button"
                            class="btn btn-sm btn-danger"
                            title="<?= __t('delete') ?>"
                            data-bs-toggle="modal"
                            data-bs-target="#docDeleteModal"
                            data-code="<?= h((string)$r['DataObjectCode']) ?>"
                            data-name="<?= h((string)$r['DataObjectName']) ?>">
                      <i class="bi bi-trash"></i>
                    </button>
                  </div>
                </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <nav aria-label="<?= __t('pagination') ?>" class="mt-3">
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
        <p class="text-center text-muted small">
          <?= __t('showing') ?> <?= count($rows) ?> <?= __t('of') ?> <?= $total ?> <?= __t('records') ?>
        </p>
      </nav>
    <?php endif; ?>
  </div>
</div>

<?php if (!$printMode): ?>
<!-- Delete Modal -->

<?php if (!$printMode): ?>
<!-- Delete Confirmation Modal -->
<div class="modal fade" id="docDeleteModal" tabindex="-1" aria-labelledby="docDeleteModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="docDeleteModalLabel"><?= __t('confirm_delete') ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p><?= __t('delete_confirm_message') ?></p>
        <p><strong><?= __t('code') ?>:</strong> <span id="docDeleteCode"></span></p>
        <p><strong><?= __t('name') ?>:</strong> <span id="docDeleteName"></span></p>
      </div>
      <div class="modal-footer">
        <form method="post" action="index.php?route=dataobjectcodes/delete" style="display:inline;">
          <input type="hidden" name="_csrf" value="<?= h($_csrf ?? csrf_token()) ?>">
          <input type="hidden" name="DataObjectCode" id="docDeleteCodeInput" value="">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __t('cancel') ?></button>
          <button type="submit" class="btn btn-danger"><?= __t('delete') ?></button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('docDeleteModal');
    if (!modal) return;

    modal.addEventListener('show.bs.modal', (event) => {
      const button = event.relatedTarget;
      const code = button.getAttribute('data-code') || '';
      const name = button.getAttribute('data-name') || '';

      modal.querySelector('#docDeleteCode').textContent = code || '—';
      modal.querySelector('#docDeleteName').textContent = name || '—';
      modal.querySelector('#docDeleteCodeInput').value = code;
    });
  });
</script>
<?php endif; ?>
<?php endif; ?>
