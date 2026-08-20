<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('dt_local_value_caps_training')) {
    function dt_local_value_caps_training(mixed $value): string
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
      <h3 class="mb-1"><?= !empty($row['TrainingID']) ? 'Edit CAPS Training Record' : 'Add CAPS Training Record' ?></h3>
      <div class="text-muted">Maintain course, employee, and completion details for CAPS training records.</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="index.php?route=admin/caps-training">
      <i class="bi bi-arrow-left me-1"></i>Back
    </a>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Training Details</strong>
      <span class="text-muted small">
        <?= !empty($row['TrainingID']) ? 'Entry #' . h((string)$row['TrainingID']) : 'New Entry' ?>
      </span>
    </div>
    <div class="card-body">
      <form method="post" action="index.php?route=admin/caps-training-save">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="TrainingID" value="<?= h((string)($row['TrainingID'] ?? 0)) ?>">

        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">Course ID</label>
            <input class="form-control" maxlength="10" name="CourseID" value="<?= h((string)($row['CourseID'] ?? '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Offering ID</label>
            <input class="form-control" maxlength="10" name="OfferingID" value="<?= h((string)($row['OfferingID'] ?? '')) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Course Title</label>
            <input class="form-control" maxlength="50" name="CourseTitle" value="<?= h((string)($row['CourseTitle'] ?? '')) ?>">
          </div>

          <div class="col-md-3">
            <label class="form-label">Employee ID</label>
            <input class="form-control" maxlength="20" name="EmployeeID" value="<?= h((string)($row['EmployeeID'] ?? '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">First Name</label>
            <input class="form-control" maxlength="50" name="FirstName" value="<?= h((string)($row['FirstName'] ?? '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Last Name</label>
            <input class="form-control" maxlength="50" name="LastName" value="<?= h((string)($row['LastName'] ?? '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Email</label>
            <input class="form-control" maxlength="100" name="Email" value="<?= h((string)($row['Email'] ?? '')) ?>">
          </div>

          <div class="col-md-4">
            <label class="form-label">Completion Date</label>
            <input class="form-control" type="datetime-local" name="CompletionDate" value="<?= h(dt_local_value_caps_training($row['CompletionDate'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Date Updated</label>
            <input class="form-control" type="datetime-local" name="DateUpdated" value="<?= h(dt_local_value_caps_training($row['DateUpdated'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Loaded</label>
            <select class="form-select" name="Loaded">
              <?php foreach ($flagOptions as $option): ?>
                <option value="<?= h($option) ?>" <?= ((string)($row['Loaded'] ?? '') === $option) ? 'selected' : '' ?>>
                  <?= $option === '' ? 'Blank' : h($option) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">File ID</label>
            <input class="form-control" type="number" name="FileID" value="<?= h((string)($row['FileID'] ?? '')) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Updated By</label>
            <input class="form-control" type="number" name="UpdatedBy" value="<?= h((string)($row['UpdatedBy'] ?? '')) ?>">
          </div>
        </div>

        <div class="mt-3 d-flex gap-2">
          <button type="submit" class="btn btn-primary">Save</button>
          <a class="btn btn-outline-secondary" href="index.php?route=admin/caps-training">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
