<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$rows = is_array($rows ?? null) ? $rows : [];
$csrf = h(csrf_token());
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Restricted List</h3>
      <div class="text-muted">Employees listed here cannot apply for cards while the entry is active.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-primary btn-sm" href="index.php?route=eligibility-admin/blacklist-edit">
        <i class="bi bi-plus-circle me-1"></i>Add Entry
      </a>
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
        <i class="bi bi-arrow-left me-1"></i>Back
      </a>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Restricted List Entries</strong>
      <span class="text-muted small"><?= count($rows) ?> entr<?= count($rows) === 1 ? 'y' : 'ies' ?></span>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>EmployeeID</th>
              <th>Application Type</th>
              <th>Status</th>
              <th>Reason</th>
              <th>Effective</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="6" class="text-center text-muted">No restricted list entries found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= h((string)($row['EmployeeID'] ?? '')) ?></td>
                  <td><?= h((string)($row['ApplicationTypeName'] ?? 'All Application Types')) ?></td>
                  <td>
                    <span class="badge <?= !empty($row['IsActive']) ? 'bg-danger' : 'bg-secondary' ?>">
                      <?= !empty($row['IsActive']) ? 'Active' : 'Inactive' ?>
                    </span>
                  </td>
                  <td><?= h((string)($row['Reason'] ?? '')) ?></td>
                  <td>
                    <div class="small">From: <?= h((string)($row['EffectiveFrom'] ?? '-')) ?></div>
                    <div class="small">To: <?= h((string)($row['EffectiveTo'] ?? '-')) ?></div>
                  </td>
                  <td>
                    <div class="d-flex gap-2">
                      <a class="btn btn-outline-primary btn-sm" href="index.php?route=eligibility-admin/blacklist-edit&id=<?= urlencode((string)($row['BlacklistID'] ?? 0)) ?>">Edit</a>
                      <form method="post" action="index.php?route=eligibility-admin/blacklist-delete" class="d-inline" onsubmit="return confirm('Delete this restricted list entry?');">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="id" value="<?= h((string)($row['BlacklistID'] ?? 0)) ?>">
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
