<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$rows = is_array($rows ?? null) ? $rows : [];
$filters = is_array($filters ?? null) ? $filters : [];
$applicationTypes = is_array($applicationTypes ?? null) ? $applicationTypes : [];
$employeeGroups = is_array($employeeGroups ?? null) ? $employeeGroups : [];
$approverTypes = is_array($approverTypes ?? null) ? $approverTypes : [];
$csrf = h((string)($_csrf ?? csrf_token()));
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Workflow Approval Rules</h3>
      <div class="text-muted">Manage limit bands, employee group scope, and required approver types used by workflow approvals.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-primary btn-sm" href="index.php?route=admin/workflow-approval-rules-upload">
        <i class="bi bi-upload me-1"></i>Upload Rules
      </a>
      <a class="btn btn-primary btn-sm" href="index.php?route=admin/workflow-approval-rules-edit">
        <i class="bi bi-plus-circle me-1"></i>Add Rule
      </a>
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
        <i class="bi bi-arrow-left me-1"></i>Back
      </a>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Approval Rule Entries</strong>
      <span class="text-muted small"><?= count($rows) ?> entr<?= count($rows) === 1 ? 'y' : 'ies' ?></span>
    </div>
    <div class="card-body">
      <form method="get" action="index.php" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="route" value="admin/workflow-approval-rules">

        <div class="col-md-3">
          <label class="form-label">Application Type</label>
          <select class="form-select" name="applicationTypeId">
            <option value="">All application types</option>
            <?php foreach ($applicationTypes as $type): ?>
              <?php $typeId = (string)($type['ApplicationTypeID'] ?? ''); ?>
              <option value="<?= h($typeId) ?>" <?= ((string)($filters['applicationTypeId'] ?? '') === $typeId) ? 'selected' : '' ?>>
                <?= h((string)($type['ApplicationTypeName'] ?? $typeId)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-2">
          <label class="form-label">Approver Type</label>
          <select class="form-select" name="approverType">
            <option value="">All approver types</option>
            <?php foreach ($approverTypes as $type): ?>
              <option value="<?= h((string)$type) ?>" <?= ((string)($filters['approverType'] ?? '') === (string)$type) ? 'selected' : '' ?>>
                <?= h((string)$type) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label">Employee Group</label>
          <select class="form-select" name="employeeGroup">
            <option value="">All employee groups</option>
            <?php foreach ($employeeGroups as $group): ?>
              <option value="<?= h((string)$group) ?>" <?= ((string)($filters['employeeGroup'] ?? '') === (string)$group) ? 'selected' : '' ?>>
                <?= h((string)$group) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-2">
          <label class="form-label">Status</label>
          <select class="form-select" name="active">
            <option value="">Any</option>
            <option value="1" <?= ((string)($filters['active'] ?? '') === '1') ? 'selected' : '' ?>>Active</option>
            <option value="0" <?= ((string)($filters['active'] ?? '') === '0') ? 'selected' : '' ?>>Inactive</option>
          </select>
        </div>

        <div class="col-md-2 d-flex gap-2">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-search me-1"></i>Filter
          </button>
          <a class="btn btn-outline-secondary" href="index.php?route=admin/workflow-approval-rules&reset=1">
            <i class="bi bi-arrow-repeat me-1"></i>Reset
          </a>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>ID</th>
              <th>Application Type</th>
              <th>Employee Group</th>
              <th>Stage</th>
              <th>Min Limit</th>
              <th>Max Limit</th>
              <th>Approver Type</th>
              <th>Required Rank</th>
              <th>Status</th>
              <th>Updated</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="11" class="text-center text-muted">No workflow approval rules found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= h((string)($row['RuleID'] ?? '')) ?></td>
                  <td>
                    <div><?= h((string)($row['ApplicationTypeName'] ?? '')) ?></div>
                    <div class="small text-muted"><?= h((string)($row['ApplicationTypeKey'] ?? '')) ?></div>
                  </td>
                  <td><?= h((string)($row['EmployeeGroup'] ?? '')) ?></td>
                  <td><?= h((string)($row['ApprovalStage'] ?? '1')) ?></td>
                  <td><?= h(number_format((float)($row['MinLimit'] ?? 0), 2)) ?></td>
                  <td><?= ($row['MaxLimit'] ?? null) === null ? '<span class="text-muted">No maximum</span>' : h(number_format((float)$row['MaxLimit'], 2)) ?></td>
                  <td><?= h((string)($row['RequiredApproverType'] ?? '')) ?></td>
                  <td><?= h((string)($row['RequiredRank'] ?? '')) ?></td>
                  <td>
                    <span class="badge <?= !empty($row['IsActive']) ? 'bg-success' : 'bg-secondary' ?>">
                      <?= !empty($row['IsActive']) ? 'Active' : 'Inactive' ?>
                    </span>
                  </td>
                  <td><?= h((string)($row['UpdatedAt'] ?? $row['CreatedAt'] ?? '')) ?></td>
                  <td>
                    <div class="d-flex gap-2">
                      <a class="btn btn-outline-primary btn-sm" href="index.php?route=admin/workflow-approval-rules-edit&id=<?= urlencode((string)($row['RuleID'] ?? 0)) ?>">Edit</a>
                      <form method="post" action="index.php?route=admin/workflow-approval-rules-delete" class="d-inline" onsubmit="return confirm('Delete this workflow approval rule?');">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="id" value="<?= h((string)($row['RuleID'] ?? 0)) ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
