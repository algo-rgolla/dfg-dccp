<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$rows = is_array($rows ?? null) ? $rows : [];
$filters = is_array($filters ?? null) ? $filters : [];
$csrf = h((string)($_csrf ?? csrf_token()));
$currentPage = max(1, (int)($currentPage ?? 1));
$perPage = max(1, (int)($perPage ?? 100));
$totalCount = max(0, (int)($totalCount ?? count($rows)));
$totalPages = max(1, (int)($totalPages ?? 1));
$query = $filters;
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">CAPS Employee Types</h3>
      <div class="text-muted">Manage employee type entitlement rules stored in the CAPS employee type table.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-primary btn-sm" href="index.php?route=admin/caps-employee-types-edit">
        <i class="bi bi-plus-circle me-1"></i>Add Employee Type
      </a>
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
        <i class="bi bi-arrow-left me-1"></i>Back
      </a>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Search</strong>
      <span class="text-muted small">Showing <?= count($rows) ?> of <?= $totalCount ?> result<?= $totalCount === 1 ? '' : 's' ?></span>
    </div>
    <div class="card-body">
      <form method="get" action="index.php" class="row g-3 align-items-end">
        <input type="hidden" name="route" value="admin/caps-employee-types">
        <div class="col-md-5">
          <label class="form-label">Search</label>
          <input class="form-control" name="q" value="<?= h((string)($filters['q'] ?? '')) ?>" placeholder="ID or employee type">
        </div>
        <div class="col-md-3">
          <label class="form-label">Employee Type</label>
          <input class="form-control" name="employee_type" value="<?= h((string)($filters['employee_type'] ?? '')) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">DPC Entitled</label>
          <select class="form-select" name="dpc_entitled">
            <option value="">Any</option>
            <option value="Y" <?= ((string)($filters['dpc_entitled'] ?? '') === 'Y') ? 'selected' : '' ?>>Y</option>
            <option value="N" <?= ((string)($filters['dpc_entitled'] ?? '') === 'N') ? 'selected' : '' ?>>N</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">DTC Entitled</label>
          <select class="form-select" name="dtc_entitled">
            <option value="">Any</option>
            <option value="Y" <?= ((string)($filters['dtc_entitled'] ?? '') === 'Y') ? 'selected' : '' ?>>Y</option>
            <option value="N" <?= ((string)($filters['dtc_entitled'] ?? '') === 'N') ? 'selected' : '' ?>>N</option>
          </select>
        </div>
        <div class="col-md-12">
          <button type="submit" class="btn btn-primary">Search</button>
          <a class="btn btn-outline-secondary" href="index.php?route=admin/caps-employee-types">Clear</a>
        </div>
      </form>
    </div>
  </div>

  <?php if ($totalPages > 1): ?>
    <nav class="mb-3" aria-label="CAPS employee type pagination">
      <ul class="pagination pagination-sm justify-content-center mb-0">
        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
          <?php $prev = http_build_query(['route' => 'admin/caps-employee-types', 'page' => $currentPage - 1] + $query); ?>
          <a class="page-link" href="index.php?<?= h($prev) ?>">Prev</a>
        </li>
        <li class="page-item disabled"><span class="page-link">Page <?= $currentPage ?> of <?= $totalPages ?></span></li>
        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
          <?php $next = http_build_query(['route' => 'admin/caps-employee-types', 'page' => $currentPage + 1] + $query); ?>
          <a class="page-link" href="index.php?<?= h($next) ?>">Next</a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Results</strong>
      <span class="text-muted small">CAPS.dbo.tblCAPSEmployeeType</span>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>ID</th>
              <th>Employee Type</th>
              <th>DPC Entitled</th>
              <th>DTC Entitled</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="5" class="text-center text-muted">No employee type records found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <?php
                  $dpc = strtoupper(trim((string)($row['DPCEntitled'] ?? '')));
                  $dtc = strtoupper(trim((string)($row['DTCEntitled'] ?? '')));
                ?>
                <tr>
                  <td><?= h((string)($row['EmployeeTypeID'] ?? '')) ?></td>
                  <td><?= h((string)($row['EmployeeType'] ?? '')) ?></td>
                  <td><span class="badge <?= $dpc === 'Y' ? 'bg-success' : 'bg-secondary' ?>"><?= h($dpc !== '' ? $dpc : '-') ?></span></td>
                  <td><span class="badge <?= $dtc === 'Y' ? 'bg-success' : 'bg-secondary' ?>"><?= h($dtc !== '' ? $dtc : '-') ?></span></td>
                  <td class="text-end">
                    <div class="btn-group btn-group-sm">
                      <a class="btn btn-outline-primary" href="index.php?route=admin/caps-employee-types-edit&id=<?= urlencode((string)($row['EmployeeTypeID'] ?? 0)) ?>">Edit</a>
                      <form method="post" action="index.php?route=admin/caps-employee-types-delete" class="d-inline" onsubmit="return confirm('Delete this employee type record?');">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="id" value="<?= h((string)($row['EmployeeTypeID'] ?? 0)) ?>">
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
