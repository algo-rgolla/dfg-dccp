<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fmt_dt_admin')) {
    function fmt_dt_admin(?string $v): string
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
$search = trim((string)($search ?? ''));
$statusFilter = trim((string)($statusFilter ?? ''));
$csrf = h(csrf_token());
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Applications</h3>
      <div class="text-muted">Administrative list of applications across the portal. Search and open a read-only view of any application.</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
      <i class="bi bi-arrow-left me-1"></i>Back
    </a>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Search</strong>
      <span class="text-muted small">Up to 250 results</span>
    </div>
    <div class="card-body">
      <form method="get" action="index.php" class="row g-3 align-items-end">
        <input type="hidden" name="route" value="admin/applications">
        <div class="col-md-6">
          <label class="form-label">Search</label>
          <input class="form-control" name="q" value="<?= h($search) ?>" placeholder="Application ID, EmployeeID, username, name, or application type">
        </div>
        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select class="form-select" name="status">
            <option value="">All statuses</option>
            <?php foreach (['Draft', 'InProgress', 'Submitted', 'Approved', 'Rejected', 'SentToBank', 'CardIssued', 'Cancelled'] as $status): ?>
              <option value="<?= h($status) ?>" <?= strcasecmp($statusFilter, $status) === 0 ? 'selected' : '' ?>><?= h($status) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <button type="submit" class="btn btn-primary">Search</button>
          <a class="btn btn-outline-secondary" href="index.php?route=admin/applications">Clear</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Application Results</strong>
      <span class="text-muted small"><?= count($rows) ?> result<?= count($rows) === 1 ? '' : 's' ?></span>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>Application</th>
              <th>Applicant</th>
              <th>Submitter</th>
              <th>Type</th>
              <th>Status</th>
              <th>Current Step</th>
              <th>Dates</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="8" class="text-center text-muted">No applications found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <?php
                  $typeKey = strtolower(trim((string)($row['ApplicationTypeKey'] ?? '')));
                  $typeName = strtolower(trim((string)($row['ApplicationTypeName'] ?? '')));
                  $typeId = (int)($row['ApplicationTypeID'] ?? 0);
                  $isLimitChange = str_contains($typeKey, 'limit_change')
                    || str_contains($typeName, 'limit change')
                    || in_array($typeId, [5, 6, 9], true);
                  $openFormHref = $isLimitChange
                    ? 'index.php?route=cards/request-limit-change&application_id=' . urlencode((string)($row['ApplicationID'] ?? 0)) . '&admin=1'
                    : 'index.php?route=applications/edit&id=' . urlencode((string)($row['ApplicationID'] ?? 0)) . '&admin_edit=1';
                ?>
                <tr>
                  <td>
                    <div><strong>#<?= h((string)($row['ApplicationID'] ?? 0)) ?></strong></div>
                    <div class="small text-muted">UserID: <?= h((string)($row['UserID'] ?? 0)) ?></div>
                    <div class="small text-muted">EmployeeID: <?= h((string)($row['EmployeeID'] ?? '')) ?></div>
                  </td>
                  <td>
                    <div><?= h((string)($row['ApplicantName'] ?? '')) ?: '-' ?></div>
                    <div class="small text-muted">EmployeeID: <?= h((string)($row['ApplicantEmployeeID'] ?? '')) ?></div>
                    <?php if (trim((string)($row['ApplicantEmail'] ?? '')) !== ''): ?>
                      <div class="small text-muted"><?= h((string)($row['ApplicantEmail'] ?? '')) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($row['IsOnBehalf'])): ?>
                      <div class="small text-muted">Submitted on behalf</div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div><?= h((string)($row['SubmitterName'] ?? '')) ?: '-' ?></div>
                    <div class="small text-muted"><?= h((string)($row['Username'] ?? '')) ?></div>
                    <div class="small text-muted">UserID: <?= h((string)($row['UserID'] ?? 0)) ?></div>
                    <div class="small text-muted">EmployeeID: <?= h((string)($row['EmployeeID'] ?? '')) ?></div>
                    <?php if (trim((string)($row['SubmitterEmail'] ?? '')) !== ''): ?>
                      <div class="small text-muted"><?= h((string)($row['SubmitterEmail'] ?? '')) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div><?= h((string)($row['ApplicationTypeName'] ?? '')) ?></div>
                    <div class="small text-muted"><?= h((string)($row['ApplicationTypeKey'] ?? '')) ?></div>
                  </td>
                  <td>
                    <span class="badge <?= !empty($row['Locked']) ? 'bg-warning text-dark' : 'bg-secondary' ?>">
                      <?= h((string)($row['Status'] ?? '-')) ?>
                    </span>
                    <?php if (!empty($row['Locked'])): ?>
                      <div class="small text-muted">Locked</div>
                    <?php endif; ?>
                  </td>
                  <td><?= h((string)($row['CurrentStepKey'] ?? '-')) ?></td>
                  <td>
                    <div>Started: <?= h(fmt_dt_admin((string)($row['StartedAt'] ?? ''))) ?></div>
                    <div class="small text-muted">Saved: <?= h(fmt_dt_admin((string)($row['LastSavedAt'] ?? ''))) ?></div>
                  </td>
                  <td class="text-end">
                    <div class="btn-group btn-group-sm">
                      <a class="btn btn-outline-primary" href="index.php?route=admin/applications-view&id=<?= urlencode((string)($row['ApplicationID'] ?? 0)) ?>">View</a>
                      <a class="btn btn-outline-secondary" href="<?= h($openFormHref) ?>">Edit Form</a>
                      <form method="post" action="index.php?route=admin/applications-delete" class="d-inline" onsubmit="return confirm('Delete this application? This admin action can delete applications in any status.');">
                        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="application_id" value="<?= h((string)($row['ApplicationID'] ?? 0)) ?>">
                        <button type="submit" class="btn btn-outline-danger">Delete</button>
                      </form>
                      <button
                        type="button"
                        class="btn btn-outline-warning quick-reopen-btn"
                        data-application-id="<?= h((string)($row['ApplicationID'] ?? 0)) ?>"
                        data-current-status="<?= h((string)($row['Status'] ?? '')) ?>"
                        data-app-label="#<?= h((string)($row['ApplicationID'] ?? 0)) ?>"
                        data-bs-toggle="modal"
                        data-bs-target="#quickReopenModal"
                      >Quick Status</button>
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

