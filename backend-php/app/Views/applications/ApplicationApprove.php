<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fmt_dt_application_approval')) {
    function fmt_dt_application_approval(?string $value): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return '-';
        }
        $ts = strtotime($text);
        return $ts === false ? $text : date('d-m-Y H:i', $ts);
    }
}

$csrf = h(csrf_token());
$application = is_array($application ?? null) ? $application : [];
$data = is_array($data ?? null) ? $data : [];
$progress = is_array($progress ?? null) ? $progress : [];
$decisionErrors = is_array($decisionErrors ?? null) ? $decisionErrors : [];

$applicationId = (int)($application['ApplicationID'] ?? 0);
$status = trim((string)($application['Status'] ?? ''));
$statusKey = strtolower($status);
$canApproveAction = !empty($canApproveAction);
$isApprovalFinalised = !empty($isApprovalFinalised);
$isSelfRequest = !empty($isSelfRequest);
$supervisorDisplay = trim((string)($supervisorDisplay ?? ''));
$requestorDisplay = trim((string)($requestorDisplay ?? ''));

$statusBannerClass = 'bg-secondary text-white';
if ($statusKey === 'rejected') {
    $statusBannerClass = 'bg-danger text-white';
} elseif (in_array($statusKey, ['tobeapproved', 'awaitingapproval', 'submitted'], true)) {
    $statusBannerClass = 'bg-warning text-dark';
} elseif (in_array($statusKey, ['approved', 'senttobank', 'sent_to_bank', 'cardissued', 'card_issued'], true)) {
    $statusBannerClass = 'bg-success text-white';
}
?>

<section class="container-fluid mt-4" aria-labelledby="applicationApproveHeading">
  <div class="p-3 rounded mb-3 <?= h($statusBannerClass) ?>" role="status" aria-live="polite">
    <div class="small text-uppercase fw-semibold">Application Status</div>
    <div class="fs-4 fw-bold"><?= h($status !== '' ? $status : 'Pending') ?></div>
  </div>

  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h1 class="h3 mb-1" id="applicationApproveHeading">DPC Application Approval</h1>
      <div class="text-muted">Review the application and record your decision.</div>
      <div class="text-muted small">Application ID: <?= h((string)$applicationId) ?></div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
      <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back
    </a>
  </div>

  <div class="row g-3">
    <div class="col-lg-8">
      <section class="card shadow-sm mb-3" aria-labelledby="applicantDetailsHeading">
        <div class="card-header"><h2 class="h5 mb-0" id="applicantDetailsHeading">Applicant Details</h2></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="approvalApplicant">Applicant</label>
              <input class="form-control" id="approvalApplicant" value="<?= h($requestorDisplay !== '' ? $requestorDisplay : '-') ?>" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="approvalEmployeeId">Employee ID</label>
              <input class="form-control" id="approvalEmployeeId" value="<?= h((string)($application['EmployeeID'] ?? ($data['employee_id'] ?? '-'))) ?>" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="approvalApplicationType">Application Type</label>
              <input class="form-control" id="approvalApplicationType" value="<?= h((string)($application['ApplicationTypeName'] ?? 'DPC')) ?>" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="approvalSupervisor">Supervisor</label>
              <input class="form-control" id="approvalSupervisor" value="<?= h($supervisorDisplay !== '' ? $supervisorDisplay : '-') ?>" readonly>
            </div>
          </div>
        </div>
      </section>

      <section class="card shadow-sm mb-3" aria-labelledby="applicationSummaryHeading">
        <div class="card-header"><h2 class="h5 mb-0" id="applicationSummaryHeading">Application Summary</h2></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="approvalName">Name</label>
              <input class="form-control" id="approvalName" value="<?= h(trim((string)($data['first_name'] ?? '')) . ' ' . trim((string)($data['surname'] ?? ''))) ?>" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="approvalEmail">Email</label>
              <input class="form-control" id="approvalEmail" value="<?= h((string)($data['email'] ?? '')) ?>" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="approvalMobile">Mobile</label>
              <input class="form-control" id="approvalMobile" value="<?= h((string)($data['mobile'] ?? '')) ?>" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="approvalCompanyCostCentre">Company / Cost Centre</label>
              <input class="form-control" id="approvalCompanyCostCentre" value="<?= h(trim((string)($data['company'] ?? '')) . ' / ' . trim((string)($data['cost_centre'] ?? ''))) ?>" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="approvalCmsUserId">CMS User ID</label>
              <input class="form-control" id="approvalCmsUserId" value="<?= h((string)($data['cms_account_holder'] ?? '')) ?>" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="approvalBranding">Branding</label>
              <input class="form-control" id="approvalBranding" value="<?= h((string)($data['branding'] ?? '-')) ?>" readonly>
            </div>
            <div class="col-12">
              <label class="form-label" for="approvalPostalAddress">Postal Address</label>
              <textarea class="form-control" id="approvalPostalAddress" rows="3" readonly><?= h(trim(implode("\n", array_filter([
                (string)($data['address1'] ?? ''),
                (string)($data['address2'] ?? ''),
                (string)($data['address3'] ?? ''),
                trim((string)($data['suburb'] ?? '') . ' ' . (string)($data['state'] ?? '') . ' ' . (string)($data['postcode'] ?? '')),
              ], static fn($v): bool => trim((string)$v) !== '')))) ?></textarea>
            </div>
          </div>
        </div>
      </section>

      <section class="card shadow-sm mb-3" aria-labelledby="decisionHeading">
        <div class="card-header"><h2 class="h5 mb-0" id="decisionHeading">Decision</h2></div>
        <div class="card-body">
          <?php if (!$canApproveAction): ?>
            <div class="alert alert-warning py-2" role="alert">
              <?= $isApprovalFinalised
                ? 'This application has already been approved and can no longer be actioned.'
                : ($isSelfRequest
                ? 'You cannot approve your own application.'
                : 'You are not the assigned supervisor for this application.') ?>
            </div>
          <?php endif; ?>

          <form method="post" action="index.php?route=applications/approve-save" id="approvalForm" class="js-submit-feedback-form" aria-describedby="decisionHeading">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <input type="hidden" name="application_id" value="<?= h((string)$applicationId) ?>">
            <input type="hidden" name="decision" id="decisionField" value="">
            <input type="hidden" name="reject_reason" id="rejectReasonField" value="">

            <div class="d-flex gap-2" role="group" aria-label="Approval actions">
              <button type="button" class="btn btn-success" id="approveBtn" <?= $canApproveAction ? '' : 'disabled' ?>>Approve</button>
              <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectReasonModal" <?= $canApproveAction ? '' : 'disabled' ?>>Reject</button>
            </div>
          </form>
        </div>
      </section>
    </div>

    <div class="col-lg-4">
      <section class="card shadow-sm mb-3" aria-labelledby="timelineHeading">
        <div class="card-header"><h2 class="h5 mb-0" id="timelineHeading">Timeline</h2></div>
        <div class="card-body">
          <div class="mb-2"><span class="text-muted">Submitted At:</span> <span><?= h(fmt_dt_application_approval((string)($application['SubmittedAt'] ?? ''))) ?></span></div>
          <div class="mb-2"><span class="text-muted">Approved At:</span> <span><?= h(fmt_dt_application_approval((string)($data['approved_at'] ?? ''))) ?></span></div>
          <div class="mb-2"><span class="text-muted">Rejected At:</span> <span><?= h(fmt_dt_application_approval((string)($data['rejected_at'] ?? ''))) ?></span></div>
          <?php if (trim((string)($data['reject_reason'] ?? '')) !== ''): ?>
            <div class="mb-2"><span class="text-muted">Rejection Reason:</span> <span><?= h((string)$data['reject_reason']) ?></span></div>
          <?php endif; ?>
        </div>
      </section>

      <section class="card shadow-sm" aria-labelledby="approvalProgressHeading">
        <div class="card-header"><h2 class="h5 mb-0" id="approvalProgressHeading">Progress</h2></div>
        <div class="card-body">
          <div class="list-group list-group-flush" role="list" aria-label="Application approval progress">
            <?php foreach ($progress as $p): ?>
              <?php $active = !empty($p['IsActive']); $done = !empty($p['Complete']); ?>
              <div class="list-group-item d-flex justify-content-between align-items-center" role="listitem">
                <span><?= $done ? '<i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>' : '<i class="bi bi-circle text-muted me-2" aria-hidden="true"></i>' ?><?= h((string)($p['Label'] ?? 'Step')) ?></span>
                <?php if ($active): ?><span class="badge bg-primary">Current</span><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    </div>
  </div>
