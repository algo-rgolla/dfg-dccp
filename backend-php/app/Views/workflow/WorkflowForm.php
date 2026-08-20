<?php
declare(strict_types=1);

/** Expected: $task, $types, $statuses, $users, $title (optional), $flash (optional) */

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('t_or')) {
    function t_or(string $key, string $fallback): string {
        $t = __t($key);
        return $t === $key ? $fallback : $t;
    }
}
/** Normalize any date string to YYYY-MM-DD for <input type="date"> */
if (!function_exists('toIsoDate')) {
    function toIsoDate(?string $v): string {
        if (!$v) return '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : '';
    }
}

// ---- Safe fallbacks for possibly-missing vars ----
$task     = $task     ?? null;
$types    = is_array($types ?? null)    ? $types    : [];
$statuses = is_array($statuses ?? null) ? $statuses : [];
$users    = is_array($users ?? null)    ? $users    : [];
$title    = $title    ?? __t(($task && !empty($task['WorkflowTaskID'])) ? 'edit_task' : 'create_task');

// Preserve navigation/query context if provided
$q        = (string)($_GET['q']        ?? '');
$typeID   = ($_GET['typeID']  ?? '') !== '' ? (int)$_GET['typeID']  : null;
$statusID = ($_GET['statusID']?? '') !== '' ? (int)$_GET['statusID'] : null;
$status   = (string)($_GET['status']   ?? '');
$page     = (int)($_GET['page']        ?? 1);
$pageSize = (int)($_GET['pageSize']    ?? 10);
$isIframe = !empty($_GET['iframe']);

// Current values for form fields
$id               = (int)($task['WorkflowTaskID'] ?? 0);
$valTitle         = (string)($task['Title'] ?? '');
$valDescription   = (string)($task['Description'] ?? '');
$valTaskTypeID    = (int)($task['TaskTypeID'] ?? 0);
$valStatusID      = (int)($task['StatusID'] ?? 0);
$valAssignedToID  = isset($task['AssignedToUserID']) ? (int)$task['AssignedToUserID'] : 0;
$valRelatedEntity = (string)($task['RelatedEntity'] ?? '');
$valRelatedKey    = (string)($task['RelatedKey'] ?? '');

// Normalize due date for date input
$rawDue     = $task['DueDate'] ?? $task['DateDue'] ?? $task['Due'] ?? $task['Due_On'] ?? null;
$valDueDate = toIsoDate(is_string($rawDue) ? $rawDue : null);

// Optional one-off flash (controller may pass it)
$flash = $flash ?? null;
?>

<?php if ($isIframe): ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= h($title) ?></title>
  <!-- Local Bootstrap for iframe/standalone rendering -->
  <link href="assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/icons/bootstrap-icons.css" rel="stylesheet">
  <script src="assets/js/bootstrap.bundle.min.js"></script>
</head>
<body class="bg-light p-3">
<div class="container-fluid">
<?php endif; ?>

<!-- Safety net: if rendered via renderPartial WITHOUT iframe=1, inject Bootstrap so styling still applies -->
<script>
(function () {
  if (!window.bootstrap) {
    var head = document.head || document.getElementsByTagName('head')[0];

    var css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = 'assets/css/bootstrap.min.css';
    head.appendChild(css);

    var icons = document.createElement('link');
    icons.rel = 'stylesheet';
    icons.href = 'assets/icons/bootstrap-icons.css';
    head.appendChild(icons);

    var js = document.createElement('script');
    js.src = 'assets/js/bootstrap.bundle.min.js';
    head.appendChild(js);
  }
})();
</script>

