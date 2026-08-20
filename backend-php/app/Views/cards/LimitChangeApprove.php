<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fmt_money')) {
    function fmt_money($v): string {
        $s = trim((string)$v);
        if ($s === '' || !is_numeric(str_replace([',', '$', ' '], '', $s))) {
            return '-';
        }
        $n = (float)str_replace([',', '$', ' '], '', $s);
        return '$' . number_format($n, 0);
    }
}
if (!function_exists('fmt_dt_approval')) {
    function fmt_dt_approval(?string $v): string {
        $s = trim((string)$v);
        if ($s === '') {
            return '-';
        }
        try {
            $utc = new DateTimeZone('UTC');
            $localTz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $s, $utc);
            if (!$dt) {
                $ts = strtotime($s);
                return $ts === false ? $s : date('d-m-Y H:i', $ts);
            }
            return $dt->setTimezone($localTz)->format('d-m-Y H:i');
        } catch (Throwable $e) {
            $ts = strtotime($s);
            return $ts === false ? $s : date('d-m-Y H:i', $ts);
        }
    }
}

$csrf = h(csrf_token());
$application = is_array($application ?? null) ? $application : [];
$card = is_array($card ?? null) ? $card : [];
$data = is_array($data ?? null) ? $data : [];
$progress = is_array($progress ?? null) ? $progress : [];
$decisionErrors = is_array($decisionErrors ?? null) ? $decisionErrors : [];
$approverDisplay = (string)($approverDisplay ?? '');
$forwardOptions = is_array($forwardOptions ?? null) ? $forwardOptions : [];
$previousApprovals = is_array($previousApprovals ?? null) ? $previousApprovals : [];
$approvalRequiresSesConfirmation = (bool)($approvalRequiresSesConfirmation ?? true);
$manualApproverNeedsConfirmation = (bool)($manualApproverNeedsConfirmation ?? false);

$applicationId = (int)($application['ApplicationID'] ?? 0);
$applicationTypeKey = strtolower(trim((string)($application['ApplicationTypeKey'] ?? '')));
$status = (string)($application['Status'] ?? '');
$statusKey = strtolower(trim($status));
$canApproveAction = (bool)($canApproveAction ?? false);
$isSelfRequest = (bool)($isSelfRequest ?? false);
$isActionLocked = in_array($statusKey, ['approved', 'rejected', 'senttobank', 'sent_to_bank', 'limitchanged', 'limit_changed'], true);
$actionsDisabled = $isActionLocked || !$canApproveAction;
$requestId = (string)$applicationId;

$statusLabel = $status !== '' ? $status : 'Pending';
$statusBannerClass = 'bg-secondary text-white';
if (in_array($statusKey, ['approved', 'limitchanged', 'limit_changed'], true)) {
    $statusBannerClass = 'bg-success text-white';
} elseif ($statusKey === 'rejected') {
    $statusBannerClass = 'bg-danger text-white';
} elseif (in_array($statusKey, ['tobeapproved', 'awaitingapproval', 'submitted'], true)) {
    $statusBannerClass = 'bg-warning text-dark';
} elseif (in_array($statusKey, ['senttobank', 'sent_to_bank'], true)) {
    $statusBannerClass = 'bg-primary text-white';
}

$cardNumberRaw = (string)($card['CardNumber'] ?? ($data['card_number'] ?? ''));
$cardNumberDigits = preg_replace('/\D+/', '', $cardNumberRaw) ?? '';
$cardNumberTail = $cardNumberDigits !== '' ? substr($cardNumberDigits, -4) : substr($cardNumberRaw, -4);
$cardNumberMasked = $cardNumberTail !== '' ? ('************' . $cardNumberTail) : '';
$cardTypeSub = trim((string)($card['CardTypeSub'] ?? ($data['card_type_sub'] ?? '')));
$firstName = trim((string)($card['FirstName'] ?? ($card['FName'] ?? ($data['first_name'] ?? ''))));
$lastName = trim((string)($card['Surname'] ?? ($card['LastName'] ?? ($card['LName'] ?? ($data['last_name'] ?? '')))));

$submittedAt = fmt_dt_approval((string)($application['SubmittedAt'] ?? ''));

