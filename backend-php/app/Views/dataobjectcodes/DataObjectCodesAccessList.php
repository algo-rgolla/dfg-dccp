<?php
declare(strict_types=1);

/** @var array $access */
/** @var int $fy */
/** @var string $title */

use App\Shared\SessionHelper;

$canGrant = in_array('DATAOBJECTCODES_ADMIN', SessionHelper::get('auth.perms', []));

// Filters
$qUser = trim($_GET['q_user'] ?? '');
$qCode = trim($_GET['q_code'] ?? '');
$qStatus = $_GET['q_status'] ?? '';
$qDateFrom = $_GET['q_date_from'] ?? '';
$qDateTo = $_GET['q_date_to'] ?? '';

$filtered = false;
if ($qUser || $qCode || $qStatus || $qDateFrom || $qDateTo) {
    $filtered = true;
    $access = array_filter($access, function($a) use ($qUser, $qCode, $qStatus, $qDateFrom, $qDateTo) {
        // User filter
        if ($qUser && stripos($a['Username'] . ' ' . $a['Email'], $qUser) === false) {
            return false;
        }
        
        // Code filter
        if ($qCode && stripos($a['DataObjectCode'], $qCode) === false) {
            return false;
        }
        
        // Status filter
        if ($qStatus && $qStatus !== 'all' && $a['Revoked'] !== ($qStatus === 'revoked')) {
            return false;
        }
        
        // Date range
        if ($qDateFrom || $qDateTo) {
            $assignedAt = new DateTime($a['AssignedAt']);
            if ($qDateFrom && $assignedAt < new DateTime($qDateFrom)) {
                return false;
            }
            if ($qDateTo && $assignedAt > new DateTime($qDateTo . ' 23:59:59')) {
                return false;
            }
        }
        
        return true;
    });
    $access = array_values($access);  // Re-index
}
?>
<div class="card shadow-sm mt-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>
      <i class="bi bi-shield-lock me-2"></i><?= htmlspecialchars(__t($title ?? 'docodes_access_title'), ENT_QUOTES, 'UTF-8') ?>
    </strong>
    <?php if ($canGrant): ?>
      <a href="index.php?route=dataobjectcodes/access_form" class="btn btn-sm btn-primary">
        <i class="bi bi-person-plus me-1"></i> <?= __t('grant_access') ?>
      </a>
    <?php endif; ?>
  </div>

  <div class="card-body">
    <!-- FILTERS -->
    <?php if ($canGrant): ?>
      <div class="p-3 border-bottom">
        <form method="get" class="row g-2 align-items-end">
          <input type="hidden" name="route" value="dataobjectcodes/access">
          
          <div class="col-md-3">
            <label for="q_user" class="form-label small text-muted"><?= __t('user') ?></label>
            <input type="text" id="q_user" name="q_user" value="<?= htmlspecialchars($qUser, ENT_QUOTES, 'UTF-8') ?>" 
                   class="form-control form-control-sm" placeholder="<?= __t('search_user') ?>">
          </div>
          
          <div class="col-md-3">
            <label for="q_code" class="form-label small text-muted"><?= __t('code') ?></label>
            <input type="text" id="q_code" name="q_code" value="<?= htmlspecialchars($qCode, ENT_QUOTES, 'UTF-8') ?>" 
                   class="form-control form-control-sm" placeholder="<?= __t('search_code') ?>">
          </div>
          
          <div class="col-md-2">
            <label for="q_status" class="form-label small text-muted"><?= __t('status') ?></label>
            <select id="q_status" name="q_status" class="form-select form-select-sm">
              <option value="">All</option>
              <option value="active" <?= $qStatus === 'active' ? 'selected' : '' ?>>Active</option>
              <option value="revoked" <?= $qStatus === 'revoked' ? 'selected' : '' ?>>Revoked</option>
            </select>
          </div>
          
          <div class="col-md-2">
            <label for="q_date_from" class="form-label small text-muted">From</label>
            <input type="date" id="q_date_from" name="q_date_from" value="<?= htmlspecialchars($qDateFrom, ENT_QUOTES, 'UTF-8') ?>" 
                   class="form-control form-control-sm">
          </div>
          
          <div class="col-md-2">
            <label for="q_date_to" class="form-label small text-muted">To</label>
            <input type="date" id="q_date_to" name="q_date_to" value="<?= htmlspecialchars($qDateTo, ENT_QUOTES, 'UTF-8') ?>" 
                   class="form-control form-control-sm">
          </div>
          
          <div class="col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-primary flex-fill">
              <i class="bi bi-search"></i>
            </button>
            <a href="index.php?route=dataobjectcodes/access" class="btn btn-sm btn-outline-secondary">
              <i class="bi bi-arrow-clockwise"></i>
            </a>
          </div>
        </form>
        
        <?php if ($filtered): ?>
          <div class="mt-2 small text-muted">
            <?= __t('filtered_results', ['count' => count($access)]) ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th><?= __t('user') ?></th>
            <th><?= __t('code') ?></th>
            <th><?= __t('access_level') ?></th>
            <th><?= __t('assigned_by') ?></th>
            <th><?= __t('assigned_at') ?></th>
            <th><?= __t('status') ?></th>
            <?php if ($canGrant): ?>
              <th class="text-end"><?= __t('action') ?></th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($access)): ?>
            <tr>
              <td colspan="<?= $canGrant ? 7 : 6 ?>" class="text-center py-4">
                <i class="bi bi-search text-muted fs-1"></i>
                <p class="text-muted mt-2"><?= __t('no_results_found') ?></p>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($access as $a): ?>
              <tr class="<?= $a['Revoked'] ? 'table-secondary text-muted' : '' ?>">
                <td>
                  <div class="d-flex align-items-center">
                    <div class="bg-light rounded-circle d-flex align-items-center justify-content-center me-2" 
                         style="width:32px;height:32px;">
                      <i class="bi bi-person text-primary"></i>
                    </div>
                    <div>
                      <div class="fw-semibold"><?= htmlspecialchars($a['Username'], ENT_QUOTES, 'UTF-8') ?></div>
                      <small class="text-muted"><?= htmlspecialchars($a['Email'], ENT_QUOTES, 'UTF-8') ?></small>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="fw-semibold"><?= htmlspecialchars($a['DataObjectCode'], ENT_QUOTES, 'UTF-8') ?></div>
                  <small class="text-muted"><?= htmlspecialchars($a['DataObjectName'], ENT_QUOTES, 'UTF-8') ?></small>
                </td>
                <td>
                  <span class="badge bg-<?= $a['AccessLevel'] === 'edit' ? 'success' : 'info' ?>">
                    <?= htmlspecialchars(ucfirst($a['AccessLevel']), ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </td>
                <td>
                  <div><?= htmlspecialchars($a['AssignedByName'] ?? $a['AssignedBy'], ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td>
                  <div><?= date('d M Y', strtotime($a['AssignedAt'])) ?></div>
                  <small class="text-muted"><?= date('H:i', strtotime($a['AssignedAt'])) ?></small>
                </td>
                <td>
                  <?php if ($a['Revoked']): ?>
                    <span class="badge bg-danger"><?= __t('revoked') ?></span>
                    <div class="small text-muted">
                      by <?= htmlspecialchars($a['RevokedByName'] ?? $a['RevokedBy'], ENT_QUOTES, 'UTF-8') ?>
                      <br><?= date('d M Y H:i', strtotime($a['RevokedAt'])) ?>
                    </div>
                  <?php else: ?>
                    <span class="badge bg-success"><?= __t('active') ?></span>
                  <?php endif; ?>
                </td>
<?php if ($canGrant): ?>
  <td class="text-end">
    <?php if (!$a['Revoked']): ?>
      <div class="btn-group btn-group-sm">
        <!-- REVOKE BUTTON -->
        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#revokeModal"
                data-user="<?= htmlspecialchars($a['Username'], ENT_QUOTES, 'UTF-8') ?>"
                data-code="<?= htmlspecialchars($a['DataObjectCode'], ENT_QUOTES, 'UTF-8') ?>"
                data-revoke-url="index.php?route=dataobjectcodes/access_revoke&revoke=<?= $a['UserID'] . '_' . $a['DataObjectCode'] ?>">
          <i class="bi bi-x-circle"></i>
        </button>

        <!-- VIEW REPORT BUTTON -->
        <a href="index.php?route=dataobjectcodes/access_report&user=<?= $a['UserID'] ?>"
           class="btn btn-outline-info" title="<?= __t('view_access_report') ?>">
          <i class="bi bi-eye"></i>
        </a>
      </div>
    <?php else: ?>
      <span class="text-muted"><?= __t('revoked') ?></span>
    <?php endif; ?>
  </td>
<?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card-footer text-muted small">
    <i class="bi bi-info-circle me-1"></i>
    <?= __t('access_list_footer', ['fy' => $fy]) ?>
  </div>
</div>

<!-- REVOKE CONFIRMATION MODAL -->
<div class="modal fade" id="revokeModal" tabindex="-1" aria-labelledby="revokeModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="revokeModalLabel">
          <i class="bi bi-exclamation-triangle text-warning me-2"></i><?= __t('confirm_revoke') ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __t('close') ?>"></button>
      </div>
      <div class="modal-body">
        <p><?= __t('revoke_confirm_message') ?></p>
        <div class="alert alert-warning">
          <strong><?= __t('user') ?>: </strong><span id="revokeUser"></span><br>
          <strong><?= __t('code') ?>: </strong><span id="revokeCode"></span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <?= __t('cancel') ?>
        </button>
        <a id="confirmRevokeBtn" href="#" class="btn btn-danger">
          <?= __t('revoke') ?>
        </a>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const modal = document.getElementById('revokeModal');
  if (!modal) return;

  modal.addEventListener('show.bs.modal', (event) => {
    const button = event.relatedTarget;
    const user = button.dataset.user;
    const code = button.dataset.code;
    const url = button.dataset.revokeUrl;

    document.getElementById('revokeUser').textContent = user;
    document.getElementById('revokeCode').textContent = code;
    document.getElementById('confirmRevokeBtn').href = url;
  });
});
</script>