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
      <h3 class="mb-1"><?= !empty($row['RuleID']) ? 'Edit Workflow Approval Rule' : 'Add Workflow Approval Rule' ?></h3>
      <div class="text-muted">Define the application type, amount band, employee group scope, and approver requirement used by workflow approvals.</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="index.php?route=admin/workflow-approval-rules">
      <i class="bi bi-arrow-left me-1"></i>Back
    </a>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Approval Rule Details</strong>
      <span class="text-muted small">
        <?= !empty($row['RuleID']) ? 'Rule #' . h((string)$row['RuleID']) : 'New Rule' ?>
      </span>
    </div>
    <div class="card-body">
      <form method="post" action="index.php?route=admin/workflow-approval-rules-save">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="RuleID" value="<?= h((string)($row['RuleID'] ?? 0)) ?>">

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Application Type</label>
            <select class="form-select" name="ApplicationTypeID" required>
              <option value="">Select application type</option>
              <?php foreach ($applicationTypes as $type): ?>
                <?php $typeId = (string)($type['ApplicationTypeID'] ?? ''); ?>
                <option value="<?= h($typeId) ?>" <?= ((string)($row['ApplicationTypeID'] ?? '') === $typeId) ? 'selected' : '' ?>>
                  <?= h((string)($type['ApplicationTypeName'] ?? $typeId)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Employee Group</label>
            <input class="form-control" name="EmployeeGroup" value="<?= h((string)($row['EmployeeGroup'] ?? '')) ?>" placeholder="Leave blank for global rule">
          </div>

          <div class="col-md-4">
            <label class="form-label">Required Approver Type</label>
            <input class="form-control" name="RequiredApproverType" value="<?= h((string)($row['RequiredApproverType'] ?? '')) ?>" required>
          </div>

          <div class="col-md-3">
            <label class="form-label">Approval Stage</label>
            <input class="form-control" type="number" min="1" step="1" name="ApprovalStage" value="<?= h((string)($row['ApprovalStage'] ?? '1')) ?>" required>
          </div>

          <div class="col-md-3">
            <label class="form-label">Min Limit</label>
            <input class="form-control" type="number" step="0.01" min="0" name="MinLimit" value="<?= h((string)($row['MinLimit'] ?? '0')) ?>" required>
          </div>

          <div class="col-md-3">
            <label class="form-label">Max Limit</label>
            <input class="form-control" type="number" step="0.01" min="0" name="MaxLimit" value="<?= h((string)($row['MaxLimit'] ?? '')) ?>" placeholder="Leave blank for no maximum">
          </div>

          <div class="col-md-3">
            <label class="form-label">Required Rank</label>
            <input class="form-control" name="RequiredRank" value="<?= h((string)($row['RequiredRank'] ?? '')) ?>" placeholder="Optional">
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
          <a class="btn btn-outline-secondary" href="index.php?route=admin/workflow-approval-rules">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
