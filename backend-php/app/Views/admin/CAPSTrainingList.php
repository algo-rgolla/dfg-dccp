<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fmt_dt_caps_training')) {
    function fmt_dt_caps_training(?string $v): string
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
$currentPage = max(1, (int)($currentPage ?? 1));
$perPage = max(1, (int)($perPage ?? 100));
$totalCount = max(0, (int)($totalCount ?? count($rows)));
$totalPages = max(1, (int)($totalPages ?? 1));
$query = $filters;
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">CAPS Training</h3>
      <div class="text-muted">Manage training completion records stored in the CAPS training table.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-primary btn-sm" href="index.php?route=admin/caps-training-edit">
        <i class="bi bi-plus-circle me-1"></i>Add Training Record
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
        <input type="hidden" name="route" value="admin/caps-training">
        <div class="col-md-5">
          <label class="form-label">Search</label>
          <input class="form-control" name="q" value="<?= h((string)($filters['q'] ?? '')) ?>" placeholder="ID, course, title, employee, name, email">
        </div>
        <div class="col-md-2">
          <label class="form-label">Course ID</label>
          <input class="form-control" name="course_id" value="<?= h((string)($filters['course_id'] ?? '')) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Employee ID</label>
          <input class="form-control" name="employee_id" value="<?= h((string)($filters['employee_id'] ?? '')) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Loaded</label>
          <select class="form-select" name="loaded">
            <option value="">Any</option>
            <option value="Y" <?= ((string)($filters['loaded'] ?? '') === 'Y') ? 'selected' : '' ?>>Y</option>
            <option value="N" <?= ((string)($filters['loaded'] ?? '') === 'N') ? 'selected' : '' ?>>N</option>
          </select>
        </div>
        <div class="col-md-1">
          <button type="submit" class="btn btn-primary">Search</button>
        </div>
      </form>
      <div class="mt-3">
        <a class="btn btn-outline-secondary btn-sm" href="index.php?route=admin/caps-training">Clear</a>
      </div>
    </div>
  </div>

  <?php if ($totalPages > 1): ?>
    <nav class="mb-3" aria-label="CAPS training pagination">
      <ul class="pagination pagination-sm justify-content-center mb-0">
        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
          <?php $prev = http_build_query(['route' => 'admin/caps-training', 'page' => $currentPage - 1] + $query); ?>
          <a class="page-link" href="index.php?<?= h($prev) ?>">Prev</a>
        </li>
        <li class="page-item disabled"><span class="page-link">Page <?= $currentPage ?> of <?= $totalPages ?></span></li>
        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
          <?php $next = http_build_query(['route' => 'admin/caps-training', 'page' => $currentPage + 1] + $query); ?>
          <a class="page-link" href="index.php?<?= h($next) ?>">Next</a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Results</strong>
      <span class="text-muted small">CAPS.dbo.tblCAPSTraining</span>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>ID</th>
              <th>Course</th>
              <th>Title</th>
              <th>Employee</th>
              <th>Name</th>
              <th>Email</th>
              <th>Completion</th>
              <th>Loaded</th>
              <th>Updated</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="10" class="text-center text-muted">No training records found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <?php $fullName = trim((string)($row['FirstName'] ?? '') . ' ' . (string)($row['LastName'] ?? '')); ?>
                <tr>
                  <td><?= h((string)($row['TrainingID'] ?? '')) ?></td>
                  <td>
                    <div><?= h((string)($row['CourseID'] ?? '')) ?></div>
                    <div class="small text-muted"><?= h((string)($row['OfferingID'] ?? '')) ?></div>
                  </td>
                  <td><?= h((string)($row['CourseTitle'] ?? '')) ?></td>
                  <td><code><?= h((string)($row['EmployeeID'] ?? '')) ?></code></td>
                  <td><?= h($fullName !== '' ? $fullName : '-') ?></td>
                  <td><?= h((string)($row['Email'] ?? '')) ?></td>
                  <td><?= h(fmt_dt_caps_training((string)($row['CompletionDate'] ?? ''))) ?></td>
                  <td>
                    <span class="badge <?= strtoupper((string)($row['Loaded'] ?? '')) === 'Y' ? 'bg-success' : 'bg-secondary' ?>">
                      <?= h((string)($row['Loaded'] ?? '-')) ?>
                    </span>
                  </td>
                  <td><?= h(fmt_dt_caps_training((string)($row['DateUpdated'] ?? ''))) ?></td>
                  <td class="text-end">
                    <div class="btn-group btn-group-sm">
                      <a class="btn btn-outline-primary" href="index.php?route=admin/caps-training-edit&id=<?= urlencode((string)($row['TrainingID'] ?? 0)) ?>">Edit</a>
                      <form method="post" action="index.php?route=admin/caps-training-delete" class="d-inline" onsubmit="return confirm('Delete this training record?');">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="id" value="<?= h((string)($row['TrainingID'] ?? 0)) ?>">
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
