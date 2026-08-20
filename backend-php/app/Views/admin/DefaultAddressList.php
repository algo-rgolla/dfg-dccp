<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$rows = is_array($rows ?? null) ? $rows : [];
$csrf = function_exists('csrf_token') ? h(csrf_token()) : '';
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Portal Default Addresses</h3>
      <div class="text-muted">These addresses override CAPS for new applications after a user has submitted an application.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-primary btn-sm" href="index.php?route=admin/default-addresses-edit">
        <i class="bi bi-plus-circle me-1"></i>Add Address
      </a>
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
        <i class="bi bi-arrow-left me-1"></i>Back
      </a>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Saved Default Addresses</strong>
      <span class="text-muted small"><?= count($rows) ?> address<?= count($rows) === 1 ? '' : 'es' ?></span>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>EmployeeID</th>
              <th>Address</th>
              <th>Source Application</th>
              <th>Confirmed</th>
              <th>Updated</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="6" class="text-center text-muted">No saved default addresses found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= h((string)($row['EmployeeID'] ?? '')) ?></td>
                  <td>
                    <div><?= h((string)($row['Address1'] ?? '')) ?></div>
                    <?php if (trim((string)($row['Address2'] ?? '')) !== ''): ?><div><?= h((string)$row['Address2']) ?></div><?php endif; ?>
                    <?php if (trim((string)($row['Address3'] ?? '')) !== ''): ?><div><?= h((string)$row['Address3']) ?></div><?php endif; ?>
                    <div><?= h(trim((string)($row['Suburb'] ?? '') . ' ' . (string)($row['State'] ?? '') . ' ' . (string)($row['PostCode'] ?? ''))) ?></div>
                  </td>
                  <td><?= h((string)($row['SourceApplicationID'] ?? '')) ?></td>
                  <td><?= h((string)($row['ConfirmedAt'] ?? '')) ?></td>
                  <td><?= h((string)($row['UpdatedAt'] ?? '')) ?></td>
                  <td>
                    <div class="d-flex gap-2">
                      <a class="btn btn-outline-primary btn-sm" href="index.php?route=admin/default-addresses-edit&id=<?= urlencode((string)($row['DefaultAddressID'] ?? 0)) ?>">Edit</a>
                      <form method="post" action="index.php?route=admin/default-addresses-delete" onsubmit="return confirm('Delete this default address?');" class="d-inline">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="id" value="<?= h((string)($row['DefaultAddressID'] ?? 0)) ?>">
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
