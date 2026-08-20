<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$row = is_array($row ?? null) ? $row : [];
$validationErrors = is_array($validationErrors ?? null) ? $validationErrors : [];
$csrf = h(csrf_token());

function field_error(array $errs, string $key): ?string
{
    return $errs[$key] ?? null;
}
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1"><?= !empty($row['id']) ? 'Edit Custom Suburb' : 'Add Custom Suburb' ?></h3>
      <div class="text-muted">Saved entries are merged into the suburb autocomplete dataset used by forms.</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="index.php?route=admin/suburbs">
      <i class="bi bi-arrow-left me-1"></i>Back
    </a>
  </div>

  <div class="card shadow-sm">
    <div class="card-header">
      <strong>Suburb Details</strong>
    </div>
    <div class="card-body">
      <form method="post" action="index.php?route=admin/suburbs-save" novalidate>
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="id" value="<?= h((string)($row['id'] ?? '')) ?>">

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Suburb</label>
            <?php $e = field_error($validationErrors, 'suburb'); ?>
            <input class="form-control <?= $e ? 'is-invalid' : '' ?>" name="suburb" maxlength="21" value="<?= h((string)($row['n'] ?? '')) ?>" required>
            <?php if ($e): ?><div class="invalid-feedback d-block"><?= h($e) ?></div><?php endif; ?>
            <div class="form-text">Stored in uppercase and trimmed to 21 characters.</div>
          </div>
          <div class="col-md-3">
            <label class="form-label">State</label>
            <?php $e = field_error($validationErrors, 'state'); ?>
            <select class="form-select <?= $e ? 'is-invalid' : '' ?>" name="state" required>
              <option value="">Select a state</option>
              <?php foreach (['ACT','NSW','NT','QLD','SA','TAS','VIC','WA'] as $st): ?>
                <option value="<?= h($st) ?>" <?= ((string)($row['s'] ?? '') === $st) ? 'selected' : '' ?>><?= h($st) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($e): ?><div class="invalid-feedback d-block"><?= h($e) ?></div><?php endif; ?>
          </div>
          <div class="col-md-3">
            <label class="form-label">Postcode</label>
            <?php $e = field_error($validationErrors, 'postcode'); ?>
            <input class="form-control <?= $e ? 'is-invalid' : '' ?>" name="postcode" inputmode="numeric" maxlength="4" value="<?= h((string)($row['p'] ?? '')) ?>" required>
            <?php if ($e): ?><div class="invalid-feedback d-block"><?= h($e) ?></div><?php endif; ?>
          </div>
        </div>

        <div class="mt-3 d-flex gap-2">
          <button type="submit" class="btn btn-primary">Save</button>
          <a class="btn btn-outline-secondary" href="index.php?route=admin/suburbs">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