<div class="modal fade" id="quickReopenModal" tabindex="-1" aria-labelledby="quickReopenModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="quickReopenModalLabel">Quick Application Status Update</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="post" action="index.php?route=admin/applications-status" id="quickReopenForm">
          <input type="hidden" name="_csrf" value="<?= $csrf ?>">
          <input type="hidden" name="application_id" id="quickReopenApplicationId" value="">
          <div class="mb-3">
            <label class="form-label">Application</label>
            <input class="form-control" id="quickReopenApplicationLabel" value="" disabled>
          </div>
          <div class="mb-3">
            <label class="form-label">Current Status</label>
            <input class="form-control" id="quickReopenCurrentStatus" value="" disabled>
          </div>
          <div class="mb-3">
            <label class="form-label">Set Status To</label>
            <select class="form-select" name="target_status" id="quickReopenTargetStatus">
              <option value="Draft">Draft</option>
              <option value="InProgress">InProgress</option>
              <option value="CardIssued">CardIssued</option>
            </select>
          </div>
          <div class="mb-0">
            <label class="form-label">Reason</label>
            <input class="form-control" name="status_reason" id="quickReopenReason" maxlength="250" required placeholder="Explain why the application status is being updated">
          </div>
        </form>
        <div class="small text-muted mt-3">
          Use Draft or InProgress to reopen for applicant editing. Use CardIssued after the bank has processed the application so the user sees it as completed.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-warning" form="quickReopenForm">Update Status</button>
      </div>
    </div>
  </div>
</div>

<script>
  (function () {
    const modal = document.getElementById('quickReopenModal');
    const applicationIdInput = document.getElementById('quickReopenApplicationId');
    const appLabel = document.getElementById('quickReopenApplicationLabel');
    const currentStatus = document.getElementById('quickReopenCurrentStatus');
    const targetStatus = document.getElementById('quickReopenTargetStatus');
    const reason = document.getElementById('quickReopenReason');
    if (!modal) return;

    document.querySelectorAll('.quick-reopen-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (applicationIdInput) applicationIdInput.value = btn.getAttribute('data-application-id') || '';
        if (appLabel) appLabel.value = btn.getAttribute('data-app-label') || '';
        if (currentStatus) currentStatus.value = btn.getAttribute('data-current-status') || '';
        if (targetStatus) {
          const current = (btn.getAttribute('data-current-status') || '').toLowerCase();
          targetStatus.value = current === 'draft'
            ? 'Draft'
            : (current === 'cardissued' || current === 'card_issued' ? 'CardIssued' : 'InProgress');
        }
        if (reason) {
          reason.value = '';
          reason.classList.remove('is-invalid');
        }
      });
    });

    const form = document.getElementById('quickReopenForm');
    if (form && reason) {
      form.addEventListener('submit', function (e) {
        if (reason.value.trim() === '') {
          e.preventDefault();
          reason.classList.add('is-invalid');
          reason.focus();
        } else {
          reason.classList.remove('is-invalid');
        }
      });
    }
  })();
</script>
