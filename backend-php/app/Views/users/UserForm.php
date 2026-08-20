<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php'; // CSRF helper

/** @var array|null $user */
/** @var array $roles */
/** @var array $userRoles */
/** @var array|null $activationPreview */

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('t_or')) {
    // Translate key; if missing, fallback
    function t_or(string $key, string $fallback): string {
        $t = __t($key);
        return $t === $key ? $fallback : $t;
    }
}

$csrf     = csrf_token();
$id       = (int)($user['UserID'] ?? 0);
$titleKey = $id > 0 ? 'edit_user' : 'create_user';

// --- Normalize assigned roles into a fast lookup set ---
$assignedRoleIds = [];
foreach (($userRoles ?? []) as $ur) {
    // accept row or scalar
    if (is_array($ur)) {
        $val = $ur['RoleID'] ?? $ur['RoleId'] ?? $ur['role_id'] ?? $ur['id'] ?? null;
    } else {
        $val = $ur;
    }
    if ($val !== null && $val !== '') {
        $assignedRoleIds[(int)$val] = true;
    }
}
?>
<div class="container mt-4">
  <div class="card shadow-sm">
    <!-- Header: consistent with DataObjectCodesForm -->
    <div class="card-header d-flex justify-content-between align-items-center">
      <div class="d-flex align-items-center">
        <i class="bi bi-person me-2"></i>
        <strong><?= __t($titleKey) ?></strong>
      </div>
      <div class="d-flex align-items-center gap-2">
        <a href="index.php?route=users/list" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-arrow-left me-1"></i><?= __t('back') ?>
        </a>
        <a href="index.php?route=users/exportUserPdf&id=<?= h((string)$id) ?>" 
           target="_blank" class="btn btn-sm btn-outline-secondary">
           <i class="bi bi-file-earmark-pdf me-1"></i> <?= __t('export_pdf') ?>
        </a>
      </div>
    </div>

    <div class="card-body">
      <!-- Tabs -->
      <ul class="nav nav-tabs mb-3" id="userTab" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="edit-tab" data-bs-toggle="tab"
                  data-bs-target="#edit" type="button" role="tab">
            <?= __t('edit_user') ?>
          </button>
        </li>
        <?php if ($id > 0): ?>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="details-tab" data-bs-toggle="tab"
                    data-bs-target="#details" type="button" role="tab">
              <?= __t('user_details') ?>
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="roles-tab" data-bs-toggle="tab"
                    data-bs-target="#roles" type="button" role="tab">
              <?= __t('assign_roles') ?>
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="account-tab" data-bs-toggle="tab"
                    data-bs-target="#account" type="button" role="tab">
              <?= __t('account_access') ?>
            </button>
          </li>
        <?php endif; ?>
      </ul>

      <div class="tab-content">
        <!-- Edit Tab -->
        <div class="tab-pane fade show active" id="edit" role="tabpanel">
          <form method="post" action="index.php?route=users/save" class="needs-validation" novalidate>
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
            <?php if ($id > 0): ?>
              <input type="hidden" name="UserID" value="<?= h((string)$id) ?>">
            <?php endif; ?>

            <div class="row mb-3">
              <div class="col-md-6">
                <label for="EmployeeID" class="form-label">EmployeeID</label>
                <input type="text" id="EmployeeID" name="EmployeeID" class="form-control"
                       value="<?= h($user['EmployeeID'] ?? '') ?>">
              </div>
              <div class="col-md-6">
                <label for="Username" class="form-label"><?= __t('username_label') ?></label>
                <input type="text" id="Username" name="Username" class="form-control" required
                       value="<?= h($user['Username'] ?? '') ?>">
                <div class="invalid-feedback"><?= __t('required_field') ?: 'This field is required.' ?></div>
              </div>
            </div>

            <div class="row mb-3">
              <div class="col-md-6">
                <label for="Email" class="form-label"><?= __t('email') ?></label>
                <input type="email" id="Email" name="Email" class="form-control"
                       value="<?= h($user['Email'] ?? '') ?>">
                <div class="invalid-feedback"><?= __t('invalid_email') ?: 'Please enter a valid email.' ?></div>
              </div>
            </div>

            <div class="row mb-3">
              <div class="col-md-6">
                <label for="FirstName" class="form-label"><?= __t('first_name') ?></label>
                <input type="text" id="FirstName" name="FirstName" class="form-control"
                       value="<?= h($user['FirstName'] ?? '') ?>">
              </div>
              <div class="col-md-6">
                <label for="LastName" class="form-label"><?= __t('last_name') ?></label>
                <input type="text" id="LastName" name="LastName" class="form-control"
                       value="<?= h($user['LastName'] ?? '') ?>">
              </div>
            </div>

            <div class="row mb-3">
              <div class="col-md-6">
                <label for="DisplayName" class="form-label"><?= __t('display_name') ?></label>
                <input type="text" id="DisplayName" name="DisplayName" class="form-control"
                       value="<?= h($user['DisplayName'] ?? '') ?>">
              </div>
              <div class="col-md-6">
                <label for="Phone" class="form-label"><?= __t('phone') ?></label>
                <input type="text" id="Phone" name="Phone" class="form-control"
                       value="<?= h($user['Phone'] ?? '') ?>">
              </div>
            </div>

            <div class="row mb-3">
              <div class="col-md-6">
                <label for="Department" class="form-label"><?= __t('department') ?></label>
                <input type="text" id="Department" name="Department" class="form-control"
                       value="<?= h($user['Department'] ?? '') ?>">
              </div>
              <div class="col-md-6">
                <label for="JobTitle" class="form-label"><?= __t('job_title') ?></label>
                <input type="text" id="JobTitle" name="JobTitle" class="form-control"
                       value="<?= h($user['JobTitle'] ?? '') ?>">
              </div>
            </div>

            <div class="row g-3 mb-3">
              <div class="col-md-3 form-check">
                <input type="checkbox" class="form-check-input" id="IsActive"
                       name="IsActive" value="1" <?= !empty($user['IsActive']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="IsActive"><?= __t('enabled') ?></label>
              </div>
              <div class="col-md-3 form-check">
                <input type="checkbox" class="form-check-input" id="IsActivated"
                       name="IsActivated" value="1" <?= !empty($user['IsActivated']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="IsActivated">Activated</label>
              </div>
              <div class="col-md-3 form-check">
                <input type="checkbox" class="form-check-input" id="ForcePasswordReset"
                       name="ForcePasswordReset" value="1" <?= !empty($user['ForcePasswordReset']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="ForcePasswordReset"><?= __t('force_password_reset') ?></label>
              </div>
              <div class="col-md-3 form-check">
                <input type="checkbox" class="form-check-input" id="MustChangePassword"
                       name="MustChangePassword" value="1" <?= !empty($user['MustChangePassword']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="MustChangePassword"><?= __t('must_change_password') ?></label>
              </div>
            </div>

            <div class="mb-3">
              <label for="Notes" class="form-label"><?= __t('notes') ?></label>
              <textarea id="Notes" name="Notes" class="form-control" rows="3"><?= h($user['Notes'] ?? '') ?></textarea>
            </div>

            <!-- Bottom bar (Edit tab) -->
            <hr class="mt-4 mb-2">
            <div class="d-flex justify-content-between align-items-center">
              <p class="text-muted small mb-0">
                  <i class="bi bi-info-circle me-1"></i>
                <?= t_or('form_save_hint', 'Changes are not saved until you click Save.') ?>
              </p>
              <div class="d-flex gap-2">
                <?php if ($id > 0): ?>
                  <button type="submit"
                          formaction="index.php?route=users/resetActivation"
                          formmethod="post"
                          class="btn btn-sm btn-outline-warning"
                          onclick="return confirm('Reset activation for this user and issue a new activation link?');">
                    <i class="bi bi-envelope-arrow-up me-1"></i>Reset Activation
                  </button>
                <?php endif; ?>
                <a href="index.php?route=users/list" class="btn btn-sm btn-outline-secondary">
                  <i class="bi bi-arrow-left me-1"></i><?= __t('back') ?>
                </a>
                <button type="submit" class="btn btn-sm btn-primary">
                  <i class="bi bi-save me-1"></i><?= __t('save') ?>
                </button>
              </div>
            </div>
          </form>
        </div>

        <?php if ($id > 0): ?>
        <!-- Details Tab -->
        <div class="tab-pane fade" id="details" role="tabpanel">
          <div class="table-responsive mt-3">
            <table class="table table-striped table-hover align-middle">
              <tbody>
                <tr><th><?= __t('user_id') ?></th><td><?= h((string)$user['UserID']) ?></td></tr>
                <tr><th>Activated</th><td><?= !empty($user['IsActivated']) ? 'Yes' : 'No' ?></td></tr>
                <tr><th><?= __t('last_login') ?></th><td><?= h($user['LastLoginAt'] ?? '—') ?></td></tr>
                <tr><th><?= __t('last_login_ip') ?></th><td><?= h($user['LastLoginIP'] ?? '—') ?></td></tr>
                <tr><th><?= __t('login_count') ?></th><td><?= h((string)($user['LoginCount'] ?? 0)) ?></td></tr>
                <tr><th><?= __t('failed_logins') ?></th><td><?= h((string)($user['FailedLoginCount'] ?? 0)) ?></td></tr>
                <tr><th><?= __t('last_failed_login') ?></th><td><?= h($user['LastFailedLoginAt'] ?? '—') ?></td></tr>
                <tr><th><?= __t('created_at') ?></th><td><?= h($user['CreatedAt'] ?? '—') ?></td></tr>
                <tr><th><?= __t('created_by') ?></th><td><?= h((string)($user['CreatedBy'] ?? '—')) ?></td></tr>
                <tr><th><?= __t('updated_at') ?></th><td><?= h($user['UpdatedAt'] ?? '—') ?></td></tr>
                <tr><th><?= __t('updated_by') ?></th><td><?= h((string)($user['UpdatedBy'] ?? '—')) ?></td></tr>
              </tbody>
            </table>
          </div>

          <?php if (!empty($activationPreview) && is_array($activationPreview)): ?>
            <section class="card border-warning-subtle mt-3">
              <div class="card-header bg-warning-subtle">
                <strong>Activation Link Preview</strong>
              </div>
              <div class="card-body">
                <div class="row g-3">
                  <div class="col-md-4">
                    <div class="small text-muted">Token Status</div>
                    <div><?= !empty($activationPreview['IsUsed']) ? 'Used' : 'Unused' ?></div>
                  </div>
                  <div class="col-md-4">
                    <div class="small text-muted">Expires At</div>
                    <div><?= h((string)($activationPreview['ExpiresAt'] ?? '—')) ?></div>
                  </div>
                  <div class="col-md-4">
                    <div class="small text-muted">Token Email</div>
                    <div><?= h((string)($activationPreview['Email'] ?? '—')) ?></div>
                  </div>
                  <?php if (!empty($activationPreview['UsedAt'])): ?>
                    <div class="col-md-4">
                      <div class="small text-muted">Used At</div>
                      <div><?= h((string)$activationPreview['UsedAt']) ?></div>
                    </div>
                  <?php endif; ?>
                  <div class="col-12">
                    <label class="form-label" for="activationLinkPreview">Activation Link</label>
                    <textarea id="activationLinkPreview" class="form-control font-monospace" rows="3" readonly><?= h((string)($activationPreview['ActivationLink'] ?? '')) ?></textarea>
                    <div class="form-text">This is the exact link currently derived from the latest activation token and portal base URL.</div>
                  </div>
                </div>
              </div>
            </section>
          <?php endif; ?>

          <!-- Bottom bar (Details tab) -->
          <hr class="mt-4 mb-2">
          <div class="d-flex justify-content-between align-items-center">
            <p class="text-muted small mb-0">
              <i class="bi bi-info-circle me-1"></i>
              <?= t_or('form_save_hint', 'Changes are not saved until you click Save.') ?>
            </p>
            <div class="d-flex gap-2">
              <a href="index.php?route=users/list" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i><?= __t('back') ?>
              </a>
            </div>
          </div>
        </div>

        <!-- Roles Tab -->
        <div class="tab-pane fade" id="roles" role="tabpanel">
          <form method="post" action="index.php?route=users/saveRoles" class="mt-3 needs-validation" novalidate>
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="UserID" value="<?= h((string)$user['UserID']) ?>">

            <div class="mb-3">
              <label class="form-label"><?= __t('assign_roles') ?></label>
              <?php if (!empty($roles)): ?>
                <?php foreach ($roles as $r): ?>
                  <?php if (!is_array($r)) continue; ?>
                  <?php $rid = (int)($r['RoleID'] ?? 0); ?>
                  <?php if ($rid === 0) continue; ?>
                  <div class="form-check mb-2">
                    <input class="form-check-input"
                           type="checkbox"
                           name="RoleIDs[]"
                           value="<?= $rid ?>"
                           id="role_<?= $rid ?>"
                           <?= isset($assignedRoleIds[$rid]) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="role_<?= $rid ?>">
                      <?= h((string)($r['RoleName'] ?? 'Unknown Role')) ?>
                    </label>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <p class="text-muted mb-0">  <i class="bi bi-info-circle me-1"></i><?= __t('no_roles_found') ?: 'No roles found.' ?></p>
              <?php endif; ?>
            </div>

            <!-- Bottom bar (Roles tab) -->
            <hr class="mt-4 mb-2">
            <div class="d-flex justify-content-between align-items-center">
              <p class="text-muted small mb-0">
                <i class="bi bi-info-circle me-1"></i>
                <?= t_or('form_save_hint', 'Changes are not saved until you click Save.') ?>
              </p>
              <div class="d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary">
                  <i class="bi bi-save me-1"></i><?= __t('save_roles') ?: __t('save') ?>
                </button>
              </div>
            </div>
          </form>
        </div>

        <!-- Account & Access Tab -->
        <div class="tab-pane fade" id="account" role="tabpanel">
          <div class="mt-3">
            <iframe src="index.php?route=auth/account&UserID=<?= h((string)$user['UserID']) ?>&iframe=1"
                    style="width:100%;height:600px;border:0;"
                    title="<?= __t('account_access') ?>">
            </iframe>
          </div>

          <!-- Bottom bar (Account tab) -->
          <hr class="mt-4 mb-2">
          <div class="d-flex justify-content-between align-items-center">    
            <p class="text-muted small mb-0">   
            <i class="bi bi-info-circle me-1"></i>
              <?= t_or('form_save_hint', 'Changes are not saved until you click Save.') ?>
            </p>
            <div class="d-flex gap-2">
              <a href="index.php?route=users/list" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i><?= __t('back') ?>
              </a>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
// Bootstrap validation (consistent with other forms)
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

<script>
// Deep-link to a specific tab via hash
document.addEventListener('DOMContentLoaded', () => {
  const hash = window.location.hash;
  if (hash) {
    const triggerEl = document.querySelector(`button[data-bs-target="${hash}"]`);
    if (triggerEl) new bootstrap.Tab(triggerEl).show();
  }
});
</script>
