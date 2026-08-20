<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$packages = is_array($packages ?? null) ? $packages : [];
$selectedPackage = is_array($selectedPackage ?? null) ? $selectedPackage : null;
$selectedPackageId = trim((string)($selectedPackageId ?? ''));
$flash = is_array($flash ?? null) ? $flash : null;
$csrf = (string)($_csrf ?? '');
$applyPasswordConfigured = !empty($applyPasswordConfigured);

$summary = is_array($selectedPackage['summary'] ?? null) ? $selectedPackage['summary'] : [];
$appliedFiles = is_array($selectedPackage['appliedFiles'] ?? null) ? $selectedPackage['appliedFiles'] : [];
$rollbackFiles = is_array($selectedPackage['rollbackFiles'] ?? null) ? $selectedPackage['rollbackFiles'] : [];
$status = (string)($selectedPackage['status'] ?? '');
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Deployment History</h3>
      <div class="text-muted">Review applied and rolled-back deployment packages, including backup paths and rollback results.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=admin/release-packages">
        <i class="bi bi-box-arrow-up me-1"></i>Release Packages
      </a>
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
        <i class="bi bi-arrow-left me-1"></i>Back
      </a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= h((string)($flash['type'] ?? 'info')) ?> alert-dismissible fade show" role="alert">
      <?= h((string)($flash['text'] ?? '')) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-xl-4">
      <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
          <strong>Deployment History</strong>
          <span class="text-muted small"><?= count($packages) ?> package(s)</span>
        </div>
        <div class="list-group list-group-flush">
          <?php if (!$packages): ?>
            <div class="list-group-item text-muted">No applied or rolled-back packages yet.</div>
          <?php else: ?>
            <?php foreach ($packages as $package): ?>
              <?php
                $packageId = (string)($package['packageId'] ?? '');
                $packageName = (string)($package['originalName'] ?? $packageId);
                $active = $packageId === $selectedPackageId;
                $packageStatus = (string)($package['status'] ?? '');
                $statusClass = $packageStatus === 'rolled_back' ? 'bg-secondary' : 'bg-primary';
              ?>
              <a class="list-group-item list-group-item-action <?= $active ? 'active' : '' ?>" href="index.php?route=admin/release-package-history&package=<?= urlencode($packageId) ?>">
                <div class="d-flex justify-content-between align-items-start">
                  <div class="me-3">
                    <div class="fw-semibold"><?= h($packageName) ?></div>
                    <div class="<?= $active ? 'text-white-50' : 'text-muted' ?> small">
                      Uploaded <?= h((string)($package['uploadedAt'] ?? '')) ?> by <?= h((string)($package['uploadedByUsername'] ?? 'unknown')) ?>
                    </div>
                    <?php if (!empty($package['appliedAt'])): ?>
                      <div class="<?= $active ? 'text-white-50' : 'text-muted' ?> small">
                        Applied <?= h((string)$package['appliedAt']) ?> by <?= h((string)($package['appliedByUsername'] ?? 'unknown')) ?>
                      </div>
                    <?php endif; ?>
                    <?php if (!empty($package['rolledBackAt'])): ?>
                      <div class="<?= $active ? 'text-white-50' : 'text-muted' ?> small">
                        Rolled back <?= h((string)$package['rolledBackAt']) ?> by <?= h((string)($package['rolledBackByUsername'] ?? 'unknown')) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                  <span class="badge <?= $statusClass ?>"><?= h(str_replace('_', ' ', ucfirst($packageStatus))) ?></span>
                </div>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-xl-8">
      <div class="card shadow-sm">
        <div class="card-header">
          <strong>History Detail</strong>
        </div>
        <div class="card-body">
          <?php if (!$selectedPackage): ?>
            <div class="text-muted">Choose a deployment from the history list to view its details.</div>
          <?php else: ?>
            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <div class="small text-muted">Package</div>
                <div class="fw-semibold"><?= h((string)($selectedPackage['originalName'] ?? '')) ?></div>
              </div>
              <div class="col-md-3">
                <div class="small text-muted">Status</div>
                <div><?= h($status) ?></div>
              </div>
              <div class="col-md-3">
                <div class="small text-muted">Uploaded By</div>
                <div><?= h((string)($selectedPackage['uploadedByUsername'] ?? 'unknown')) ?></div>
              </div>
              <div class="col-md-3">
                <div class="small text-muted">Applied At</div>
                <div><?= h((string)($selectedPackage['appliedAt'] ?? '')) ?></div>
              </div>
              <div class="col-md-3">
                <div class="small text-muted">Applied By</div>
                <div><?= h((string)($selectedPackage['appliedByUsername'] ?? '')) ?></div>
              </div>
              <div class="col-md-3">
                <div class="small text-muted">Rolled Back At</div>
                <div><?= h((string)($selectedPackage['rolledBackAt'] ?? '')) ?></div>
              </div>
              <div class="col-md-3">
                <div class="small text-muted">Rolled Back By</div>
                <div><?= h((string)($selectedPackage['rolledBackByUsername'] ?? '')) ?></div>
              </div>
              <div class="col-md-3">
                <div class="small text-muted">Deploy Files</div>
                <div><?= (int)($summary['deployCount'] ?? 0) ?></div>
              </div>
              <div class="col-md-9">
                <div class="small text-muted">Backup Path</div>
                <div class="font-monospace small"><?= h((string)($selectedPackage['backupPath'] ?? '')) ?></div>
              </div>
            </div>

            <?php if ($appliedFiles): ?>
              <div class="card border-success-subtle mb-3">
                <div class="card-header bg-success-subtle">
                  <strong>Applied Files</strong>
                </div>
                <div class="card-body">
                  <ul class="mb-0 small">
                    <?php foreach ($appliedFiles as $appliedFile): ?>
                      <li class="font-monospace">
                        <?= h((string)($appliedFile['relativePath'] ?? '')) ?>
                        <span class="text-muted">(<?= !empty($appliedFile['backedUp']) ? 'backup created' : 'new file' ?>)</span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </div>
            <?php endif; ?>

            <?php if ($rollbackFiles): ?>
              <div class="card border-secondary-subtle mb-3">
                <div class="card-header bg-secondary-subtle">
                  <strong>Rollback Result</strong>
                </div>
                <div class="card-body">
                  <ul class="mb-0 small">
                    <?php foreach ($rollbackFiles as $rollbackFile): ?>
                      <li class="font-monospace">
                        <?= h((string)($rollbackFile['relativePath'] ?? '')) ?>
                        <span class="text-muted">(<?= h((string)($rollbackFile['action'] ?? 'restored')) ?>)</span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </div>
            <?php endif; ?>

            <?php if ($status === 'applied' && !empty($selectedPackage['backupPath'])): ?>
              <form method="post" action="index.php?route=admin/release-packages-rollback" class="mt-3 release-confirm-form" data-confirm-title="Roll Back Deployment" data-confirm-message="Roll back this deployment now?">
                <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="package_id" value="<?= h($selectedPackageId) ?>">
                <div class="mb-3" style="max-width: 420px;">
                  <label class="form-label" for="rollbackPassword">Rollback Password</label>
                  <input type="password" class="form-control" id="rollbackPassword" name="apply_password" autocomplete="off" <?= $applyPasswordConfigured ? 'required' : 'disabled' ?>>
                  <div class="form-text">Rollback uses the same extra deployment password as Apply.</div>
                </div>
                <button type="submit" class="btn btn-outline-warning" <?= $applyPasswordConfigured ? '' : 'disabled' ?>>
                  <i class="bi bi-arrow-counterclockwise me-1"></i>Roll Back Deployment
                </button>
                <?php if (!$applyPasswordConfigured): ?>
                  <div class="form-text text-danger mt-2">Set the release package apply password in the environment before rollback can be used.</div>
                <?php endif; ?>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="releaseHistoryConfirmModal" tabindex="-1" aria-labelledby="releaseHistoryConfirmModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="releaseHistoryConfirmModalLabel">Confirm Action</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="releaseHistoryConfirmModalMessage">
        Are you sure you want to continue?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-warning" id="releaseHistoryConfirmModalSubmit">Continue</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('releaseHistoryConfirmModal');
  if (!modalEl || typeof bootstrap === 'undefined') {
    return;
  }

  const modal = new bootstrap.Modal(modalEl);
  const titleEl = document.getElementById('releaseHistoryConfirmModalLabel');
  const messageEl = document.getElementById('releaseHistoryConfirmModalMessage');
  const submitBtn = document.getElementById('releaseHistoryConfirmModalSubmit');
  let pendingForm = null;

  document.querySelectorAll('.release-confirm-form').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (form.dataset.confirmed === 'true') {
        form.dataset.confirmed = 'false';
        return;
      }

      event.preventDefault();
      pendingForm = form;
      titleEl.textContent = form.dataset.confirmTitle || 'Confirm Action';
      messageEl.textContent = form.dataset.confirmMessage || 'Are you sure you want to continue?';
      modal.show();
    });
  });

  submitBtn.addEventListener('click', () => {
    if (!pendingForm) {
      return;
    }
    pendingForm.dataset.confirmed = 'true';
    modal.hide();
    pendingForm.requestSubmit ? pendingForm.requestSubmit() : pendingForm.submit();
  });

  modalEl.addEventListener('hidden.bs.modal', () => {
    pendingForm = null;
  });
});
</script>
