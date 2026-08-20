<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('dt_local_value_caps_promaster')) {
    function dt_local_value_caps_promaster(mixed $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        $ts = strtotime($value);
        return $ts === false ? '' : date('Y-m-d\TH:i', $ts);
    }
}

$row = is_array($row ?? null) ? $row : [];
$csrf = h((string)($_csrf ?? csrf_token()));
$flagOptions = ['', 'Y', 'N'];
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1"><?= !empty($row['ProMasterUserID']) ? 'Edit CAPS ProMaster User' : 'Add CAPS ProMaster User' ?></h3>
      <div class="text-muted">Maintain user, contact, status, and controller details for CAPS ProMaster users.</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="index.php?route=admin/caps-promaster-users">
      <i class="bi bi-arrow-left me-1"></i>Back
    </a>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>ProMaster User Details</strong>
      <span class="text-muted small">
        <?= !empty($row['ProMasterUserID']) ? 'Entry #' . h((string)$row['ProMasterUserID']) : 'New Entry' ?>
      </span>
    </div>
    <div class="card-body">
      <form method="post" action="index.php?route=admin/caps-promaster-users-save">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="ProMasterUserID" value="<?= h((string)($row['ProMasterUserID'] ?? 0)) ?>">

        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">Employee ID</label>
            <input class="form-control" name="employee_id" value="<?= h((string)($row['employee_id'] ?? '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">User Name</label>
            <input class="form-control" name="user_name" value="<?= h((string)($row['user_name'] ?? '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">First Name</label>
            <input class="form-control" name="first_name" value="<?= h((string)($row['first_name'] ?? '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Surname</label>
            <input class="form-control" name="surname" value="<?= h((string)($row['surname'] ?? '')) ?>">
          </div>

          <div class="col-md-4">
            <label class="form-label">Location Name</label>
            <input class="form-control" name="location_name" value="<?= h((string)($row['location_name'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Admin Centre</label>
            <input class="form-control" name="admin_ctr" value="<?= h((string)($row['admin_ctr'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Admin Centre Name</label>
            <input class="form-control" name="admin_ctr_name" value="<?= h((string)($row['admin_ctr_name'] ?? '')) ?>">
          </div>

          <div class="col-md-3">
            <label class="form-label">Contractor</label>
            <select class="form-select" name="contractor_ind">
              <?php foreach ($flagOptions as $option): ?>
                <option value="<?= h($option) ?>" <?= ((string)($row['contractor_ind'] ?? '') === $option) ? 'selected' : '' ?>>
                  <?= $option === '' ? 'Blank' : h($option) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Active Indicator</label>
            <select class="form-select" name="active_indicator">
              <?php foreach ($flagOptions as $option): ?>
                <option value="<?= h($option) ?>" <?= ((string)($row['active_indicator'] ?? '') === $option) ? 'selected' : '' ?>>
                  <?= $option === '' ? 'Blank' : h($option) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Locked</label>
            <select class="form-select" name="locked">
              <?php foreach ($flagOptions as $option): ?>
                <option value="<?= h($option) ?>" <?= ((string)($row['locked'] ?? '') === $option) ? 'selected' : '' ?>>
                  <?= $option === '' ? 'Blank' : h($option) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Review Date</label>
            <input class="form-control" name="review_date" value="<?= h((string)($row['review_date'] ?? '')) ?>">
          </div>

          <div class="col-md-3">
            <label class="form-label">Admin Centre Controller</label>
            <select class="form-select" name="admin_centre_controller">
              <?php foreach ($flagOptions as $option): ?>
                <option value="<?= h($option) ?>" <?= ((string)($row['admin_centre_controller'] ?? '') === $option) ? 'selected' : '' ?>>
                  <?= $option === '' ? 'Blank' : h($option) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Enterprise Controller</label>
            <select class="form-select" name="enterprise_controller">
              <?php foreach ($flagOptions as $option): ?>
                <option value="<?= h($option) ?>" <?= ((string)($row['enterprise_controller'] ?? '') === $option) ? 'selected' : '' ?>>
                  <?= $option === '' ? 'Blank' : h($option) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Unprocessed Transactions</label>
            <input class="form-control" type="number" name="unprocessed_transactions" value="<?= h((string)($row['unprocessed_transactions'] ?? '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Active Cards</label>
            <input class="form-control" type="number" name="active_cards" value="<?= h((string)($row['active_cards'] ?? '')) ?>">
          </div>

          <div class="col-md-4">
            <label class="form-label">Email Address</label>
            <input class="form-control" name="email_address" value="<?= h((string)($row['email_address'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Work Phone</label>
            <input class="form-control" name="Work_Phone" value="<?= h((string)($row['Work_Phone'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Mobile</label>
            <input class="form-control" name="Mobile" value="<?= h((string)($row['Mobile'] ?? '')) ?>">
          </div>

          <div class="col-md-6">
            <label class="form-label">Supervisor</label>
            <input class="form-control" name="Supervisor" value="<?= h((string)($row['Supervisor'] ?? '')) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Created By</label>
            <input class="form-control" name="created_by" value="<?= h((string)($row['created_by'] ?? '')) ?>">
          </div>

          <div class="col-md-4">
            <label class="form-label">Extract Date</label>
            <input class="form-control" type="datetime-local" name="extract_date" value="<?= h(dt_local_value_caps_promaster($row['extract_date'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Create Date</label>
            <input class="form-control" type="datetime-local" name="create_date" value="<?= h(dt_local_value_caps_promaster($row['create_date'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Last Logon</label>
            <input class="form-control" type="datetime-local" name="last_logon" value="<?= h(dt_local_value_caps_promaster($row['last_logon'] ?? '')) ?>">
          </div>

          <div class="col-12">
            <label class="form-label">Inactive Reason</label>
            <textarea class="form-control" name="inactive_reason" rows="3"><?= h((string)($row['inactive_reason'] ?? '')) ?></textarea>
          </div>
        </div>

        <div class="mt-3 d-flex gap-2">
          <button type="submit" class="btn btn-primary">Save</button>
          <a class="btn btn-outline-secondary" href="index.php?route=admin/caps-promaster-users">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