$creditCurrent = (string)($card['CreditLimitAmount'] ?? ($data['credit_limit_current'] ?? ''));
$creditRequested = (string)($data['credit_limit_new'] ?? '');
$txnCurrent = (string)($card['TransactionLimit'] ?? ($data['transaction_limit_current'] ?? ''));
$txnRequested = (string)($data['transaction_limit_new_amount'] ?? ($data['transaction_limit_new'] ?? ''));
$reason = (string)($data['limit_change_reason'] ?? '');
$reasonOther = (string)($data['limit_change_reason_other'] ?? '');
$durationType = strtolower(trim((string)($data['limit_change_duration_type'] ?? 'permanent')));
$periodFrom = trim((string)($data['period_change_from'] ?? ''));
$periodTo = trim((string)($data['period_change_to'] ?? ''));
$limitChangeScope = strtolower(trim((string)($data['limit_change_scope'] ?? 'both')));
$creditDurationType = strtolower(trim((string)($data['credit_limit_change_duration_type'] ?? $durationType)));
$creditPeriodFrom = trim((string)($data['credit_period_change_from'] ?? $periodFrom));
$creditPeriodTo = trim((string)($data['credit_period_change_to'] ?? $periodTo));
$transactionDurationType = strtolower(trim((string)($data['transaction_limit_change_duration_type'] ?? 'permanent')));
$transactionPeriodFrom = trim((string)($data['transaction_period_change_from'] ?? ''));
$transactionPeriodTo = trim((string)($data['transaction_period_change_to'] ?? ''));
$selectedApprover = (string)($data['approver'] ?? '');
$selectedApproverEmail = trim((string)($data['approver_email'] ?? ''));
if ($selectedApprover === '' && $selectedApproverEmail !== '') {
    $selectedApprover = $selectedApproverEmail;
}
$forwardedTo = (string)($data['forward_to'] ?? '');
$rejectReasonSaved = (string)($data['reject_reason'] ?? '');
$approvedAt = (string)($data['approved_at'] ?? '');
$rejectedAt = (string)($data['rejected_at'] ?? '');
$forwardedAt = (string)($data['forwarded_at'] ?? '');
$currentApprovalStage = max(1, (int)($currentApprovalStage ?? 1));
$totalApprovalStages = max(1, (int)($totalApprovalStages ?? 1));
if ($periodFrom !== '' && ($tsPeriodFrom = strtotime($periodFrom)) !== false) {
    $periodFrom = date('d-m-Y', $tsPeriodFrom);
}
if ($periodTo !== '' && ($tsPeriodTo = strtotime($periodTo)) !== false) {
    $periodTo = date('d-m-Y', $tsPeriodTo);
}
if ($creditPeriodFrom !== '' && ($tsCreditPeriodFrom = strtotime($creditPeriodFrom)) !== false) {
    $creditPeriodFrom = date('d-m-Y', $tsCreditPeriodFrom);
}
if ($creditPeriodTo !== '' && ($tsCreditPeriodTo = strtotime($creditPeriodTo)) !== false) {
    $creditPeriodTo = date('d-m-Y', $tsCreditPeriodTo);
}
if ($transactionPeriodFrom !== '' && ($tsTxnPeriodFrom = strtotime($transactionPeriodFrom)) !== false) {
    $transactionPeriodFrom = date('d-m-Y', $tsTxnPeriodFrom);
}
if ($transactionPeriodTo !== '' && ($tsTxnPeriodTo = strtotime($transactionPeriodTo)) !== false) {
    $transactionPeriodTo = date('d-m-Y', $tsTxnPeriodTo);
}

$requestedBy = trim((string)($application['Username'] ?? ''));
$requesterEmail = trim((string)($application['Email'] ?? ''));
?>
<style>
  .readonly-field {
    background-color: #f8f9fa;
    color: #495057;
    cursor: not-allowed;
  }

  .approver-policy-warning {
    color: #b35a00;
  }
</style>

