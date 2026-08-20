<?php declare(strict_types=1);
/** @var array  $row
 *  @var array  $types
 *  @var string $_csrf
 *  @var string $title
 *  @var string $mode   'create' | 'edit'
 *  @var array  $errors
 *  @var array  $parents
 *  @var array  $attributes
 */

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$row        = $row ?? [];
$mode       = $mode ?? 'create';
$errors     = $errors ?? [];
$parents    = $parents ?? [];
$attributes = $attributes ?? [];
$attrValues = $row['attributes'] ?? [];

$code    = (string)($row['DataObjectCode']       ?? '');
$name    = (string)($row['DataObjectName']       ?? '');
$parent  = (string)($row['DataObjectCodeParent'] ?? '');
$typeId  = (string)($row['DataObjectTypeID']     ?? '');
$desc    = (string)($row['DataObjectDesc']       ?? '');
$status  = (string)($row['DataObjectCodeStatus'] ?? '');

$isEdit = ($mode === 'edit');
?>
<div class="card shadow-sm mt-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>
      <i class="bi bi-collection me-2"></i>
      <?= h(__t($title ?? ($isEdit ? 'docodes_edit_title' : 'docodes_add_title'))) ?>
      <?php if ($isEdit && $code): ?>
        <span class="text-muted small ms-2">
          – <?= h($code) ?> – <?= h($name) ?>
        </span>
      <?php endif; ?>
    </strong>
    <a href="index.php?route=dataobjectcodes/index" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left-short"></i> <?= __t('back_to_list') ?>
    </a>
  </div>

  <div class="card-body">
    <form class="needs-validation" novalidate method="post" action="index.php?route=dataobjectcodes/save">
      <input type="hidden" name="_csrf" value="<?= h($_csrf ?? csrf_token()) ?>">

      <?php if ($isEdit): ?>
        <input type="hidden" name="DataObjectCode" value="<?= h($code) ?>">
      <?php endif; ?>

      <!-- TABS -->
      <ul class="nav nav-tabs mb-4" id="docodeTabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" type="button" role="tab">
            <?= __t('general') ?>
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="attributes-tab" data-bs-toggle="tab" data-bs-target="#attributes" type="button" role="tab">
            <?= __t('custom_attributes') ?> <?= $attributes ? '<span class="badge bg-primary ms-1">' . count($attributes) . '</span>' : '' ?>
          </button>
        </li>
      </ul>

      <div class="tab-content" id="docodeTabContent">
        <!-- GENERAL TAB -->
        <div class="tab-pane fade show active" id="general" role="tabpanel">
          <div class="row g-3">
            <!-- CODE -->
            <div class="col-md-4">
              <label for="DataObjectCode" class="form-label"><?= __t('code') ?></label>
              <input
                type="text"
                class="form-control form-control-sm<?= isset($errors['DataObjectCode']) ? ' is-invalid' : '' ?>"
                id="DataObjectCode"
                name="DataObjectCode"
                value="<?= h($code) ?>"
                maxlength="20"
                <?= $isEdit ? 'readonly' : 'required' ?>
                pattern="^[A-Za-z0-9_\-\.]+$"
                <?php if (!$isEdit): ?>placeholder="<?= __t('docodes_code_ph') ?>"<?php endif; ?>
              >
              <div class="form-text"><?= __t('docodes_code_help') ?></div>
              <div class="invalid-feedback">
                <?= h($errors['DataObjectCode'] ?? __t('docodes_code_invalid')) ?>
              </div>
            </div>

            <!-- NAME -->
            <div class="col-md-8">
              <label for="DataObjectName" class="form-label"><?= __t('name') ?></label>
              <input
                type="text"
                class="form-control form-control-sm<?= isset($errors['DataObjectName']) ? ' is-invalid' : '' ?>"
                id="DataObjectName"
                name="DataObjectName"
                value="<?= h($name) ?>"
                maxlength="100"
                required
                placeholder="<?= __t('docodes_name_ph') ?>"
              >
              <div class="invalid-feedback">
                <?= h($errors['DataObjectName'] ?? __t('docodes_name_invalid')) ?>
              </div>
            </div>

            <!-- PARENT -->
            <div class="col-md-4">
              <label for="DataObjectCodeParent" class="form-label"><?= __t('parent_code') ?></label>
              <select
                id="DataObjectCodeParent"
                name="DataObjectCodeParent"
                class="form-select form-select-sm<?= isset($errors['DataObjectCodeParent']) ? ' is-invalid' : '' ?>"
              >
                <option value=""><?= __t('none') ?></option>
                <?php foreach ($parents as $p): ?>
                  <?php 
                    $pcode = (string)($p['DataObjectCode'] ?? '');
                    $pname = (string)($p['DataObjectName'] ?? '');
                    $ptype = (string)($p['DataObjectTypeName'] ?? '');
                  ?>
                  <option value="<?= h($pcode) ?>" <?= $parent === $pcode ? 'selected' : '' ?>>
                    <?= h($pcode) ?> – <?= h($pname) ?> (Level <?= $p['Level'] ?> - <?= h($ptype) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text"><?= __t('docodes_parent_help') ?></div>
              <div class="invalid-feedback">
                <?= h($errors['DataObjectCodeParent'] ?? __t('docodes_parent_invalid')) ?>
              </div>
            </div>

            <!-- TYPE -->
            <div class="col-md-4">
              <label for="DataObjectTypeID" class="form-label"><?= __t('type') ?></label>
              <select
                id="DataObjectTypeID"
                name="DataObjectTypeID"
                class="form-select form-select-sm<?= isset($errors['DataObjectTypeID']) ? ' is-invalid' : '' ?>"
                required
              >
                <option value=""><?= __t('select_type') ?></option>
                <?php foreach ($types as $t): ?>
                  <?php $tid = (string)($t['DataObjectTypeID'] ?? ''); $tname = (string)($t['DataObjectTypeName'] ?? $tid); ?>
                  <option value="<?= h($tid) ?>" <?= $typeId === $tid ? 'selected' : '' ?>>
                    <?= h($tname) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="invalid-feedback">
                <?= h($errors['DataObjectTypeID'] ?? __t('docodes_type_invalid')) ?>
              </div>
            </div>

            <!-- STATUS -->
            <div class="col-md-4">
              <label for="DataObjectCodeStatus" class="form-label"><?= __t('status') ?></label>
              <select
                id="DataObjectCodeStatus"
                name="DataObjectCodeStatus"
                class="form-select form-select-sm<?= isset($errors['DataObjectCodeStatus']) ? ' is-invalid' : '' ?>"
              >
                <option value=""><?= __t('none') ?></option>
                <option value="Active"   <?= $status === 'Active'   ? 'selected' : '' ?>><?= __t('active') ?></option>
                <option value="Inactive" <?= $status === 'Inactive' ? 'selected' : '' ?>><?= __t('inactive') ?></option>
              </select>
              <div class="invalid-feedback">
                <?= h($errors['DataObjectCodeStatus'] ?? __t('docodes_status_invalid')) ?>
              </div>
            </div>

            <!-- DESCRIPTION -->
            <div class="col-12">
              <label for="DataObjectDesc" class="form-label"><?= __t('description') ?></label>
              <textarea
                id="DataObjectDesc"
                name="DataObjectDesc"
                class="form-control form-control-sm<?= isset($errors['DataObjectDesc']) ? ' is-invalid' : '' ?>"
                rows="3"
                placeholder="<?= __t('optional') ?>"
              ><?= h($desc) ?></textarea>
              <div class="invalid-feedback">
                <?= h($errors['DataObjectDesc'] ?? __t('docodes_desc_invalid')) ?>
              </div>
            </div>
          </div>
        </div>

        <!-- ATTRIBUTES TAB -->
        <div class="tab-pane fade" id="attributes" role="tabpanel">
          <?php if (!$attributes): ?>
            <p class="text-muted"><?= __t('no_custom_attributes') ?></p>
          <?php else: ?>
            <div class="row g-3">
              <?php foreach ($attributes as $attr): ?>
                <?php 
                  $key = $attr['AttributeKey'];
                  $value = $attrValues[$key] ?? '';
                  $type = $attr['AttributeType'];
                  $rules = $attr['rules'] ?? [];
                  $required = $attr['IsRequired'];
                  $options = $attr['options'] ?? [];
                  $isDate = in_array($type, ['date', 'datetime-local']);
                ?>
                <div class="col-md-6 mb-3">
                  <label for="attr_<?= $key ?>" class="form-label">
                    <?= h($attr['AttributeLabel']) ?>
                    <?= $required ? '<span class="text-danger">*</span>' : '' ?>
                  </label>

                  <?php if ($type === 'textarea'): ?>
                    <textarea
                      id="attr_<?= $key ?>"
                      name="attributes[<?= $key ?>]"
                      class="form-control form-control-sm"
                      rows="3"
                      <?= $required ? 'required' : '' ?>
                      <?= isset($rules['minlength']) ? "minlength='{$rules['minlength']}'" : '' ?>
                      <?= isset($rules['maxlength']) ? "maxlength='{$rules['maxlength']}'" : '' ?>
                      <?= isset($rules['pattern']) ? "pattern='" . addslashes($rules['pattern']) . "'" : '' ?>
                      placeholder="<?= h($attr['HelpText'] ?? '') ?>"
                    ><?= h($value) ?></textarea>

                  <?php elseif ($type === 'select' && $options): ?>
                    <select
                      id="attr_<?= $key ?>"
                      name="attributes[<?= $key ?>]"
                      class="form-select form-select-sm"
                      <?= $required ? 'required' : '' ?>
                    >
                      <option value=""><?= __t('select') ?></option>
                      <?php foreach ($options as $optLabel => $optValue): ?>
                        <option value="<?= h($optValue) ?>" <?= $value === $optValue ? 'selected' : '' ?>>
                          <?= h($optLabel) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>

                  <?php elseif ($type === 'color'): ?>
                    <input
                      type="color"
                      id="attr_<?= $key ?>"
                      name="attributes[<?= $key ?>]"
                      class="form-control form-control-color"
                      value="<?= h($value) ?>"
                      <?= $required ? 'required' : '' ?>
                    >

                  <?php elseif ($isDate): ?>
                    <!-- FLATPICKR DATE/TIME INPUT -->
                    <input
                      type="text"
                      id="attr_<?= $key ?>"
                      name="attributes[<?= $key ?>]"
                      class="form-control form-control-sm flatpickr-input"
                      value="<?= h($value) ?>"
                      data-input
                      data-type="<?= h($type) ?>"
                      placeholder="<?= h($attr['HelpText'] ?? 'Select date') ?>"
                      <?= $required ? 'required' : '' ?>
                    >

                  <?php else: ?>
                    <input
                      type="<?= h($type) ?>"
                      id="attr_<?= $key ?>"
                      name="attributes[<?= $key ?>]"
                      class="form-control form-control-sm"
                      value="<?= h($value) ?>"
                      <?= $required ? 'required' : '' ?>
                       <?= $type === 'number' && isset($rules['min']) ? "min='" . $rules['min'] . "'" : '' ?>
                    >
                  <?php endif; ?>

                  <?php if (!empty($rules['message'])): ?>
                    <div class="invalid-feedback">
                      <?= h($rules['message']) ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <hr class="my-4">

      <div class="d-flex justify-content-between">
        <div class="text-muted small">
          <i class="bi bi-info-circle me-1"></i>
          <?= $isEdit
            ? __t('docodes_edit_hint', ['code' => h($code)])
            : __t('docodes_create_hint')
          ?>
        </div>
        <div class="d-flex gap-2">
          <a href="index.php?route=dataobjectcodes/index" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-x-circle me-1"></i> <?= __t('cancel') ?>
          </a>
          <button type="submit" class="btn btn-sm btn-primary">
            <i class="bi bi-check2-circle me-1"></i> <?= __t('save') ?>
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- FLATPICKR CSS + JS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('.needs-validation');
  if (!form) return;

  // Track first invalid field
  let firstInvalid = null;

  // Form validation
  form.addEventListener('submit', (e) => {
    if (!form.checkValidity()) {
      e.preventDefault();
      e.stopPropagation();

      // Find first invalid field
      firstInvalid = form.querySelector(':invalid');
      if (firstInvalid) {
        const tabPane = firstInvalid.closest('.tab-pane');
        if (tabPane) {
          const tabId = tabPane.id;
          const tabButton = document.querySelector(`[data-bs-target="#${tabId}"]`);
          
          // Activate tab
          const bsTab = new bootstrap.Tab(tabButton);
          bsTab.show();

          // Add red badge to tab
          const badge = tabButton.querySelector('.error-badge') || document.createElement('span');
          badge.className = 'error-badge badge bg-danger ms-1';
          badge.textContent = '!';
          if (!tabButton.querySelector('.error-badge')) {
            tabButton.appendChild(badge);
          }

          // Scroll to field
          setTimeout(() => {
            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstInvalid.focus();
          }, 300);
        }
      }
    }
    form.classList.add('was-validated');
  });

  // Real-time validation
  form.querySelectorAll('input, textarea, select').forEach(input => {
    input.addEventListener('input', () => {
      if (input.checkValidity()) {
        input.classList.remove('is-invalid');
        input.classList.add('is-valid');
      } else {
        input.classList.remove('is-valid');
        input.classList.add('is-invalid');
      }
    });
  });

  // Initialize Flatpickr
  flatpickr('.flatpickr-input', {
    enableTime: (input) => input.dataset.type === 'datetime-local',
    dateFormat: (input) => input.dataset.type === 'datetime-local' ? 'Y-m-d\\TH:i:S' : 'Y-m-d',
    altInput: true,
    altFormat: (input) => input.dataset.type === 'datetime-local' ? 'd M Y h:i K' : 'd M Y',
    locale: { firstDayOfWeek: 1 },
    allowInput: true,
    clickOpens: true
  });
});
</script>