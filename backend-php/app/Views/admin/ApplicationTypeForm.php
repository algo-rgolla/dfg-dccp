<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$row = is_array($row ?? null) ? $row : [];
$csrf = h((string)($_csrf ?? csrf_token()));
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1"><?= !empty($row['ApplicationTypeID']) ? 'Edit Application Type' : 'Add Application Type' ?></h3>
      <div class="text-muted">Maintain application type keys, display names, descriptions, and privacy agreement wording.</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="index.php?route=admin/application-types">
      <i class="bi bi-arrow-left me-1"></i>Back
    </a>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Application Type Details</strong>
      <span class="text-muted small">
        <?= !empty($row['ApplicationTypeID']) ? 'Entry #' . h((string)$row['ApplicationTypeID']) : 'New Entry' ?>
      </span>
    </div>
    <div class="card-body">
      <form method="post" action="index.php?route=admin/application-types-save">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="ApplicationTypeID" value="<?= h((string)($row['ApplicationTypeID'] ?? 0)) ?>">

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Application Type Key</label>
            <input class="form-control" name="ApplicationTypeKey" value="<?= h((string)($row['ApplicationTypeKey'] ?? '')) ?>" required>
          </div>
          <div class="col-md-8">
            <label class="form-label">Application Type Name</label>
            <input class="form-control" name="ApplicationTypeName" value="<?= h((string)($row['ApplicationTypeName'] ?? '')) ?>" required>
          </div>
          <div class="col-12">
            <label class="form-label">Description</label>
            <textarea class="form-control" name="Description" rows="4"><?= h((string)($row['Description'] ?? '')) ?></textarea>
          </div>
          <div class="col-md-4">
            <label class="form-label">Privacy Agreement</label>
            <select class="form-select" name="PrivacyAgreementRequired">
              <option value="0" <?= (empty($row['PrivacyAgreementRequired'])) ? 'selected' : '' ?>>Not Required</option>
              <option value="1" <?= (!empty($row['PrivacyAgreementRequired'])) ? 'selected' : '' ?>>Required</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Privacy Agreement Text</label>
            <textarea class="form-control" name="PrivacyAgreementText" rows="8" placeholder="Enter the agreement wording shown before a new application starts."><?= h((string)($row['PrivacyAgreementText'] ?? '')) ?></textarea>
          </div>
          <div class="col-md-4">
            <label class="form-label">Status</label>
            <select class="form-select" name="IsActive">
              <option value="1" <?= (!isset($row['IsActive']) || !empty($row['IsActive'])) ? 'selected' : '' ?>>Active</option>
              <option value="0" <?= (isset($row['IsActive']) && empty($row['IsActive'])) ? 'selected' : '' ?>>Inactive</option>
            </select>
          </div>
        </div>

        <div class="mt-3 d-flex gap-2">
          <button type="submit" class="btn btn-primary">Save</button>
          <a class="btn btn-outline-secondary" href="index.php?route=admin/application-types">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
