<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$rows = (isset($rows) && is_array($rows)) ? $rows : [];
$filters = (isset($filters) && is_array($filters)) ? $filters : [];
$cardTypeSubs = (isset($cardTypeSubs) && is_array($cardTypeSubs)) ? $cardTypeSubs : [];
$statuses = (isset($statuses) && is_array($statuses)) ? $statuses : [];
$hasFilters = !empty($hasFilters);
$currentPage = max(1, (int)($currentPage ?? 1));
$totalPages = max(1, (int)($totalPages ?? 1));
$totalCount = max(0, (int)($totalCount ?? 0));
$csrf = h((string)($_csrf ?? csrf_token()));

$queryBase = [
    'route' => 'admin/portal-cards',
    'q' => (string)($filters['q'] ?? ''),
    'employeeId' => (string)($filters['employeeId'] ?? ''),
    'cardTypeSub' => (string)($filters['cardTypeSub'] ?? ''),
    'status' => (string)($filters['status'] ?? ''),
    'active' => (string)($filters['active'] ?? ''),
];

$buildPageUrl = static function (int $page) use ($queryBase): string {
    return 'index.php?' . http_build_query($queryBase + ['page' => $page]);
};
?>

<div class="card shadow-sm mt-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong><i class="bi bi-credit-card-2-front me-2"></i>Portal Cards</strong>
    <a href="index.php?route=admin/portal-cards-edit" class="btn btn-sm btn-primary">
      <i class="bi bi-plus-circle me-1"></i>Create Portal Card
    </a>
  </div>

  <div class="card-body">
    <form method="get" action="index.php" class="row g-2 mb-3">
      <input type="hidden" name="route" value="admin/portal-cards">

      <div class="col-md-3">
        <input
          type="text"
          name="q"
          value="<?= h((string)($filters['q'] ?? '')) ?>"
          class="form-control"
          placeholder="Search card ID, employee, name, email, type, status"
        >
      </div>

      <div class="col-md-2">
        <input
          type="text"
          name="employeeId"
          value="<?= h((string)($filters['employeeId'] ?? '')) ?>"
          class="form-control"
          placeholder="Employee ID"
        >
      </div>

      <div class="col-md-2">
        <select name="cardTypeSub" class="form-select">
          <option value="">All card type subs</option>
          <?php foreach ($cardTypeSubs as $option): ?>
            <option value="<?= h((string)$option) ?>" <?= ((string)($filters['cardTypeSub'] ?? '') === (string)$option) ? 'selected' : '' ?>>
              <?= h((string)$option) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <select name="status" class="form-select">
          <option value="">All statuses</option>
          <?php foreach ($statuses as $option): ?>
            <option value="<?= h((string)$option) ?>" <?= ((string)($filters['status'] ?? '') === (string)$option) ? 'selected' : '' ?>>
              <?= h((string)$option) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-1">
        <select name="active" class="form-select">
          <option value="">Any</option>
          <option value="1" <?= ((string)($filters['active'] ?? '') === '1') ? 'selected' : '' ?>>Active</option>
          <option value="0" <?= ((string)($filters['active'] ?? '') === '0') ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>

      <div class="col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-primary flex-fill">
          <i class="bi bi-search me-1"></i>Filter
        </button>
        <a href="index.php?route=admin/portal-cards" class="btn btn-outline-secondary flex-fill">
          <i class="bi bi-x-circle me-1"></i>Reset
        </a>
      </div>
    </form>

    <div class="table-responsive">
      <table class="table table-striped table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Card ID</th>
            <th>Employee ID</th>
            <th>Name</th>
            <th>Card Type</th>
            <th>Status</th>
            <th>Active</th>
            <th>Expiry</th>
            <th>Updated</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$hasFilters): ?>
            <tr>
              <td colspan="9" class="text-center text-muted py-4">Apply at least one filter to load portal cards.</td>
            </tr>
          <?php elseif (!$rows): ?>
            <tr>
              <td colspan="9" class="text-center text-muted py-3">No portal cards found.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $row): ?>
              <?php
                $cardId = (int)($row['CardID'] ?? 0);
                $name = trim((string)($row['FirstName'] ?? '') . ' ' . (string)($row['Surname'] ?? ''));
                $updated = (string)($row['DateUpdated'] ?? '');
                $status = trim((string)($row['Status'] ?? ''));
                $isActive = $status === '';
              ?>
              <tr>
                <td><?= h((string)$cardId) ?></td>
                <td><?= h((string)($row['EmployeeID'] ?? '')) ?></td>
                <td><?= h($name !== '' ? $name : '-') ?></td>
                <td><?= h(trim((string)($row['CardType'] ?? '') . ' ' . (string)($row['CardTypeSub'] ?? ''))) ?></td>
                <td><?= h($status) ?></td>
                <td>
                  <?php if ($isActive): ?>
                    <span class="badge bg-success">Yes</span>
                  <?php else: ?>
                    <span class="badge bg-secondary">No</span>
                  <?php endif; ?>
                </td>
                <td><?= h($row['Expiry'] ? substr((string)$row['Expiry'], 0, 10) : '-') ?></td>
                <td><?= h($updated !== '' ? substr($updated, 0, 19) : '-') ?></td>
                <td class="text-end">
                  <div class="btn-group btn-group-sm">
                    <a
                      href="index.php?route=admin/portal-cards-edit&id=<?= urlencode((string)$cardId) ?>"
                      class="btn btn-outline-secondary"
                      title="Edit"
                    >
                      <i class="bi bi-pencil-square"></i>
                    </a>
                    <button
                      type="button"
                      class="btn btn-outline-danger"
                      data-bs-toggle="modal"
                      data-bs-target="#deletePortalCardModal"
                      data-cardid="<?= h((string)$cardId) ?>"
                      data-label="<?= h((string)($row['EmployeeID'] ?? '') . ' - ' . (string)($row['CardType'] ?? 'Card')) ?>"
                      title="Delete"
                    >
                      <i class="bi bi-trash"></i>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($hasFilters && $totalPages > 1): ?>
      <nav class="mt-3" aria-label="Portal cards pagination">
        <ul class="pagination justify-content-center">
          <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= h($buildPageUrl(max(1, $currentPage - 1))) ?>">&laquo; Prev</a>
          </li>
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
              <a class="page-link" href="<?= h($buildPageUrl($i)) ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= h($buildPageUrl(min($totalPages, $currentPage + 1))) ?>">Next &raquo;</a>
          </li>
        </ul>
        <p class="text-center text-muted small mb-0">
          Showing <?= count($rows) ?> of <?= $totalCount ?> portal cards
        </p>
      </nav>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="deletePortalCardModal" tabindex="-1" aria-labelledby="deletePortalCardLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="index.php?route=admin/portal-cards-delete">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="CardID" id="deletePortalCardId" value="">

        <div class="modal-header">
          <h5 class="modal-title" id="deletePortalCardLabel">Delete Portal Card</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <p class="mb-0">Delete <strong id="deletePortalCardLabelText"></strong>?</p>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Delete</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const modal = document.getElementById('deletePortalCardModal');
  if (!modal) return;

  modal.addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    if (!button) return;

    const cardId = button.getAttribute('data-cardid') || '';
    const label = button.getAttribute('data-label') || 'this portal card';

    const idInput = document.getElementById('deletePortalCardId');
    const labelText = document.getElementById('deletePortalCardLabelText');

    if (idInput) idInput.value = cardId;
    if (labelText) labelText.textContent = label;
  });
});
</script>
