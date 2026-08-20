<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$summary = is_array($summary ?? null) ? $summary : [];
$starBreakdown = is_array($starBreakdown ?? null) ? $starBreakdown : [];
$rows = is_array($rows ?? null) ? $rows : [];

$totalFeedback = (int)($summary['TotalFeedback'] ?? 0);
$totalEmployees = (int)($summary['TotalEmployees'] ?? 0);
$averageStars = $summary['AverageStars'] ?? null;
$averageStarsLabel = $averageStars !== null ? number_format((float)$averageStars, 2) : '0.00';
$firstUpdated = trim((string)($summary['FirstUpdated'] ?? ''));
$lastUpdated = trim((string)($summary['LastUpdated'] ?? ''));

$fmtDateTime = static function (string $value): string {
    if ($value === '') {
        return '-';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y H:i', $ts) : $value;
};

$breakdownMap = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
foreach ($starBreakdown as $row) {
    $stars = (int)($row['Stars'] ?? 0);
    if ($stars >= 1 && $stars <= 5) {
        $breakdownMap[$stars] = (int)($row['FeedbackCount'] ?? 0);
    }
}
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">User Feedback</h3>
      <div class="text-muted">Summary of feedback submitted through the portal feedback modal.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
        <i class="bi bi-arrow-left me-1"></i>Back
      </a>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small text-uppercase">Total Feedback</div>
          <div class="display-6 fw-semibold"><?= h((string)$totalFeedback) ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small text-uppercase">Employees</div>
          <div class="display-6 fw-semibold"><?= h((string)$totalEmployees) ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small text-uppercase">Average Rating</div>
          <div class="display-6 fw-semibold"><?= h($averageStarsLabel) ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small text-uppercase">Last Updated</div>
          <div class="fw-semibold"><?= h($fmtDateTime($lastUpdated)) ?></div>
          <div class="small text-muted mt-2">First feedback: <?= h($fmtDateTime($firstUpdated)) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-header">
      <strong>Star Breakdown</strong>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <?php foreach ($breakdownMap as $stars => $count): ?>
          <div class="col-md-2 col-sm-4 col-6">
            <div class="border rounded p-3 text-center h-100">
              <div class="fw-semibold"><?= h((string)$stars) ?> Star<?= $stars === 1 ? '' : 's' ?></div>
              <div class="fs-4"><?= h((string)$count) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Recent Feedback</strong>
      <span class="text-muted small"><?= count($rows) ?> row(s)</span>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>ID</th>
              <th>Employee ID</th>
              <th>Stars</th>
              <th>Comments</th>
              <th>Updated By</th>
              <th>Date Updated</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="6" class="text-center text-muted">No feedback found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <?php
                  $updatedByLabel = trim((string)($row['UpdatedByUsername'] ?? ''));
                  if ($updatedByLabel === '') {
                      $updatedByLabel = trim((string)($row['UpdatedBy'] ?? ''));
                  }
                ?>
                <tr>
                  <td><?= h((string)($row['FeedbackID'] ?? '')) ?></td>
                  <td><?= h((string)($row['EmployeeID'] ?? '')) ?></td>
                  <td><span class="badge bg-warning text-dark"><?= h((string)($row['Stars'] ?? '')) ?>/5</span></td>
                  <td style="min-width: 320px; white-space: pre-wrap;"><?= h((string)($row['Comments'] ?? '')) ?></td>
                  <td><?= h($updatedByLabel !== '' ? $updatedByLabel : '-') ?></td>
                  <td><?= h($fmtDateTime((string)($row['DateUpdated'] ?? ''))) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
