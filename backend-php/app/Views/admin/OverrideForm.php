<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('dt_local')) {
    function dt_local($v): string
    {
        $s = trim((string)$v);
        if ($s === '') {
            return '';
        }
        $ts = strtotime($s);
        return $ts === false ? '' : date('Y-m-d\TH:i', $ts);
    }
}

$row = is_array($row ?? null) ? $row : [];
$applicationTypes = is_array($applicationTypes ?? null) ? $applicationTypes : [];
$overrideTypes = is_array($overrideTypes ?? null) ? $overrideTypes : [];
$csrf = h(csrf_token());
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1"><?= !empty($row['OverrideID']) ? 'Edit Eligibility Override' : 'Add Eligibility Override' ?></h3>
      <div class="text-muted">Define exceptions for specific eligibility checks.</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="index.php?route=eligibility-admin/override-list">
      <i class="bi bi-arrow-left me-1"></i>Back
    </a>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Override Details</strong>
      <span class="text-muted small">
        <?= !empty($row['OverrideID']) ? 'Entry #' . h((string)$row['OverrideID']) : 'New Entry' ?>
      </span>
    </div>
    <div class="card-body">
      <form method="post" action="index.php?route=eligibility-admin/override-save">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="override_id" value="<?= h((string)($row['OverrideID'] ?? 0)) ?>">

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">EmployeeID</label>
            <input class="form-control" name="employee_id" value="<?= h((string)($row['EmployeeID'] ?? '')) ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Override Type</label>
            <select class="form-select" name="override_type" required>
              <option value="">Select override type</option>
              <?php foreach ($overrideTypes as $type): ?>
                <?php $typeLabel = ((string)$type === 'POSITION_TYPE_CHECK') ? 'EMPLOYEE_TYPE_CHECK' : (string)$type; ?>
                <option value="<?= h((string)$type) ?>" <?= ((string)($row['OverrideType'] ?? '') === (string)$type) ? 'selected' : '' ?>>
                  <?= h($typeLabel) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Application Type</label>
            <select class="form-select" name="application_type_id">
              <option value="0">All Application Types</option>
              <?php foreach ($applicationTypes as $type): ?>
                <?php $typeId = (int)($type['ApplicationTypeID'] ?? 0); ?>
                <option value="<?= h((string)$typeId) ?>" <?= ((int)($row['AppliesToApplicationTypeID'] ?? 0) === $typeId) ? 'selected' : '' ?>>
                  <?= h((string)($type['ApplicationTypeName'] ?? '')) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Status</label>
            <select class="form-select" name="is_active">
              <option value="1" <?= (!isset($row['IsActive']) || !empty($row['IsActive'])) ? 'selected' : '' ?>>Active</option>
              <option value="0" <?= (isset($row['IsActive']) && empty($row['IsActive'])) ? 'selected' : '' ?>>Inactive</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Reason</label>
            <textarea class="form-control" name="reason" rows="3"><?= h((string)($row['Reason'] ?? '')) ?></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label">Effective From</label>
            <input class="form-control" type="datetime-local" name="effective_from" value="<?= h(dt_local($row['EffectiveFrom'] ?? '')) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Effective To</label>
            <input class="form-control" type="datetime-local" name="effective_to" value="<?= h(dt_local($row['EffectiveTo'] ?? '')) ?>">
          </div>
        </div>

        <div class="mt-3 d-flex gap-2">
          <button type="submit" class="btn btn-primary">Save</button>
          <a class="btn btn-outline-secondary" href="index.php?route=eligibility-admin/override-list">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
