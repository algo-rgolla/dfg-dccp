<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fmt_dt_dpc_admin')) {
    function fmt_dt_dpc_admin(?string $v): string
    {
        $s = trim((string)$v);
        if ($s === '') {
            return '-';
        }
        $ts = strtotime($s);
        return $ts === false ? $s : date('d-m-Y H:i', $ts);
    }
}
if (!function_exists('fmt_dt_dpc_admin_utc')) {
    function fmt_dt_dpc_admin_utc(?string $v): string
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
$heading = trim((string)($heading ?? 'DPC Application Approvals'));
$description = trim((string)($description ?? 'Administrative view of all DPC applications and supervisor approval outcomes.'));
$baseRoute = trim((string)($baseRoute ?? 'admin/dpc-application-approvals'));
$showFilter = !isset($showFilter) || (bool)$showFilter;
$emptyMessage = trim((string)($emptyMessage ?? 'No DPC approval records found.'));
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
                'cardissued' => 'Card Issued',
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
              <th>Requestor</th>
              <th>Supervisor</th>
              <th>Application Details</th>
              <th>Outcome</th>
              <th>Submitted</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr>
                <td colspan="8" class="text-center text-muted"><?= h($emptyMessage) ?></td>
              </tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td>
                    <div><strong>#<?= h((string)($row['ApplicationID'] ?? 0)) ?></strong></div>
                    <div class="small text-muted"><?= h((string)($row['ApplicationTypeName'] ?? ($row['ApplicationTypeKey'] ?? 'DPC'))) ?></div>
                  </td>
                  <td><?= h((string)($row['Status'] ?? '-')) ?></td>
                  <td>
                    <div><?= h((string)($row['RequestorName'] ?? '-')) ?></div>
                    <div class="small text-muted">UserID: <?= h((string)($row['RequestorUserID'] ?? 0)) ?></div>
                    <div class="small text-muted">EmpID: <?= h((string)($row['RequestorEmployeeID'] ?? '')) ?></div>
                    <div class="small text-muted"><?= h((string)($row['RequestorEmail'] ?? ($row['ApplicantEmail'] ?? ''))) ?></div>
                  </td>
                  <td>
                    <div><?= h((string)($row['SupervisorName'] ?? '-')) ?></div>
                    <div class="small text-muted">EmpID: <?= h((string)($row['SupervisorEmployeeID'] ?? '')) ?></div>
                    <div class="small text-muted"><?= h((string)($row['SupervisorEmail'] ?? '')) ?></div>
                  </td>
                  <td>
                    <div>Company: <?= h((string)($row['Company'] ?? '-')) ?></div>
                    <div class="small text-muted">Cost Centre: <?= h((string)($row['CostCentre'] ?? '')) ?></div>
                    <div class="small text-muted">Branding: <?= h((string)($row['Branding'] ?? '')) ?></div>
                  </td>
                  <td>
                    <?php if ((int)($row['ApprovedByUserID'] ?? 0) > 0): ?>
                      <div>Approved by UserID <?= h((string)($row['ApprovedByUserID'] ?? 0)) ?></div>
                      <div class="small text-muted"><?= h(fmt_dt_dpc_admin_utc((string)($row['ApprovedAt'] ?? ''))) ?></div>
                    <?php elseif ((int)($row['RejectedByUserID'] ?? 0) > 0): ?>
                      <div>Rejected by UserID <?= h((string)($row['RejectedByUserID'] ?? 0)) ?></div>
                      <div class="small text-muted"><?= h(fmt_dt_dpc_admin_utc((string)($row['RejectedAt'] ?? ''))) ?></div>
                      <?php if (trim((string)($row['RejectReason'] ?? '')) !== ''): ?>
                        <div class="small text-muted">Reason: <?= h((string)$row['RejectReason']) ?></div>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="text-muted">Pending</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div><?= h(fmt_dt_dpc_admin((string)($row['SubmittedAt'] ?? ''))) ?></div>
                    <div class="small text-muted">Updated: <?= h(fmt_dt_dpc_admin((string)($row['LastSavedAt'] ?? ''))) ?></div>
                  </td>
                  <td>
                    <a href="index.php?route=applications/approve&id=<?= urlencode((string)($row['ApplicationID'] ?? 0)) ?>">Open</a>
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