<section class="container-fluid mt-4" aria-labelledby="limitChangeApproveHeading">
  <div class="p-3 rounded mb-3 <?= h($statusBannerClass) ?>" role="status" aria-live="polite">
    <div class="small text-uppercase fw-semibold">Application Status</div>
    <div class="fs-4 fw-bold"><?= h($statusLabel) ?></div>
  </div>

  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h1 class="h3 mb-1" id="limitChangeApproveHeading">Limit Change Approval</h1>
      <?php if ($cardTypeSub !== ''): ?>
        <div class="fw-semibold"><?= h($cardTypeSub) ?> Limit Change</div>
      <?php endif; ?>
      <div class="text-muted">Review the request and take action.</div>
      <div class="text-muted small">Request ID: <?= h($requestId) ?></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back
      </a>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-8">
      <section class="card shadow-sm mb-3" aria-labelledby="limitChangeApproveCardDetailsHeading">
        <div class="card-header">
          <h2 class="h5 mb-0" id="limitChangeApproveCardDetailsHeading">Card Details</h2>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="limitChangeApproveFirstName">First Name</label>
              <input class="form-control readonly-field" id="limitChangeApproveFirstName" value="<?= h($firstName) ?>" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="limitChangeApproveLastName">Last Name</label>
              <input class="form-control readonly-field" id="limitChangeApproveLastName" value="<?= h($lastName) ?>" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="limitChangeApproveNameOnCard">Name on Card</label>
              <input class="form-control readonly-field" id="limitChangeApproveNameOnCard" value="<?= h((string)($card['NameOnCard'] ?? ($data['name_on_card'] ?? ''))) ?>" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="limitChangeApproveCardNumber">Card Number</label>
              <input class="form-control readonly-field" id="limitChangeApproveCardNumber" value="<?= h($cardNumberMasked) ?>" readonly>
            </div>
          </div>
        </div>
      </section>

      <section class="card shadow-sm mb-3" aria-labelledby="limitChangeApproveLimitsHeading">
        <div class="card-header">
          <h2 class="h5 mb-0" id="limitChangeApproveLimitsHeading">Limits</h2>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="limitChangeApproveCreditCurrent">Current Credit Limit</label>
              <input class="form-control readonly-field" id="limitChangeApproveCreditCurrent" value="<?= h(fmt_money($creditCurrent)) ?>" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="limitChangeApproveCreditRequested">Credit Limit Requested</label>
              <input class="form-control readonly-field" id="limitChangeApproveCreditRequested" value="<?= h(fmt_money($creditRequested)) ?>" readonly>
            </div>
            <?php if ($applicationTypeKey === 'dpc_limit_change'): ?>
              <div class="col-md-6">
                <label class="form-label" for="limitChangeApproveTxnCurrent">Current Transaction Limit</label>
                <input class="form-control readonly-field" id="limitChangeApproveTxnCurrent" value="<?= h(fmt_money($txnCurrent)) ?>" readonly>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="limitChangeApproveTxnRequested">Transaction Limit Requested</label>
                <input class="form-control readonly-field" id="limitChangeApproveTxnRequested" value="<?= h(fmt_money($txnRequested)) ?>" readonly>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <section class="card shadow-sm mb-3" aria-labelledby="limitChangeApproveJustificationHeading">
        <div class="card-header">
          <h2 class="h5 mb-0" id="limitChangeApproveJustificationHeading">Justification</h2>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="limitChangeApproveReason">Reason</label>
              <input class="form-control readonly-field" id="limitChangeApproveReason" value="<?= h($reason) ?>" readonly>
            </div>
            <div class="col-12">
              <label class="form-label" for="limitChangeApproveReasonOther">Details</label>
              <textarea class="form-control readonly-field" id="limitChangeApproveReasonOther" rows="4" readonly><?= h($reasonOther) ?></textarea>
            </div>
            <div class="col-12">
              <label class="form-label" for="limitChangeApproveSelectedApprover">Selected Approver</label>
              <input class="form-control readonly-field" id="limitChangeApproveSelectedApprover" value="<?= h($approverDisplay !== '' ? $approverDisplay : $selectedApprover) ?>" readonly>
            </div>
          </div>
        </div>
      </section>

      <section class="card shadow-sm mb-3" aria-labelledby="limitChangeApprovePeriodHeading">
        <div class="card-header">
          <h2 class="h5 mb-0" id="limitChangeApprovePeriodHeading">Change Period</h2>
        </div>
        <div class="card-body">
          <?php if ($applicationTypeKey === 'dpc_limit_change' && $limitChangeScope === 'both'): ?>
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label" for="limitChangeApproveCreditDurationType">Credit Limit Duration</label>
                <input class="form-control readonly-field" id="limitChangeApproveCreditDurationType" value="<?= h($creditDurationType === 'temporary' ? 'Temporary' : 'Permanent') ?>" readonly>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="limitChangeApproveCreditPeriodFrom">Credit Start Date</label>
                <input class="form-control readonly-field" id="limitChangeApproveCreditPeriodFrom" value="<?= h($creditDurationType === 'temporary' && $creditPeriodFrom !== '' ? $creditPeriodFrom : '-') ?>" readonly>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="limitChangeApproveCreditPeriodTo">Credit End Date</label>
                <input class="form-control readonly-field" id="limitChangeApproveCreditPeriodTo" value="<?= h($creditDurationType === 'temporary' && $creditPeriodTo !== '' ? $creditPeriodTo : '-') ?>" readonly>
              </div>
              <div class="col-12">
                <label class="form-label" for="limitChangeApproveTransactionDurationType">Transaction Limit Duration</label>
                <input class="form-control readonly-field" id="limitChangeApproveTransactionDurationType" value="<?= h($transactionDurationType === 'temporary' ? 'Temporary' : 'Permanent') ?>" readonly>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="limitChangeApproveTransactionPeriodFrom">Transaction Start Date</label>
                <input class="form-control readonly-field" id="limitChangeApproveTransactionPeriodFrom" value="<?= h($transactionDurationType === 'temporary' && $transactionPeriodFrom !== '' ? $transactionPeriodFrom : '-') ?>" readonly>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="limitChangeApproveTransactionPeriodTo">Transaction End Date</label>
                <input class="form-control readonly-field" id="limitChangeApproveTransactionPeriodTo" value="<?= h($transactionDurationType === 'temporary' && $transactionPeriodTo !== '' ? $transactionPeriodTo : '-') ?>" readonly>
              </div>
            </div>
          <?php else: ?>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label" for="limitChangeApproveDurationType">Duration</label>
                <input class="form-control readonly-field" id="limitChangeApproveDurationType" value="<?= h($durationType === 'temporary' ? 'Temporary' : 'Permanent') ?>" readonly>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="limitChangeApprovePeriodFrom">Start Date</label>
                <input class="form-control readonly-field" id="limitChangeApprovePeriodFrom" value="<?= h($durationType === 'temporary' && $periodFrom !== '' ? $periodFrom : '-') ?>" readonly>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="limitChangeApprovePeriodTo">End Date</label>
                <input class="form-control readonly-field" id="limitChangeApprovePeriodTo" value="<?= h($durationType === 'temporary' && $periodTo !== '' ? $periodTo : '-') ?>" readonly>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <section class="card shadow-sm mb-3" aria-labelledby="limitChangeApproveDecisionHeading">
        <div class="card-header">
          <h2 class="h5 mb-0" id="limitChangeApproveDecisionHeading">Decision</h2>
        </div>
        <div class="card-body">
          <?php if ($isActionLocked): ?>
            <div class="alert alert-info py-2" role="status" aria-live="polite">
              This application is finalised and cannot be actioned further.
            </div>
          <?php endif; ?>
          <?php if (!$canApproveAction): ?>
            <div class="alert alert-warning py-2" role="alert">
              <?= $isSelfRequest
                ? 'You cannot action your own limit change request. Decision actions are disabled.'
                : 'You are not the assigned approver for this application. Decision actions are disabled.' ?>
            </div>
          <?php endif; ?>
          <form method="post" action="index.php?route=cards/limit-change-approve-save" id="approvalForm" class="js-submit-feedback-form" aria-describedby="limitChangeApproveDecisionHelp">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <input type="hidden" name="application_id" value="<?= h((string)$applicationId) ?>">
            <input type="hidden" name="decision" id="decisionField" value="">
            <input type="hidden" name="reject_reason" id="rejectReasonField" value="">
            <p id="limitChangeApproveDecisionHelp" class="visually-hidden">Use the decision actions to approve or reject this request.</p>

            <div class="d-flex flex-wrap gap-2 mb-3">
              <button type="button" class="btn btn-success" id="approveBtn" <?= $actionsDisabled ? 'disabled' : '' ?>>Approve</button>
              <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectReasonModal" <?= $actionsDisabled ? 'disabled' : '' ?>>Reject</button>
            </div>

            <?php if ($approvalRequiresSesConfirmation): ?>
              <?php $mcErr = (string)($decisionErrors['manual_approver_confirmed'] ?? ''); ?>
              <div class="mb-3">
                <?php if ($manualApproverNeedsConfirmation): ?>
                  <div class="form-text approver-policy-warning mb-2">
                    <?= nl2br(h((string)($data['approver_email_warning'] ?? 'The nominated approver must be a SES Band 1 / 1 Star or above. Defence HR records indicate this person does not meet this level.' . "\n\n" . 'By continuing, you are confirming that the approver meets this requirement in line with Policy.'))) ?>
                  </div>
                <?php endif; ?>
                <div class="form-check">
                  <input
                    class="form-check-input <?= $mcErr !== '' ? 'is-invalid' : '' ?>"
                    type="checkbox"
                    name="manual_approver_confirmed"
                    id="manualApproverConfirmed"
                    value="1"
                    <?= ((string)($data['manual_approver_confirmed'] ?? '') === '1') ? 'checked' : '' ?>
                    <?= $mcErr !== '' ? 'aria-invalid="true"' : '' ?>
                    <?= $actionsDisabled ? 'disabled' : '' ?>
                  >
                  <label class="form-check-label" for="manualApproverConfirmed">
                    I confirm I am SES Band 1 / 1 Star and am authorised to approve this application.
                  </label>
                  <?php if ($mcErr !== ''): ?><div class="invalid-feedback d-block"><?= h($mcErr) ?></div><?php endif; ?>
                </div>
              </div>
            <?php endif; ?>

            <?php $fErr = (string)($decisionErrors['forward_to'] ?? ''); ?>
            <div id="forwardWrap" class="mb-3" style="display:<?= ($isActionLocked || (!$fErr && trim((string)($data['forward_to'] ?? '')) === '')) ? 'none' : '' ?>;">
              <label class="form-label" for="forwardToInput">Forward To</label>
              <select class="form-select <?= $fErr !== '' ? 'is-invalid' : '' ?>" name="forward_to" id="forwardToInput" aria-describedby="forwardToHelp" <?= $fErr !== '' ? 'aria-invalid="true"' : '' ?> <?= $actionsDisabled ? 'disabled' : '' ?>>
                <option value="">Select an approver</option>
                <?php foreach ($forwardOptions as $opt): ?>
                  <?php
                    $optValue = (string)($opt['value'] ?? '');
                    $optLabel = (string)($opt['label'] ?? $optValue);
                  ?>
                  <option value="<?= h($optValue) ?>" <?= ((string)($data['forward_to'] ?? '') === $optValue) ? 'selected' : '' ?>>
                    <?= h($optLabel) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <?php if ($fErr !== ''): ?><div class="invalid-feedback"><?= h($fErr) ?></div><?php endif; ?>
              <div class="form-text" id="forwardToHelp">
                <?= $forwardOptions ? 'Only valid approvers for this request can be selected.' : 'No alternate approvers are available for this request.' ?>
              </div>
            </div>
          </form>
        </div>
      </section>
    </div>

    <div class="col-lg-4">
      <section class="card shadow-sm mb-3" aria-labelledby="limitChangeApproveSummaryHeading">
        <div class="card-header">
          <h2 class="h5 mb-0" id="limitChangeApproveSummaryHeading">Request Summary</h2>
        </div>
        <div class="card-body">
          <div class="mb-2">
            <span class="text-muted">Status:</span>
            <span class="badge <?= h($statusBannerClass) ?> fs-6 px-3 py-2"><?= h($status !== '' ? $status : 'Pending Approval') ?></span>
          </div>
          <div class="mb-2"><span class="text-muted">Requested By:</span> <span><?= h($requestedBy !== '' ? $requestedBy : '-') ?></span></div>
          <div class="mb-2"><span class="text-muted">Requestor Email:</span> <span><?= h($requesterEmail !== '' ? $requesterEmail : '-') ?></span></div>
          <div class="mb-2"><span class="text-muted">Submitted At:</span> <span><?= h($submittedAt !== '' ? $submittedAt : '-') ?></span></div>
          <div class="mb-2"><span class="text-muted">Approval Stage:</span> <span><?= h((string)$currentApprovalStage) ?> of <?= h((string)$totalApprovalStages) ?></span></div>
          <div class="mb-2"><span class="text-muted">Approver:</span> <span><?= h($approverDisplay !== '' ? $approverDisplay : ($selectedApprover !== '' ? $selectedApprover : '-')) ?></span></div>
          <?php if ($forwardedTo !== ''): ?>
            <div class="mb-2"><span class="text-muted">Forwarded To:</span> <span><?= h($forwardedTo) ?></span></div>
          <?php endif; ?>
          <?php if ($approvedAt !== ''): ?>
            <div class="mb-2"><span class="text-muted">Approved At:</span> <span><?= h(fmt_dt_approval($approvedAt)) ?></span></div>
          <?php endif; ?>
          <?php if ($rejectedAt !== ''): ?>
            <div class="mb-2"><span class="text-muted">Rejected At:</span> <span><?= h(fmt_dt_approval($rejectedAt)) ?></span></div>
          <?php endif; ?>
          <?php if ($rejectReasonSaved !== ''): ?>
            <div class="mb-2"><span class="text-muted">Rejection Reason:</span> <span><?= h($rejectReasonSaved) ?></span></div>
          <?php endif; ?>
          <?php if ($forwardedAt !== ''): ?>
            <div class="mb-2"><span class="text-muted">Forwarded At:</span> <span><?= h(fmt_dt_approval($forwardedAt)) ?></span></div>
          <?php endif; ?>
        </div>
      </section>

      <section class="card shadow-sm" aria-labelledby="limitChangeApproveProgressHeading">
        <div class="card-header"><h2 class="h5 mb-0" id="limitChangeApproveProgressHeading">Progress</h2></div>
        <div class="card-body">
          <div class="list-group list-group-flush" role="list" aria-label="Application progress">
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

      <?php if ($previousApprovals !== []): ?>
        <section class="card shadow-sm mt-3" aria-labelledby="limitChangePreviousApprovalsHeading">
          <div class="card-header"><h2 class="h5 mb-0" id="limitChangePreviousApprovalsHeading">Previous Approvers</h2></div>
          <div class="card-body">
            <div class="list-group list-group-flush" role="list" aria-label="Previous approval actions">
              <?php foreach ($previousApprovals as $row): ?>
                <?php
                  $decision = strtolower(trim((string)($row['decision'] ?? '')));
                  $decisionLabel = $decision !== '' ? ucfirst($decision) : 'Action';
                  $actedBy = trim((string)($row['acted_by'] ?? ''));
                  $approverLabel = trim((string)($row['approver_display'] ?? ($row['approver'] ?? '')));
                  $forwardToLabel = trim((string)($row['forward_to_display'] ?? ($row['forward_to'] ?? '')));
                  $reasonText = trim((string)($row['reason'] ?? ''));
                ?>
                <div class="list-group-item">
                  <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                      <div class="fw-semibold">Stage <?= h((string)($row['stage'] ?? '1')) ?>: <?= h($decisionLabel) ?></div>
                      <div class="small text-muted">
                        <?= h($actedBy !== '' ? $actedBy : '-') ?>
                        <?php if ($approverLabel !== ''): ?> as <?= h($approverLabel) ?><?php endif; ?>
                      </div>
                      <?php if ($forwardToLabel !== '' && $decision === 'forward'): ?>
                        <div class="small text-muted">Forwarded to <?= h($forwardToLabel) ?></div>
                      <?php endif; ?>
                      <?php if ($reasonText !== '' && $decision === 'reject'): ?>
                        <div class="small text-muted">Reason: <?= h($reasonText) ?></div>
                      <?php endif; ?>
                    </div>
                    <div class="small text-muted text-nowrap"><?= h(fmt_dt_approval((string)($row['acted_at'] ?? ''))) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </section>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- Reject Reason Modal -->