</section>

<?php require __DIR__ . '/../shared/submit_feedback.php'; ?>

<div class="modal fade" id="rejectReasonModal" tabindex="-1" aria-labelledby="rejectReasonLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="rejectReasonLabel">Reject Application</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <?php $rErr = (string)($decisionErrors['reject_reason'] ?? ''); ?>
        <label class="form-label" for="rejectReasonInput">Rejection Reason</label>
        <textarea class="form-control <?= $rErr !== '' ? 'is-invalid' : '' ?>" id="rejectReasonInput" rows="4" placeholder="Provide the reason for rejection..." aria-describedby="rejectReasonError" <?= $canApproveAction ? '' : 'disabled' ?>></textarea>
        <?php if ($rErr !== ''): ?><div class="invalid-feedback d-block" id="rejectReasonError"><?= h($rErr) ?></div><?php else: ?><div class="invalid-feedback d-block d-none" id="rejectReasonError">Rejection reason is required.</div><?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmRejectBtn" <?= $canApproveAction ? '' : 'disabled' ?>>Confirm Reject</button>
      </div>
    </div>
  </div>
</div>

<script>
  (function () {
    const canApprove = <?= $canApproveAction ? 'true' : 'false' ?>;
    const form = document.getElementById('approvalForm');
    const decisionField = document.getElementById('decisionField');
    const rejectReasonField = document.getElementById('rejectReasonField');
    const rejectReasonInput = document.getElementById('rejectReasonInput');
    const rejectReasonError = document.getElementById('rejectReasonError');
    const approveBtn = document.getElementById('approveBtn');
    const confirmRejectBtn = document.getElementById('confirmRejectBtn');

    if (!canApprove || !form || !decisionField) return;

    if (approveBtn) {
      approveBtn.addEventListener('click', function () {
        decisionField.value = 'approve';
        rejectReasonField.value = '';
        form.submit();
      });
    }

    if (confirmRejectBtn) {
      confirmRejectBtn.addEventListener('click', function () {
        const reason = rejectReasonInput ? rejectReasonInput.value.trim() : '';
        if (rejectReasonInput) {
          const isValid = reason !== '';
          rejectReasonInput.classList.toggle('is-invalid', !isValid);
          rejectReasonInput.setAttribute('aria-invalid', isValid ? 'false' : 'true');
          if (rejectReasonError) {
            rejectReasonError.classList.toggle('d-none', isValid);
          }
          if (!isValid) {
            rejectReasonInput.focus();
            return;
          }
        }
        decisionField.value = 'reject';
        rejectReasonField.value = reason;
        form.submit();
      });
    }
  })();
</script>
