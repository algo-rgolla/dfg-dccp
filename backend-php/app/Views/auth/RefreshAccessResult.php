<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// Expected vars from controller:
// $userId, $username, $rolesBefore, $permsBefore, $rolesAfter, $permsAfter, $refreshedAt, $dbName, $backUrl
$uid         = $userId      ?? null;
$username    = $username    ?? 'guest';
$rolesBefore = is_array($rolesBefore ?? null) ? $rolesBefore : [];
$permsBefore = is_array($permsBefore ?? null) ? $permsBefore : [];
$rolesAfter  = is_array($rolesAfter ?? null)  ? $rolesAfter  : [];
$permsAfter  = is_array($permsAfter ?? null)  ? $permsAfter  : [];
$refreshedAt = $refreshedAt ?? null;
$dbName      = $dbName      ?? '(unknown)';
$backUrl     = $backUrl     ?? 'index.php?route=home/index';

$isIframe = !empty($_GET['iframe']);
?>

<?php if ($isIframe): ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= h($title ?? __t('access_refreshed')) ?></title>
  <link href="assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/icons/bootstrap-icons.css" rel="stylesheet">
  <script src="assets/js/bootstrap.bundle.min.js"></script>
</head>
<body class="p-3 bg-light">
<div class="container-fluid">
<?php endif; ?>

<div class="card shadow-sm mt-4">
  <!-- Header -->
  <div class="card-header d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center">
      <i class="bi bi-arrow-repeat me-2"></i>
      <strong><?= __t('access_refreshed') ?></strong>
    </div>
    <div class="d-flex align-items-center gap-2">    
    </div>
  </div>

  <div class="card-body">
    <p class="text-muted mb-3">
      <?= __t('access_refreshed') ?> <?= __t('for_user') ?> <strong><?= h($username) ?></strong> 
      (ID: <?= h((string)$uid) ?>) — <?= __t('at_time') ?> <?= h($refreshedAt) ?><br>
      <?= __t('database') ?>: <?= h($dbName) ?>
    </p>

    <div class="row g-3">
      <!-- Roles Before -->
      <div class="col-12 col-lg-6">
        <div class="card shadow-sm h-100">
          <div class="card-header"><strong><?= __t('roles_before') ?></strong></div>
          <div class="card-body">
            <?php if ($rolesBefore): ?>
              <div class="d-flex flex-wrap gap-2">
                <?php foreach ($rolesBefore as $r): ?>
                  <span class="badge bg-secondary"><?= h($r) ?></span>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="text-muted"><?= __t('no_roles_in_session') ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Roles After -->
      <div class="col-12 col-lg-6">
        <div class="card shadow-sm h-100">
          <div class="card-header"><strong><?= __t('roles_after') ?></strong></div>
          <div class="card-body">
            <?php if ($rolesAfter): ?>
              <div class="d-flex flex-wrap gap-2">
                <?php foreach ($rolesAfter as $r): ?>
                  <span class="badge bg-primary"><?= h($r) ?></span>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="text-muted"><?= __t('no_roles_in_session') ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Permissions Before -->
      <div class="col-12 col-lg-6">
        <div class="card shadow-sm h-100">
          <div class="card-header"><strong><?= __t('permissions_before') ?></strong></div>
          <div class="card-body">
            <?php if ($permsBefore): ?>
              <div class="d-flex flex-wrap gap-2">
                <?php foreach ($permsBefore as $p): ?>
                  <span class="badge bg-secondary"><?= h($p) ?></span>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="text-muted"><?= __t('no_perms_in_session') ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Permissions After -->
      <div class="col-12 col-lg-6">
        <div class="card shadow-sm h-100">
          <div class="card-header"><strong><?= __t('permissions_after') ?></strong></div>
          <div class="card-body">
            <?php if ($permsAfter): ?>
              <div class="d-flex flex-wrap gap-2">
                <?php foreach ($permsAfter as $p): ?>
                  <span class="badge bg-success"><?= h($p) ?></span>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="text-muted"><?= __t('no_perms_in_session') ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Footer -->
    <hr class="mt-4 mb-2">
    <p class="text-muted small mb-0">
      <?= __t('access_refreshed') ?> <?= __t('at_time') ?>: <?= h($refreshedAt) ?>
    </p>
  </div>
</div>

<?php if ($isIframe): ?>
</div>
</body>
</html>
<?php endif; ?>
