<?php declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

/** @var array $roles */
/** @var string|null $title */

$csrf = h(csrf_token());
$titleKey = $title ?? 'roles'; // pass 'roles' or a key from controller
?>
<div class="container mt-4">
  <div class="card shadow-sm">
    <!-- Header: match DataObjectCodes style -->
    <div class="card-header d-flex justify-content-between align-items-center">
      <div class="d-flex align-items-center">
        <i class="bi bi-shield-lock me-2"></i>
        <strong><?= h(__t($titleKey)) ?></strong>
      </div>
      <a href="index.php?route=roles/edit" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-circle me-1"></i><?= __t('create_role') ?>
      </a>
    </div>

    <div class="card-body p-0">
      <!-- Debug output for $roles -->
      <?php if (defined('DEBUG') && DEBUG): ?>
        <div class="alert alert-info">
          <pre>Roles data: <?= print_r($roles, true) ?></pre>
        </div>
      <?php endif; ?>
      <div class="table-responsive">
        <table class="table table-striped table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>ID</th>
              <th><?= __t('role_name') ?></th>
              <th><?= __t('status') ?></th>
              <th><?= __t('created_at') ?></th>
              <th><?= __t('updated_at') ?></th>
              <th class="text-end"><?= __t('action') ?></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!empty($roles)): ?>
            <?php
            $validRoles = 0;
            foreach ($roles as $r):
              if (!is_array($r)) {
                  error_log('Invalid role data: ' . print_r($r, true));
                  continue;
              }
              $validRoles++;
            ?>
              <tr>
                <td><?= (int)($r['RoleID'] ?? 0) ?></td>
                <td><?= h((string)($r['RoleName'] ?? 'Unknown Role')) ?></td>
                <td>
                  <?php if (!empty($r['Active'])): ?>
                    <span class="badge bg-success"><?= __t('active') ?></span>
                  <?php else: ?>
                    <span class="badge bg-secondary"><?= __t('inactive') ?></span>
                  <?php endif; ?>
                </td>
                <td><?= h((string)($r['DateCreated'] ?? '')) ?></td>
                <td><?= h((string)($r['DateUpdated'] ?? '')) ?></td>
                <td class="text-end">
                  <div class="btn-group btn-group-sm" role="group">
                    <a href="index.php?route=roles/view&id=<?= (int)($r['RoleID'] ?? 0) ?>"
                       class="btn btn-outline-secondary" title="<?= __t('view') ?>">
                      <i class="bi bi-eye"></i>
                    </a>
                    <a href="index.php?route=roles/edit&id=<?= (int)($r['RoleID'] ?? 0) ?>"
                       class="btn btn-outline-secondary" title="<?= __t('edit') ?>">
                      <i class="bi bi-pencil-square"></i>
                    </a>
                    <!-- Delete via modal (POST + CSRF) -->
                    <button type="button"
                            class="btn btn-danger"
                            title="<?= __t('delete') ?>"
                            data-bs-toggle="modal"
                            data-bs-target="#deleteRoleModal"
                            data-roleid="<?= (int)($r['RoleID'] ?? 0) ?>"
                            data-rolename="<?= h((string)($r['RoleName'] ?? 'Unknown Role')) ?>">
                      <i class="bi bi-trash"></i>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if ($validRoles === 0): ?>
              <tr>
                <td colspan="6" class="text-center text-muted py-3">
                  <?= __t('no_valid_roles_found') ?: 'No valid roles found. Check logs for details.' ?>
                </td>
              </tr>
            <?php endif; ?>
          <?php else: ?>
            <tr>
              <td colspan="6" class="text-center text-muted py-3"><?= __t('no_records_found') ?></td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteRoleModal" tabindex="-1" aria-labelledby="deleteRoleModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" action="index.php?route=roles/delete" class="modal-content">
      <?= csrf_field(); ?>
      <input type="hidden" name="RoleID" id="deleteRoleID" value="">
      <div class="modal-header">
        <h5 class="modal-title" id="deleteRoleModalLabel">
          <i class="bi bi-exclamation-triangle me-2"></i><?= __t('delete') ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __t('close') ?>"></button>
      </div>
      <div class="modal-body">
        <p class="mb-1">
          <?= __t('confirm_delete_item') ?: 'Are you sure you want to delete' ?>
          <strong id="deleteRoleName"></strong> ?
        </p>
        <p class="text-muted small mb-0"><?= __t('cannot_undo') ?: 'This action cannot be undone.' ?></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= __t('close') ?></button>
        <button type="submit" class="btn btn-danger">
          <i class="bi bi-trash me-1"></i><?= __t('delete') ?>
        </button>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const modal = document.getElementById('deleteRoleModal');
  const idField = document.getElementById('deleteRoleID');
  const nameSpan = document.getElementById('deleteRoleName');

  modal.addEventListener('show.bs.modal', (e) => {
    const btn = e.relatedTarget;
    if (!btn) return;

    const id = btn.getAttribute('data-roleid') || '';
    const name = btn.getAttribute('data-rolename') || '';

    idField.value = id;
    nameSpan.textContent = name;
  });
});
</script>

<style>
/* Keep icon sizes consistent with other screens */
.table .btn i { font-size: 1rem !important; line-height: 1; }
.table td, .table th { vertical-align: middle; }
</style>
