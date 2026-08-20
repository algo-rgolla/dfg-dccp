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
if (!function_exists('flatten_payload_admin')) {
    function flatten_payload_admin(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $label = $prefix === '' ? (string)$key : $prefix . '.' . (string)$key;
            if (is_array($value)) {
                $out += flatten_payload_admin($value, $label);
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $value = '';
            }
            $out[$label] = (string)$value;
        }
        return $out;
    }
}
if (!function_exists('audit_summary_admin')) {
    function audit_summary_admin(array $row): string
    {
        $raw = (string)($row['Details'] ?? '');
        if ($raw === '') {
            return '-';
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $raw;
        }
        if (!empty($data['status_before']) || !empty($data['status_after'])) {
            $before = (string)($data['status_before'] ?? '-');
            $after = (string)($data['status_after'] ?? '-');
            $summary = 'Status: ' . $before . ' -> ' . $after;
            $reason = trim((string)($data['status_reason'] ?? ($data['reopen_reason'] ?? '')));
            if ($reason !== '') {
                $summary .= ' | Reason: ' . $reason;
            }
            return $summary;
        }
        if (!empty($data['status_after'])) {
            return 'Status: ' . (string)$data['status_after'];
        }
        $parts = [];
        foreach (array_slice($data, 0, 3, true) as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $parts[] = (string)$key . ': ' . (is_bool($value) ? ($value ? 'true' : 'false') : (string)$value);
        }
        return $parts ? implode(' | ', $parts) : '-';
    }
}
if (!function_exists('is_status_history_entry_admin')) {
    function is_status_history_entry_admin(array $row): bool
    {
        $action = strtoupper(trim((string)($row['Action'] ?? '')));
        if (in_array($action, ['REOPEN', 'SUBMIT', 'SAVE_DRAFT', 'STATUS_UPDATE'], true)) {
            return true;
        }
        $raw = (string)($row['Details'] ?? '');
        if ($raw === '') {
            return false;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return false;
        }
        return array_key_exists('status_before', $data) || array_key_exists('status_after', $data);
    }
}

$row = is_array($row ?? null) ? $row : [];
$payload = is_array($payload ?? null) ? $payload : [];
$steps = is_array($steps ?? null) ? $steps : [];
$history = is_array($history ?? null) ? $history : [];
$payloadJson = (string)($payloadJson ?? '');
$payloadMeta = is_array($payloadMeta ?? null) ? $payloadMeta : [];
$flatPayload = flatten_payload_admin($payload);
$csrf = h(csrf_token());
$currentStatus = (string)($row['Status'] ?? '');
$applicationTypeKey = strtolower(trim((string)($row['ApplicationTypeKey'] ?? '')));
$applicationTypeName = strtolower(trim((string)($row['ApplicationTypeName'] ?? '')));
$applicationTypeId = (int)($row['ApplicationTypeID'] ?? 0);
$isLimitChange = str_contains($applicationTypeKey, 'limit_change')
    || str_contains($applicationTypeName, 'limit change')
    || in_array($applicationTypeId, [5, 6, 9], true);
