<?php
declare(strict_types=1);

/** @var array $accessibleCodes */
/** @var array $directAccess */
/** @var array $user */
/** @var int $fy */

use App\Shared\SessionHelper;

$canGrant = in_array('DATAOBJECTCODES_ADMIN', SessionHelper::get('auth.perms', []));
?>
<div class="card shadow-sm mt-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>
      <i class="bi bi-eye me-2"></i><?= __t('user_access_report') ?>
      <small class="text-muted">(<?= count($accessibleCodes) ?> <?= __t('total') ?>)</small>
    </strong>
    <a href="index.php?route=dataobjectcodes/access" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i> <?= __t('back') ?>
    </a>
  </div>

  <div class="card-body">
    <!-- USER & FY INFO -->
    <div class="row mb-4">
      <div class="col-md-6">
        <div class="d-flex align-items-center p-3 border rounded bg-light">
          <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" 
               style="width:40px;height:40px;">
            <i class="bi bi-person"></i>
          </div>
          <div>
            <div class="fw-semibold"><?= htmlspecialchars($user['Username'], ENT_QUOTES, 'UTF-8') ?></div>
            <small class="text-muted"><?= htmlspecialchars($user['Email'], ENT_QUOTES, 'UTF-8') ?></small>
            <?php if (!empty($user['FullName'])): ?>
              <div class="small text-muted"><?= htmlspecialchars($user['FullName'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="d-flex align-items-center p-3 border rounded bg-light">
          <div class="bg-info text-white rounded-circle d-flex align-items-center justify-content-center me-3" 
               style="width:40px;height:40px;">
            <i class="bi bi-calendar3"></i>
          </div>
          <div>
            <div class="fw-semibold"><?= __t('fiscal_year') ?>: <?= $fy ?></div>
            <small class="text-muted">
              <?= count($directAccess) ?> <?= __t('direct') ?>, 
              <?= count($accessibleCodes) - count($directAccess) ?> <?= __t('inherited') ?>
            </small>
          </div>
        </div>
      </div>
    </div>

    <!-- DIRECT ACCESS -->
    <?php if (!empty($directAccess)): ?>
      <div class="mb-4">
        <h6 class="mb-2">
          <i class="bi bi-link me-1"></i><?= __t('direct_access') ?> (<?= count($directAccess) ?>)
        </h6>
        <div class="row g-2">
          <?php foreach ($directAccess as $d): ?>
            <div class="col-md-6">
              <div class="d-flex align-items-center p-2 border rounded">
                <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center me-2" 
                     style="width:32px;height:32px;">
                  <i class="bi bi-check-circle"></i>
                </div>
                <div>
                  <div class="fw-semibold"><?= htmlspecialchars($d['DataObjectCode'], ENT_QUOTES, 'UTF-8') ?></div>
                  <small class="text-muted"><?= htmlspecialchars($d['DataObjectName'], ENT_QUOTES, 'UTF-8') ?></small>
                  <span class="badge bg-success ms-1"><?= htmlspecialchars($d['AccessLevel'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- FULL ACCESS TABLE -->
    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th><?= __t('level') ?></th>
            <th><?= __t('code') ?></th>
            <th><?= __t('name') ?></th>
            <th><?= __t('parent') ?></th>
            <th><?= __t('source') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($accessibleCodes)): ?>
            <tr>
              <td colspan="5" class="text-center py-4">
                <i class="bi bi-search text-muted fs-1"></i>
                <p class="text-muted mt-2"><?= __t('no_access_found') ?></p>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($accessibleCodes as $c): ?>
              <tr class="<?= $c['Level'] > 0 ? 'table-light' : '' ?>">
                <td>
                  <span class="badge bg-<?= $c['Level'] === 0 ? 'success' : 'secondary' ?>">
                    <?= $c['Level'] === 0 ? __t('root') : 'L' . $c['Level'] ?>
                  </span>
                </td>
                <td class="fw-semibold"><?= htmlspecialchars($c['DataObjectCode'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($c['DataObjectName'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <?= $c['ParentCode'] 
                    ? htmlspecialchars($c['ParentName'] ?? $c['ParentCode'], ENT_QUOTES, 'UTF-8') 
                    : '<span class="text-muted">' . __t('none') . '</span>' 
                  ?>
                </td>
                <td>
                  <span class="badge bg-<?= $c['AccessSource'] === 'Direct' ? 'primary' : 'secondary' ?>">
                    <?= htmlspecialchars($c['AccessSource'], ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card-footer text-muted small">
    <i class="bi bi-info-circle me-1"></i>
    <?= __t('access_report_footer', ['user' => $user['Username'], 'fy' => $fy]) ?>
  </div>
</div>