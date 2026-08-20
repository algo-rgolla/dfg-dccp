<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$row = is_array($row ?? null) ? $row : [];
$csrf = h((string)($_csrf ?? csrf_token()));
$flagOptions = ['', 'Y', 'N'];
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1"><?= !empty($row['EmployeeTypeID']) ? 'Edit CAPS Employee Type' : 'Add CAPS Employee Type' ?></h3>
      <div class="text-muted">Maintain employee type entitlement flags used by the CAPS database.</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="index.php?route=admin/caps-employee-types">
      <i class="bi bi-arrow-left me-1"></i>Back
    </a>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Employee Type Details</strong>
      <span class="text-muted small">
        <?= !empty($row['EmployeeTypeID']) ? 'Entry #' . h((string)$row['EmployeeTypeID']) : 'New Entry' ?>
      </span>
    </div>
    <div class="card-body">
      <form method="post" action="index.php?route=admin/caps-employee-types-save">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="EmployeeTypeID" value="<?= h((string)($row['EmployeeTypeID'] ?? 0)) ?>">

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Employee Type</label>
            <input class="form-control" maxlength="20" name="EmployeeType" value="<?= h((string)($row['EmployeeType'] ?? '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">DPC Entitled</label>
            <select class="form-select" name="DPCEntitled">
              <?php foreach ($flagOptions as $option): ?>
                <option value="<?= h($option) ?>" <?= ((string)($row['DPCEntitled'] ?? '') === $option) ? 'selected' : '' ?>>
                  <?= $option === '' ? 'Blank' : h($option) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">DTC Entitled</label>
            <select class="form-select" name="DTCEntitled">
              <?php foreach ($flagOptions as $option): ?>
                <option value="<?= h($option) ?>" <?= ((string)($row['DTCEntitled'] ?? '') === $option) ? 'selected' : '' ?>>
                  <?= $option === '' ? 'Blank' : h($option) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="mt-3 d-flex gap-2">
          <button type="submit" class="btn btn-primary">Save</button>
          <a class="btn btn-outline-secondary" href="index.php?route=admin/caps-employee-types">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