<div class="modal fade" id="rejectReasonModal" tabindex="-1" aria-labelledby="rejectReasonLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="rejectReasonLabel">Reject Request</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <?php $rErr = (string)($decisionErrors['reject_reason'] ?? ''); ?>
        <label class="form-label" for="rejectReasonInput">Rejection Reason</label>
        <textarea class="form-control <?= $rErr !== '' ? 'is-invalid' : '' ?>" id="rejectReasonInput" rows="4" placeholder="Provide the reason for rejection..." aria-describedby="rejectReasonHelp" <?= $rErr !== '' ? 'aria-invalid="true"' : '' ?> <?= $actionsDisabled ? 'disabled' : '' ?>></textarea>
        <?php if ($rErr !== ''): ?><div class="invalid-feedback d-block" role="alert"><?= h($rErr) ?></div><?php endif; ?>
        <div class="form-text" id="rejectReasonHelp">Provide a clear reason so the requester understands why the request was rejected.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmRejectBtn" <?= $actionsDisabled ? 'disabled' : '' ?>>Confirm Reject</button>
      </div>
    </div>
  </div>
</div>

<!-- Forward Confirmation Modal -->
<div class="modal fade" id="forwardConfirmModal" tabindex="-1" aria-labelledby="forwardConfirmLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="forwardConfirmLabel">Confirm Forward</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning mb-0" role="alert">
          Are you sure you want to forward this request to <strong id="forwardConfirmName">the selected approver</strong>?
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="confirmForwardBtn">Yes, Forward Request</button>
      </div>
    </div>
  </div>
