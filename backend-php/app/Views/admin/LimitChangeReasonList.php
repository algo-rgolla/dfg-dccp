<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$rows = is_array($rows ?? null) ? $rows : [];
$filters = is_array($filters ?? null) ? $filters : [];
$applicationTypes = is_array($applicationTypes ?? null) ? $applicationTypes : [];
$csrf = h((string)($_csrf ?? csrf_token()));
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Limit Change Reasons</h3>
      <div class="text-muted">Maintain justification reasons by application type for the Request Limit Change form.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-primary btn-sm" href="index.php?route=admin/limit-change-reasons-edit">
        <i class="bi bi-plus-circle me-1"></i>Add Reason
      </a>
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
        <i class="bi bi-arrow-left me-1"></i>Back
      </a>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Filters</strong>
      <span class="text-muted small"><?= count($rows) ?> result<?= count($rows) === 1 ? '' : 's' ?></span>
    </div>
    <div class="card-body">
      <form method="get" action="index.php" class="row g-3 align-items-end">
        <input type="hidden" name="route" value="admin/limit-change-reasons">
        <div class="col-md-6">
          <label class="form-label">Application Type</label>
          <select class="form-select" name="applicationTypeId">
            <option value="">All application types</option>
            <?php foreach ($applicationTypes as $type): ?>
              <?php $typeId = (int)($type['ApplicationTypeID'] ?? 0); ?>
              <option value="<?= h((string)$typeId) ?>" <?= ((int)($filters['applicationTypeId'] ?? 0) === $typeId) ? 'selected' : '' ?>>
                <?= h((string)($type['ApplicationTypeName'] ?? $type['ApplicationTypeKey'] ?? '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select class="form-select" name="active">
            <option value="">Any</option>
            <option value="1" <?= ((string)($filters['active'] ?? '') === '1') ? 'selected' : '' ?>>Active</option>
            <option value="0" <?= ((string)($filters['active'] ?? '') === '0') ? 'selected' : '' ?>>Inactive</option>
          </select>
        </div>
        <div class="col-md-3">
          <button type="submit" class="btn btn-primary">Search</button>
          <a class="btn btn-outline-secondary" href="index.php?route=admin/limit-change-reasons">Clear</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Reason Results</strong>
      <span class="text-muted small">Lookup table</span>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>ID</th>
              <th>Application Type</th>
              <th>Reason</th>
              <th>Sort Order</th>
              <th>Status</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="6" class="text-center text-muted">No limit change reasons found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= h((string)($row['ReasonID'] ?? '')) ?></td>
                  <td>
                    <div><?= h((string)($row['ApplicationTypeName'] ?? '')) ?></div>
                    <div class="text-muted small"><code><?= h((string)($row['ApplicationTypeKey'] ?? '')) ?></code></div>
                  </td>
                  <td><?= h((string)($row['ReasonLabel'] ?? '')) ?></td>
                  <td><?= h((string)($row['SortOrder'] ?? '0')) ?></td>
                  <td>
                    <span class="badge <?= !empty($row['IsActive']) ? 'bg-success' : 'bg-secondary' ?>">
                      <?= !empty($row['IsActive']) ? 'Active' : 'Inactive' ?>
                    </span>
                  </td>
                  <td class="text-end">
                    <div class="btn-group btn-group-sm">
                      <a class="btn btn-outline-primary" href="index.php?route=admin/limit-change-reasons-edit&id=<?= urlencode((string)($row['ReasonID'] ?? 0)) ?>">Edit</a>
                      <form method="post" action="index.php?route=admin/limit-change-reasons-delete" class="d-inline" onsubmit="return confirm('Delete this limit change reason?');">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="id" value="<?= h((string)($row['ReasonID'] ?? 0)) ?>">
                        <button type="submit" class="btn btn-outline-danger">Delete</button>
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
