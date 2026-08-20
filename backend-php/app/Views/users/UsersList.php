<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';
/** @var array $users */
/** @var int $currentPage */
/** @var int $totalPages */
/** @var int $totalCount */
/** @var array|null $flash */
/** @var array $filters */
/** @var array $departments */
/** @var bool $canRunListing */
/** @var string|null $listMessage */
/** @var int $perPage */

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$csrf = h(csrf_token());
$filters = is_array($filters ?? null) ? $filters : [];
$departments = is_array($departments ?? null) ? $departments : [];
$canRunListing = (bool)($canRunListing ?? false);
$listMessage = $listMessage ?? null;
$perPage = max(1, (int)($perPage ?? 25));
$queryStringBase = 'q=' . urlencode((string)($filters['q'] ?? ''))
  . '&department=' . urlencode((string)($filters['department'] ?? ''))
  . '&status=' . urlencode((string)($filters['status'] ?? ''));
?>
<div class="card shadow-sm mt-4">
  <!-- Header now matches DataObjectCodes: title on left, Add button on right -->
<div class="card-header d-flex justify-content-between align-items-center">
  <strong><i class="bi bi-people me-2"></i><?= __t('menu_users') ?></strong>
  <div class="btn-group">
    <a href="index.php?route=users/exportPdf" target="_blank" class="btn btn-sm btn-outline-secondary<?= $canRunListing ? '' : ' disabled' ?>"<?= $canRunListing ? '' : ' aria-disabled="true" tabindex="-1"' ?>>
      <i class="bi bi-file-earmark-pdf me-1"></i> <?= __t('export_pdf') ?>
    </a>
    <a href="index.php?route=users/exportExcel&<?= $queryStringBase ?>" 
      target="_blank" class="btn btn-sm btn-outline-success<?= $canRunListing ? '' : ' disabled' ?>"<?= $canRunListing ? '' : ' aria-disabled="true" tabindex="-1"' ?>>
      <i class="bi bi-file-earmark-excel me-1"></i> <?= __t('export_excel') ?>
    </a>
    <a href="index.php?route=users/edit" class="btn btn-sm btn-primary">
      <i class="bi bi-plus-circle me-1"></i> <?= __t('create_user') ?>
    </a>
    <a href="index.php?route=users/upload" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-upload"></i> <?= __t('upload_users') ?>
    </a>
  </div>
</div>


  <div class="card-body">
    <!-- Filters (unchanged in spirit; aligned with your style) -->
    <form method="get" action="index.php" class="row mb-3">
  <input type="hidden" name="route" value="users/list">

  <!-- Search -->
  <div class="col-md-4 mb-2">
    <input type="text" 
           name="q" 
           value="<?= h((string)($filters['q'] ?? '')) ?>"
           class="form-control" 
           placeholder="<?= __t('search') ?>... (name / username / email, min 2 chars)">
  </div>

  <!-- Department -->
  <div class="col-md-3 mb-2">
    <select name="department" class="form-select">
      <option value=""><?= __t('all_departments') ?></option>
      <?php foreach ($departments as $dept): ?>
        <option value="<?= h((string)$dept) ?>" <?= ((string)($filters['department'] ?? '') === (string)$dept) ? 'selected' : '' ?>>
          <?= h((string)$dept) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- Status -->
  <div class="col-md-2 mb-2">
    <select name="status" class="form-select">
      <option value=""><?= __t('all_status') ?></option>
      <option value="1" <?= ((string)($filters['status'] ?? '') === '1') ? 'selected' : '' ?>><?= __t('enabled') ?></option>
      <option value="0" <?= ((string)($filters['status'] ?? '') === '0') ? 'selected' : '' ?>><?= __t('disabled') ?></option>
    </select>
  </div>

  <!-- Buttons -->
  <div class="col-md-3 mb-2 d-flex gap-2">
    <button type="submit" class="btn btn-primary flex-fill">
      <i class="bi bi-search me-1"></i><?= __t('filter') ?>
    </button>
    <a href="index.php?route=users/list" class="btn btn-outline-secondary flex-fill">
      <i class="bi bi-x-circle me-1"></i><?= __t('reset') ?>
    </a>
  </div>
