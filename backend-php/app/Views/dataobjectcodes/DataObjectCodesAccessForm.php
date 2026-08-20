<?php
declare(strict_types=1);

/** @var array $users */
/** @var array $codes */
/** @var int $fy */

require_once __DIR__ . '/../../../shared/csrf.php';  // ADD THIS LINE

use App\Shared\SessionHelper;
?>
<div class="card shadow-sm mt-4">
  <div class="card-header">
    <strong>
      <i class="bi bi-person-plus me-2"></i><?= __t('docodes_access_grant') ?>
    </strong>
  </div>
  <div class="card-body">
    <form method="post" action="index.php?route=dataobjectcodes/access_save" class="needs-validation" novalidate>
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

      <div class="row g-3">
        <!-- USER -->
        <div class="col-md-6">
          <label for="UserID" class="form-label"><?= __t('user') ?> <span class="text-danger">*</span></label>
          <select name="UserID" id="UserID" class="form-select form-select-sm" required>
            <option value=""><?= __t('select_user') ?></option>
            <?php foreach ($users as $u): ?>
              <option value="<?= $u['UserID'] ?>">
                <?= htmlspecialchars($u['Username'], ENT_QUOTES, 'UTF-8') ?>
                <?= $u['FullName'] ? ' (' . htmlspecialchars($u['FullName'], ENT_QUOTES, 'UTF-8') . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="invalid-feedback">
            <?= __t('required_field') ?>
          </div>
        </div>

        <!-- CODE -->
        <div class="col-md-6">
          <label for="DataObjectCode" class="form-label"><?= __t('code') ?> <span class="text-danger">*</span></label>
          <select name="DataObjectCode" id="DataObjectCode" class="form-select form-select-sm" required>
            <option value=""><?= __t('select_code') ?></option>
            <?php foreach ($codes as $c): ?>
              <option value="<?= htmlspecialchars($c['DataObjectCode'], ENT_QUOTES, 'UTF-8') ?>">
             <?= str_repeat('  ', (int)$c['Level']) ?>
                [L<?= $c['Level'] ?>] 
                <?= htmlspecialchars($c['DataObjectCode'], ENT_QUOTES, 'UTF-8') ?>
                <?= $c['DataObjectName'] ? ' – ' . htmlspecialchars($c['DataObjectName'], ENT_QUOTES, 'UTF-8') : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="invalid-feedback">
            <?= __t('required_field') ?>
          </div>
        </div>

        <!-- ACCESS LEVEL -->
        <div class="col-md-6">
          <label for="AccessLevel" class="form-label"><?= __t('access_level') ?></label>
          <select name="AccessLevel" id="AccessLevel" class="form-select form-select-sm">
            <option value="read"><?= __t('read_only') ?></option>
            <option value="edit"><?= __t('edit') ?></option>
            <option value="full"><?= __t('full_control') ?></option>
            <option value="delete"><?= __t('delete') ?></option>            
          </select>
        </div>

        <div class="col-md-6">
          <div class="form-check">
            <input type="checkbox" name="include_children" value="1" class="form-check-input" id="includeChildren" checked>
            <label class="form-check-label" for="includeChildren">
              <?= __t('include_child_codes') ?>
            </label>
          </div>
        </div>

        <!-- SUBMIT -->
        <div class="col-12 mt-4">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check2-circle me-1"></i> <?= __t('grant_access') ?>
          </button>
          <a href="index.php?route=dataobjectcodes/access" class="btn btn-secondary">
            <i class="bi bi-arrow-left me-1"></i> <?= __t('cancel') ?>
          </a>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('.needs-validation');
  if (!form) return;

  form.addEventListener('submit', (e) => {
    if (!form.checkValidity()) {
      e.preventDefault();
      e.stopPropagation();
    }
    form.classList.add('was-validated');
  });
});
</script>