<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$rows = is_array($rows ?? null) ? $rows : [];
$templateOptions = is_array($templateOptions ?? null) ? $templateOptions : [];
$applicationTypes = is_array($applicationTypes ?? null) ? $applicationTypes : [];
$selectedTemplateId = trim((string)($selectedTemplateId ?? ''));
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Email Templates</h3>
      <div class="text-muted">Manage editable subjects and bodies for automatic emails without changing code.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index">
        <i class="bi bi-arrow-left me-1"></i>Back
      </a>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Configured Templates</strong>
      <span class="text-muted small"><?= count($rows) ?> template(s)</span>
    </div>
    <div class="card-body border-bottom bg-light">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <div class="fw-semibold">Add Template or Override</div>
          <div class="text-muted small">Choose a template event and optional application type scope, then click Add Template.</div>
        </div>
      </div>
      <form method="get" action="index.php" class="row g-2 align-items-end">
        <input type="hidden" name="route" value="admin/email-templates-edit">
        <div class="col-md-4">
          <label class="form-label">Template Event</label>
          <select class="form-select form-select-sm" name="id" required>
            <option value="">Select template event</option>
            <?php foreach ($templateOptions as $option): ?>
              <option value="<?= h((string)($option['id'] ?? '')) ?>"><?= h((string)($option['label'] ?? ($option['id'] ?? ''))) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Scope</label>
          <select class="form-select form-select-sm" name="application_type_id">
            <option value="">Default</option>
            <?php foreach ($applicationTypes as $type): ?>
              <option value="<?= h((string)($type['ApplicationTypeID'] ?? 0)) ?>">
                <?= h((string)($type['ApplicationTypeName'] ?? ($type['ApplicationTypeKey'] ?? ''))) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <button type="submit" class="btn btn-primary btn-sm">Add Template</button>
        </div>
      </form>
    </div>
    <div class="card-body">
      <form method="get" action="index.php" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="route" value="admin/email-templates">
        <div class="col-md-4">
          <label class="form-label">Filter By Template Event</label>
          <select class="form-select form-select-sm" name="template_id">
            <option value="">Show all template events</option>
            <?php foreach ($templateOptions as $option): ?>
              <?php $optionId = (string)($option['id'] ?? ''); ?>
              <option value="<?= h($optionId) ?>" <?= $selectedTemplateId === $optionId ? 'selected' : '' ?>>
                <?= h((string)($option['label'] ?? $optionId)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-8 d-flex gap-2">
          <button type="submit" class="btn btn-outline-primary btn-sm">Filter</button>
          <a class="btn btn-outline-secondary btn-sm" href="index.php?route=admin/email-templates&reset=1">Clear</a>
        </div>
      </form>
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>Template</th>
              <th>Scope</th>
              <th>Description</th>
              <th>Subject</th>
              <th>Tokens</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="6" class="text-center text-muted">No email templates found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= h((string)($row['label'] ?? '')) ?></td>
                  <td>
                    <span class="badge <?= !empty($row['is_override']) ? 'bg-primary' : 'bg-secondary' ?>">
                      <?= h((string)($row['scope_label'] ?? 'Default')) ?>
                    </span>
                  </td>
                  <td><?= h((string)($row['description'] ?? '')) ?></td>
                  <td><?= h((string)($row['subject'] ?? '')) ?></td>
                  <td>
                    <?php foreach (($row['tokens'] ?? []) as $token): ?>
                      <span class="badge bg-secondary me-1 mb-1"><?= h((string)$token) ?></span>
                    <?php endforeach; ?>
                  </td>
                  <td class="text-end">
                    <a class="btn btn-outline-primary btn-sm" href="index.php?route=admin/email-templates-edit&id=<?= urlencode((string)($row['id'] ?? '')) ?><?= !empty($row['application_type_id']) ? '&application_type_id=' . urlencode((string)$row['application_type_id']) : '' ?>">Edit</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
