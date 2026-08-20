<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

/** @var array|null $role */
if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('t_or')) {
    // Translate a key; if the key isn't defined, use the provided fallback
    function t_or(string $key, string $fallback): string {
        $t = __t($key);
        return $t === $key ? $fallback : $t;
    }
}

$id        = (int)($role['RoleID'] ?? 0);
$roleName  = (string)($role['RoleName'] ?? '');
$isActive  = !empty($role['Active']);
$titleKey  = $id > 0 ? 'edit_role' : 'create_role';
?>
<div class="container mt-4">
  <div class="card shadow-sm">
    <!-- Header: aligned with DataObjectCodesForm -->
    <div class="card-header d-flex justify-content-between align-items-center">
      <div class="d-flex align-items-center">
        <i class="bi bi-shield-lock me-2"></i>
        <strong><?= __t($titleKey) ?></strong>
      </div>
      <div class="d-flex align-items-center gap-2">
        <a href="index.php?route=roles/list" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-arrow-left me-1"></i><?= __t('back') ?>
        </a>
        <?php if ($id > 0): ?>
          <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteRoleModal">
            <i class="bi bi-trash me-1"></i><?= __t('delete') ?>
          </button>
        <?php endif; ?>
      </div>
    </div>

    <div class="card-body">
      <form method="post" action="index.php?route=roles/save" class="needs-validation" novalidate>
        <?= csrf_field(); ?>
        <?php if ($id > 0): ?>
          <input type="hidden" name="RoleID" value="<?= $id ?>">
        <?php endif; ?>

        <div class="mb-3">
          <label for="RoleName" class="form-label"><?= __t('role_name') ?></label>
          <input
            type="text"
            class="form-control"
            id="RoleName"
            name="RoleName"
            value="<?= h($roleName) ?>"
            maxlength="100"
            required
          >
          <div class="invalid-feedback">
            <?= __t('role_name_required') ?: 'Role name is required.' ?>
          </div>
        </div>

        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" id="Active" name="Active" <?= $isActive ? 'checked' : '' ?>>
          <label class="form-check-label" for="Active"><?= __t('active') ?></label>
        </div>

        <!-- Bottom bar: hr + muted info + small buttons (consistent with DataObjectCodesForm) -->
        <hr class="mt-4 mb-2">
        <div class="d-flex justify-content-between align-items-center">
          <p class="text-muted small mb-0">
            <?= t_or('form_save_hint', 'Changes are not saved until you click Save.') ?>
          </p>
          <div class="d-flex gap-2">
            <a href="index.php?route=roles/list" class="btn btn-sm btn-outline-secondary">
              <i class="bi bi-arrow-left me-1"></i><?= __t('back') ?>
            </a>
            <button type="submit" class="btn btn-sm btn-primary">
              <i class="bi bi-save me-1"></i><?= __t('save') ?>
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($id > 0): ?>
<!-- Delete Modal (same pattern as DataObjectCodes) -->
<div class="modal fade" id="deleteRoleModal" tabindex="-1" aria-labelledby="deleteRoleModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" action="index.php?route=roles/delete" class="modal-content">
      <?= csrf_field(); ?>
      <input type="hidden" name="RoleID" value="<?= $id ?>">
      <div class="modal-header">
        <h5 class="modal-title" id="deleteRoleModalLabel">
          <i class="bi bi-exclamation-triangle me-2"></i><?= __t('delete') ?>: <?= h($roleName) ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __t('close') ?>"></button>
      </div>
      <div class="modal-body">
        <p class="mb-1">
          <?= __t('confirm_delete_item') ?: 'Are you sure you want to delete' ?> <strong><?= h($roleName) ?></strong>?
        </p>
        <p class="text-muted small mb-0">
          <?= __t('cannot_undo') ?: 'This action cannot be undone.' ?>
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
          <?= __t('close') ?>
        </button>
        <button type="submit" class="btn btn-danger">
          <i class="bi bi-trash me-1"></i><?= __t('delete') ?>
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
(() => {
  'use strict';
  const forms = document.querySelectorAll('.needs-validation');
  Array.from(forms).forEach(form => {
    form.addEventListener('submit', e => {
      if (!form.checkValidity()) {
        e.preventDefault();
        e.stopPropagation();
      }
      form.classList.add('was-validated');
    }, false);
  });
})();
</script>
