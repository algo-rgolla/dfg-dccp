<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$appId      = (int)($app['ApplicationID'] ?? 0);
$stepKey    = (string)($activeStepKey ?? '');
$stepLabel  = (string)($activeStep['StepLabel'] ?? '');
$helpText   = (string)($activeStep['HelpText'] ?? '');
$viewPath   = (string)($activeStep['ViewPath'] ?? ''); // e.g. applications/steps/dtc/address
$csrf       = h(csrf_token());

/**
 * Helper: find previous/next step keys from $progress array
 */
$keys = array_map(fn($p) => (string)$p['StepKey'], $progress ?? []);
$idx  = array_search($stepKey, $keys, true);
$prevKey = ($idx !== false && $idx > 0) ? $keys[$idx - 1] : null;
$nextKey = ($idx !== false && $idx < count($keys) - 1) ? $keys[$idx + 1] : null;

/**
 * % complete (count completed steps / total)
 */
$totalSteps = max(1, count($progress ?? []));
$completed  = 0;
foreach (($progress ?? []) as $p) {
    if (!empty($p['Complete'])) $completed++;
}
$percent = (int)round(($completed / $totalSteps) * 100);
?>

<div class="container-fluid mt-4">

  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h4 class="mb-1">
        <i class="bi bi-ui-checks-grid me-2"></i>
        <?= h((string)($title ?? 'Application')) ?>
      </h4>

      <div class="text-muted small">
        Reference: <span class="fw-semibold"><?= h((string)($app['ReferenceNo'] ?? ('APP-' . $appId))) ?></span>
        · Status: <span class="badge bg-secondary"><?= h((string)($app['Status'] ?? 'Draft')) ?></span>
      </div>
    </div>

    <div class="text-end">
      <a class="btn btn-sm btn-outline-secondary" href="index.php?route=portalcards/list">
        <i class="bi bi-arrow-left me-1"></i>Back to My Cards
      </a>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">

      <!-- progress bar -->
      <div class="mb-3">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <div class="small text-muted">Progress</div>
          <div class="small text-muted"><?= $percent ?>%</div>
        </div>
        <div class="progress" style="height: 10px;">
          <div class="progress-bar" role="progressbar" style="width: <?= (int)$percent ?>%;" aria-valuenow="<?= (int)$percent ?>" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
      </div>

      <div class="row g-4">
        <!-- left: step list -->
        <div class="col-lg-4 col-xl-3">
          <div class="list-group small">

            <?php foreach (($progress ?? []) as $p): ?>
              <?php
                $k = (string)$p['StepKey'];
                $isActive = !empty($p['IsActive']);
                $isDone   = !empty($p['Complete']);
                $icon = $isDone ? 'check-circle-fill text-success' : ($isActive ? 'record-circle-fill text-primary' : 'circle text-muted');
              ?>
              <a
                class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= $isActive ? 'active' : '' ?>"
                href="index.php?route=applications/goStep&id=<?= (int)$appId ?>&step=<?= urlencode($k) ?>"
              >
                <span class="me-2">
                  <i class="bi bi-<?= h($icon) ?> me-2"></i>
                  <?= h((string)$p['Label']) ?>
                </span>

                <?php if ($isDone): ?>
                  <span class="badge bg-success">Done</span>
                <?php elseif ($isActive): ?>
                  <span class="badge bg-light text-dark">Current</span>
                <?php else: ?>
                  <span class="badge bg-light text-muted">Pending</span>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>

          </div>
        </div>

        <!-- right: active step form -->
        <div class="col-lg-8 col-xl-9">

          <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
              <h5 class="mb-1"><?= h($stepLabel !== '' ? $stepLabel : $stepKey) ?></h5>
              <?php if ($helpText !== ''): ?>
                <div class="text-muted small"><?= h($helpText) ?></div>
              <?php endif; ?>
            </div>

            <div class="text-end small text-muted">
              Step key: <span class="fw-semibold"><?= h($stepKey) ?></span>
            </div>
          </div>

          <form method="post" action="index.php?route=applications/saveStep&id=<?= (int)$appId ?>&step=<?= urlencode($stepKey) ?>" class="js-submit-feedback-form">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <input type="hidden" name="_action" id="wizard_action" value="save">

            <div class="border rounded p-3 bg-light">

              <?php
                /**
                 * Render step partial based on ViewPath from DB.
                 * - If ViewPath is "applications/steps/dtc/address", we will include:
                 *   app/Views/applications/steps/dtc/address.php
                 */
                $partialFile = __DIR__ . '/../' . trim($viewPath, '/\\') . '.php';

                // Expose $activeData to partial, plus $app if needed
                $data = $activeData ?? [];
                $application = $app ?? [];

                if ($viewPath === '') {
                    echo '<div class="alert alert-warning mb-0">No ViewPath configured for this step.</div>';
                } elseif (!is_file($partialFile)) {
                    echo '<div class="alert alert-warning mb-0">';
                    echo 'Step view not found: <code>' . h($partialFile) . '</code>';
                    echo '</div>';
                } else {
                    include $partialFile;
                }
              ?>

            </div>

            <div class="d-flex justify-content-between align-items-center mt-3">
              <div class="small text-muted">
                <?php
                  $rt = $runtimeSteps[$stepKey] ?? null;
                  $lastSaved = $rt['LastSavedAt'] ?? null;
                  if ($lastSaved) {
                      echo 'Last saved: <span class="fw-semibold">' . h((string)$lastSaved) . '</span>';
                  }
                ?>
              </div>

              <div class="btn-group">
                <?php if ($prevKey !== null): ?>
                  <button type="submit" class="btn btn-outline-secondary"
                          onclick="document.getElementById('wizard_action').value='back';">
                    <i class="bi bi-arrow-left me-1"></i>Back
                  </button>
                <?php endif; ?>

                <button type="submit" class="btn btn-outline-primary"
                        onclick="document.getElementById('wizard_action').value='save';">
                  <i class="bi bi-save me-1"></i>Save
                </button>

                <?php if ($nextKey !== null): ?>
                  <button type="submit" class="btn btn-primary"
                          onclick="document.getElementById('wizard_action').value='next';">
                    Next <i class="bi bi-arrow-right ms-1"></i>
                  </button>
                <?php else: ?>
                  <button type="submit" class="btn btn-success"
                          onclick="document.getElementById('wizard_action').value='next';">
                    Finish <i class="bi bi-check2 ms-1"></i>
                  </button>
                <?php endif; ?>
              </div>
            </div>

          </form>

        </div>
      </div>

    </div>
  </div>

</div>

<?php require __DIR__ . '/../shared/submit_feedback.php'; ?>