</form>

    <?php if ($listMessage !== null): ?>
      <div class="alert alert-info">
        <?= h((string)$listMessage) ?>
      </div>
    <?php endif; ?>

    <!-- User table (Bootstrap like DataObjectCodes) -->
    <div class="table-responsive">
      <table class="table table-striped table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th><?= __t('user_id') ?></th>
            <th><?= __t('username_label') ?></th>
            <th><?= __t('display_name') ?></th>
            <th><?= __t('email') ?></th>
            <th><?= __t('department') ?></th>
            <th><?= __t('job_title') ?></th>
            <th><?= __t('status') ?></th>
            <th><?= __t('last_login') ?></th>
            <th><?= __t('failed_logins') ?></th>
            <th class="text-end"><?= __t('actions') ?></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$canRunListing): ?>
          <tr><td colspan="10" class="text-center text-muted py-4">No users loaded yet.</td></tr>
        <?php elseif (count($users) === 0): ?>
          <tr><td colspan="10" class="text-center text-muted py-3"><?= __t('no_records_found') ?></td></tr>
        <?php else: ?>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><?= h((string)$u['UserID']) ?></td>
              <td><?= h((string)$u['Username']) ?></td>
              <td><?= h((string)($u['DisplayName'] ?? ($u['FirstName'].' '.$u['LastName']))) ?></td>
              <td><?= h((string)($u['Email'] ?? '')) ?></td>
              <td><?= h((string)($u['Department'] ?? '')) ?></td>
              <td><?= h((string)($u['JobTitle'] ?? '')) ?></td>
              <td>
                <?php if ((int)$u['IsActive'] === 1): ?>
                  <span class="badge bg-success"><?= __t('enabled') ?></span>
                <?php else: ?>
                  <span class="badge bg-danger"><?= __t('disabled') ?></span>
                <?php endif; ?>
              </td>
              <td><?= h((string)($u['LastLoginAt'] ?? '—')) ?></td>
              <td><?= h((string)($u['FailedLoginCount'] ?? 0)) ?></td>
              <td class="text-end">
                <div class="btn-group btn-group-sm" role="group">
                  <a href="index.php?route=users/edit&id=<?= h((string)$u['UserID']) ?>" 
                     class="btn btn-outline-secondary" title="<?= __t('edit_user') ?>">
                    <i class="bi bi-pencil-square"></i>
                  </a>
                  <button type="button" 
                          class="btn btn-outline-warning"
                          data-bs-toggle="modal" 
                          data-bs-target="#unlockModal"
                          data-userid="<?= h((string)$u['UserID']) ?>"
                          data-username="<?= h((string)$u['Username']) ?>"
                          title="<?= __t('unlock_login') ?>">
                    <i class="bi bi-unlock"></i>
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Unlock Modal -->
    <div class="modal fade" id="unlockModal" tabindex="-1" aria-labelledby="unlockModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="unlockModalLabel"><?= __t('unlock_login') ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __t('close') ?>"></button>
          </div>
          <div class="modal-body">
            <p><?= __t('confirm_unlock_user') ?>: <strong id="unlockUsername"></strong>?</p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __t('cancel') ?></button>
            <a href="#" id="unlockConfirmBtn" class="btn btn-warning">
              <i class="bi bi-unlock me-1"></i><?= __t('unlock_login') ?>
            </a>
          </div>
        </div>
      </div>
    </div>

    <script>
      document.addEventListener('DOMContentLoaded', () => {
        const unlockModal = document.getElementById('unlockModal');
        unlockModal.addEventListener('show.bs.modal', event => {
          const button = event.relatedTarget;
          const userId = button.getAttribute('data-userid');
          const username = button.getAttribute('data-username');

          document.getElementById('unlockUsername').textContent = username;
          document.getElementById('unlockConfirmBtn').href = `index.php?route=users/unlock&id=${userId}`;
        });
      });
    </script>

    <?php if ($canRunListing && count($users) > 0): ?>
      <?php
        $startRow = (($currentPage - 1) * $perPage) + 1;
        $endRow = $startRow + count($users) - 1;
      ?>
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 mt-3">
        <p class="text-muted small mb-0">
          Showing <?= h((string)$startRow) ?>-<?= h((string)$endRow) ?> of <?= h((string)$totalCount) ?> users
        </p>
        <?php if ($totalPages > 1): ?>
          <nav aria-label="User pagination">
            <ul class="pagination mb-0">
              <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="index.php?route=users/list&page=<?= $currentPage-1 ?>&<?= $queryStringBase ?>">
                  &laquo; <?= __t('prev') ?>
                </a>
              </li>
              <li class="page-item disabled">
                <span class="page-link">Page <?= h((string)$currentPage) ?> of <?= h((string)$totalPages) ?></span>
              </li>
              <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="index.php?route=users/list&page=<?= $currentPage+1 ?>&<?= $queryStringBase ?>">
                  <?= __t('next') ?> &raquo;
                </a>
              </li>
            </ul>
          </nav>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
