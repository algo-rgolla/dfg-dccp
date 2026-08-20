<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fmt_dt_admin_app_type')) {
    function fmt_dt_admin_app_type(?string $v): string
    {
        $s = trim((string)$v);
        if ($s === '') {
            return '-';
        }
        $ts = strtotime($s);
        return $ts === false ? $s : date('d-m-Y H:i', $ts);
    }
}

$rows = is_array($rows ?? null) ? $rows : [];
$filters = is_array($filters ?? null) ? $filters : [];
$csrf = h((string)($_csrf ?? csrf_token()));
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Application Types</h3>
      <div class="text-muted">Manage the available application types used across the portal.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-primary btn-sm" href="index.php?route=admin/application-types-edit">
        <i class="bi bi-plus-circle me-1"></i>Add Application Type
      </a>
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
        <i class="bi bi-arrow-left me-1"></i>Back
      </a>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Search</strong>
      <span class="text-muted small"><?= count($rows) ?> result<?= count($rows) === 1 ? '' : 's' ?></span>
    </div>
    <div class="card-body">
      <form method="get" action="index.php" class="row g-3 align-items-end">
        <input type="hidden" name="route" value="admin/application-types">
        <div class="col-md-6">
          <label class="form-label">Search</label>
          <input class="form-control" name="q" value="<?= h((string)($filters['q'] ?? '')) ?>" placeholder="ID, key, name, or description">
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
          <a class="btn btn-outline-secondary" href="index.php?route=admin/application-types">Clear</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Application Type Results</strong>
      <span class="text-muted small">Master table</span>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>ID</th>
              <th>Key</th>
              <th>Name</th>
              <th>Description</th>
              <th>Privacy</th>
              <th>Status</th>
              <th>Created</th>
              <th>Updated</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="9" class="text-center text-muted">No application types found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= h((string)($row['ApplicationTypeID'] ?? '')) ?></td>
                  <td><code><?= h((string)($row['ApplicationTypeKey'] ?? '')) ?></code></td>
                  <td><?= h((string)($row['ApplicationTypeName'] ?? '')) ?></td>
                  <td><?= h((string)($row['Description'] ?? '')) ?></td>
                  <td>
                    <span class="badge <?= !empty($row['PrivacyAgreementRequired']) ? 'bg-info text-dark' : 'bg-light text-dark border' ?>">
                      <?= !empty($row['PrivacyAgreementRequired']) ? 'Required' : 'Not Required' ?>
                    </span>
                  </td>
                  <td>
                    <span class="badge <?= !empty($row['IsActive']) ? 'bg-success' : 'bg-secondary' ?>">
                      <?= !empty($row['IsActive']) ? 'Active' : 'Inactive' ?>
                    </span>
                  </td>
                  <td><?= h(fmt_dt_admin_app_type((string)($row['CreatedAt'] ?? ''))) ?></td>
                  <td><?= h(fmt_dt_admin_app_type((string)($row['UpdatedAt'] ?? ''))) ?></td>
                  <td class="text-end">
                    <div class="btn-group btn-group-sm">
                      <a class="btn btn-outline-primary" href="index.php?route=admin/application-types-edit&id=<?= urlencode((string)($row['ApplicationTypeID'] ?? 0)) ?>">Edit</a>
                      <form method="post" action="index.php?route=admin/application-types-delete" class="d-inline" onsubmit="return confirm('Delete this application type?');">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="id" value="<?= h((string)($row['ApplicationTypeID'] ?? 0)) ?>">
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
