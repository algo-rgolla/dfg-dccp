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
      <h3 class="mb-1">Custom Suburbs</h3>
      <div class="text-muted">Manage extra suburb entries merged into the suburb search dataset.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-primary btn-sm" href="index.php?route=admin/suburbs-edit">
        <i class="bi bi-plus-circle me-1"></i>Add Suburb
      </a>
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
        <i class="bi bi-arrow-left me-1"></i>Back
      </a>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Custom Entries</strong>
      <span class="text-muted small"><?= count($rows) ?> entr<?= count($rows) === 1 ? 'y' : 'ies' ?></span>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>Suburb</th>
              <th>State</th>
              <th>Postcode</th>
              <th>Updated</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr>
                <td colspan="5" class="text-center text-muted">No custom suburb entries found.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= h((string)($row['n'] ?? '')) ?></td>
                  <td><?= h((string)($row['s'] ?? '')) ?></td>
                  <td><?= h((string)($row['p'] ?? '')) ?></td>
                  <td><?= h((string)($row['updated_at'] ?? ($row['created_at'] ?? ''))) ?></td>
                  <td>
                    <div class="d-flex gap-2">
                      <a class="btn btn-outline-primary btn-sm" href="index.php?route=admin/suburbs-edit&id=<?= urlencode((string)($row['id'] ?? '')) ?>">Edit</a>
                      <form method="post" action="index.php?route=admin/suburbs-delete" class="d-inline">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="id" value="<?= h((string)($row['id'] ?? '')) ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete this custom suburb entry?');">Delete</button>
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