$supportsApprovalReset = $isLimitChange || $applicationTypeId === 1;
$approvalResetLabel = $isLimitChange ? 'Reset Limit Change Approval' : 'Reset Supervisor Approval';
$openFormHref = $isLimitChange
    ? 'index.php?route=cards/request-limit-change&application_id=' . urlencode((string)($row['ApplicationID'] ?? 0)) . '&admin=1'
    : 'index.php?route=applications/edit&id=' . urlencode((string)($row['ApplicationID'] ?? 0)) . '&admin_edit=1';
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Application Details</h3>
      <div class="text-muted">Read-only administrative view of the selected application and saved payload.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-primary btn-sm" href="<?= h($openFormHref) ?>">Edit Form</a>
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=admin/applications">Back to Applications</a>
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">Home</a>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Application Summary</strong>
      <span class="text-muted small">Application #<?= h((string)($row['ApplicationID'] ?? 0)) ?></span>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-3"><strong>Type:</strong><br><?= h((string)($row['ApplicationTypeName'] ?? '')) ?></div>
        <div class="col-md-2"><strong>Status:</strong><br><?= h((string)($row['Status'] ?? '-')) ?></div>
        <div class="col-md-2"><strong>Current Step:</strong><br><?= h((string)($row['CurrentStepKey'] ?? '-')) ?></div>
        <div class="col-md-2"><strong>Locked:</strong><br><?= !empty($row['Locked']) ? 'Yes' : 'No' ?></div>
        <div class="col-md-3"><strong>Started:</strong><br><?= h(fmt_dt_admin((string)($row['StartedAt'] ?? ''))) ?></div>
        <div class="col-md-3"><strong>Last Saved:</strong><br><?= h(fmt_dt_admin((string)($row['LastSavedAt'] ?? ''))) ?></div>
        <div class="col-md-3"><strong>User:</strong><br><?= h((string)($row['Username'] ?? '')) ?> (ID <?= h((string)($row['UserID'] ?? 0)) ?>)</div>
        <div class="col-md-3"><strong>Applicant Name:</strong><br><?= h(trim((string)($row['FirstName'] ?? '') . ' ' . (string)($row['LastName'] ?? ''))) ?: '-' ?></div>
        <div class="col-md-3"><strong>EmployeeID:</strong><br><?= h((string)($row['EmployeeID'] ?? '')) ?></div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Administration</strong>
      <span class="text-muted small">Administrators can reopen for applicant editing only</span>
    </div>
    <div class="card-body">
      <div class="text-muted mb-3">
        Use <strong>Draft</strong> or <strong>InProgress</strong> to unlock the application for applicant editing. Use <strong>CardIssued</strong> after the bank has processed the application so the user sees the lifecycle as complete.
      </div>
      <form method="post" action="index.php?route=admin/applications-status" class="row g-3 align-items-end" id="adminStatusForm">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="application_id" value="<?= h((string)($row['ApplicationID'] ?? 0)) ?>">
        <div class="col-md-4">
          <label class="form-label">Current Status</label>
          <input class="form-control" value="<?= h($currentStatus) ?>" disabled>
        </div>
        <div class="col-md-4">
          <label class="form-label">Set Status To</label>
          <select class="form-select" name="target_status" id="targetStatusSelect">
            <option value="Draft" <?= strcasecmp($currentStatus, 'Draft') === 0 ? 'selected' : '' ?>>Draft</option>
            <option value="InProgress" <?= strcasecmp($currentStatus, 'InProgress') === 0 ? 'selected' : '' ?>>InProgress</option>
            <option value="CardIssued" <?= strcasecmp($currentStatus, 'CardIssued') === 0 ? 'selected' : '' ?>>CardIssued</option>
          </select>
        </div>
        <div class="col-md-8">
          <label class="form-label">Reason</label>
          <input class="form-control" name="status_reason" id="reopenReasonInput" maxlength="250" required placeholder="Explain why the application status is being updated">
        </div>
        <div class="col-md-4">
          <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#confirmStatusModal">Update Status</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($supportsApprovalReset): ?>
  <div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Approval Reset</strong>
      <span class="text-muted small">Rebuild approver payload from current workflow rules</span>
    </div>
    <div class="card-body">
      <div class="text-muted mb-3">
        This clears the saved approval decision state, rebuilds the current approval routing, sets the application back to <strong>ToBeApproved</strong>, and resends the approval email.
      </div>
      <form method="post" action="index.php?route=admin/applications-reset-approval" class="row g-3 align-items-end" id="adminResetApprovalForm">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="application_id" value="<?= h((string)($row['ApplicationID'] ?? 0)) ?>">
        <div class="col-md-8">
          <label class="form-label" for="approvalResetReasonInput">Reason</label>
          <input class="form-control" name="reset_reason" id="approvalResetReasonInput" maxlength="250" required placeholder="Explain why the approval process is being reset">
        </div>
        <div class="col-md-4">
          <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#confirmResetApprovalModal"><?= h($approvalResetLabel) ?></button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Status History</strong>
      <span class="text-muted small"><?= count($history) ?> recent event<?= count($history) === 1 ? '' : 's' ?></span>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>When</th>
              <th>Action</th>
              <th>User</th>
              <th>Summary</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$history): ?>
              <tr><td colspan="4" class="text-center text-muted">No audit history found for this application.</td></tr>
            <?php else: ?>
              <?php foreach ($history as $event): ?>
                <?php $isStatusRow = is_status_history_entry_admin($event); ?>
                <tr>
                  <td class="<?= $isStatusRow ? 'bg-warning-subtle' : '' ?>"><?= h(fmt_dt_admin((string)($event['EventTime'] ?? ''))) ?></td>
                  <td class="<?= $isStatusRow ? 'bg-warning-subtle fw-semibold' : '' ?>"><?= h((string)($event['Action'] ?? '')) ?></td>
                  <td class="<?= $isStatusRow ? 'bg-warning-subtle' : '' ?>"><?= h((string)($event['Username'] ?? '')) ?></td>
                  <td class="<?= $isStatusRow ? 'bg-warning-subtle' : '' ?>"><?= h(audit_summary_admin($event)) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Workflow Steps</strong>
      <span class="text-muted small"><?= count($steps) ?> step<?= count($steps) === 1 ? '' : 's' ?></span>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>Step Key</th>
              <th>Complete</th>
              <th>Completed</th>
              <th>Last Saved</th>
              <th>Updated By</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$steps): ?>
              <tr><td colspan="5" class="text-center text-muted">No workflow steps found.</td></tr>
            <?php else: ?>
              <?php foreach ($steps as $step): ?>
                <tr>
                  <td><?= h((string)($step['StepKey'] ?? '')) ?></td>
                  <td><?= !empty($step['IsComplete']) ? 'Yes' : 'No' ?></td>
                  <td><?= h(fmt_dt_admin((string)($step['CompletedAt'] ?? ''))) ?></td>
                  <td><?= h(fmt_dt_admin((string)($step['LastSavedAt'] ?? ''))) ?></td>
                  <td><?= h((string)($step['UpdatedBy'] ?? '')) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Application Payload</strong>
      <span class="text-muted small">Saved <?= h(fmt_dt_admin((string)($payloadMeta['LastSavedAt'] ?? ''))) ?></span>
    </div>
    <div class="card-body">
      <?php if (!$flatPayload): ?>
        <div class="text-muted">No saved application payload found.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm table-striped align-middle">
            <thead>
              <tr>
                <th>Field</th>
                <th>Value</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($flatPayload as $key => $value): ?>
                <tr>
                  <td class="text-nowrap"><?= h($key) ?></td>
                  <td><?= h($value !== '' ? $value : '-') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header">
      <strong>Raw Payload JSON</strong>
    </div>
    <div class="card-body">
      <pre class="bg-light border rounded p-3 mb-0" style="max-height:60vh; overflow:auto; white-space:pre-wrap;"><?= h($payloadJson !== '' ? $payloadJson : '{}') ?></pre>
    </div>
  </div>
