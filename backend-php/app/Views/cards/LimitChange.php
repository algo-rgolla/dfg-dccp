<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('renderAgreementText')) {
    function renderAgreementText(string $text): string
    {
        $escaped = h($text);
        $placeholders = [];
        $index = 0;

        $escaped = preg_replace_callback(
            '~\[(.*?)\]\(((?:https?://)[^\s)]+|mailto:[^\s)]+|objective:[^\s)]+)\)~i',
            static function (array $matches) use (&$placeholders, &$index): string {
                $label = trim((string)($matches[1] ?? ''));
                $href = trim((string)($matches[2] ?? ''));
                if ($label === '' || $href === '') {
                    return $matches[0];
                }

                $safeLabel = h($label);
                $safeHref = h($href);
                $lowerHref = strtolower($href);
                $rel = str_starts_with($lowerHref, 'mailto:') || str_starts_with($lowerHref, 'objective:') ? '' : ' target="_blank" rel="noopener noreferrer"';
                $token = '%%SUBMIT_AGREEMENT_LINK_' . $index++ . '%%';
                $placeholders[$token] = '<a href="' . $safeHref . '"' . $rel . '>' . $safeLabel . '</a>';
                return $token;
            },
            $escaped
        );

        $escaped = preg_replace_callback(
            '~(?:(https?://[^\s<\]]+)|(objective:[^\s<\]]+)|([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}))~i',
            static function (array $matches): string {
                if (!empty($matches[1])) {
                    $url = $matches[1];
                    $href = h($url);
                    return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $href . '</a>';
                }

                if (!empty($matches[2])) {
                    $url = $matches[2];
                    $href = h($url);
                    return '<a href="' . $href . '">' . $href . '</a>';
                }

                $email = $matches[3] ?? '';
                if ($email !== '') {
                    $safeEmail = h($email);
                    return '<a href="mailto:' . $safeEmail . '">' . $safeEmail . '</a>';
                }

                return $matches[0];
            },
            $escaped
        );

        if ($placeholders !== []) {
            $escaped = strtr($escaped, $placeholders);
        }

        $escaped = preg_replace(
            '~\*\*(.+?)\*\*~s',
            '<strong>$1</strong>',
            $escaped
        );

        $escaped = preg_replace(
            '~(?<!\*)\*(?![\s*])(.+?)(?<![\s*])\*(?!\*)~s',
            '<em>$1</em>',
            $escaped
        );

        $escaped = preg_replace(
            '~(?<![A-Z0-9])_([^_\r\n]+)_~i',
            '<em>$1</em>',
            $escaped
        );

        $lines = preg_split("/\r\n|\n|\r/", $escaped) ?: [];
        $output = [];
        $inList = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                if ($inList) {
                    $output[] = '</ul>';
                    $inList = false;
                }
                $output[] = '<br>';
                continue;
            }

            if (preg_match('/^(?:&bull;|&#8226;|•|\*|-)\s+(.+)$/u', $trimmed, $matches)) {
                if (!$inList) {
                    $output[] = '<ul>';
                    $inList = true;
                }
                $output[] = '<li>' . $matches[1] . '</li>';
                continue;
            }

            if ($inList) {
                $output[] = '</ul>';
                $inList = false;
            }

            $output[] = $trimmed . '<br>';
        }

        if ($inList) {
            $output[] = '</ul>';
        }

        return implode('', $output);
    }
}
if (!function_exists('fmt_money0')) {
    function fmt_money0($v): string {
        $s = trim((string)$v);
        if ($s === '') return '';
        $n = str_replace([',', '$', ' '], '', $s);
        if (!is_numeric($n)) return $s;
        return '$' . number_format((float)$n, 0);
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
$progress = $progress ?? [];
$workflow = $workflow ?? [];
$runtimeSteps = $runtimeSteps ?? [];
$data = is_array($data ?? null) ? $data : [];
$applicationId = (int)($applicationId ?? 0);
$validationErrors = is_array($validationErrors ?? null) ? $validationErrors : [];
$showErrors = !empty($validationErrors);
$appStatus = strtolower(trim((string)($appStatus ?? 'draft')));
$isLocked = !in_array($appStatus, ['draft', 'inprogress'], true);
$rejectReason = trim((string)($data['reject_reason'] ?? ''));
$approverRules = is_array($approverRules ?? null) ? $approverRules : [];
$approverDirectory = is_array($approverDirectory ?? null) ? $approverDirectory : [];
$manualApproverThreshold = (float)($manualApproverThreshold ?? 100000);
$sesApproverEmails = is_array($sesApproverEmails ?? null) ? $sesApproverEmails : [];
$transactionLimitOptions = is_array($transactionLimitOptions ?? null) ? $transactionLimitOptions : [];
$reasonOptions = is_array($reasonOptions ?? null) ? $reasonOptions : [];
$employeeType = (string)($employeeType ?? '');
$employeeGroupDisplay = trim((string)($employeeGroupDisplay ?? ($data['employee_group'] ?? '')));
$isAdminOverride = !empty($isAdminOverride);
$limitChangeScope = strtolower(trim((string)($limitChangeScope ?? ($data['limit_change_scope'] ?? 'both'))));
if (!in_array($limitChangeScope, ['both', 'credit_only', 'transaction_only'], true)) {
    $limitChangeScope = 'both';
}
$limitChangeScopeLabels = [
    'both' => 'Change to both monthly credit limit and transaction limit',
    'credit_only' => 'Change to monthly credit limit only',
    'transaction_only' => 'Change to transaction limit only',
];
$limitChangeScopeLabel = $limitChangeScopeLabels[$limitChangeScope] ?? $limitChangeScopeLabels['both'];
$selectedApprover = (string)($data['approver'] ?? '');
$selectedApproverEmail = trim((string)($data['approver_email'] ?? ''));
if ($selectedApproverEmail === '' && str_starts_with(strtoupper($selectedApprover), 'EMAIL|')) {
    $selectedApproverEmail = trim(substr($selectedApprover, 6));
}
$approverEmailWarning = trim((string)($data['approver_email_warning'] ?? ''));
$approverFieldError = $showErrors ? field_error($validationErrors, 'approver') : null;
$approverEmailFieldError = $showErrors ? field_error($validationErrors, 'approver_email') : null;
$selectedTransactionLimit = (string)($data['transaction_limit_new'] ?? '');
$creditLimitMaxAmount = (float)($creditLimitMaxAmount ?? 999900);
$creditLimitMaxAmountLabel = '$' . number_format($creditLimitMaxAmount, 0);
$temporaryLimitPeriodMonths = (int)($temporaryLimitPeriodMonths ?? 48);
$submitDeclarationText = trim((string)($submitDeclarationText ?? ''));
$submissionToken = trim((string)($submissionToken ?? ''));
$todayDate = date('Y-m-d');
$creditLimitDurationType = strtolower(trim((string)($data['credit_limit_change_duration_type'] ?? ($data['limit_change_duration_type'] ?? 'permanent'))));
if (!in_array($creditLimitDurationType, ['permanent', 'temporary'], true)) {
    $creditLimitDurationType = 'permanent';
}
$transactionLimitDurationType = strtolower(trim((string)($data['transaction_limit_change_duration_type'] ?? ($data['limit_change_duration_type'] ?? 'permanent'))));
if (!in_array($transactionLimitDurationType, ['permanent', 'temporary'], true)) {
    $transactionLimitDurationType = 'permanent';
}
$currentApprovalStage = max(1, (int)($currentApprovalStage ?? 1));
$totalApprovalStages = max(1, (int)($totalApprovalStages ?? 1));
$currentApprovalDisplay = trim((string)($currentApprovalDisplay ?? ''));
$previousApprovals = is_array($previousApprovals ?? null) ? $previousApprovals : [];
$applicantEmailJs = trim((string)($applicantEmail ?? ($data['email'] ?? '')));
if ($applicantEmailJs === '' && is_array($card)) {
    $applicantEmailJs = trim((string)($card['Email'] ?? ($card['Email_Address'] ?? '')));
}

function field_error(array $errs, string $key): ?string {
    return $errs[$key] ?? null;
}
$card = $card ?? [];
$card = is_array($card) ? $card : [];
$cardExpiry = (string)($card['Expiry'] ?? ($data['card_expiry'] ?? ''));
if ($cardExpiry !== '' && ($ts = strtotime($cardExpiry)) !== false) {
    $cardExpiry = date('Y/m', $ts);
}
$cardDateIssued = (string)($card['DateIssued'] ?? ($data['date_issued'] ?? ''));
if ($cardDateIssued !== '' && ($tsIssued = strtotime($cardDateIssued)) !== false) {
    $cardDateIssued = date('d-m-Y', $tsIssued);
}
$cardNumberRaw = (string)($card['CardNumber'] ?? ($data['card_number'] ?? ''));
$cardNumberDigits = preg_replace('/\D+/', '', $cardNumberRaw) ?? '';
$cardNumberTail = $cardNumberDigits !== '' ? substr($cardNumberDigits, -4) : substr($cardNumberRaw, -4);
$cardNumberMasked = $cardNumberTail !== '' ? ('************' . $cardNumberTail) : '';
$cardFirstName = (string)($card['FirstName'] ?? ($data['first_name'] ?? ''));
$cardSurname = (string)($card['Surname'] ?? ($data['surname'] ?? ''));
$cardTypeCode = strtoupper(trim((string)($card['CardType'] ?? ($data['card_type'] ?? ''))));
$isDtcCard = ($cardTypeCode === 'DTC' || str_contains($cardTypeCode, 'DTC'));
$isOnBehalfRequest = (string)($data['on_behalf'] ?? '') === '1';
$showDpcScopeModal = (bool)($showDpcScopeModal ?? false);
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

  .approver-directory-warning {
    color: #dc3545;
  }

  .approver-valid-message {
    color: #198754;
  }
</style>

<section class="container-fluid mt-4" aria-labelledby="limitChangeHeading">
  <?php if ($isAdminOverride): ?>
    <div class="alert alert-warning py-2" role="status" aria-live="polite">
      <strong>Administrator Edit Mode:</strong> You are editing this limit change on behalf of the application owner. Reopen it to <strong>Draft</strong> or <strong>InProgress</strong> first if the form is locked.
    </div>
  <?php endif; ?>
  <?php if ($isLocked): ?>
    <div class="alert alert-info py-2" role="status" aria-live="polite">
      This application has been submitted and is now locked. Fields and actions are disabled.
    </div>
  <?php endif; ?>
  <?php if ($appStatus === 'rejected'): ?>
    <div class="alert alert-danger py-2" role="alert">
      <strong>Application Rejected.</strong>
      <?= $rejectReason !== '' ? ('Reason: ' . h($rejectReason)) : 'Please contact your approver for details.' ?>
    </div>
  <?php endif; ?>

  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h1 class="h3 mb-1" id="limitChangeHeading">Request Limit Change</h1>
      <?php if (!empty($typeLabel)): ?>
        <div class="fw-semibold"><?= h((string)$typeLabel) ?></div>
      <?php endif; ?>
      <?php if ($applicationId > 0): ?>
        <div class="text-muted small">Application ID: <?= h((string)$applicationId) ?></div>
      <?php endif; ?>
      <div class="text-muted">Complete the details below to request a card limit change.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="<?= $isAdminOverride && $applicationId > 0 ? 'index.php?route=admin/applications-view&id=' . urlencode((string)$applicationId) : 'index.php?route=home/index' ?>">
        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Cards
      </a>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-4">
      <section class="card shadow-sm mb-3" aria-labelledby="limitChangeProgressHeading">
        <div class="card-header">
          <h2 class="h5 mb-0" id="limitChangeProgressHeading">Progress</h2>
        </div>
        <div class="card-body">
          <div class="list-group list-group-flush" role="list" aria-label="Application progress">
            <?php foreach ($progress as $p): ?>
              <?php
                $isActive = !empty($p['IsActive']);
                $done = !empty($p['Complete']);
              ?>
              <div class="list-group-item d-flex justify-content-between align-items-center" role="listitem">
                <div>
                  <?php if ($done): ?>
                    <i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>
                  <?php else: ?>
                    <i class="bi bi-circle text-muted me-2" aria-hidden="true"></i>
                  <?php endif; ?>
                  <span class="<?= $isActive ? 'fw-semibold' : '' ?>">
                    <?= h((string)($p['Label'] ?? 'Step')) ?>
                  </span>
                  <?php if ($isActive): ?>
                    <span class="badge bg-primary ms-2">Current</span>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <section class="card shadow-sm mb-3" aria-labelledby="limitChangeChecklistHeading">
        <div class="card-header">
          <h2 class="h5 mb-0" id="limitChangeChecklistHeading">Checklist</h2>
          <div class="text-muted small">Auto-evaluated once the form is wired.</div>
        </div>
        <div class="card-body">
          <div class="list-group" role="list" aria-label="Application checklist">
            <?php foreach ($workflow as $ws): ?>
              <?php
                $key = strtolower(trim((string)($ws['StepKey'] ?? '')));
                $rt = $runtimeSteps[$key] ?? null;
                $state = null;
                if ($rt && !empty($rt['LastSavedAt'])) {
                    $state = ((int)($rt['IsComplete'] ?? 0) === 1) ? 'pass' : 'fail';
                }
                $icon = match ($state) {
                    'pass' => '<i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>',
                    'fail' => '<i class="bi bi-x-circle-fill text-danger me-2" aria-hidden="true"></i>',
                    default => '<i class="bi bi-circle text-muted me-2" aria-hidden="true"></i>',
                };
              ?>
              <div class="list-group-item d-flex align-items-center" data-step-key="<?= h($key) ?>" role="listitem">
                <span class="check-icon" data-step-key="<?= h($key) ?>"><?= $icon ?></span>
                <span><?= h((string)($ws['StepLabel'] ?? $key)) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <?php if ($applicationId > 0 && !in_array($appStatus, ['draft', 'inprogress'], true)): ?>
        <section class="card shadow-sm mb-3" aria-labelledby="limitChangeApprovalProcessHeading">
          <div class="card-header">
            <h2 class="h5 mb-0" id="limitChangeApprovalProcessHeading">Approval Process</h2>
          </div>
          <div class="card-body">
            <div class="mb-2"><span class="text-muted">Approval Stage:</span> <span><?= h((string)$currentApprovalStage) ?> of <?= h((string)$totalApprovalStages) ?></span></div>
            <div class="mb-2"><span class="text-muted">Current Approver:</span> <span><?= h($currentApprovalDisplay !== '' ? $currentApprovalDisplay : '-') ?></span></div>
            <div class="mb-3"><span class="text-muted">Current Status:</span> <span><?= h($appStatus !== '' ? $appStatus : '-') ?></span></div>

            <?php if ($previousApprovals !== []): ?>
              <div class="small text-muted mb-2">Completed Actions</div>
              <div class="list-group list-group-flush" role="list" aria-label="Completed approval actions">
                <?php foreach ($previousApprovals as $row): ?>
                  <?php
                    $decision = strtolower(trim((string)($row['decision'] ?? '')));
                    $decisionLabel = $decision !== '' ? ucfirst($decision) : 'Action';
                    $actedBy = trim((string)($row['acted_by'] ?? ''));
                    $approverLabel = trim((string)($row['approver_display'] ?? ($row['approver'] ?? '')));
                    $forwardToLabel = trim((string)($row['forward_to_display'] ?? ($row['forward_to'] ?? '')));
                    $reasonText = trim((string)($row['reason'] ?? ''));
                  ?>
                  <div class="list-group-item px-0">
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
                    <div class="small text-muted"><?= h((string)fmt_dt_approval((string)($row['acted_at'] ?? ''))) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="text-muted small">No approval actions have been completed yet.</div>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>
    </div>

    <div class="col-lg-8">
      <form method="post" action="index.php?route=cards/limit-change-save" enctype="multipart/form-data" novalidate id="limitChangeForm" class="js-submit-feedback-form" aria-describedby="limitChangeIntro">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="_action" id="_action" value="save">
        <input type="hidden" name="limit_change_scope" id="limitChangeScopeField" value="<?= h($limitChangeScope) ?>">
        <input type="hidden" name="application_id" value="<?= h((string)$applicationId) ?>">
        <input type="hidden" name="card_id" value="<?= h((string)($card['CardID'] ?? ($data['card_id'] ?? 0))) ?>">
        <input type="hidden" name="type_key" value="<?= h((string)($typeKey ?? '')) ?>">
        <input type="hidden" name="on_behalf" value="<?= h((string)($data['on_behalf'] ?? '')) ?>">
        <input type="hidden" name="target_employee_id" value="<?= h((string)($data['target_employee_id'] ?? '')) ?>">
        <input type="hidden" name="card_type" value="<?= h((string)($card['CardType'] ?? ($data['card_type'] ?? ''))) ?>">
        <input type="hidden" name="card_type_sub" value="<?= h((string)($card['CardTypeSub'] ?? ($data['card_type_sub'] ?? ''))) ?>">
        <input type="hidden" name="submission_token" value="<?= h($submissionToken) ?>">
        <?php if ($isAdminOverride): ?>
          <input type="hidden" name="admin_override" value="1">
        <?php endif; ?>
        <fieldset <?= $isLocked ? 'disabled' : '' ?>>
        <legend class="visually-hidden">Limit change details</legend>
        <p id="limitChangeIntro" class="visually-hidden">Complete the card, limit, and justification fields before submitting this limit change request.</p>

        <section class="card shadow-sm mb-3" aria-labelledby="limitChangeCardDetailsHeading">
          <div class="card-header">
            <h2 class="h5 mb-0" id="limitChangeCardDetailsHeading">Card Details</h2>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6 js-credit-scope-field">
                <label class="form-label" for="limitChangeFirstName">First Name</label>
                <input class="form-control readonly-field" id="limitChangeFirstName" value="<?= h($cardFirstName) ?>" readonly>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="limitChangeSurname">Surname</label>
                <input class="form-control readonly-field" id="limitChangeSurname" value="<?= h($cardSurname) ?>" readonly>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="limitChangeEmployeeGroup">Employee Group</label>
                <input class="form-control readonly-field" id="limitChangeEmployeeGroup" value="<?= h($employeeGroupDisplay !== '' ? $employeeGroupDisplay : '-') ?>" readonly>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="limitChangeNameOnCard">Name on Card</label>
                <input class="form-control readonly-field" id="limitChangeNameOnCard" name="name_on_card" value="<?= h((string)($card['NameOnCard'] ?? ($data['name_on_card'] ?? ''))) ?>" readonly>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="limitChangeCardNumber">Card Number</label>
                <input class="form-control readonly-field" id="limitChangeCardNumber" name="card_number" value="<?= h($cardNumberMasked) ?>" readonly>
              </div>
            </div>
          </div>
        </section>

        <section class="card shadow-sm mb-3" aria-labelledby="limitChangeLimitsHeading">
          <div class="card-header">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
              <h2 class="h5 mb-0" id="limitChangeLimitsHeading">Limits</h2>
              <?php if (($typeKey ?? '') === 'dpc_limit_change'): ?>
                <div class="w-100 w-md-auto" style="max-width: 360px;">
                  <label class="form-label small mb-1" for="limitChangeScopeSelect">Change Type</label>
                  <select class="form-select form-select-sm" id="limitChangeScopeSelect">
                    <option value="both" <?= $limitChangeScope === 'both' ? 'selected' : '' ?>><?= h($limitChangeScopeLabels['both']) ?></option>
                    <option value="credit_only" <?= $limitChangeScope === 'credit_only' ? 'selected' : '' ?>><?= h($limitChangeScopeLabels['credit_only']) ?></option>
                    <option value="transaction_only" <?= $limitChangeScope === 'transaction_only' ? 'selected' : '' ?>><?= h($limitChangeScopeLabels['transaction_only']) ?></option>
                  </select>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6 js-credit-scope-field">
                <label class="form-label" for="creditLimitChangeDurationType">Credit Limit Change Type</label>
                <?php $e = $showErrors ? field_error($validationErrors, 'credit_limit_change_duration_type') : null; ?>
                <select class="form-select <?= $e ? 'is-invalid' : '' ?>" name="credit_limit_change_duration_type" id="creditLimitChangeDurationType" <?= $e ? 'aria-invalid="true"' : '' ?>>
                  <option value="permanent" <?= $creditLimitDurationType === 'permanent' ? 'selected' : '' ?>>Permanent</option>
                  <option value="temporary" <?= $creditLimitDurationType === 'temporary' ? 'selected' : '' ?>>Temporary</option>
                </select>
                <?php if ($e): ?><div class="invalid-feedback"><?= h($e) ?></div><?php endif; ?>
              </div>
              <div class="col-md-3 js-credit-scope-field" id="creditPeriodFromWrap" style="display:none;">
                <label class="form-label" for="creditPeriodChangeFrom">Credit Period of Change From</label>
                <?php $e = $showErrors ? field_error($validationErrors, 'credit_period_change_from') : null; ?>
                <input type="date" class="form-control <?= $e ? 'is-invalid' : '' ?>" name="credit_period_change_from" id="creditPeriodChangeFrom"
                       value="<?= h((string)($data['credit_period_change_from'] ?? ($data['period_change_from'] ?? ''))) ?>" <?= $e ? 'aria-invalid="true"' : '' ?>>
                <?php if ($e): ?><div class="invalid-feedback"><?= h($e) ?></div><?php endif; ?>
              </div>
              <div class="col-md-3 js-credit-scope-field" id="creditPeriodToWrap" style="display:none;">
                <label class="form-label" for="creditPeriodChangeTo">Credit Period of Change To</label>
                <?php $e = $showErrors ? field_error($validationErrors, 'credit_period_change_to') : null; ?>
                <input type="date" class="form-control <?= $e ? 'is-invalid' : '' ?>" name="credit_period_change_to" id="creditPeriodChangeTo"
                       min="<?= h($todayDate) ?>"
                       value="<?= h((string)($data['credit_period_change_to'] ?? ($data['period_change_to'] ?? ''))) ?>" aria-describedby="creditPeriodChangeToHelp" <?= $e ? 'aria-invalid="true"' : '' ?>>
                <?php if ($e): ?><div class="invalid-feedback"><?= h($e) ?></div><?php endif; ?>
                <div class="invalid-feedback js-period-range-msg d-none">Credit Period of Change To cannot be more than <?= h((string)$temporaryLimitPeriodMonths) ?> months after Credit Period of Change From.</div>
                <div class="form-text" id="creditPeriodChangeToHelp">Maximum temporary period: <?= h((string)$temporaryLimitPeriodMonths) ?> months from Credit Period of Change From.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="creditLimitCurrent">Current Credit Limit</label>
                <input class="form-control readonly-field" id="creditLimitCurrent" name="credit_limit_current" value="<?= h(fmt_money0((string)($card['CreditLimitAmount'] ?? ($data['credit_limit_current'] ?? '')))) ?>" readonly>
              </div>
              <div class="col-md-6 js-credit-scope-field">
                <label class="form-label" for="creditLimitNew">New Credit Limit</label>
                <?php $e = $showErrors ? field_error($validationErrors, 'credit_limit_new') : null; ?>
                <input class="form-control <?= $e ? 'is-invalid' : '' ?>" id="creditLimitNew" name="credit_limit_new" value="<?= h(fmt_money0((string)($data['credit_limit_new'] ?? ''))) ?>"
                       inputmode="numeric" pattern="[0-9,$ ]*" data-max-credit-limit="<?= h((string)(int)$creditLimitMaxAmount) ?>" aria-describedby="creditLimitNewHelp" <?= $e ? 'aria-invalid="true"' : '' ?>>
                <?php if ($e): ?><div class="invalid-feedback"><?= h($e) ?></div><?php endif; ?>
                <div class="invalid-feedback js-multiple-msg d-none">Amount must be in multiples of 100.</div>
                <div class="invalid-feedback js-max-credit-msg d-none">Amount cannot exceed <?= h($creditLimitMaxAmountLabel) ?>.</div>
                <div class="form-text" id="creditLimitNewHelp">Maximum allowed amount: <?= h($creditLimitMaxAmountLabel) ?>.</div>
              </div>
              <?php if (!$isDtcCard): ?>
                <div class="col-12 js-transaction-scope-field"><hr class="my-1"></div>
                <div class="col-md-6 js-transaction-scope-field">
                  <label class="form-label" for="transactionLimitChangeDurationType">Transaction Limit Change Type</label>
                  <?php $e = $showErrors ? field_error($validationErrors, 'transaction_limit_change_duration_type') : null; ?>
                  <select class="form-select <?= $e ? 'is-invalid' : '' ?>" name="transaction_limit_change_duration_type" id="transactionLimitChangeDurationType" <?= $e ? 'aria-invalid="true"' : '' ?>>
                    <option value="permanent" <?= $transactionLimitDurationType === 'permanent' ? 'selected' : '' ?>>Permanent</option>
                    <option value="temporary" <?= $transactionLimitDurationType === 'temporary' ? 'selected' : '' ?>>Temporary</option>
                  </select>
                  <?php if ($e): ?><div class="invalid-feedback"><?= h($e) ?></div><?php endif; ?>
                </div>
                <div class="col-md-3 js-transaction-scope-field" id="transactionPeriodFromWrap" style="display:none;">
                  <label class="form-label" for="transactionPeriodChangeFrom">Transaction Period of Change From</label>
                  <?php $e = $showErrors ? field_error($validationErrors, 'transaction_period_change_from') : null; ?>
                  <input type="date" class="form-control <?= $e ? 'is-invalid' : '' ?>" name="transaction_period_change_from" id="transactionPeriodChangeFrom"
                         value="<?= h((string)($data['transaction_period_change_from'] ?? ($data['period_change_from'] ?? ''))) ?>" <?= $e ? 'aria-invalid="true"' : '' ?>>
                  <?php if ($e): ?><div class="invalid-feedback"><?= h($e) ?></div><?php endif; ?>
                </div>
                <div class="col-md-3 js-transaction-scope-field" id="transactionPeriodToWrap" style="display:none;">
                  <label class="form-label" for="transactionPeriodChangeTo">Transaction Period of Change To</label>
                  <?php $e = $showErrors ? field_error($validationErrors, 'transaction_period_change_to') : null; ?>
                  <input type="date" class="form-control <?= $e ? 'is-invalid' : '' ?>" name="transaction_period_change_to" id="transactionPeriodChangeTo"
                         min="<?= h($todayDate) ?>"
                         value="<?= h((string)($data['transaction_period_change_to'] ?? ($data['period_change_to'] ?? ''))) ?>" aria-describedby="transactionPeriodChangeToHelp" <?= $e ? 'aria-invalid="true"' : '' ?>>
                  <?php if ($e): ?><div class="invalid-feedback"><?= h($e) ?></div><?php endif; ?>
                  <div class="invalid-feedback js-period-range-msg d-none">Transaction Period of Change To cannot be more than <?= h((string)$temporaryLimitPeriodMonths) ?> months after Transaction Period of Change From.</div>
                  <div class="form-text" id="transactionPeriodChangeToHelp">Maximum temporary period: <?= h((string)$temporaryLimitPeriodMonths) ?> months from Transaction Period of Change From.</div>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="transactionLimitCurrent">Current Transaction Limit</label>
                  <input class="form-control readonly-field" id="transactionLimitCurrent" name="transaction_limit_current" value="<?= h(fmt_money0((string)($card['TransactionLimit'] ?? ($data['transaction_limit_current'] ?? '')))) ?>" readonly>
                </div>
                <div class="col-md-6 js-transaction-scope-field">
                  <label class="form-label" for="transactionLimitNew">New Transaction Limit</label>
                  <?php $e = $showErrors ? field_error($validationErrors, 'transaction_limit_new') : null; ?>
                  <select class="form-select <?= $e ? 'is-invalid' : '' ?>" id="transactionLimitNew" name="transaction_limit_new" <?= $e ? 'aria-invalid="true"' : '' ?>>
                    <option value="">Select a transaction limit</option>
                    <?php foreach ($transactionLimitOptions as $option): ?>
                      <?php $trackCode = (string)($option['track_code'] ?? ''); ?>
                      <option
                        value="<?= h($trackCode) ?>"
                        data-trans-limit="<?= h((string)($option['trans_limit'] ?? '')) ?>"
                        <?= $selectedTransactionLimit === $trackCode ? 'selected' : '' ?>
                      >
                        <?= h((string)($option['label'] ?? $trackCode)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <?php if ($e): ?><div class="invalid-feedback"><?= h($e) ?></div><?php endif; ?>
                  <div class="invalid-feedback js-txn-credit-msg d-none">Transaction Limit cannot exceed Credit Limit.</div>
                </div>
              <?php endif; ?>
              <div class="col-md-6">
                <label class="form-label" for="approverSelect">Approver</label>
                <?php $e = $approverFieldError; ?>
                <?php $ee = $approverEmailFieldError; ?>
                <div id="approverSelectWrap">
                  <select class="form-select <?= $e ? 'is-invalid' : '' ?>" name="approver" id="approverSelect" data-selected="<?= h($selectedApprover) ?>" aria-describedby="approverHelp" <?= $e ? 'aria-invalid="true"' : '' ?>>
                    <option value="">Select an approver</option>
                  </select>
                  <?php if ($e): ?><div class="invalid-feedback"><?= h($e) ?></div><?php endif; ?>
                  <div id="approverReqError" class="invalid-feedback" style="display:none;">Select your Band 1 / 1 Star or above approver.</div>
                </div>
                <div id="approverEmailWrap" style="display:none;">
                  <input
                    type="email"
                    class="form-control <?= $ee ? 'is-invalid' : '' ?>"
                    name="approver_email"
                    id="approverEmailInput"
                    value="<?= h($selectedApproverEmail) ?>"
                    placeholder="Enter approver email address"
                    aria-describedby="approverHelp approverEmailWarning"
                    <?= $ee ? 'aria-invalid="true"' : '' ?>
                  >
                  <?php if ($ee): ?><div class="invalid-feedback"><?= h($ee) ?></div><?php endif; ?>
                  <div id="approverEmailReqError" class="invalid-feedback" style="display:none;">Select your Band 1 / 1 Star or above approver.</div>
                  <div class="form-text approver-policy-warning<?= $approverEmailWarning !== '' ? '' : ' d-none' ?>" id="approverEmailWarning"><?= nl2br(h($approverEmailWarning !== '' ? $approverEmailWarning : 'The nominated approver must be a SES Band 1 / 1 Star or above. Defence HR records indicate this person does not meet this level.' . "\n\n" . 'By continuing, you are confirming that the approver meets this requirement in line with Policy.')) ?></div>
                  <div class="form-text approver-directory-warning d-none" id="approverEmailCapsWarning">Email address does not exist in Defence Corporate Directory.</div>
                  <div class="form-text approver-valid-message d-none" id="approverEmailValid">Approver is valid.</div>
                </div>
                <div id="approverAutoWrap" class="d-none">
                  <div class="form-control readonly-field <?= ($approverFieldError || $approverEmailFieldError) ? 'is-invalid' : '' ?>" id="approverAutoDisplay" readonly aria-invalid="<?= ($approverFieldError || $approverEmailFieldError) ? 'true' : 'false' ?>">Approval recipients will be determined automatically.</div>
                </div>
                <div class="form-text" id="approverHelp">Approvers are filtered by applicant employee group and amount rules<?= $employeeType !== '' ? ' (' . h($employeeType) . ' routing)' : '' ?>.</div>
                <?php if ($approverFieldError || $approverEmailFieldError): ?>
                  <div class="alert alert-warning mt-2 mb-0 py-2" role="alert">
                    <?= h((string)($approverFieldError ?: $approverEmailFieldError)) ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </section>

        <section class="card shadow-sm mb-3" aria-labelledby="limitChangeJustificationHeading">
          <div class="card-header">
            <h2 class="h5 mb-0" id="limitChangeJustificationHeading">Justification</h2>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="limitChangeReason">Reason</label>
                <?php $e = $showErrors ? field_error($validationErrors, 'limit_change_reason') : null; ?>
                <select class="form-select <?= $e ? 'is-invalid' : '' ?>" name="limit_change_reason" id="limitChangeReason" <?= $e ? 'aria-invalid="true"' : '' ?>>
                  <option value="">Select a reason</option>
                  <?php $reasonVal = (string)($data['limit_change_reason'] ?? ''); ?>
                  <?php foreach ($reasonOptions as $option): ?>
                    <?php $optionValue = (string)($option['value'] ?? ''); ?>
                    <option value="<?= h($optionValue) ?>" <?= $reasonVal === $optionValue ? 'selected' : '' ?>>
                      <?= h((string)($option['label'] ?? $optionValue)) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <?php if ($e): ?><div class="invalid-feedback"><?= h($e) ?></div><?php endif; ?>
              </div>
              <div class="col-12" id="limitChangeReasonOtherWrap">
                <label class="form-label" for="limitChangeReasonOther">Additional Justification</label>
                <?php $e = $showErrors ? field_error($validationErrors, 'limit_change_reason_other') : null; ?>
                <textarea class="form-control <?= $e ? 'is-invalid' : '' ?>" name="limit_change_reason_other" id="limitChangeReasonOther" rows="4" placeholder="Provide details..." aria-describedby="limitChangeReasonOtherHelp" <?= $e ? 'aria-invalid="true"' : '' ?>><?= h((string)($data['limit_change_reason_other'] ?? '')) ?></textarea>
                <?php if ($e): ?><div class="invalid-feedback"><?= h($e) ?></div><?php endif; ?>
                <div class="form-text" id="limitChangeReasonOtherHelp">Required for all reason selections.</div>
              </div>
              <div class="col-12">
                <?php $e = $showErrors ? field_error($validationErrors, 'aged_transactions_confirmed') : null; ?>
                <div class="form-check">
                  <input class="form-check-input <?= $e ? 'is-invalid' : '' ?>" type="checkbox"
                         name="aged_transactions_confirmed" id="agedTransactionsConfirmed" value="1"
                         <?= ((string)($data['aged_transactions_confirmed'] ?? '') === '1') ? 'checked' : '' ?> aria-describedby="agedTransactionsHelp" <?= $e ? 'aria-invalid="true"' : '' ?>>
                  <label class="form-check-label" for="agedTransactionsConfirmed">
                    <?= $isOnBehalfRequest
                      ? 'The cardholder has confirmed they do not have any un-acquitted transactions aged &gt;45 days.'
                      : 'I have discussed this application with my Supervisor, and also confirm I do not have any un-acquitted transactions aged &gt;45 days.' ?>
                  </label>
                  <?php if ($e): ?><div class="invalid-feedback d-block"><?= h($e) ?></div><?php endif; ?>
                  <div class="invalid-feedback js-aged-txn-msg d-none">You must confirm there are no un-acquitted transactions aged more than 45 days.</div>
                  <div class="visually-hidden" id="agedTransactionsHelp">This confirmation is required before the application can be submitted.</div>
                </div>
              </div>
              <div class="col-12">
                <label class="form-label" for="limitChangeAttachments">Attachments</label>
                <input class="form-control" id="limitChangeAttachments" type="file" name="limit_change_attachments[]" multiple aria-describedby="limitChangeAttachmentsHelp">
                <div class="form-text" id="limitChangeAttachmentsHelp">Optional: attach supporting documents (PDF, DOCX, images).</div>
              </div>
            </div>
          </div>
        </section>

        <div class="d-flex justify-content-end gap-2">
          <button type="submit" class="btn btn-outline-primary" onclick="document.getElementById('_action').value='save'" <?= $isLocked ? 'disabled' : '' ?>>Save (Draft)</button>
          <button type="button" class="btn btn-success" id="openLimitChangeDeclarationBtn" <?= $isLocked ? 'disabled' : '' ?>>Submit Application</button>
          <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteLimitChangeModal">
            Delete Application
          </button>
        </div>
        </fieldset>
      </form>
    </div>
  </div>
</section>

<?php require __DIR__ . '/../shared/submit_feedback.php'; ?>

<div class="modal fade" id="limitChangeDeclarationModal" tabindex="-1" aria-labelledby="limitChangeDeclarationModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="limitChangeDeclarationModalLabel">Declaration</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="border rounded p-3 bg-light" role="document">
          <p class="mb-2"><strong>Declaration</strong></p>
          <p class="mb-0">
            <?= renderAgreementText($submitDeclarationText !== '' ? $submitDeclarationText : 'By submitting this application, you confirm the details provided are true and correct.') ?>
          </p>
        </div>
        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" id="limitChangeAgreeModal" aria-describedby="limitChangeAgreeModalError" />
          <label class="form-check-label" for="limitChangeAgreeModal">
            I have read and agree to the terms and conditions.
          </label>
        </div>
        <div id="limitChangeAgreeModalError" class="text-danger small mt-2 d-none" role="alert">
          You must agree before submitting.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="confirmLimitChangeSubmitBtn" disabled>
          Confirm & Submit
        </button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="limitChangeSubmitBlockedModal" tabindex="-1" aria-labelledby="limitChangeSubmitBlockedModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-warning">
      <div class="modal-header">
        <h5 class="modal-title" id="limitChangeSubmitBlockedModalLabel">Cannot Submit Application</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div role="alert" id="limitChangeSubmitBlockedModalMessage">Please complete all mandatory fields marked with <span class="text-danger">*</span> before submitting.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
      </div>
    </div>
  </div>
</div>

<?php if (($typeKey ?? '') === 'dpc_limit_change'): ?>
<div class="modal fade" id="limitChangeScopeModal" tabindex="-1" aria-labelledby="limitChangeScopeLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="limitChangeScopeLabel">Select Change Type</h5>
      </div>
      <div class="modal-body">
        <p class="mb-3">Choose what you want to change for this DPC limit change application.</p>
        <div class="d-grid gap-2">
          <button type="button" class="btn btn-outline-primary js-limit-scope-option" data-scope="both">Change to both monthly credit limit and transaction limit</button>
          <button type="button" class="btn btn-outline-primary js-limit-scope-option" data-scope="credit_only">Change to monthly credit limit only</button>
          <button type="button" class="btn btn-outline-primary js-limit-scope-option" data-scope="transaction_only">Change to transaction limit only</button>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
  (function () {
    const form = document.querySelector('form[action="index.php?route=cards/limit-change-save"]');
    const csrfInput = form ? form.querySelector('input[name="_csrf"]') : null;
    const applicationIdInput = form ? form.querySelector('input[name="application_id"]') : null;
    const actionInput = document.getElementById('_action');
    const openDeclarationBtn = document.getElementById('openLimitChangeDeclarationBtn');
    const declarationModalEl = document.getElementById('limitChangeDeclarationModal');
    const submitBlockedModalEl = document.getElementById('limitChangeSubmitBlockedModal');
    const agreeModal = document.getElementById('limitChangeAgreeModal');
    const confirmSubmitBtn = document.getElementById('confirmLimitChangeSubmitBtn');
    const agreeModalError = document.getElementById('limitChangeAgreeModalError');
    const reasonSel = document.getElementById('limitChangeReason');
    const reasonOther = document.getElementById('limitChangeReasonOther');
    const approverSelect = document.getElementById('approverSelect');
    const approverSelectWrap = document.getElementById('approverSelectWrap');
    const approverEmailWrap = document.getElementById('approverEmailWrap');
    const approverEmailInput = document.getElementById('approverEmailInput');
    const approverEmailWarning = document.getElementById('approverEmailWarning');
    const approverEmailCapsWarning = document.getElementById('approverEmailCapsWarning');
    const approverEmailValid = document.getElementById('approverEmailValid');
    const approverHelp = document.getElementById('approverHelp');
    const approverAutoWrap = document.getElementById('approverAutoWrap');
    const approverAutoDisplay = document.getElementById('approverAutoDisplay');
    const approverReqErr = document.getElementById('approverReqError');
    const approverEmailReqErr = document.getElementById('approverEmailReqError');
    const applicantEmail = String(<?= json_encode($applicantEmailJs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?> || '').trim().toLowerCase();
    const applicantEmployeeId = String(<?= json_encode(trim((string)($data['target_employee_id'] ?? ($card['EmployeeID'] ?? ''))), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?> || '').trim().toLowerCase();
    let approverEmailMatchedEmployeeId = '';
    const creditLimitNew = document.getElementById('creditLimitNew');
    const creditScopeFields = Array.from(document.querySelectorAll('.js-credit-scope-field'));
    const txnLimitNew = document.getElementById('transactionLimitNew');
    const transactionScopeFields = Array.from(document.querySelectorAll('.js-transaction-scope-field'));
    const agedTxnConfirm = document.getElementById('agedTransactionsConfirmed');
    const limitChangeScopeField = document.getElementById('limitChangeScopeField');
    const limitChangeScopeSelect = document.getElementById('limitChangeScopeSelect');
    const scopeModalEl = document.getElementById('limitChangeScopeModal');
    const submitBlockedModalMessage = document.getElementById('limitChangeSubmitBlockedModalMessage');
    const scopeOptionButtons = Array.from(document.querySelectorAll('.js-limit-scope-option'));
    const periodSections = [
      {
        duration: document.getElementById('creditLimitChangeDurationType'),
        fromWrap: document.getElementById('creditPeriodFromWrap'),
        toWrap: document.getElementById('creditPeriodToWrap'),
        fromInput: document.getElementById('creditPeriodChangeFrom'),
        toInput: document.getElementById('creditPeriodChangeTo'),
      },
      {
        duration: document.getElementById('transactionLimitChangeDurationType'),
        fromWrap: document.getElementById('transactionPeriodFromWrap'),
        toWrap: document.getElementById('transactionPeriodToWrap'),
        fromInput: document.getElementById('transactionPeriodChangeFrom'),
        toInput: document.getElementById('transactionPeriodChangeTo'),
      }
    ].filter((section) => !!section.duration);
    const creditLimitMaxAmount = creditLimitNew ? Number(creditLimitNew.getAttribute('data-max-credit-limit') || '0') : 0;
    const temporaryLimitPeriodMonths = <?= json_encode($temporaryLimitPeriodMonths, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const todayDate = <?= json_encode($todayDate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const manualApproverThreshold = <?= json_encode($manualApproverThreshold, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const typeKey = <?= json_encode((string)($typeKey ?? ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const initialLimitChangeScope = <?= json_encode($limitChangeScope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const shouldShowScopeModal = <?= $showDpcScopeModal ? 'true' : 'false' ?>;
    const limitChangeScopeLabels = <?= json_encode($limitChangeScopeLabels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const hasValidationErrors = <?= $showErrors ? 'true' : 'false' ?>;
    const rules = <?= json_encode($approverRules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
    const directory = <?= json_encode($approverDirectory, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}' ?>;
    const sesApproverEmails = new Set((<?= json_encode($sesApproverEmails, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]' ?>).map((email) => String(email || '').trim().toLowerCase()).filter(Boolean));
    const selected = approverSelect ? (approverSelect.getAttribute('data-selected') || '') : '';
    const employeeGroup = <?= json_encode(trim((string)($data['employee_group'] ?? '')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    let isSubmitting = false;
    let approverEmailCheckTimer = null;
    let approverEmailRequestSeq = 0;
    let approverEmailAbortController = null;
    let limitChangeScope = initialLimitChangeScope;
    let scopeModalInstance = null;
    let limitChangeScopeSaveRequest = 0;

    function parseAmount(raw) {
      if (!raw) return null;
      const n = Number(String(raw).replace(/[^0-9.]/g, ''));
      if (!Number.isFinite(n) || n <= 0) return null;
      return n;
    }

    function formatCurrency0(raw) {
      const digits = String(raw || '').replace(/[^0-9]/g, '');
      if (!digits) return '';
      const n = Number(digits);
      if (!Number.isFinite(n)) return '';
      return '$' + n.toLocaleString('en-US', { maximumFractionDigits: 0 });
    }

    function parseWholeAmount(raw) {
      const digits = String(raw || '').replace(/[^0-9]/g, '');
      if (!digits) return null;
      const n = Number(digits);
      if (!Number.isFinite(n) || n <= 0) return null;
      return n;
    }

    function toggleMultipleFeedback(input, invalid) {
      if (!input) return;
      const wrap = input.closest('.col-md-6');
      const hint = wrap ? wrap.querySelector('.js-multiple-msg') : null;
      if (invalid) {
        if (hint) hint.classList.remove('d-none');
      } else {
        if (hint) hint.classList.add('d-none');
      }
      syncAmountInvalidState(input);
    }

    function validateMultipleOf100(input) {
      if (!input) return true;
      const val = String(input.value || '').trim();
      if (val === '') {
        toggleMultipleFeedback(input, false);
        return true;
      }
      const amount = parseWholeAmount(val);
      const ok = amount !== null && (amount % 100 === 0);
      toggleMultipleFeedback(input, !ok);
      return ok;
    }

    function toggleMaxCreditFeedback(input, invalid) {
      if (!input) return;
      const wrap = input.closest('.col-md-6');
      const hint = wrap ? wrap.querySelector('.js-max-credit-msg') : null;
      if (invalid) {
        if (hint) hint.classList.remove('d-none');
      } else {
        if (hint) hint.classList.add('d-none');
      }
      syncAmountInvalidState(input);
    }

    function syncAmountInvalidState(input) {
      if (!input) return;
      const wrap = input.closest('.col-md-6');
      if (!wrap) return;
      const multipleVisible = !!wrap.querySelector('.js-multiple-msg:not(.d-none)');
      const maxVisible = !!wrap.querySelector('.js-max-credit-msg:not(.d-none)');
      const txnCreditVisible = !!wrap.querySelector('.js-txn-credit-msg:not(.d-none)');
      const invalid = multipleVisible || maxVisible || txnCreditVisible;
      input.classList.toggle('is-invalid', invalid);
      input.setAttribute('aria-invalid', invalid ? 'true' : 'false');
    }

    function validateMaxCreditLimit(input) {
      if (!input || !creditLimitMaxAmount) return true;
      const val = String(input.value || '').trim();
      if (val === '') {
        toggleMaxCreditFeedback(input, false);
        return true;
      }
      const amount = parseWholeAmount(val);
      const ok = amount !== null && amount <= creditLimitMaxAmount;
      toggleMaxCreditFeedback(input, !ok);
      return ok;
    }

    function toggleTxnCreditFeedback(input, invalid) {
      if (!input) return;
      const wrap = input.closest('.col-md-6');
      const hint = wrap ? wrap.querySelector('.js-txn-credit-msg') : null;
      if (invalid) {
        if (hint) hint.classList.remove('d-none');
      } else {
        if (hint) hint.classList.add('d-none');
      }
      syncAmountInvalidState(input);
    }

    function validateTransactionDoesNotExceedCredit() {
      if (!txnLimitNew || typeKey === 'dtc_limit_change') {
        return true;
      }
      const scope = getLimitChangeScope();
      if (scope === 'credit_only') {
        toggleTxnCreditFeedback(txnLimitNew, false);
        return true;
      }

      const txnAmount = getSelectedTransactionLimitAmount();
      if (txnAmount === null) {
        toggleTxnCreditFeedback(txnLimitNew, false);
        return true;
      }

      const creditSource = scope === 'transaction_only'
        ? document.getElementById('creditLimitCurrent')
        : creditLimitNew;
      const creditAmount = parseAmount(creditSource ? creditSource.value : '');
      if (creditAmount === null) {
        toggleTxnCreditFeedback(txnLimitNew, false);
        return true;
      }

      const ok = txnAmount <= creditAmount;
      toggleTxnCreditFeedback(txnLimitNew, !ok);
      return ok;
    }

    function validateAgedTxnConfirmation() {
      if (!agedTxnConfirm) return true;
      const hint = agedTxnConfirm.parentElement ? agedTxnConfirm.parentElement.querySelector('.js-aged-txn-msg') : null;
      const ok = !!agedTxnConfirm.checked;
      agedTxnConfirm.classList.toggle('is-invalid', !ok);
      agedTxnConfirm.setAttribute('aria-invalid', ok ? 'false' : 'true');
      if (hint) {
        hint.classList.toggle('d-none', ok);
      }
      return ok;
    }

    function checklistIconMarkup(isComplete) {
      return isComplete
        ? '<i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>'
        : '<i class="bi bi-x-circle-fill text-danger me-2" aria-hidden="true"></i>';
    }

    function setChecklistIcon(stepKey, isComplete) {
      const icon = document.querySelector(`.check-icon[data-step-key="${stepKey}"]`);
      if (!icon) {
        return;
      }
      icon.innerHTML = checklistIconMarkup(!!isComplete);
    }

    function isTemporaryPeriodSectionComplete(section) {
      if (!section || !section.duration) {
        return true;
      }
      if (String(section.duration.value || '').trim().toLowerCase() !== 'temporary') {
        return true;
      }

      const fromValue = section.fromInput ? String(section.fromInput.value || '').trim() : '';
      const toValue = section.toInput ? String(section.toInput.value || '').trim() : '';
      if (fromValue === '' || toValue === '' || toValue < fromValue) {
        return false;
      }

      const maxToValue = addMonths(fromValue, temporaryLimitPeriodMonths);
      return maxToValue === '' || toValue <= maxToValue;
    }

    function updateChecklistState() {
      const scope = getLimitChangeScope();
      const cardIdInput = form ? form.querySelector('input[name="card_id"]') : null;
      const cardIdValue = Number(cardIdInput ? (cardIdInput.value || '0') : '0');
      const creditAmount = parseWholeAmount(creditLimitNew ? creditLimitNew.value : '');
      const txnAmount = getSelectedTransactionLimitAmount();
      const creditCurrentInput = form ? form.querySelector('input[name="credit_limit_current"]') : null;
      const creditLimitForTxnComparison = scope === 'transaction_only'
        ? parseAmount(creditCurrentInput ? creditCurrentInput.value : '')
        : creditAmount;

      let limitsComplete = true;
      if (scope !== 'transaction_only') {
        limitsComplete = limitsComplete
          && creditAmount !== null
          && creditAmount > 0
          && creditAmount % 100 === 0
          && (!creditLimitMaxAmount || creditAmount <= creditLimitMaxAmount)
          && isTemporaryPeriodSectionComplete(periodSections[0]);
      }
      if (typeKey !== 'dtc_limit_change' && scope !== 'credit_only') {
        limitsComplete = limitsComplete
          && txnAmount !== null
          && txnAmount > 0
          && txnAmount % 100 === 0
          && isTemporaryPeriodSectionComplete(periodSections[1])
          && (creditLimitForTxnComparison === null || txnAmount <= creditLimitForTxnComparison);
      }

      const justificationComplete = !!reasonSel
        && String(reasonSel.value || '').trim() !== ''
        && !!reasonOther
        && String(reasonOther.value || '').trim() !== ''
        && !!agedTxnConfirm
        && agedTxnConfirm.checked;

      setChecklistIcon('card_details', cardIdValue > 0);
      setChecklistIcon('limits_complete', limitsComplete);
      setChecklistIcon('justification_complete', justificationComplete);
    }

    function getSubmitBlockedMessage() {
      const txnCreditHint = txnLimitNew && txnLimitNew.closest('.col-md-6')
        ? txnLimitNew.closest('.col-md-6').querySelector('.js-txn-credit-msg')
        : null;
      if (txnCreditHint && !txnCreditHint.classList.contains('d-none')) {
        return 'Transaction Limit cannot exceed Credit Limit.';
      }
      return 'Please complete all mandatory fields marked with * before submitting.';
    }

    function showSubmitBlockedMessage() {
      if (submitBlockedModalMessage) {
        submitBlockedModalMessage.textContent = getSubmitBlockedMessage();
      }
      if (submitBlockedModalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modal = bootstrap.Modal.getOrCreateInstance(submitBlockedModalEl);
        modal.show();
      }
    }

    function getBootstrapModalInstance(modalEl) {
      if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
        return null;
      }
      return bootstrap.Modal.getOrCreateInstance(modalEl);
    }

    function showScopeSelectionModal() {
      const modal = getBootstrapModalInstance(scopeModalEl);
      if (!modal) {
        return false;
      }
      scopeModalInstance = modal;
      scopeModalInstance.show();
      return true;
    }

    function persistLimitChangeScope(scope) {
      if (typeKey !== 'dpc_limit_change') {
        return;
      }
      const applicationId = applicationIdInput ? Number(applicationIdInput.value || '0') : 0;
      const csrfToken = csrfInput ? String(csrfInput.value || '').trim() : '';
      if (!applicationId || !csrfToken) {
        return;
      }

      const requestId = ++limitChangeScopeSaveRequest;
      const body = new URLSearchParams();
      body.set('_csrf', csrfToken);
      body.set('application_id', String(applicationId));
      body.set('limit_change_scope', String(scope || 'both'));

      fetch('index.php?route=cards/limit-change-scope-save', {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        },
        body: body.toString(),
      })
        .then((response) => response.ok ? response.json() : Promise.reject(new Error('Request failed')))
        .then((payload) => {
          if (requestId !== limitChangeScopeSaveRequest) {
            return;
          }
          if (payload && payload.ok && payload.limit_change_scope) {
            limitChangeScope = String(payload.limit_change_scope || 'both');
            if (limitChangeScopeField) {
              limitChangeScopeField.value = limitChangeScope;
            }
          }
        })
        .catch(() => {});
    }

    function addMonths(dateText, months) {
      if (!dateText) return '';
      const parts = String(dateText).split('-').map(Number);
      if (parts.length !== 3 || parts.some((part) => !Number.isFinite(part))) return '';
      const [year, month, day] = parts;
      const dt = new Date(Date.UTC(year, month - 1, day));
      if (Number.isNaN(dt.getTime())) return '';
      dt.setUTCMonth(dt.getUTCMonth() + Number(months || 0));
      return dt.toISOString().slice(0, 10);
    }

    function togglePeriodRangeFeedback(section, invalid) {
      if (!section || !section.toInput) return;
      const wrap = section.toInput.closest('.col-md-3');
      const hint = wrap ? wrap.querySelector('.js-period-range-msg') : null;
      if (invalid) {
        section.toInput.classList.add('is-invalid');
        section.toInput.setAttribute('aria-invalid', 'true');
        if (hint) hint.classList.remove('d-none');
      } else {
        section.toInput.classList.remove('is-invalid');
        section.toInput.setAttribute('aria-invalid', 'false');
        if (hint) hint.classList.add('d-none');
      }
    }

    function normalizeType(v) {
      return String(v || '').trim().toUpperCase();
    }

    function parseSelectedType(v) {
      const first = String(v || '').split('|')[0];
      return normalizeType(first);
    }

    function normalizeEmailValue(value) {
      return String(value || '').trim().toLowerCase();
    }

    function approverStageConfig(amount) {
      if (!Array.isArray(rules) || amount === null) {
        return { mode: 'select', options: [] };
      }
      const matchedRules = rules.filter((r) => {
        const min = Number(r.MinLimit ?? 0);
        const maxRaw = r.MaxLimit;
        const max = (maxRaw === null || maxRaw === '') ? null : Number(maxRaw);
        if (amount < min) return false;
        if (max !== null && amount > max) return false;
        return true;
      });
      const stageValues = matchedRules
        .map((r) => Number(r.ApprovalStage ?? 1))
        .filter((stage) => Number.isFinite(stage) && stage > 0);
      const activeStage = stageValues.length > 0 ? Math.min(...stageValues) : 1;
      const stageRules = matchedRules.filter((r) => {
        const stage = Number(r.ApprovalStage ?? 1);
        return !Number.isFinite(stage) || stage <= 0 ? activeStage === 1 : stage === activeStage;
      });
      const manual = stageRules.some((r) => {
        const type = normalizeType(r.RequiredApproverType);
        return type === 'EMAIL' || type === 'MANUAL';
      });
      if (manual) {
        return { mode: 'manual', options: [] };
      }
      return { mode: 'select', options: optionsForAmount(amount) };
    }

    function shouldUseManualApprover(amount) {
      return approverStageConfig(amount).mode === 'manual';
    }

    function getLimitChangeScope() {
      const scope = String(limitChangeScope || '').trim().toLowerCase();
      if (scope === 'credit_only' || scope === 'transaction_only') {
        return scope;
      }
      return 'both';
    }

    function getApproverAmount() {
      const scope = getLimitChangeScope();
      if (scope === 'transaction_only') {
        return getSelectedTransactionLimitAmount();
      }
      return parseAmount(creditLimitNew ? creditLimitNew.value : '');
    }

    function getSelectedTransactionLimitAmount() {
      if (!txnLimitNew) {
        return null;
      }
      const selectedOption = txnLimitNew.options && txnLimitNew.selectedIndex >= 0
        ? txnLimitNew.options[txnLimitNew.selectedIndex]
        : null;
      if (!selectedOption) {
        return null;
      }
      const dataAmount = selectedOption.getAttribute('data-trans-limit');
      if (dataAmount) {
        return parseAmount(dataAmount);
      }
      return parseAmount(selectedOption.textContent || '');
    }

    function updateLimitChangeScopeSummary(scope) {
      if (!limitChangeScopeSelect) {
        return;
      }
      const key = String(scope || 'both').trim().toLowerCase();
      limitChangeScopeSelect.value = limitChangeScopeLabels[key] ? key : 'both';
    }

    function applyLimitChangeScope() {
      const scope = typeKey === 'dpc_limit_change' ? getLimitChangeScope() : 'both';
      if (limitChangeScopeField) {
        limitChangeScopeField.value = scope;
      }
      updateLimitChangeScopeSummary(scope);
      creditScopeFields.forEach((el) => {
        el.style.display = scope === 'transaction_only' ? 'none' : '';
      });
      transactionScopeFields.forEach((el) => {
        el.style.display = scope === 'credit_only' ? 'none' : '';
      });
      if (scope === 'credit_only') {
        if (txnLimitNew) {
          txnLimitNew.value = '';
        }
        const txnDuration = document.getElementById('transactionLimitChangeDurationType');
        if (txnDuration) txnDuration.value = 'permanent';
        const txnFrom = document.getElementById('transactionPeriodChangeFrom');
        const txnTo = document.getElementById('transactionPeriodChangeTo');
        if (txnFrom) txnFrom.value = '';
        if (txnTo) txnTo.value = '';
        toggleTxnCreditFeedback(txnLimitNew, false);
      }
      if (scope === 'transaction_only') {
        if (creditLimitNew) {
          creditLimitNew.value = '';
        }
        const creditDuration = document.getElementById('creditLimitChangeDurationType');
        if (creditDuration) creditDuration.value = 'permanent';
        const creditFrom = document.getElementById('creditPeriodChangeFrom');
        const creditTo = document.getElementById('creditPeriodChangeTo');
        if (creditFrom) creditFrom.value = '';
        if (creditTo) creditTo.value = '';
      }
      validateTransactionDoesNotExceedCredit();
      periodSections.forEach(function (section) {
        toggleDurationFields(section);
        updatePeriodDateMinimums(section);
      });
      renderApprovers();
      updateChecklistState();
    }

    function syncApproverEmailWarning() {
      if (!approverEmailWarning || !approverEmailInput) return;
      const amount = getApproverAmount();
      const email = normalizeEmailValue(approverEmailInput.value);
      const showWarning = shouldUseManualApprover(amount) && email !== '' && !sesApproverEmails.has(email) && approverEmailCapsWarning && approverEmailCapsWarning.classList.contains('d-none');
      approverEmailWarning.classList.toggle('d-none', !showWarning);
    }

    function syncApproverEmailCapsWarning(showWarning) {
      if (!approverEmailCapsWarning) return;
      approverEmailCapsWarning.classList.toggle('d-none', !showWarning);
    }

    function syncApproverEmailValid(showValid) {
      if (!approverEmailValid) return;
      approverEmailValid.classList.toggle('d-none', !showValid);
    }

    function scheduleApproverEmailCheck() {
      if (approverEmailCheckTimer) {
        window.clearTimeout(approverEmailCheckTimer);
      }
      approverEmailCheckTimer = window.setTimeout(runApproverEmailCheck, 250);
    }

    function runApproverEmailCheck() {
      if (!approverEmailInput) return;
      const amount = getApproverAmount();
      const email = normalizeEmailValue(approverEmailInput.value);
      const useManual = shouldUseManualApprover(amount);

      if (!useManual || email === '') {
        if (approverEmailAbortController) {
          approverEmailAbortController.abort();
          approverEmailAbortController = null;
        }
        approverEmailMatchedEmployeeId = '';
        syncApproverEmailCapsWarning(false);
        syncApproverEmailValid(false);
        syncApproverEmailWarning();
        validateApproverSelection();
        return;
      }

      const requestId = ++approverEmailRequestSeq;
      if (approverEmailAbortController) {
        approverEmailAbortController.abort();
      }
      approverEmailAbortController = typeof AbortController !== 'undefined' ? new AbortController() : null;

      const url = 'index.php?route=cards/limit-change-approver-email-check'
        + '&email=' + encodeURIComponent(email)
        + '&employee_group=' + encodeURIComponent(employeeGroup);

      fetch(url, {
        headers: {
          'Accept': 'application/json',
        },
        signal: approverEmailAbortController ? approverEmailAbortController.signal : undefined,
      })
        .then((response) => response.ok ? response.json() : Promise.reject(new Error('Request failed')))
        .then((payload) => {
          if (requestId !== approverEmailRequestSeq) return;
          const existsInCaps = !!(payload && payload.exists_in_caps);
          const existsInSesView = !!(payload && payload.exists_in_ses_view);
          approverEmailMatchedEmployeeId = String(payload && payload.matched_employee_id || '').trim().toLowerCase();
          syncApproverEmailCapsWarning(!existsInCaps);
          syncApproverEmailValid(existsInCaps && existsInSesView && email !== '');
          if (approverEmailWarning) {
            approverEmailWarning.classList.toggle('d-none', !existsInCaps || existsInSesView || email === '');
          }
          validateApproverSelection();
        })
        .catch((error) => {
          if (error && error.name === 'AbortError') {
            return;
          }
          approverEmailMatchedEmployeeId = '';
          syncApproverEmailValid(false);
          validateApproverSelection();
        });
    }

    function syncApproverInputMode() {
      const amount = getApproverAmount();
      const config = approverStageConfig(amount);
      const useManual = config.mode === 'manual';
      const autoMode = config.mode !== 'manual' && Array.isArray(config.options) && config.options.length > 1;
      const autoSingle = config.mode !== 'manual' && Array.isArray(config.options) && config.options.length === 1;
      if (approverSelectWrap) {
        approverSelectWrap.style.display = useManual || autoMode || autoSingle ? 'none' : '';
      }
      if (approverEmailWrap) {
        approverEmailWrap.style.display = useManual ? '' : 'none';
      }
      if (approverAutoWrap) {
        approverAutoWrap.classList.toggle('d-none', !(autoMode || autoSingle));
      }
      if (approverSelect) {
        approverSelect.disabled = useManual || autoMode || autoSingle;
      }
      if (approverEmailInput) {
        approverEmailInput.disabled = !useManual;
      }
      if (approverHelp) {
        if (useManual) {
          approverHelp.textContent = 'Enter the approver email address required by the workflow rule.';
        } else if (autoMode) {
          approverHelp.textContent = 'Approval recipients are determined by workflow rules. All matching approvers will be notified and any one approver can approve the request.';
        } else if (autoSingle) {
          approverHelp.textContent = 'Approval recipient is determined automatically by workflow rules.';
        } else {
          approverHelp.textContent = 'Approvers are filtered by applicant employee group and amount rules<?= $employeeType !== '' ? ' (' . h($employeeType) . ' routing)' : '' ?>.';
        }
      }
      if (approverAutoDisplay) {
        if (autoMode) {
          const labels = config.options
            .map((option) => String(option && option.label ? option.label : '').trim())
            .filter(Boolean);
          approverAutoDisplay.textContent = labels.length > 0
            ? 'Approval request will be sent to: ' + labels.join(', ') + '. Any one approver can approve this request.'
            : 'Approval request will be sent to all matching approvers. Any one approver can approve this request.';
        } else if (autoSingle) {
          const only = config.options[0];
          approverAutoDisplay.textContent = only && only.label ? `Approval recipient will be selected automatically: ${only.label}` : 'Approval recipient will be selected automatically.';
        } else {
          approverAutoDisplay.textContent = 'Approval recipients will be determined automatically.';
        }
      }
      if (!useManual && approverEmailWarning) {
        approverEmailWarning.classList.add('d-none');
      }
      syncApproverEmailCapsWarning(false);
      syncApproverEmailValid(false);
      scheduleApproverEmailCheck();
    }

    function optionsForAmount(amount) {
      if (!Array.isArray(rules) || amount === null) return [];
      const matchedRules = rules.filter((r) => {
        const min = Number(r.MinLimit ?? 0);
        const maxRaw = r.MaxLimit;
        const max = (maxRaw === null || maxRaw === '') ? null : Number(maxRaw);
        if (amount < min) return false;
        if (max !== null && amount > max) return false;
        return true;
      });
      const stageValues = matchedRules
        .map((r) => Number(r.ApprovalStage ?? 1))
        .filter((stage) => Number.isFinite(stage) && stage > 0);
      const activeStage = stageValues.length > 0 ? Math.min(...stageValues) : 1;
      const seen = new Set();
      const out = [];
      matchedRules.forEach((r) => {
        const stage = Number(r.ApprovalStage ?? 1);
        if (Number.isFinite(stage) && stage > 0 && stage !== activeStage) return;

        const type = normalizeType(r.RequiredApproverType);
        if (!type || type === 'EMAIL' || type === 'MANUAL') return;

        const rows = Array.isArray(directory[type]) ? directory[type] : [];
        if (rows.length > 0) {
          rows.forEach((row) => {
            const val = String(row.value || '').trim();
            if (!val || seen.has(val)) return;
            seen.add(val);
            out.push({
              value: val,
              label: String(row.label || val),
              type,
              email: String(row.email || '').trim().toLowerCase(),
            });
          });
          return;
        }

        if (type === 'SES') {
          const rank = normalizeType(r.RequiredRank || 'SES');
          const val = `SES|${rank}`;
          if (!seen.has(val)) {
            seen.add(val);
            out.push({ value: val, label: `SES (${rank})`, type: 'SES' });
          }
          return;
        }

        if (!seen.has(type)) {
          seen.add(type);
          out.push({ value: type, label: type, type });
        }
      });
      return out;
    }

    function selectedApproverEmail() {
      if (!approverSelect) return '';
      const selectedOption = approverSelect.options && approverSelect.selectedIndex >= 0
        ? approverSelect.options[approverSelect.selectedIndex]
        : null;
      return selectedOption ? String(selectedOption.getAttribute('data-email') || '').trim().toLowerCase() : '';
    }

    function isApplicantApproverClient() {
      if (!applicantEmail) return false;
      const amount = getApproverAmount();
      const useManual = shouldUseManualApprover(amount);
      if (useManual) {
        const enteredEmail = normalizeEmailValue(approverEmailInput ? approverEmailInput.value : '');
        return (applicantEmail && enteredEmail === applicantEmail)
          || (applicantEmployeeId && approverEmailMatchedEmployeeId && approverEmailMatchedEmployeeId === applicantEmployeeId);
      }
      return selectedApproverEmail() !== '' && selectedApproverEmail() === applicantEmail;
    }

    function validateApproverSelection() {
      const amount = getApproverAmount();
      const config = approverStageConfig(amount);
      const useManual = config.mode === 'manual';
      const autoMode = config.mode !== 'manual' && Array.isArray(config.options) && config.options.length > 1;
      const autoSingle = config.mode !== 'manual' && Array.isArray(config.options) && config.options.length === 1;
      const selfApprover = isApplicantApproverClient();
      let ok = true;

      if (useManual) {
        const email = normalizeEmailValue(approverEmailInput ? approverEmailInput.value : '');
        ok = email !== '' && !selfApprover;
        if (approverEmailInput) {
          approverEmailInput.classList.toggle('is-invalid', !ok);
        }
        if (approverEmailReqErr) {
          approverEmailReqErr.textContent = selfApprover
            ? 'Approver cannot be the applicant.'
            : 'Select your Band 1 / 1 Star or above approver.';
          approverEmailReqErr.style.display = ok ? 'none' : '';
        }
        if (approverReqErr) {
          approverReqErr.style.display = 'none';
        }
        return ok;
      }

      if (autoMode || autoSingle) {
        if (approverSelect) {
          approverSelect.classList.remove('is-invalid');
        }
        if (approverEmailInput) {
          approverEmailInput.classList.remove('is-invalid');
        }
        if (approverReqErr) {
          approverReqErr.style.display = 'none';
        }
        if (approverEmailReqErr) {
          approverEmailReqErr.style.display = 'none';
        }
        return true;
      }

      const selectedValue = approverSelect ? String(approverSelect.value || '').trim() : '';
      ok = selectedValue !== '' && !selfApprover;
      if (approverSelect) {
        approverSelect.classList.toggle('is-invalid', !ok);
      }
      if (approverReqErr) {
        approverReqErr.textContent = selfApprover
          ? 'Approver cannot be the applicant.'
          : 'Select your Band 1 / 1 Star or above approver.';
        approverReqErr.style.display = ok ? 'none' : '';
      }
      if (approverEmailReqErr) {
        approverEmailReqErr.style.display = 'none';
      }
      return ok;
    }

    function renderApprovers() {
      if (!approverSelect) return;
      const amount = getApproverAmount();
      const config = approverStageConfig(amount);
      const opts = Array.isArray(config.options) ? config.options : [];
      approverSelect.innerHTML = '';
      const empty = document.createElement('option');
      empty.value = '';
      empty.textContent = amount === null
        ? (getLimitChangeScope() === 'transaction_only' ? 'Enter New Transaction Limit first' : 'Enter New Credit Limit first')
        : 'Select an approver';
      approverSelect.appendChild(empty);

      let matched = false;
      opts.forEach((o) => {
        const opt = document.createElement('option');
        opt.value = o.value;
        opt.textContent = o.label;
        opt.setAttribute('data-email', String(o.email || '').trim().toLowerCase());
        if (selected && (selected === o.value || parseSelectedType(selected) === o.type)) {
          opt.selected = true;
          matched = true;
        }
        approverSelect.appendChild(opt);
      });
      if (!matched) {
        approverSelect.value = '';
      }
      syncApproverInputMode();
      validateApproverSelection();
    }

    function toggleDurationFields(section) {
      if (!section || !section.duration || !section.fromWrap || !section.toWrap) return;
      const isTemporary = String(section.duration.value) === 'temporary';
      section.fromWrap.style.display = isTemporary ? '' : 'none';
      section.toWrap.style.display = isTemporary ? '' : 'none';
      if (!isTemporary) {
        if (section.fromInput) section.fromInput.value = '';
        if (section.toInput) section.toInput.value = '';
        togglePeriodRangeFeedback(section, false);
      }
    }

    function updatePeriodDateMinimums(section) {
      if (!section) return;
      if (section.toInput) {
        const fromValue = section.fromInput ? String(section.fromInput.value || '').trim() : '';
        section.toInput.min = fromValue && fromValue >= todayDate ? fromValue : todayDate;
        section.toInput.max = fromValue ? addMonths(fromValue, temporaryLimitPeriodMonths) : '';
      }
    }

    function validateTemporaryDates(section) {
      if (!section || !section.duration || String(section.duration.value) !== 'temporary') {
        return true;
      }

      let ok = true;
      const fromValue = section.fromInput ? String(section.fromInput.value || '').trim() : '';
      const toValue = section.toInput ? String(section.toInput.value || '').trim() : '';

      if (section.fromInput && fromValue !== '' && fromValue < todayDate) {
        section.fromInput.classList.add('is-invalid');
        section.fromInput.setAttribute('aria-invalid', 'true');
        ok = false;
      }
      if (section.toInput && toValue !== '' && toValue < todayDate) {
        section.toInput.classList.add('is-invalid');
        section.toInput.setAttribute('aria-invalid', 'true');
        ok = false;
      }
      if (section.toInput && fromValue !== '' && toValue !== '' && toValue < fromValue) {
        section.toInput.classList.add('is-invalid');
        section.toInput.setAttribute('aria-invalid', 'true');
        ok = false;
      }
      if (section.toInput && fromValue !== '' && toValue !== '') {
        const maxToValue = addMonths(fromValue, temporaryLimitPeriodMonths);
        const rangeOk = maxToValue === '' || toValue <= maxToValue;
        togglePeriodRangeFeedback(section, !rangeOk);
        ok = rangeOk && ok;
      } else {
        togglePeriodRangeFeedback(section, false);
      }

      return ok;
    }

    function validateLimitChangeForm() {
      const scope = getLimitChangeScope();
      let ok = true;
      if (scope !== 'transaction_only') {
        ok = validateMultipleOf100(creditLimitNew) && ok;
        ok = validateMaxCreditLimit(creditLimitNew) && ok;
      }
      if (scope !== 'credit_only' && txnLimitNew && txnLimitNew.tagName === 'INPUT') {
        ok = validateMultipleOf100(txnLimitNew) && ok;
      }
      if (scope !== 'credit_only') {
        ok = validateTransactionDoesNotExceedCredit() && ok;
      }
      ok = validateApproverSelection() && ok;
      ok = validateAgedTxnConfirmation() && ok;
      periodSections.forEach(function (section) {
        if (section.duration && section.duration.id === 'creditLimitChangeDurationType' && scope === 'transaction_only') {
          return;
        }
        if (section.duration && section.duration.id === 'transactionLimitChangeDurationType' && scope === 'credit_only') {
          return;
        }
        ok = validateTemporaryDates(section) && ok;
      });
      return ok;
    }

    function isSubmitAction() {
      return !!actionInput && String(actionInput.value || '').trim().toLowerCase() === 'submit';
    }

    function findFirstVisibleInvalidField() {
      if (!form) {
        return null;
      }
      const invalidFields = Array.from(form.querySelectorAll('.is-invalid'));
      return invalidFields.find((field) => {
        if (!(field instanceof HTMLElement)) {
          return false;
        }
        if (field.closest('[style*="display:none"]')) {
          return false;
        }
        return field.offsetParent !== null;
      }) || null;
    }

    function focusFirstVisibleInvalidField() {
      const field = findFirstVisibleInvalidField();
      if (!field) {
        return;
      }
      field.scrollIntoView({ behavior: 'smooth', block: 'center' });
      if (typeof field.focus === 'function') {
        window.setTimeout(function () {
          field.focus({ preventScroll: true });
        }, 100);
      }
    }

    if (reasonSel) {
      reasonSel.addEventListener('change', updateChecklistState);
    }
    if (reasonOther) {
      reasonOther.addEventListener('input', updateChecklistState);
      reasonOther.addEventListener('change', updateChecklistState);
    }
    periodSections.forEach(function (section) {
      if (section.duration) {
        section.duration.addEventListener('change', function () {
          toggleDurationFields(section);
          updatePeriodDateMinimums(section);
          updateChecklistState();
        });
      }
      if (section.fromInput) {
        section.fromInput.addEventListener('change', function () {
          section.fromInput.classList.remove('is-invalid');
          section.fromInput.setAttribute('aria-invalid', 'false');
          updatePeriodDateMinimums(section);
          updateChecklistState();
        });
      }
      if (section.toInput) {
        section.toInput.addEventListener('change', function () {
          section.toInput.classList.remove('is-invalid');
          section.toInput.setAttribute('aria-invalid', 'false');
          togglePeriodRangeFeedback(section, false);
          updateChecklistState();
        });
      }
    });
    if (creditLimitNew) {
        creditLimitNew.addEventListener('input', function () {
          creditLimitNew.value = formatCurrency0(creditLimitNew.value);
          validateMultipleOf100(creditLimitNew);
          validateMaxCreditLimit(creditLimitNew);
          validateTransactionDoesNotExceedCredit();
          updateChecklistState();
        });
      creditLimitNew.addEventListener('input', renderApprovers);
      creditLimitNew.addEventListener('change', renderApprovers);
      creditLimitNew.addEventListener('change', function () {
        validateMultipleOf100(creditLimitNew);
        validateMaxCreditLimit(creditLimitNew);
        validateTransactionDoesNotExceedCredit();
        updateChecklistState();
      });
    }
    if (approverEmailInput) {
      approverEmailInput.addEventListener('input', function () {
        scheduleApproverEmailCheck();
        validateApproverSelection();
      });
      approverEmailInput.addEventListener('change', runApproverEmailCheck);
      approverEmailInput.addEventListener('blur', runApproverEmailCheck);
    }
    if (approverSelect) {
      approverSelect.addEventListener('change', validateApproverSelection);
    }
    scopeOptionButtons.forEach(function (button) {
      button.addEventListener('click', function () {
        limitChangeScope = String(button.getAttribute('data-scope') || 'both');
        applyLimitChangeScope();
        persistLimitChangeScope(limitChangeScope);
        if (scopeModalEl) {
          if (!scopeModalInstance) {
            scopeModalInstance = getBootstrapModalInstance(scopeModalEl);
          }
          if (scopeModalInstance) {
          scopeModalInstance.hide();
          }
        }
      });
    });
    if (limitChangeScopeSelect) {
      limitChangeScopeSelect.addEventListener('change', function () {
        limitChangeScope = String(limitChangeScopeSelect.value || 'both');
        applyLimitChangeScope();
        persistLimitChangeScope(limitChangeScope);
        updateChecklistState();
      });
    }
    if (txnLimitNew && txnLimitNew.tagName === 'INPUT') {
      txnLimitNew.addEventListener('input', function () {
        txnLimitNew.value = formatCurrency0(txnLimitNew.value);
        validateMultipleOf100(txnLimitNew);
        validateTransactionDoesNotExceedCredit();
        updateChecklistState();
      });
      txnLimitNew.addEventListener('change', function () {
        validateMultipleOf100(txnLimitNew);
        validateTransactionDoesNotExceedCredit();
        updateChecklistState();
      });
    } else if (txnLimitNew) {
      txnLimitNew.addEventListener('change', function () {
        validateTransactionDoesNotExceedCredit();
        renderApprovers();
        updateChecklistState();
      });
    }
    if (agedTxnConfirm) {
      agedTxnConfirm.addEventListener('change', function () {
        validateAgedTxnConfirmation();
        updateChecklistState();
      });
    }
    if (agreeModal && confirmSubmitBtn) {
      agreeModal.addEventListener('change', function () {
        confirmSubmitBtn.disabled = !agreeModal.checked;
        agreeModal.setAttribute('aria-invalid', agreeModal.checked ? 'false' : 'true');
        if (agreeModalError) {
          agreeModalError.classList.toggle('d-none', agreeModal.checked);
        }
      });
      confirmSubmitBtn.addEventListener('click', function (evt) {
        if (isSubmitting) {
          evt.preventDefault();
          return;
        }
        if (!agreeModal.checked) {
          evt.preventDefault();
          agreeModal.setAttribute('aria-invalid', 'true');
          if (agreeModalError) {
            agreeModalError.classList.remove('d-none');
          }
          return;
        }
        isSubmitting = true;
        confirmSubmitBtn.disabled = true;
        confirmSubmitBtn.textContent = 'Submitting...';
        if (openDeclarationBtn) {
          openDeclarationBtn.disabled = true;
        }
        if (actionInput) {
          actionInput.value = 'submit';
        }
        if (form) {
          if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
          } else {
            form.submit();
          }
        }
      });
    }
    if (openDeclarationBtn) {
      openDeclarationBtn.addEventListener('click', function (evt) {
        const ok = validateLimitChangeForm();
        if (!ok) {
          evt.preventDefault();
          showSubmitBlockedMessage();
          return;
        }
        if (declarationModalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
          const modal = bootstrap.Modal.getOrCreateInstance(declarationModalEl);
          modal.show();
        }
      });
    }
    if (declarationModalEl) {
      declarationModalEl.addEventListener('show.bs.modal', function (evt) {
        const ok = validateLimitChangeForm();
        if (!ok) {
          evt.preventDefault();
          showSubmitBlockedMessage();
        }
      });
      declarationModalEl.addEventListener('show.bs.modal', function () {
        if (agreeModal) {
          agreeModal.checked = false;
          agreeModal.setAttribute('aria-invalid', 'false');
        }
        if (confirmSubmitBtn) {
          confirmSubmitBtn.disabled = true;
        }
        if (agreeModalError) {
          agreeModalError.classList.add('d-none');
        }
      });
    }
    if (form) {
      form.addEventListener('keydown', function (evt) {
        if (evt.key !== 'Enter') {
          return;
        }
        const target = evt.target;
        if (!(target instanceof HTMLElement)) {
          return;
        }
        const tagName = target.tagName.toUpperCase();
        if (tagName === 'TEXTAREA') {
          return;
        }
        if (tagName === 'BUTTON') {
          return;
        }
        if (tagName === 'INPUT') {
          const inputType = String(target.getAttribute('type') || 'text').toLowerCase();
          if (['submit', 'button', 'checkbox', 'radio', 'file'].includes(inputType)) {
            return;
          }
        }
        evt.preventDefault();
      });

      form.addEventListener('submit', function (evt) {
        if (!isSubmitAction()) {
          return;
        }
        const ok = validateLimitChangeForm();
        if (!ok) {
          evt.preventDefault();
          showSubmitBlockedMessage();
        }
      });
    }
    periodSections.forEach(function (section) {
      toggleDurationFields(section);
      updatePeriodDateMinimums(section);
    });
    applyLimitChangeScope();
    renderApprovers();
    syncApproverInputMode();
    updateChecklistState();
    if (hasValidationErrors) {
      window.setTimeout(focusFirstVisibleInvalidField, 50);
    }
    if (shouldShowScopeModal && scopeModalEl) {
      if (!showScopeSelectionModal()) {
        window.addEventListener('load', function () {
          showScopeSelectionModal();
        }, { once: true });
      }
    }
  })();
</script>

<!-- Delete Application Modal -->
<div class="modal fade" id="deleteLimitChangeModal" tabindex="-1" aria-labelledby="deleteLimitChangeLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="deleteLimitChangeLabel">Delete Application</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        Are you sure you want to delete this limit change application? This action cannot be undone.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <form method="post" action="index.php?route=cards/limit-change-delete" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= $csrf ?>">
          <input type="hidden" name="application_id" value="<?= h((string)$applicationId) ?>">
          <input type="hidden" name="type_key" value="<?= h((string)($typeKey ?? '')) ?>">
          <input type="hidden" name="card_id" value="<?= h((string)($card['CardID'] ?? ($data['card_id'] ?? 0))) ?>">
          <button type="submit" class="btn btn-danger" <?= $isLocked ? 'disabled' : '' ?>>Delete</button>
        </form>
      </div>
    </div>
  </div>
</div>
