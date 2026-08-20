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
$environmentStatus = is_array($environmentStatus ?? null) ? $environmentStatus : [];
$applyPasswordConfigured = !empty($applyPasswordConfigured);

$summary = is_array($selectedPackage['summary'] ?? null) ? $selectedPackage['summary'] : [];
$files = is_array($selectedPackage['files'] ?? null) ? $selectedPackage['files'] : [];
$manifestText = trim((string)($selectedPackage['manifestText'] ?? ''));
$applyReady = !empty($selectedPackage['applyReady']);
$status = (string)($selectedPackage['status'] ?? 'staged');
$environmentFolders = is_array($environmentStatus['folders'] ?? null) ? $environmentStatus['folders'] : [];
$hasStagedPackage = $selectedPackage !== null;

if (!function_exists('release_access_badge')) {
    function release_access_badge(bool $state, string $trueLabel, string $falseLabel, string $trueClass = 'success', string $falseClass = 'secondary'): string
    {
        $label = $state ? $trueLabel : $falseLabel;
        $class = $state ? $trueClass : $falseClass;
        return '<span class="badge bg-' . h($class) . '">' . h($label) . '</span>';
    }
}
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Release Packages</h3>
      <div class="text-muted">Manage the single staged deployment package that is waiting to be applied.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=admin/release-package-history">
        <i class="bi bi-clock-history me-1"></i>Deployment History
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

  <div class="alert alert-warning">
    <div class="fw-semibold mb-1">Deployment safety notes</div>
    <div class="small">
      This queue only allows one staged package at a time. Apply it or delete it before uploading the next package. The deployment history is kept separately.
    </div>
  </div>

  <div class="row g-4">
    <div class="col-xl-4">
      <div class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <strong>Upload Package</strong>
          <span class="badge <?= $hasStagedPackage ? 'bg-warning text-dark' : 'bg-success' ?>">
            <?= $hasStagedPackage ? 'Queue Full' : 'Ready' ?>
          </span>
        </div>
        <div class="card-body">
          <form method="post" action="index.php?route=admin/release-packages-upload" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
            <div class="mb-3">
              <label for="packageFile" class="form-label">Release ZIP</label>
              <input type="file" class="form-control" id="packageFile" name="packageFile" accept=".zip" <?= $hasStagedPackage ? 'disabled' : 'required' ?>>
              <div class="form-text">Upload a ZIP made from one of the transfer folders. The ZIP should contain `backend-php/...` files in the same structure they will be deployed to.</div>
            </div>
            <button type="submit" class="btn btn-primary" <?= $hasStagedPackage ? 'disabled' : '' ?>>
              <i class="bi bi-upload me-1"></i>Upload And Stage
            </button>
            <?php if ($hasStagedPackage): ?>
              <div class="form-text text-danger mt-2">A staged package already exists. Apply or delete it before uploading another package.</div>
            <?php endif; ?>
          </form>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-header">
          <strong>Current Staged Package</strong>
        </div>
        <div class="card-body">
          <?php if (!$selectedPackage): ?>
            <div class="text-muted">No package is currently staged.</div>
          <?php else: ?>
            <div class="fw-semibold"><?= h((string)($selectedPackage['originalName'] ?? '')) ?></div>
            <div class="text-muted small mt-1">
              Uploaded <?= h((string)($selectedPackage['uploadedAt'] ?? '')) ?> by <?= h((string)($selectedPackage['uploadedByUsername'] ?? 'unknown')) ?>
            </div>
            <div class="small mt-3">
              <div>Deploy files: <?= (int)($summary['deployCount'] ?? 0) ?></div>
              <div>Ignored files: <?= (int)($summary['ignoredCount'] ?? 0) ?></div>
              <div>Rejected files: <?= (int)($summary['rejectedCount'] ?? 0) ?></div>
            </div>
            <div class="mt-3 d-flex gap-2">
              <span class="badge <?= $applyReady ? 'bg-success' : 'bg-warning text-dark' ?>">
                <?= $applyReady ? 'Ready To Apply' : 'Needs Review' ?>
              </span>
              <span class="badge bg-secondary"><?= h($status) ?></span>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-xl-8">
      <div class="card shadow-sm mb-4">
        <div class="card-header">
          <strong>Deployment Environment</strong>
        </div>
        <div class="card-body">
          <div class="small text-muted mb-3">
            This section shows the effective access the web app currently has to the key deployment folders. It is based on what PHP can see, so it is a practical deployment check for the IIS app pool identity.
          </div>

          <div class="mb-3">
            <span class="me-2 fw-semibold">Apply Password Configured</span>
            <?= release_access_badge($applyPasswordConfigured, 'Yes', 'No', 'success', 'danger') ?>
            <div class="form-text">Set either <code>RELEASE_PACKAGE_APPLY_PASSWORD</code> or <code>RELEASE_PACKAGE_APPLY_PASSWORD_HASH</code> in the server environment or <code>.env</code>.</div>
          </div>

          <?php foreach ($environmentFolders as $group): ?>
            <?php $children = is_array($group['children'] ?? null) ? $group['children'] : []; ?>
            <div class="border rounded p-3 mb-3">
              <div class="fw-semibold"><?= h((string)($group['label'] ?? 'Folder Group')) ?></div>
              <div class="font-monospace small mt-1"><?= h((string)($group['path'] ?? '')) ?></div>
              <?php if (!empty($group['notes'])): ?>
                <div class="text-muted small mt-1"><?= h((string)$group['notes']) ?></div>
              <?php endif; ?>
              <div class="table-responsive mt-3">
                <table class="table table-sm align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Folder</th>
                      <th>Path</th>
                      <th>Exists</th>
                      <th>Readable</th>
                      <th>Writable</th>
                      <th>Notes</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($children as $folder): ?>
                      <tr>
                        <td class="fw-semibold"><?= h((string)($folder['label'] ?? '')) ?></td>
                        <td class="font-monospace small"><?= h((string)($folder['path'] ?? '')) ?></td>
                        <td><?= release_access_badge(!empty($folder['exists']), 'Yes', 'No', 'success', 'danger') ?></td>
                        <td><?= release_access_badge(!empty($folder['readable']), 'Yes', 'No', 'success', 'danger') ?></td>
                        <td><?= release_access_badge(!empty($folder['writable']), 'Yes', 'No', 'success', 'danger') ?></td>
                        <td class="small text-muted"><?= h((string)($folder['notes'] ?? '')) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
          <strong>Package Review</strong>
          <?php if ($selectedPackage): ?>
            <form method="post" action="index.php?route=admin/release-packages-delete" class="m-0 release-confirm-form" data-confirm-title="Delete Staged Package" data-confirm-message="Delete this staged package?">
              <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="package_id" value="<?= h($selectedPackageId) ?>">
              <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-trash me-1"></i>Delete Staged Package
              </button>
            </form>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if (!$selectedPackage): ?>
            <div class="text-muted">Upload a package to review it here.</div>
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
                <div class="small text-muted">Deploy Files</div>
                <div><?= (int)($summary['deployCount'] ?? 0) ?></div>
              </div>
              <div class="col-md-3">
                <div class="small text-muted">Ignored Files</div>
                <div><?= (int)($summary['ignoredCount'] ?? 0) ?></div>
              </div>
              <div class="col-md-3">
                <div class="small text-muted">Rejected Files</div>
                <div><?= (int)($summary['rejectedCount'] ?? 0) ?></div>
              </div>
              <div class="col-md-3">
                <div class="small text-muted">Total Size</div>
                <div><?= number_format(((int)($summary['totalBytes'] ?? 0)) / 1024, 1) ?> KB</div>
              </div>
            </div>

            <?php if ($manifestText !== ''): ?>
              <div class="mb-3">
                <label class="form-label">Manifest</label>
                <textarea class="form-control font-monospace" rows="5" readonly><?= h($manifestText) ?></textarea>
              </div>
            <?php endif; ?>

            <div class="table-responsive mb-3">
              <table class="table table-sm table-striped align-middle">
                <thead>
                  <tr>
                    <th>Status</th>
                    <th>Path</th>
                    <th>Reason / Lint</th>
                    <th class="text-end">Size</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$files): ?>
                    <tr><td colspan="4" class="text-center text-muted">No files were detected in this package.</td></tr>
                  <?php else: ?>
                    <?php foreach ($files as $file): ?>
                      <?php
                        $fileStatus = (string)($file['status'] ?? '');
                        $lint = is_array($file['lint'] ?? null) ? $file['lint'] : null;
                        $badgeClass = $fileStatus === 'deploy'
                          ? (($lint['status'] ?? '') === 'error' ? 'bg-danger' : 'bg-success')
                          : ($fileStatus === 'ignored' ? 'bg-secondary' : 'bg-warning text-dark');
                        $statusLabel = $fileStatus === 'deploy'
                          ? (($lint['status'] ?? '') === 'error' ? 'Lint Error' : 'Deploy')
                          : ucfirst($fileStatus);
                        $reason = trim((string)($file['reason'] ?? ''));
                        $lintMessage = trim((string)($lint['message'] ?? ''));
                      ?>
                      <tr>
                        <td><span class="badge <?= $badgeClass ?>"><?= h($statusLabel) ?></span></td>
                        <td class="font-monospace small"><?= h((string)($file['relativePath'] ?: $file['entryName'] ?? '')) ?></td>
                        <td class="small">
                          <?php if ($reason !== ''): ?>
                            <div><?= h($reason) ?></div>
                          <?php endif; ?>
                          <?php if ($lintMessage !== ''): ?>
                            <div class="text-muted"><?= h($lintMessage) ?></div>
                          <?php endif; ?>
                        </td>
                        <td class="text-end small"><?= number_format(((int)($file['size'] ?? 0)) / 1024, 1) ?> KB</td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <form method="post" action="index.php?route=admin/release-packages-apply" class="release-confirm-form" data-confirm-title="Apply Release Package" data-confirm-message="Apply this release package to the production folders now?">
              <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="package_id" value="<?= h($selectedPackageId) ?>">
              <div class="mb-3" style="max-width: 420px;">
                <label class="form-label" for="applyPassword">Apply Password</label>
                <input type="password" class="form-control" id="applyPassword" name="apply_password" autocomplete="off" <?= $applyReady && $applyPasswordConfigured ? 'required' : 'disabled' ?>>
                <div class="form-text">This extra password is required before a package can be applied.</div>
              </div>
              <button type="submit" class="btn btn-danger" <?= $applyReady && $applyPasswordConfigured ? '' : 'disabled' ?>>
                <i class="bi bi-box-arrow-in-down me-1"></i>Apply Release Package
              </button>
              <?php if (!$applyReady): ?>
                <div class="form-text text-danger mt-2">This package cannot be applied until rejected files and PHP lint errors are resolved.</div>
              <?php elseif (!$applyPasswordConfigured): ?>
                <div class="form-text text-danger mt-2">Set the release package apply password in the environment before this button can be used.</div>
              <?php endif; ?>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="releaseConfirmModal" tabindex="-1" aria-labelledby="releaseConfirmModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="releaseConfirmModalLabel">Confirm Action</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="releaseConfirmModalMessage">
        Are you sure you want to continue?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="releaseConfirmModalSubmit">Continue</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('releaseConfirmModal');
  if (!modalEl || typeof bootstrap === 'undefined') {
    return;
  }

  const modal = new bootstrap.Modal(modalEl);
  const titleEl = document.getElementById('releaseConfirmModalLabel');
  const messageEl = document.getElementById('releaseConfirmModalMessage');
  const submitBtn = document.getElementById('releaseConfirmModalSubmit');
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
