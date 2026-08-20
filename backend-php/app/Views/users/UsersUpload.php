<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php'; // CSRF helper

use App\Shared\SessionHelper;

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$flash = SessionHelper::get('flash.message', null);
$_csrf = csrf_token();
?>
<div class="card shadow-sm mt-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong><i class="bi bi-upload me-2"></i><?= __t('upload_users') ?></strong>
    <a href="assets/UserUploadTemplate.xlsx" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-file-earmark-excel me-1"></i> <?= __t('download_template') ?>
    </a>
  </div>
  <div class="card-body">

    <!-- Flash messages -->
    <?php if (!empty($flash)): ?>
      <div class="alert alert-<?= h($flash['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
        <?= $flash['text'] ?? '' ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <!-- Upload form -->
    <form action="index.php?route=users/uploadProcess"
          method="post"
          enctype="multipart/form-data"
          class="needs-validation"
          novalidate>
      <input type="hidden" name="_csrf" value="<?= h($_csrf) ?>">

      <div class="mb-3">
        <label for="uploadFile" class="form-label"><?= __t('select_excel_file') ?></label>
        <input type="file"
               name="uploadFile"
               id="uploadFile"
               class="form-control"
               accept=".xlsx,.xls"
               required>
        <div class="invalid-feedback">
          <?= __t('required_field') ?: 'Please select an Excel file before uploading.' ?>
        </div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-cloud-upload me-1"></i> <?= __t('upload') ?>
        </button>
        <a href="index.php?route=users/list" class="btn btn-secondary">
          <i class="bi bi-arrow-left me-1"></i> <?= __t('back') ?>
        </a>
      </div>
    </form>
  </div>
</div>

<script>
// Bootstrap validation (same as UserForm)
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
