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
      <h3 class="mb-1">Eligibility Overrides</h3>
      <div class="text-muted">Manage employees who can bypass specific eligibility checks.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-primary btn-sm" href="index.php?route=eligibility-admin/override-edit">
        <i class="bi bi-plus-circle me-1"></i>Add Override
      </a>
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
        <i class="bi bi-arrow-left me-1"></i>Back
      </a>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Override Entries</strong>
      <span class="text-muted small"><?= count($rows) ?> entr<?= count($rows) === 1 ? 'y' : 'ies' ?></span>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>EmployeeID</th>
              <th>Override Type</th>
              <th>Application Type</th>
              <th>Status</th>
              <th>Reason</th>
              <th>Effective</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="7" class="text-center text-muted">No override entries found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= h((string)($row['EmployeeID'] ?? '')) ?></td>
                  <td><?= h((string)($row['OverrideType'] ?? '')) ?></td>
                  <td><?= h((string)($row['ApplicationTypeName'] ?? 'All Application Types')) ?></td>
                  <td>
                    <span class="badge <?= !empty($row['IsActive']) ? 'bg-success' : 'bg-secondary' ?>">
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
                      <a class="btn btn-outline-primary btn-sm" href="index.php?route=eligibility-admin/override-edit&id=<?= urlencode((string)($row['OverrideID'] ?? 0)) ?>">Edit</a>
                      <?php if (!empty($row['IsActive'])): ?>
                        <form method="post" action="index.php?route=eligibility-admin/override-delete" class="d-inline">
                          <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                          <input type="hidden" name="id" value="<?= h((string)($row['OverrideID'] ?? 0)) ?>">
                          <button type="submit" class="btn btn-outline-danger btn-sm">Deactivate</button>
                        </form>
                      <?php endif; ?>
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
