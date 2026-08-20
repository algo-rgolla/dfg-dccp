<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fmt_money0_admin')) {
    function fmt_money0_admin($v): string
    {
        $s = trim((string)$v);
        if ($s === '') {
            return '-';
        }
        $n = str_replace([',', '$', ' '], '', $s);
        if (!is_numeric($n)) {
            return $s;
        }
        return '$' . number_format((float)$n, 0);
    }
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
if (!function_exists('fmt_dt_admin_utc')) {
    function fmt_dt_admin_utc(?string $v): string
    {
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

$rows = is_array($rows ?? null) ? $rows : [];
$statusFilter = strtolower(trim((string)($statusFilter ?? '')));
$csrf = function_exists('csrf_token') ? h(csrf_token()) : '';
$heading = trim((string)($heading ?? 'Limit Change Approvals'));
$description = trim((string)($description ?? 'Administrative view of all limit change requests and approval outcomes.'));
$baseRoute = trim((string)($baseRoute ?? 'cards/limit-change-approvals'));
$showFilter = !isset($showFilter) || (bool)$showFilter;
$allowDelete = isset($allowDelete) ? (bool)$allowDelete : true;
$emptyMessage = trim((string)($emptyMessage ?? 'No limit change approval records found.'));
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1"><?= h($heading) ?></h3>
      <div class="text-muted"><?= h($description) ?></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
        <i class="bi bi-arrow-left me-1"></i>Back
      </a>
    </div>
  </div>

  <?php if ($showFilter): ?>
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <form method="get" action="index.php" class="row g-3 align-items-end">
          <input type="hidden" name="route" value="<?= h($baseRoute) ?>">
          <div class="col-md-4">
            <label class="form-label">Status</label>
            <select class="form-select" name="status">
              <option value="">All statuses</option>
              <?php foreach ([
                'tobeapproved' => 'To Be Approved',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
                'senttobank' => 'Sent to Bank',
                'limitchanged' => 'Limit Changed',
                'draft' => 'Draft',
                'inprogress' => 'In Progress',
              ] as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-8">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a class="btn btn-outline-secondary" href="index.php?route=<?= h($baseRoute) ?>">Clear</a>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>Application</th>
              <th>Status</th>
              <th>Applicant</th>
              <th>Submitter</th>
              <th>Card</th>
              <th>Requested Limits</th>
              <th>Approver</th>
              <th>Outcome</th>
              <th>Submitted</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr>
                <td colspan="10" class="text-center text-muted"><?= h($emptyMessage) ?></td>
              </tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <?php $status = strtolower(trim((string)($row['Status'] ?? ''))); ?>
                <tr>
                  <td>
                    <div><strong>#<?= h((string)($row['ApplicationID'] ?? 0)) ?></strong></div>
                    <div class="small text-muted"><?= h((string)($row['ApplicationTypeName'] ?? ($row['ApplicationTypeKey'] ?? 'Limit Change'))) ?></div>
                  </td>
                  <td><?= h((string)($row['Status'] ?? '-')) ?></td>
                  <td>
                    <div><?= h((string)($row['ApplicantName'] ?? '-') ?: '-') ?></div>
                    <div class="small text-muted">EmpID: <?= h((string)($row['ApplicantEmployeeID'] ?? '')) ?></div>
                    <?php if (trim((string)($row['ApplicantEmail'] ?? '')) !== ''): ?>
                      <div class="small text-muted"><?= h((string)$row['ApplicantEmail']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($row['IsOnBehalf'])): ?>
                      <div class="small text-muted">Submitted on behalf</div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div><?= h((string)($row['RequestorName'] ?? '-')) ?></div>
                    <div class="small text-muted">UserID: <?= h((string)($row['RequestorUserID'] ?? 0)) ?></div>
                    <div class="small text-muted">EmpID: <?= h((string)($row['RequestorEmployeeID'] ?? '')) ?></div>
                    <?php if (trim((string)($row['RequestorEmail'] ?? '')) !== ''): ?>
                      <div class="small text-muted"><?= h((string)$row['RequestorEmail']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div><?= h((string)($row['CardType'] ?? '-')) ?></div>
                    <div class="small text-muted">CardID: <?= h((string)($row['CardID'] ?? 0)) ?></div>
                  </td>
                  <td>
                    <div>Credit: <?= h(fmt_money0_admin($row['CreditLimitNew'] ?? '')) ?></div>
                    <div class="small text-muted">Txn: <?= h(fmt_money0_admin($row['TransactionLimitNew'] ?? '')) ?></div>
                  </td>
                  <td>
                    <div><?= h((string)($row['SelectedApprover'] ?? '-')) ?></div>
                    <div class="small text-muted"><?= h((string)($row['ApproverType'] ?? '')) ?></div>
                    <?php if (trim((string)($row['ForwardTo'] ?? '')) !== ''): ?>
                      <div class="small text-muted">Forwarded to: <?= h((string)$row['ForwardTo']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ((int)($row['ApprovedByUserID'] ?? 0) > 0): ?>
                      <div>Approved by UserID <?= h((string)($row['ApprovedByUserID'] ?? 0)) ?></div>
                      <div class="small text-muted"><?= h(fmt_dt_admin_utc((string)($row['ApprovedAt'] ?? ''))) ?></div>
                    <?php elseif ((int)($row['RejectedByUserID'] ?? 0) > 0): ?>
                      <div>Rejected by UserID <?= h((string)($row['RejectedByUserID'] ?? 0)) ?></div>
                      <div class="small text-muted"><?= h(fmt_dt_admin_utc((string)($row['RejectedAt'] ?? ''))) ?></div>
                      <?php if (trim((string)($row['RejectReason'] ?? '')) !== ''): ?>
                        <div class="small text-muted">Reason: <?= h((string)$row['RejectReason']) ?></div>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="text-muted">Pending</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div><?= h(fmt_dt_admin((string)($row['SubmittedAt'] ?? ''))) ?></div>
                    <div class="small text-muted">Updated: <?= h(fmt_dt_admin((string)($row['LastSavedAt'] ?? ''))) ?></div>
                  </td>
                  <td>
                    <div class="d-flex flex-column gap-1 align-items-start">
                      <a href="index.php?route=cards/limit-change-approve&id=<?= urlencode((string)($row['ApplicationID'] ?? 0)) ?>">Open</a>
                      <?php if ($allowDelete): ?>
                        <form method="post" action="index.php?route=cards/limit-change-delete" onsubmit="return confirm('Delete this limit change application?');">
                          <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                          <input type="hidden" name="application_id" value="<?= h((string)($row['ApplicationID'] ?? 0)) ?>">
                          <input type="hidden" name="card_id" value="<?= h((string)($row['CardID'] ?? 0)) ?>">
                          <input type="hidden" name="type_key" value="<?= h((string)($row['ApplicationTypeKey'] ?? '')) ?>">
                          <input type="hidden" name="admin_delete" value="1">
                          <button type="submit" class="btn btn-link btn-sm p-0 text-danger">Delete</button>
                        </form>
                      <?php endif; ?>
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
