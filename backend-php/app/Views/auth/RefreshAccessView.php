<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

require_once __DIR__ . '/../../../shared/csrf.php';
$csrf = csrf_token();

$uid = $userId ?? null;
$username = $username ?? 'guest';
$employeeId = $employeeId ?? '';
$employeeGroup = $employeeGroup ?? '';
$employeeType = $employeeType ?? '';
$roles = is_array($roles ?? null) ? $roles : [];
$perms = is_array($perms ?? null) ? $perms : [];
$refreshedAt = $refreshedAt ?? null;
$backUrl = 'index.php?route=home/index';

$isIframe = !empty($_GET['iframe']);
?>

<?php if ($isIframe): ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= h($title ?? __t('account_access')) ?></title>
  <link href="assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/icons/bootstrap-icons.css" rel="stylesheet">
  <script src="assets/js/bootstrap.bundle.min.js"></script>
</head>
<body class="p-3 bg-light">
<div class="container-fluid">
<?php endif; ?>

<div class="card shadow-sm mt-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center">
      <i class="bi bi-person-gear me-2"></i>
      <strong><?= __t('account_access') ?></strong>
    </div>
    <div class="d-flex align-items-center gap-2">
      <form method="post"
            action="index.php?route=auth/refreshAccess&UserID=<?= h((string)$uid) ?><?= $isIframe ? '&iframe=1' : '' ?>"
            class="d-inline">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <button type="submit" class="btn btn-sm btn-primary">
          <i class="bi bi-arrow-repeat me-1"></i><?= __t('refresh_access') ?>
        </button>
      </form>
     </div>
  </div>

  <div class="card-body">
    <p class="text-muted mb-3"><?= __t('account_access_intro') ?></p>

    <div class="row g-3">
      <div class="col-12 col-lg-6">
        <div class="card shadow-sm h-100">
          <div class="card-header"><strong><?= __t('user') ?></strong></div>
          <div class="card-body">
            <dl class="row mb-0">
              <dt class="col-sm-4"><?= __t('user_id') ?></dt>
              <dd class="col-sm-8"><?= h($uid ?? '-') ?></dd>

              <dt class="col-sm-4"><?= __t('username_label') ?></dt>
              <dd class="col-sm-8"><?= h($username) ?></dd>

              <dt class="col-sm-4">EmployeeID</dt>
              <dd class="col-sm-8"><?= h($employeeId !== '' ? $employeeId : '-') ?></dd>

              <dt class="col-sm-4">Employee Group</dt>
              <dd class="col-sm-8"><?= h($employeeGroup !== '' ? $employeeGroup : '-') ?></dd>

              <dt class="col-sm-4">Employee Type</dt>
              <dd class="col-sm-8"><?= h($employeeType !== '' ? $employeeType : '-') ?></dd>

              <dt class="col-sm-4"><?= __t('last_refreshed') ?></dt>
              <dd class="col-sm-8"><?= h($refreshedAt ?: __t('not_refreshed_yet')) ?></dd>
            </dl>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-6">
        <div class="card shadow-sm h-100">
          <div class="card-header"><strong><?= __t('roles') ?></strong></div>
          <div class="card-body">
            <?php if ($roles): ?>
              <div class="d-flex flex-wrap gap-2">
                <?php foreach ($roles as $r): ?>
                  <span class="badge text-bg-primary"><?= h($r) ?></span>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="text-muted"><?= __t('no_roles_in_session') ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="card shadow-sm">
          <div class="card-header"><strong><?= __t('permissions') ?></strong></div>
          <div class="card-body">
            <?php if ($perms): ?>
              <div class="d-flex flex-wrap gap-2">
                <?php foreach ($perms as $p): ?>
                  <span class="badge text-bg-secondary"><?= h($p) ?></span>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="text-muted"><?= __t('no_perms_in_session') ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <hr class="mt-4 mb-2">
    <p class="text-muted small mb-0">
      <?= __t('access_refreshed') ?> <?= __t('at_time') ?>: <?= h($refreshedAt ?: __t('not_refreshed_yet')) ?>
    </p>
  </div>
</div>

<?php if ($isIframe): ?>
</div>
</body>
</html>
<?php endif; ?>