<div class="card shadow-sm">
  <!-- Header: consistent with DataObjectCodesForm/UserForm -->
  <div class="card-header d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center">
      <i class="bi bi-clipboard<?= $id > 0 ? '-check' : '-plus' ?> me-2"></i>
      <strong><?= h($title) ?></strong>
    </div>

    <?php
      // Build a resilient back URL
      $qs = [
        'route'    => 'workflow/list',
        'q'        => $q,
        'page'     => $page,
        'pageSize' => $pageSize,
      ];
      if ($typeID   !== null) $qs['typeID']   = (string)$typeID;
      if ($statusID !== null) $qs['statusID'] = (string)$statusID;
      if ($status   !== '')   $qs['status']   = $status;
      if ($isIframe)          $qs['iframe']   = '1';
      $backUrl = 'index.php?' . http_build_query($qs);
    ?>
    <a href="<?= h($backUrl) ?>" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i><?= __t('back') ?>
    </a>
  </div>

  <div class="card-body">
    <?php if (is_array($flash) && !empty($flash['text'])): ?>
      <div class="alert alert-<?= h($flash['type'] ?? 'info') ?> alert-dismissible fade show mb-3" role="alert">
        <?= $flash['text'] /* controller controls content */ ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= __t('close') ?>"></button>
      </div>
    <?php endif; ?>

    <!-- Form (needs-validation for consistent Bootstrap feedback) -->
    <form method="post" action="index.php?route=workflow/save" class="needs-validation" novalidate>
      <?php if (function_exists('csrf_field')): ?>
        <?= csrf_field(); ?>
      <?php elseif (function_exists('csrf_token')): ?>
        <input type="hidden" name="_csrf" value="<?= h((string)csrf_token()) ?>">
      <?php endif; ?>

      <?php if ($id > 0): ?>
        <input type="hidden" name="WorkflowTaskID" value="<?= h((string)$id) ?>">
      <?php endif; ?>

      <!-- Preserve navigation context on SAVE -->
      <?php if ($isIframe): ?><input type="hidden" name="iframe" value="1"><?php endif; ?>
      <input type="hidden" name="q"        value="<?= h($q) ?>">
      <input type="hidden" name="page"     value="<?= h((string)$page) ?>">
      <input type="hidden" name="pageSize" value="<?= h((string)$pageSize) ?>">
      <?php if ($typeID   !== null): ?><input type="hidden" name="typeID"   value="<?= h((string)$typeID)   ?>"><?php endif; ?>
      <?php if ($statusID !== null): ?><input type="hidden" name="statusID" value="<?= h((string)$statusID) ?>"><?php endif; ?>
      <?php if ($status   !== ''):   ?><input type="hidden" name="status"   value="<?= h($status) ?>"><?php endif; ?>

      <div class="row mb-3">
        <div class="col-12 col-lg-8">
          <label class="form-label"><?= t_or('title', 'Title') ?></label>
          <input type="text" name="Title" class="form-control" required value="<?= h($valTitle) ?>">
          <div class="invalid-feedback"><?= t_or('required_field', 'This field is required.') ?></div>
        </div>
        <div class="col-12 col-lg-4">
          <label class="form-label"><?= t_or('due_date', 'Due Date') ?></label>
          <input type="date" name="DueDate" class="form-control" required value="<?= h($valDueDate) ?>">
          <div class="invalid-feedback"><?= t_or('required_field', 'This field is required.') ?></div>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label"><?= t_or('description', 'Description') ?></label>
        <textarea name="Description" rows="4" class="form-control" required><?= h($valDescription) ?></textarea>
        <div class="invalid-feedback"><?= t_or('required_field', 'This field is required.') ?></div>
      </div>

      <div class="row mb-3">
        <div class="col-12 col-md-4">
          <label class="form-label"><?= t_or('type', 'Type') ?></label>
          <select name="TaskTypeID" class="form-select" required>
            <option value=""><?= t_or('select_type', 'Select type') ?></option>
            <?php foreach ($types as $tid => $tname): ?>
              <?php $sel = ((int)$tid === $valTaskTypeID) ? 'selected' : ''; ?>
              <option value="<?= h((string)$tid) ?>" <?= $sel ?>><?= h((string)$tname) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="invalid-feedback"><?= t_or('required_field', 'This field is required.') ?></div>
        </div>

        <div class="col-12 col-md-4">
          <label class="form-label"><?= t_or('status', 'Status') ?></label>
          <select name="StatusID" class="form-select" required>
            <option value=""><?= t_or('select_status', 'Select status') ?></option>
            <?php foreach ($statuses as $sid => $sname): ?>
              <?php $sel = ((int)$sid === $valStatusID) ? 'selected' : ''; ?>
              <option value="<?= h((string)$sid) ?>" <?= $sel ?>><?= h((string)$sname) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="invalid-feedback"><?= t_or('required_field', 'This field is required.') ?></div>
        </div>

        <div class="col-12 col-md-4">
          <label class="form-label"><?= t_or('assigned_to', 'Assigned To') ?></label>
          <select name="AssignedToUserID" class="form-select" required>
            <option value=""><?= t_or('select_user', 'Select user') ?></option>
            <?php foreach ($users as $u): ?>
              <?php
                $uid   = (int)($u['UserID'] ?? 0);
                $label = (string)($u['DisplayName'] ?? $u['Username'] ?? ('#'.$uid));
                $sel   = $uid === $valAssignedToID ? 'selected' : '';
              ?>
              <option value="<?= h((string)$uid) ?>" <?= $sel ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="invalid-feedback"><?= t_or('required_field', 'This field is required.') ?></div>
        </div>
      </div>

      <div class="row mb-3">
        <div class="col-12 col-md-6">
          <label class="form-label"><?= t_or('related_entity', 'Related Entity') ?></label>
          <input type="text" name="RelatedEntity" class="form-control" value="<?= h($valRelatedEntity) ?>">
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label"><?= t_or('related_key', 'Related Key') ?></label>
          <input type="text" name="RelatedKey" class="form-control" value="<?= h($valRelatedKey) ?>">
        </div>
      </div>

      <?php if ($id > 0): ?>
        <div class="mb-3 small text-muted">
          <div><?= __t('id') ?>: <?= h((string)$id) ?></div>
          <?php if (!empty($task['CreatedAt'])): ?>
            <div><?= __t('created_at') ?>: <?= h((string)$task['CreatedAt']) ?></div>
          <?php endif; ?>
          <?php if (!empty($task['UpdatedAt'])): ?>
            <div><?= __t('updated_at') ?>: <?= h((string)$task['UpdatedAt']) ?></div>
          <?php endif; ?>
          <?php if (!empty($task['CompletedAt'])): ?>
            <div><?= t_or('completed_at', 'Completed at') ?>: <?= h((string)$task['CompletedAt']) ?></div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- Bottom bar (consistent muted line + small buttons) -->
      <hr class="mt-4 mb-2">
      <div class="d-flex justify-content-between align-items-center">
        <p class="text-muted small mb-0">
          <?= t_or('form_save_hint', 'Changes are not saved until you click Save.') ?>
        </p>
        <div class="d-flex gap-2">
          <a href="<?= h($backUrl) ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i><?= __t('back') ?>
          </a>
          <button type="submit" class="btn btn-sm btn-primary">
            <i class="bi bi-save me-1"></i><?= __t('save') ?>
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Bootstrap validation (consistent across forms) -->
<script>
(() => {
  'use strict';
  const forms = document.querySelectorAll('.needs-validation');
  Array.from(forms).forEach(form => {
    form.addEventListener('submit', e => {
      if (!form.checkValidity()) {
        e.preventDefault();
        e.stopPropagation();
      }
      form.classList.add('was-validated');
    }, false);
  });
})();
</script>

<?php if ($isIframe): ?>
</div>
</body>
</html>
<?php endif; ?>