</div>

<div class="modal fade" id="confirmStatusModal" tabindex="-1" aria-labelledby="confirmStatusModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="confirmStatusModalLabel">Confirm Status Update</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        You are about to reopen this application as <strong id="confirmTargetStatusLabel"><?= h($currentStatus) ?></strong>.
        The applicant will be able to edit and resubmit it after this change.
        <div class="small text-muted mt-2" id="confirmReopenReasonText"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-warning" form="adminStatusForm">Yes, Update Status</button>
      </div>
    </div>
  </div>
</div>

<?php if ($supportsApprovalReset): ?>
<div class="modal fade" id="confirmResetApprovalModal" tabindex="-1" aria-labelledby="confirmResetApprovalModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="confirmResetApprovalModalLabel"><?= h($approvalResetLabel) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        You are about to clear the current approval payload and resend the approval workflow for application <strong>#<?= h((string)($row['ApplicationID'] ?? 0)) ?></strong>.
        <div class="small text-muted mt-2" id="confirmResetApprovalReasonText"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-danger" form="adminResetApprovalForm">Yes, Reset Approval</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
  (function () {
    const select = document.getElementById('targetStatusSelect');
    const label = document.getElementById('confirmTargetStatusLabel');
    const reason = document.getElementById('reopenReasonInput');
    const reasonText = document.getElementById('confirmReopenReasonText');
    const modalEl = document.getElementById('confirmStatusModal');
    if (!select || !label) return;

    function syncLabel() {
      const selected = select.options[select.selectedIndex];
      label.textContent = selected ? selected.text : select.value;
      if (reasonText) {
        const value = reason ? reason.value.trim() : '';
        reasonText.textContent = value ? ('Reason: ' + value) : 'A reason is required before confirming.';
      }
    }

    select.addEventListener('change', syncLabel);
    if (reason) reason.addEventListener('input', syncLabel);
    if (modalEl) {
      modalEl.addEventListener('show.bs.modal', function (e) {
        if (reason && reason.value.trim() === '') {
          e.preventDefault();
          reason.classList.add('is-invalid');
          reason.focus();
          return;
        }
        if (reason) {
          reason.classList.remove('is-invalid');
        }
        syncLabel();
      });
    }
    syncLabel();
  })();

  (function () {
    const reason = document.getElementById('approvalResetReasonInput');
    const reasonText = document.getElementById('confirmResetApprovalReasonText');
    const modalEl = document.getElementById('confirmResetApprovalModal');
    if (!reason || !reasonText || !modalEl) return;

    function syncReason() {
      const value = reason.value.trim();
      reasonText.textContent = value ? ('Reason: ' + value) : 'A reason is required before confirming.';
    }

    reason.addEventListener('input', syncReason);
    modalEl.addEventListener('show.bs.modal', function (e) {
      if (reason.value.trim() === '') {
        e.preventDefault();
        reason.classList.add('is-invalid');
        reason.focus();
        return;
      }
      reason.classList.remove('is-invalid');
      syncReason();
    });

    syncReason();
  })();
</script>
