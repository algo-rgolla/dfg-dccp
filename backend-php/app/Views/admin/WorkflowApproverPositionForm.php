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
      <h3 class="mb-1"><?= !empty($row['ApproverPositionID']) ? 'Edit Workflow Approver Position' : 'Add Workflow Approver Position' ?></h3>
      <div class="text-muted">Define the approver type, group scope, and position details used by workflow approvals.</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="index.php?route=admin/workflow-approver-positions">
      <i class="bi bi-arrow-left me-1"></i>Back
    </a>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Approver Position Details</strong>
      <span class="text-muted small">
        <?= !empty($row['ApproverPositionID']) ? 'Entry #' . h((string)$row['ApproverPositionID']) : 'New Entry' ?>
      </span>
    </div>
    <div class="card-body">
      <form method="post" action="index.php?route=admin/workflow-approver-positions-save">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="ApproverPositionID" value="<?= h((string)($row['ApproverPositionID'] ?? 0)) ?>">

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Approver Type</label>
            <input class="form-control" name="ApproverType" value="<?= h((string)($row['ApproverType'] ?? '')) ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Employee Group</label>
            <input class="form-control" name="EmployeeGroup" value="<?= h((string)($row['EmployeeGroup'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Position Number</label>
            <input class="form-control" name="PositionNumber" value="<?= h((string)($row['PositionNumber'] ?? '')) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Display Name</label>
            <input class="form-control" name="DisplayName" value="<?= h((string)($row['DisplayName'] ?? '')) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input class="form-control" type="email" name="Email" value="<?= h((string)($row['Email'] ?? '')) ?>">
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
          <a class="btn btn-outline-secondary" href="index.php?route=admin/workflow-approver-positions">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
