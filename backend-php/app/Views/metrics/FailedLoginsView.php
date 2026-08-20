<?php
declare(strict_types=1);
/** @var array $chartData */

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$labels = $chartData['labels'] ?? [];
$values = $chartData['values'] ?? [];

$labelsJson = json_encode($labels, JSON_UNESCAPED_SLASHES);
$valuesJson = json_encode($values, JSON_UNESCAPED_SLASHES);
?>
<div class="container mt-3">
  <h2><i class="bi bi-shield-lock me-2"></i> <?= __t('failed_logins_last_30_days') ?></h2>

  <!-- Chart Card -->
  <div class="card shadow-sm mb-3">
    <div class="card-header d-flex align-items-center justify-content-between">
      <strong><?= __t('trend_chart') ?></strong>
      <?php if (!empty($values)): ?>
        <span class="badge bg-secondary"><?= __t('last_n_days', ['n'=>count($values)]) ?></span>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if (!empty($labels) && !empty($values)): ?>
        <canvas id="failedLoginsChart" height="90"></canvas>
      <?php else: ?>
        <div class="alert alert-info mb-0">
          <i class="bi bi-info-circle me-1"></i> <?= __t('no_data_available') ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Counts Table Card -->
  <div class="card shadow-sm">
    <div class="card-header">
      <strong><?= __t('daily_failed_logins') ?></strong>
    </div>
    <div class="card-body p-0">
      <?php if (!empty($labels) && !empty($values)): ?>
        <div class="table-responsive">
          <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th scope="col"><?= __t('date') ?></th>
                <th scope="col"><?= __t('count') ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($labels as $i => $d): ?>
                <tr>
                  <td><?= h((string)$d) ?></td>
                  <td>
                    <span class="badge bg-danger"><?= h((string)($values[$i] ?? 0)) ?></span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="alert alert-info m-3 mb-0">
          <i class="bi bi-info-circle me-1"></i> <?= __t('no_failed_logins_found') ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (!empty($labels) && !empty($values)): ?>
<script>
(function() {
  const labels = <?= $labelsJson ?>;
  const values = <?= $valuesJson ?>;

  function renderChart() {
    const ctx = document.getElementById('failedLoginsChart').getContext('2d');
    if (window.__failedLoginsChart) {
      window.__failedLoginsChart.destroy();
    }
    window.__failedLoginsChart = new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label: '<?= __t('failed_logins') ?>',
          data: values,
          borderWidth: 2,
          tension: 0.25,
          fill: true,
          borderColor: 'rgb(13,110,253)', // Bootstrap "primary"
          backgroundColor: 'rgba(13,110,253,0.15)',
          pointRadius: 3,
        }]
      },
      options: {
        plugins: {
          legend: { display: true },
          tooltip: { mode: 'index', intersect: false }
        },
        scales: {
          x: { ticks: { autoSkip: true, maxRotation: 0 } },
          y: { beginAtZero: true, precision: 0 }
        },
        maintainAspectRatio: false
      }
    });
  }

  if (typeof window.Chart === 'undefined') {
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/chart.js';
    s.onload = renderChart;
    document.head.appendChild(s);
  } else {
    renderChart();
  }
})();
</script>
<?php endif; ?>
