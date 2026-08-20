<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

use App\Shared\SessionHelper;

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$flash = SessionHelper::get('flash.message', null);
$_csrf = csrf_token();
?>
<div class="card shadow-sm mt-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong><i class="bi bi-upload me-2"></i>Upload Workflow Approval Rules</strong>
    <a href="index.php?route=admin/workflow-approval-rules-template" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-file-earmark-excel me-1"></i>Download Template
    </a>
  </div>
  <div class="card-body">
    <?php if (!empty($flash)): ?>
      <div class="alert alert-<?= h($flash['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
        <?= $flash['text'] ?? '' ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <div class="mb-3 text-muted small">
      Expected columns in order:
      <code>ApplicationTypeKey</code>,
      <code>EmployeeGroup</code>,
      <code>MinLimit</code>,
      <code>MaxLimit</code>,
      <code>RequiredApproverType</code>,
      <code>RequiredRank</code>,
      <code>IsActive</code>.
      Leave <code>MaxLimit</code> blank for no maximum.
    </div>

    <form action="index.php?route=admin/workflow-approval-rules-upload-process"
          method="post"
          enctype="multipart/form-data"
          class="needs-validation"
          novalidate>
      <input type="hidden" name="_csrf" value="<?= h($_csrf) ?>">

      <div class="mb-3">
        <label for="uploadFile" class="form-label">Select Excel File</label>
        <input type="file"
               name="uploadFile"
               id="uploadFile"
               class="form-control"
               accept=".xlsx,.xls"
               required>
        <div class="invalid-feedback">
          Please select an Excel file before uploading.
        </div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-cloud-upload me-1"></i>Upload
        </button>
        <a href="index.php?route=admin/workflow-approval-rules" class="btn btn-secondary">
          <i class="bi bi-arrow-left me-1"></i>Back
        </a>
      </div>
    </form>
  </div>
</div>

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
