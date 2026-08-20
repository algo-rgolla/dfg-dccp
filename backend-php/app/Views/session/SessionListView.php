<?php
declare(strict_types=1);
/** @var array       $grouped */
/** @var string|null $title */
/** @var array|null  $flash */

use App\Shared\SessionHelper;

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$accordionId = 'sessionAccordion';
$totalGroups = is_array($grouped) ? count($grouped) : 0;
$totalVars   = 0;
foreach ($grouped as $vars) { $totalVars += is_countable($vars) ? count($vars) : 0; }
?>
<div class="container mt-4">
  <div class="card shadow-sm">
    <!-- Header (consistent with other list screens) -->
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>
        <i class="bi bi-person-badge me-2"></i>
        <?= h($title ?? __t('menu_session_vars')) ?>
      </strong>
      <div class="d-flex align-items-center gap-2">
        <button type="button"
                class="btn btn-sm btn-outline-secondary"
                onclick="if (document.referrer) { history.back(); } else { window.location.href='index.php?route=home/index'; }">
          <i class="bi bi-arrow-left me-1"></i><?= __t('back') ?>
        </button>
      </div>
    </div>

    <div class="card-body">
      <!-- Optional inline flash (safe if layout doesn't already render it) -->
      <?php if (!empty($flash) && !empty($flash['text'])): ?>
        <div id="flashMessage"
             class="alert alert-<?= h($flash['type'] ?? 'info') ?> alert-dismissible fade show mb-3" role="alert">
          <?= h((string)$flash['text']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= __t('close') ?>"></button>
        </div>
        <?php SessionHelper::forget('flash'); ?>
      <?php endif; ?>

      <!-- Grouped session variables -->
      <div class="accordion" id="<?= $accordionId ?>">
        <?php $i = 0; foreach ($grouped as $prefix => $vars): ?>
          <?php
            $headingId = "heading{$i}";
            $collapseId = "collapse{$i}";
            $isFirst = ($i === 0);
            $count = is_countable($vars) ? count($vars) : 0;
          ?>
          <div class="accordion-item">
            <h2 class="accordion-header" id="<?= $headingId ?>">
              <button
                class="accordion-button <?= $isFirst ? '' : 'collapsed' ?>"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#<?= $collapseId ?>"
                aria-expanded="<?= $isFirst ? 'true' : 'false' ?>"
                aria-controls="<?= $collapseId ?>"
              >
                <?= h((string)$prefix) ?>
                <span class="badge bg-secondary ms-2"><?= $count ?></span>
              </button>
            </h2>
            <div id="<?= $collapseId ?>"
                 class="accordion-collapse collapse <?= $isFirst ? 'show' : '' ?>"
                 aria-labelledby="<?= $headingId ?>"
                 data-bs-parent="#<?= $accordionId ?>">
              <div class="accordion-body p-0">
                <div class="table-responsive">
                  <table class="table table-striped table-hover align-middle mb-0">
                    <thead class="table-light">
                      <tr>
                        <th><?= __t('key') ?></th>
                        <th><?= __t('value') ?></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if ($count === 0): ?>
                        <tr>
                          <td colspan="2" class="text-center text-muted py-3"><?= __t('no_records_found') ?></td>
                        </tr>
                      <?php else: ?>
                        <?php foreach ($vars as $k => $v): ?>
                          <tr>
                            <td class="fw-semibold"><?= h((string)$k) ?></td>
                            <td><pre class="small bg-light p-2 mb-0"><code><?= h(var_export($v, true)) ?></code></pre></td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        <?php $i++; endforeach; ?>
      </div>

      <!-- Footer hint (muted line, consistent with other screens) -->
      <hr class="mt-4 mb-2">
      <p class="text-muted small mb-0">
        <?= __t('showing') ?> <?= (int)$totalVars ?> <?= __t('of') ?> <?= (int)$totalVars ?> <?= __t('entries') ?> ·
        <?= (int)$totalGroups ?> <?= strtolower(__t('groups') ?? 'groups') ?>
      </p>
    </div>
  </div>
</div>

<script>
// Auto-dismiss inline flash after 5s (matches global pattern)
setTimeout(() => {
  const el = document.getElementById('flashMessage');
  if (!el) return;
  el.classList.remove('show');
  el.addEventListener('transitionend', () => el.remove());
}, 5000);
</script>
