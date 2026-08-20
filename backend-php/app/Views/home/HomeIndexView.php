<?php declare(strict_types=1); ?>

<?php
use App\Core\Rbac;
?>

<?php
$pendingApprovals = is_array($pendingApprovals ?? null) ? $pendingApprovals : [];
$pendingTotal = (int)($pendingApprovals['totalCount'] ?? 0);
$pendingDpc = (int)($pendingApprovals['dpcCount'] ?? 0);
$pendingLimitChange = (int)($pendingApprovals['limitChangeCount'] ?? 0);
$dpcRoute = (string)($pendingApprovals['dpcListRoute'] ?? 'applications/my-dpc-approvals');
$limitChangeRoute = (string)($pendingApprovals['limitChangeListRoute'] ?? 'cards/my-limit-change-approvals');
$employeeIdAvailable = !empty($employeeIdAvailable);
?>

<?php if ($pendingTotal > 0): ?>
  <div class="container-fluid mt-4">
    <section class="card shadow-sm border-warning-subtle mb-3" aria-labelledby="pendingApprovalsHeading">
      <div class="card-body">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
          <div>
            <h2 class="h4 mb-1" id="pendingApprovalsHeading">Pending Approvals</h2>
            <div class="text-muted">You have <?= htmlspecialchars((string)$pendingTotal, ENT_QUOTES, 'UTF-8') ?> approval<?= $pendingTotal === 1 ? '' : 's' ?> waiting for action.</div>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <?php if ($pendingDpc > 0): ?>
              <a class="btn btn-outline-primary btn-sm" href="index.php?route=<?= htmlspecialchars($dpcRoute, ENT_QUOTES, 'UTF-8') ?>">
                DPC Approvals (<?= htmlspecialchars((string)$pendingDpc, ENT_QUOTES, 'UTF-8') ?>)
              </a>
            <?php endif; ?>
            <?php if ($pendingLimitChange > 0): ?>
              <a class="btn btn-outline-primary btn-sm" href="index.php?route=<?= htmlspecialchars($limitChangeRoute, ENT_QUOTES, 'UTF-8') ?>">
                Limit Change Approvals (<?= htmlspecialchars((string)$pendingLimitChange, ENT_QUOTES, 'UTF-8') ?>)
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>
  </div>
<?php endif; ?>

<?php if (Rbac::can('PORTALCARDS_VIEW') && $employeeIdAvailable): ?>
  <?php require __DIR__ . '/../portalcards/PortalCardsList.php'; ?>
<?php elseif (Rbac::can('PORTALCARDS_VIEW')): ?>
  <div class="container-fluid mt-4">
    <div class="alert alert-warning mb-0">
      Card information is temporarily unavailable because the logged-in employee ID is not available in the current session.
    </div>
  </div>
<?php else: ?>
  <div class="alert alert-warning">
    You do not have permission to view Portal Cards.
  </div>
<?php endif; ?>