</div>

<script>
  (function () {
    const isLocked = <?= $actionsDisabled ? 'true' : 'false' ?>;
    const form = document.getElementById('approvalForm');
    const decisionField = document.getElementById('decisionField');
    const rejectReasonField = document.getElementById('rejectReasonField');
    const rejectReasonInput = document.getElementById('rejectReasonInput');
    const approveBtn = document.getElementById('approveBtn');
    const forwardBtn = document.getElementById('forwardBtn');
    const confirmRejectBtn = document.getElementById('confirmRejectBtn');
    const forwardWrap = document.getElementById('forwardWrap');
    const forwardToInput = document.getElementById('forwardToInput');
    const forwardConfirmEl = document.getElementById('forwardConfirmModal');
    const forwardConfirmName = document.getElementById('forwardConfirmName');
    const confirmForwardBtn = document.getElementById('confirmForwardBtn');
    let forwardConfirmModal = null;

    function getForwardConfirmModal() {
      if (!forwardConfirmEl || typeof bootstrap === 'undefined') {
        return null;
      }
      if (!forwardConfirmModal) {
        forwardConfirmModal = new bootstrap.Modal(forwardConfirmEl);
      }
      return forwardConfirmModal;
    }

    if (!form || !decisionField || isLocked) return;

    if (approveBtn) {
      approveBtn.addEventListener('click', function () {
        decisionField.value = 'approve';
        rejectReasonField.value = '';
        form.submit();
      });
    }

    if (forwardBtn) {
      forwardBtn.addEventListener('click', function () {
        if (forwardWrap) {
          const hidden = forwardWrap.style.display === 'none';
          if (hidden) {
            forwardWrap.style.display = '';
            if (forwardToInput) {
              forwardToInput.classList.remove('is-invalid');
              forwardToInput.setAttribute('aria-invalid', 'false');
              forwardToInput.focus();
            }
            return;
          }
        }
        if (forwardToInput && !String(forwardToInput.value || '').trim()) {
          forwardToInput.classList.add('is-invalid');
          forwardToInput.setAttribute('aria-invalid', 'true');
          forwardToInput.focus();
          return;
        }
        if (forwardConfirmName && forwardToInput) {
          const selectedText = forwardToInput.options && forwardToInput.selectedIndex >= 0
            ? String(forwardToInput.options[forwardToInput.selectedIndex].text || '').trim()
            : '';
          forwardConfirmName.textContent = selectedText !== '' ? selectedText : 'the selected approver';
        }
        const modal = getForwardConfirmModal();
        if (modal) {
          modal.show();
          return;
        }
        decisionField.value = 'forward';
        rejectReasonField.value = '';
        form.submit();
      });
    }

    if (confirmForwardBtn) {
      confirmForwardBtn.addEventListener('click', function () {
        const modal = getForwardConfirmModal();
        if (modal) {
          modal.hide();
        }
        decisionField.value = 'forward';
        rejectReasonField.value = '';
        form.submit();
      });
    }

    if (confirmRejectBtn) {
      confirmRejectBtn.addEventListener('click', function () {
        if (rejectReasonInput && !String(rejectReasonInput.value || '').trim()) {
          rejectReasonInput.classList.add('is-invalid');
          rejectReasonInput.setAttribute('aria-invalid', 'true');
          rejectReasonInput.focus();
          return;
        }
        if (rejectReasonInput) {
          rejectReasonInput.classList.remove('is-invalid');
          rejectReasonInput.setAttribute('aria-invalid', 'false');
        }
        decisionField.value = 'reject';
        rejectReasonField.value = rejectReasonInput ? rejectReasonInput.value : '';
        form.submit();
      });
    }

    if (rejectReasonInput) {
      rejectReasonInput.addEventListener('input', function () {
        const hasValue = !!String(rejectReasonInput.value || '').trim();
        rejectReasonInput.classList.toggle('is-invalid', !hasValue && decisionField.value === 'reject');
        rejectReasonInput.setAttribute('aria-invalid', hasValue ? 'false' : 'true');
      });
    }
  })();
</script>

<?php require __DIR__ . '/../shared/submit_feedback.php'; ?>
