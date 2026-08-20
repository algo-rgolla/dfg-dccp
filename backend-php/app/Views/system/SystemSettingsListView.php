<?php
declare(strict_types=1);
/** @var array $rows */

use App\Shared\SessionHelper;

if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

// group by prefix before "_"
$savedSettingKey = trim((string)($savedSettingKey ?? ''));
$grouped = [];
foreach ($rows as $r) {
    $key = (string)$r['SettingKey'];
    $prefix = $key;
    if (str_contains($key, '_')) {
        $prefix = explode('_', $key, 2)[0];
    }
    $grouped[$prefix][] = $r;
}
ksort($grouped);
?>
<div class="container mt-4">
  <h2><i class="bi bi-gear me-2"></i> <?= __t('system_settings') ?></h2>

  <div class="accordion" id="settingsAccordion">
    <?php $i=0; foreach ($grouped as $prefix => $settings): $i++; ?>
      <?php
        $groupContainsSaved = false;
        foreach ($settings as $candidate) {
            if ((string)($candidate['SettingKey'] ?? '') === $savedSettingKey) {
                $groupContainsSaved = true;
                break;
            }
        }
      ?>
      <div class="accordion-item">
        <h2 class="accordion-header" id="heading<?= $i ?>">
          <button class="accordion-button <?= $groupContainsSaved ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse"
                  data-bs-target="#collapse<?= $i ?>" aria-expanded="<?= $groupContainsSaved ? 'true' : 'false' ?>" aria-controls="collapse<?= $i ?>">
            <?= h((string)$prefix) ?> <?= __t('settings') ?>
          </button>
        </h2>
        <div id="collapse<?= $i ?>" class="accordion-collapse collapse <?= $groupContainsSaved ? 'show' : '' ?>"
             aria-labelledby="heading<?= $i ?>" data-bs-parent="#settingsAccordion">
          <div class="accordion-body p-0">
            <div class="table-responsive">
              <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th><?= __t('key') ?></th>
                    <th><?= __t('value') ?></th>
                    <th><?= __t('type') ?></th>
                    <th><?= __t('description') ?></th>
                    <th><?= __t('updated_by') ?></th>
                    <th><?= __t('updated_at') ?></th>
                    <th class="text-end"><?= __t('actions') ?></th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($settings as $r): ?>
                  <?php
                    $settingKey = (string)($r['SettingKey'] ?? '');
                    $settingValue = (string)($r['SettingValue'] ?? '');
                    $settingType = strtolower((string)($r['SettingType'] ?? 'string'));
                    $useTextarea = ($settingType === 'text');
                    $useToggle = ($settingType === 'bool');
                    $isSavedRow = ($settingKey === $savedSettingKey);
                  ?>
                  <form method="post" action="index.php?route=system-settings/save" class="m-0">
                    <?= csrf_field(); ?>
                    <tr id="setting-<?= h($settingKey) ?>" class="<?= $isSavedRow ? 'table-warning' : '' ?>">
                      <td style="width: 15%">
                        <input class="form-control form-control-sm" name="SettingKey"
                               value="<?= h($settingKey) ?>" readonly>
                      </td>
                      <td style="width: 25%">
                        <?php if ($useTextarea): ?>
                          <textarea class="form-control form-control-sm" name="SettingValue" rows="4"><?= h($settingValue) ?></textarea>
                        <?php elseif ($useToggle): ?>
                          <?php $isChecked = in_array(strtolower(trim($settingValue)), ['1', 'true', 'yes', 'on'], true); ?>
                          <input type="hidden" name="SettingValue" value="0">
                          <div class="form-check form-switch">
                            <input
                              class="form-check-input"
                              type="checkbox"
                              role="switch"
                              id="toggle-<?= h($settingKey) ?>"
                              name="SettingValue"
                              value="1"
                              <?= $isChecked ? 'checked' : '' ?>
                            >
                            <label class="form-check-label" for="toggle-<?= h($settingKey) ?>">
                              <?= $isChecked ? 'On' : 'Off' ?>
                            </label>
                          </div>
                        <?php else: ?>
                          <input class="form-control form-control-sm" name="SettingValue"
                                 value="<?= h($settingValue) ?>">
                        <?php endif; ?>
                      </td>
                      <td style="width: 10%">
                        <select class="form-select form-select-sm" name="SettingType">
                          <?php foreach (['string','text','bool','int','json'] as $t): ?>
                            <option value="<?= h($t) ?>"
                              <?= $settingType === $t ? 'selected':'' ?>>
                              <?= __t($t) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td><?= h((string)($r['Description'] ?? '')) ?></td>
                      <td><?= h((string)($r['UpdatedBy'] ?? '')) ?></td>
                      <td><?= h((string)($r['UpdatedAt'] ?? '')) ?></td>
                      <td class="text-end">
                        <button class="btn btn-sm btn-primary" type="submit" title="<?= __t('save') ?>">
                          <i class="bi bi-save me-1"></i><?= __t('save') ?>
                        </button>
                      </td>
                    </tr>
                  </form>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
