<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$summary = is_array($summary ?? null) ? $summary : [];
$timingSummary = is_array($timingSummary ?? null) ? $timingSummary : [];
$applicationStatusRows = is_array($applicationStatusRows ?? null) ? $applicationStatusRows : [];
$applicationTypeRows = is_array($applicationTypeRows ?? null) ? $applicationTypeRows : [];
$cardTypeRows = is_array($cardTypeRows ?? null) ? $cardTypeRows : [];
$loginTrendRows = is_array($loginTrendRows ?? null) ? $loginTrendRows : [];
$feedbackSummary = is_array($feedbackSummary ?? null) ? $feedbackSummary : [];
$feedbackBreakdown = is_array($feedbackBreakdown ?? null) ? $feedbackBreakdown : [];

$fmtDateTime = static function (?string $value): string {
    $s = trim((string)$value);
    if ($s === '') {
        return '-';
    }
    $ts = strtotime($s);
    return $ts === false ? $s : date('d/m/Y H:i', $ts);
};

$fmtDate = static function (?string $value): string {
    $s = trim((string)$value);
    if ($s === '') {
        return '-';
    }
    $ts = strtotime($s);
    return $ts === false ? $s : date('d/m/Y', $ts);
};

$fmtDuration = static function ($seconds): string {
    $value = is_numeric($seconds) ? (float)$seconds : 0.0;
    if ($value <= 0) {
        return '-';
    }
    $minutes = $value / 60;
    $hours = $value / 3600;
    $days = $value / 86400;

    if ($days >= 1) {
        return number_format($days, 2) . ' days';
    }
    if ($hours >= 1) {
        return number_format($hours, 2) . ' hours';
    }
    return number_format($minutes, 2) . ' mins';
};

$feedbackAvg = $feedbackSummary['AverageStars'] ?? null;
$feedbackAvgLabel = $feedbackAvg !== null ? number_format((float)$feedbackAvg, 2) : '0.00';
$feedbackBreakdownMap = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
foreach ($feedbackBreakdown as $row) {
    $stars = (int)($row['Stars'] ?? 0);
    if ($stars >= 1 && $stars <= 5) {
        $feedbackBreakdownMap[$stars] = (int)($row['FeedbackCount'] ?? 0);
    }
}

$summaryCardLinks = [
    'users' => ['href' => 'index.php?route=users/list', 'label' => 'Open users'],
    'applications' => ['href' => 'index.php?route=admin/applications', 'label' => 'Open applications'],
    'pendingApproval' => ['href' => 'index.php?route=admin/dpc-application-approvals', 'label' => 'Open approvals'],
    'issuedCards' => ['href' => 'index.php?route=admin/portal-cards', 'label' => 'Open cards'],
    'feedback' => ['href' => 'index.php?route=admin/feedback', 'label' => 'Open feedback'],
];
?>

<style>
  .dashboard-link-card {
    transition: transform 0.15s ease, box-shadow 0.15s ease;
  }

  .dashboard-link-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 .5rem 1rem rgba(0, 0, 0, 0.12);
  }

  .dashboard-link-card .card-body {
    position: relative;
  }

  .dashboard-card-link {
    position: relative;
    z-index: 2;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    margin-top: 0.75rem;
    font-size: 0.9rem;
    font-weight: 600;
    text-decoration: none;
  }

  .dashboard-card-link:hover {
    text-decoration: underline;
  }
