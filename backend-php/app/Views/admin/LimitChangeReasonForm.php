<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$row = is_array($row ?? null) ? $row : [];
$applicationTypes = is_array($applicationTypes ?? null) ? $applicationTypes : [];
$csrf = h((string)($_csrf ?? csrf_token()));
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1"><?= !empty($row['ReasonID']) ? 'Edit Limit Change Reason' : 'Add Limit Change Reason' ?></h3>
      <div class="text-muted">Assign a justification reason to a specific application type and control its display order.</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="index.php?route=admin/limit-change-reasons">
      <i class="bi bi-arrow-left me-1"></i>Back
    </a>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Reason Details</strong>
      <span class="text-muted small">
        <?= !empty($row['ReasonID']) ? 'Entry #' . h((string)$row['ReasonID']) : 'New Entry' ?>
      </span>
    </div>
    <div class="card-body">
      <form method="post" action="index.php?route=admin/limit-change-reasons-save">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="ReasonID" value="<?= h((string)($row['ReasonID'] ?? 0)) ?>">

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Application Type</label>
            <select class="form-select" name="ApplicationTypeID" required>
              <option value="">Select an application type</option>
              <?php foreach ($applicationTypes as $type): ?>
                <?php $typeId = (int)($type['ApplicationTypeID'] ?? 0); ?>
                <option value="<?= h((string)$typeId) ?>" <?= ((int)($row['ApplicationTypeID'] ?? 0) === $typeId) ? 'selected' : '' ?>>
                  <?= h((string)($type['ApplicationTypeName'] ?? $type['ApplicationTypeKey'] ?? '')) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Reason Label</label>
            <input class="form-control" name="ReasonLabel" value="<?= h((string)($row['ReasonLabel'] ?? '')) ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Sort Order</label>
            <input class="form-control" type="number" name="SortOrder" value="<?= h((string)($row['SortOrder'] ?? 0)) ?>" step="1">
          </div>
          <div class="col-md-3">
            <label class="form-label">Status</label>
            <select class="form-select" name="IsActive">
              <option value="1" <?= (!isset($row['IsActive']) || !empty($row['IsActive'])) ? 'selected' : '' ?>>Active</option>
              <option value="0" <?= (isset($row['IsActive']) && empty($row['IsActive'])) ? 'selected' : '' ?>>Inactive</option>
            </select>
          </div>
        </div>

        <div class="mt-3 d-flex gap-2">
          <button type="submit" class="btn btn-primary">Save</button>
          <a class="btn btn-outline-secondary" href="index.php?route=admin/limit-change-reasons">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
