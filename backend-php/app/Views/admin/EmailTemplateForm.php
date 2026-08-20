<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$template = is_array($template ?? null) ? $template : [];
$preview = is_array($preview ?? null) ? $preview : ['subject' => '', 'body' => ''];
$applicationTypes = is_array($applicationTypes ?? null) ? $applicationTypes : [];
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1"><?= h((string)($template['label'] ?? 'Edit Email Template')) ?></h3>
      <div class="text-muted"><?= h((string)($template['description'] ?? '')) ?></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=admin/email-templates">
        <i class="bi bi-arrow-left me-1"></i>Back
      </a>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Template Editor</strong>
      <span class="text-muted small"><?= h((string)($template['id'] ?? '')) ?><?= !empty($template['scope_label']) ? ' / ' . h((string)$template['scope_label']) : '' ?></span>
    </div>
    <div class="card-body">
      <form method="post" action="index.php?route=admin/email-templates-save">
        <?= csrf_field() ?>
        <input type="hidden" name="template_id" value="<?= h((string)($template['id'] ?? '')) ?>">

        <div class="mb-3">
          <label class="form-label">Template Scope</label>
          <select name="application_type_id" class="form-select">
            <option value="">Default</option>
            <?php $selectedApplicationTypeId = (int)($template['application_type_id'] ?? 0); ?>
            <?php foreach ($applicationTypes as $type): ?>
              <?php $typeId = (int)($type['ApplicationTypeID'] ?? 0); ?>
              <option value="<?= h((string)$typeId) ?>" <?= $selectedApplicationTypeId === $typeId ? 'selected' : '' ?>>
                <?= h((string)($type['ApplicationTypeName'] ?? ($type['ApplicationTypeKey'] ?? ''))) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Leave blank to edit the default template. Select an application type to create or update a scoped override.</div>
        </div>

        <div class="mb-3">
          <label class="form-label">Subject</label>
          <input type="text" name="subject" class="form-control" required value="<?= h((string)($template['subject'] ?? '')) ?>">
          <div class="form-text">Use token placeholders in the subject if needed.</div>
        </div>

        <div class="mb-3">
          <label class="form-label">Body</label>
          <textarea name="body" class="form-control" rows="14" required><?= h((string)($template['body'] ?? '')) ?></textarea>
          <div class="form-text">Tokens are replaced at send time using the values from the related application or request.</div>
        </div>

        <div class="card border-0 bg-light mb-3">
          <div class="card-body py-3">
            <label class="form-label mb-2">Available Tokens</label>
            <div>
              <?php foreach (($template['tokens'] ?? []) as $token): ?>
                <span class="badge bg-secondary me-1 mb-1"><?= h((string)$token) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-primary btn-sm">Save Template</button>
          <a class="btn btn-outline-secondary btn-sm" href="index.php?route=admin/email-templates">Cancel</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card shadow-sm mt-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Preview</strong>
      <span class="text-muted small">Uses sample token values</span>
    </div>
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label">Preview Subject</label>
        <div class="form-control bg-light"><?= h((string)($preview['subject'] ?? '')) ?></div>
      </div>

      <div>
        <label class="form-label">Preview Body</label>
        <div class="border rounded p-3 bg-light">
          <?= (string)($preview['body'] ?? '') ?>
        </div>
      </div>
    </div>
  </div>
</div>