</style>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Defence Credit Card Portal Management Dashboard</h3>
      <div class="text-muted">High-level usage and operational snapshot for management.</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
      <i class="bi bi-arrow-left me-1"></i>Back
    </a>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
      <div class="card shadow-sm h-100 dashboard-link-card">
        <div class="card-body">
          <div class="text-muted small text-uppercase">Users</div>
          <div class="display-6 fw-semibold"><?= h((string)($summary['totalUsers'] ?? 0)) ?></div>
          <div class="small text-muted">Active: <?= h((string)($summary['activeUsers'] ?? 0)) ?> | Activated: <?= h((string)($summary['activatedUsers'] ?? 0)) ?></div>
          <a class="dashboard-card-link stretched-link" href="<?= h((string)$summaryCardLinks['users']['href']) ?>">
            <?= h((string)$summaryCardLinks['users']['label']) ?><span aria-hidden="true">-></span>
          </a>
        </div>
      </div>
    </div>
    <div class="col-md-3 col-sm-6">
      <div class="card shadow-sm h-100 dashboard-link-card">
        <div class="card-body">
          <div class="text-muted small text-uppercase">Applications</div>
          <div class="display-6 fw-semibold"><?= h((string)($summary['totalApplications'] ?? 0)) ?></div>
          <div class="small text-muted">Last 30 days: <?= h((string)($summary['applications30d'] ?? 0)) ?></div>
          <a class="dashboard-card-link stretched-link" href="<?= h((string)$summaryCardLinks['applications']['href']) ?>">
            <?= h((string)$summaryCardLinks['applications']['label']) ?><span aria-hidden="true">-></span>
          </a>
        </div>
      </div>
    </div>
    <div class="col-md-3 col-sm-6">
      <div class="card shadow-sm h-100 dashboard-link-card">
        <div class="card-body">
          <div class="text-muted small text-uppercase">Pending Approval</div>
          <div class="display-6 fw-semibold"><?= h((string)($summary['pendingApproval'] ?? 0)) ?></div>
          <div class="small text-muted">Current workflow queue</div>
          <a class="dashboard-card-link stretched-link" href="<?= h((string)$summaryCardLinks['pendingApproval']['href']) ?>">
            <?= h((string)$summaryCardLinks['pendingApproval']['label']) ?><span aria-hidden="true">-></span>
          </a>
        </div>
      </div>
    </div>
    <div class="col-md-3 col-sm-6">
      <div class="card shadow-sm h-100 dashboard-link-card">
        <div class="card-body">
          <div class="text-muted small text-uppercase">Issued Cards</div>
          <div class="display-6 fw-semibold"><?= h((string)($summary['issuedCards'] ?? 0)) ?></div>
          <div class="small text-muted">Currently active / held rows</div>
          <a class="dashboard-card-link stretched-link" href="<?= h((string)$summaryCardLinks['issuedCards']['href']) ?>">
            <?= h((string)$summaryCardLinks['issuedCards']['label']) ?><span aria-hidden="true">-></span>
          </a>
        </div>
      </div>
    </div>
    <div class="col-md-3 col-sm-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small text-uppercase">Active Sessions</div>
          <div class="display-6 fw-semibold"><?= h((string)($summary['activeSessions'] ?? 0)) ?></div>
          <div class="small text-muted">Current logged-in sessions</div>
        </div>
      </div>
    </div>
    <div class="col-md-3 col-sm-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small text-uppercase">Successful Unique Logins</div>
          <div class="display-6 fw-semibold"><?= h((string)($summary['successfulUniqueLogins30d'] ?? 0)) ?></div>
          <div class="small text-muted">Distinct users in the last 30 days</div>
        </div>
      </div>
    </div>
    <div class="col-md-3 col-sm-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small text-uppercase">Failed Logins</div>
          <div class="display-6 fw-semibold"><?= h((string)($summary['failedLogins30d'] ?? 0)) ?></div>
          <div class="small text-muted">Last 30 days</div>
        </div>
      </div>
    </div>
    <div class="col-md-3 col-sm-6">
      <div class="card shadow-sm h-100 dashboard-link-card">
        <div class="card-body">
          <div class="text-muted small text-uppercase">Average Feedback</div>
          <div class="display-6 fw-semibold"><?= h($feedbackAvgLabel) ?></div>
          <div class="small text-muted">From <?= h((string)($feedbackSummary['TotalFeedback'] ?? 0)) ?> feedback item(s)</div>
          <a class="dashboard-card-link stretched-link" href="<?= h((string)$summaryCardLinks['feedback']['href']) ?>">
            <?= h((string)$summaryCardLinks['feedback']['label']) ?><span aria-hidden="true">-></span>
          </a>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-header"><strong>Application Timing</strong></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-sm-6">
              <div class="border rounded p-3 h-100">
                <div class="text-muted small text-uppercase">Creation To Submission</div>
                <div class="fs-4 fw-semibold">
                  <?= h($fmtDuration($timingSummary['creationToSubmission']['AverageSeconds'] ?? null)) ?>
                </div>
                <div class="small text-muted">
                  Based on <?= h((string)($timingSummary['creationToSubmission']['SampleCount'] ?? 0)) ?> submitted application(s)
                </div>
              </div>
            </div>
            <div class="col-sm-6">
              <div class="border rounded p-3 h-100">
                <div class="text-muted small text-uppercase">Submission To Approval</div>
                <div class="fs-4 fw-semibold">
                  <?= h($fmtDuration($timingSummary['submissionToApproval']['AverageSeconds'] ?? null)) ?>
                </div>
                <div class="small text-muted">
                  Based on <?= h((string)($timingSummary['submissionToApproval']['SampleCount'] ?? 0)) ?> approved application(s)
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-header"><strong>Feedback Breakdown</strong></div>
        <div class="card-body">
          <div class="row g-2">
            <?php foreach ($feedbackBreakdownMap as $stars => $count): ?>
              <div class="col-6">
                <div class="border rounded p-2 h-100 text-center">
                  <div class="fw-semibold"><?= h((string)$stars) ?> Star<?= $stars === 1 ? '' : 's' ?></div>
                  <div class="fs-5"><?= h((string)$count) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="small text-muted mt-3">
            Last feedback update: <?= h($fmtDateTime((string)($feedbackSummary['LastUpdated'] ?? ''))) ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-4">
      <div class="card shadow-sm h-100">
        <div class="card-header"><strong>Applications by Status</strong></div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Status</th>
                  <th class="text-end">Count</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$applicationStatusRows): ?>
                  <tr><td colspan="2" class="text-center text-muted">No data.</td></tr>
                <?php else: ?>
                  <?php foreach ($applicationStatusRows as $row): ?>
                    <tr>
                      <td><?= h((string)($row['StatusLabel'] ?? 'Unknown')) ?></td>
                      <td class="text-end"><?= h((string)($row['ItemCount'] ?? 0)) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card shadow-sm h-100">
        <div class="card-header"><strong>Card Holdings by Type</strong></div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Card Type</th>
                  <th class="text-end">Held</th>
                  <th class="text-end">Total</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$cardTypeRows): ?>
                  <tr><td colspan="3" class="text-center text-muted">No data.</td></tr>
                <?php else: ?>
                  <?php foreach ($cardTypeRows as $row): ?>
                    <tr>
                      <td>
                        <?= h((string)($row['CardType'] ?? 'Unknown')) ?>
                        <div class="small text-muted"><?= h((string)($row['CardTypeSub'] ?? '-')) ?></div>
                      </td>
                      <td class="text-end"><?= h((string)($row['HeldCards'] ?? 0)) ?></td>
                      <td class="text-end"><?= h((string)($row['TotalCards'] ?? 0)) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-header"><strong>Applications by Type</strong></div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead>
                <tr>
                  <th>Type</th>
                  <th class="text-end">Total</th>
                  <th class="text-end">Last 30 Days</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$applicationTypeRows): ?>
                  <tr><td colspan="3" class="text-center text-muted">No data.</td></tr>
                <?php else: ?>
                  <?php foreach ($applicationTypeRows as $row): ?>
                    <tr>
                      <td>
                        <?= h((string)($row['ApplicationTypeName'] ?? 'Unknown')) ?>
                        <div class="small text-muted"><?= h((string)($row['ApplicationTypeKey'] ?? '')) ?></div>
                      </td>
                      <td class="text-end"><?= h((string)($row['ItemCount'] ?? 0)) ?></td>
                      <td class="text-end"><?= h((string)($row['Last30Days'] ?? 0)) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-header"><strong>Login Trend (Last 14 Days)</strong></div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead>
                <tr>
                  <th>Date</th>
                  <th class="text-end">Successful</th>
                  <th class="text-end">Failed</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$loginTrendRows): ?>
                  <tr><td colspan="3" class="text-center text-muted">No login trend found.</td></tr>
                <?php else: ?>
                  <?php foreach ($loginTrendRows as $row): ?>
                    <tr>
                      <td><?= h($fmtDate((string)($row['LoginDate'] ?? ''))) ?></td>
                      <td class="text-end"><?= h((string)($row['SuccessfulLogins'] ?? 0)) ?></td>
                      <td class="text-end"><?= h((string)($row['FailedLogins'] ?? 0)) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>
