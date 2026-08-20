<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../shared/csrf.php';
/** @var array $rows */
/** @var string $title */

if (!function_exists('h')) {
  function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  }
}
?>
<div class="container-fluid mt-4 px-3">
  <div class="card shadow-sm">
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
      <h5 class="mb-0">
        <i class="bi bi-activity text-primary me-2"></i><?= h($title) ?>
      </h5>
      <span class="text-muted small"><?= date('Y-m-d H:i') ?> UTC</span>
    </div>

    <div class="card-body">
      <?php if (!empty($_SESSION['flash'])): ?>
        <div class="alert alert-<?= h($_SESSION['flash']['type'] ?? 'info') ?> alert-dismissible fade show">
          <?= h($_SESSION['flash']['text'] ?? '') ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
      <?php endif; ?>

      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle">
          <thead class="table-light">
            <tr class="text-nowrap">
              <th>Username</th>
              <th>IP Address</th>
              <th>Login Time (UTC)</th>
              <th>Last Activity</th>
              <th>Expires At</th>
              <th>Status</th>
              <th class="text-center" style="width: 140px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="7" class="text-center text-muted py-3">No active sessions found</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <?php
                  $isActive = (int)($r['IsActive'] ?? 0) === 1;
                  $rowClass = $isActive ? '' : 'table-secondary';
                ?>
                <tr class="<?= $rowClass ?>">
                  <td><i class="bi bi-person-circle me-1 text-secondary"></i><?= h($r['Username'] ?? '') ?></td>
                  <td><?= h($r['IP'] ?? '') ?></td>
                  <td><?= h($r['LoginTime'] ?? '') ?></td>
                  <td><?= h($r['LastActivity'] ?? '') ?></td>
                  <td><?= h($r['ExpiresAt'] ?? '') ?></td>
                  <td>
                    <?php if ($isActive): ?>
                      <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">Active</span>
                    <?php else: ?>
                      <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1">Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <button type="button"
                            class="btn btn-sm btn-outline-danger"
                            data-bs-toggle="modal"
                            data-bs-target="#forceLogoutModal"
                            data-session="<?= h($r['SessionID'] ?? '') ?>"
                            data-username="<?= h($r['Username'] ?? '') ?>">
                      <i class="bi bi-box-arrow-right me-1"></i> Logout
                    </button>
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

<!-- 🧱 Force Logout Modal -->
<div class="modal fade" id="forceLogoutModal" tabindex="-1" aria-labelledby="forceLogoutLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="index.php?route=sessions/forcelogout">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" id="forceLogoutSessionID" name="SessionID" value="">

        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title" id="forceLogoutLabel"><i class="bi bi-box-arrow-right me-2"></i>Force Logout</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2">Are you sure you want to force logout this user session?</p>
          <p class="fw-bold text-danger mb-0" id="logoutUsername"></p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">
            <i class="bi bi-power"></i> Confirm Logout
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const modal = document.getElementById('forceLogoutModal');
  modal.addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    const sessionId = button.getAttribute('data-session');
    const username = button.getAttribute('data-username');
    modal.querySelector('#forceLogoutSessionID').value = sessionId;
    modal.querySelector('#logoutUsername').textContent = username ? 'User: ' + username : '';
  });
});
</script>
