<?php declare(strict_types=1); ?>
<?php
if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
$msg = is_array($msg ?? null) ? $msg : [];
$codes = is_array($codes ?? null) ? $codes : [];
$roles = is_array($roles ?? null) ? $roles : [];
$users = is_array($users ?? null) ? $users : [];
$severity = (string)($msg['Severity'] ?? '1');
$severityMap = ['1' => 'info', '2' => 'warning', '3' => 'danger', '4' => 'success'];
$severityValue = $severityMap[$severity] ?? strtolower($severity);
$startAt = (string)($msg['DeliveryStartUTC'] ?? '');
$endAt = (string)($msg['DeliveryEndUTC'] ?? '');
$startAtValue = $startAt !== '' ? str_replace(' ', 'T', substr($startAt, 0, 16)) : '';
$endAtValue = $endAt !== '' ? str_replace(' ', 'T', substr($endAt, 0, 16)) : '';
?>
<div class="card shadow-sm">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong><?= h((string)($title ?? 'Edit System Message')) ?></strong>
    <a href="index.php?route=systemmessages/index" class="btn btn-sm btn-outline-secondary">Back to List</a>
  </div>
  <div class="card-body">
    <form method="post" action="index.php?route=systemmessages/update">
      <input type="hidden" name="MessageID" value="<?= (int)($msg['MessageID'] ?? 0) ?>">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Title</label>
          <input name="Title" class="form-control" required value="<?= h((string)($msg['Title'] ?? '')) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Severity</label>
          <select name="Severity" class="form-select">
            <?php foreach (['info','success','warning','danger'] as $opt): ?>
              <option value="<?= h($opt) ?>" <?= $severityValue === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label">Body</label>
          <textarea name="Body" class="form-control" rows="6"><?= h((string)($msg['BodyHtml'] ?? '')) ?></textarea>
        </div>
        <div class="col-md-4">
          <label class="form-label">StartAt (UTC)</label>
          <input name="StartAt" type="datetime-local" class="form-control" value="<?= h($startAtValue) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">EndAt (UTC, optional)</label>
          <input name="EndAt" type="datetime-local" class="form-control" value="<?= h($endAtValue) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Options</label>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="RequiresAck" id="reqack" <?= !empty($msg['RequireAck']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="reqack">Requires acknowledgement</label>
          </div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Scope: Group Name (optional)</label>
          <input name="ScopeGroupName" class="form-control" value="<?= h((string)($msg['ScopeGroupName'] ?? '')) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Audience</label>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="AudienceGlobal" id="audg" <?= !empty($msg['IsGlobal']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="audg">Global (all active users)</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="IncludeDescendants" id="incdesc" <?= !empty($msg['DescendantTarget']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="incdesc">Include descendants for selected DataObjectCodes</label>
          </div>
          <div class="mt-2">
            <label class="form-label">DataObjectCodes (comma-separated)</label>
            <input name="DataObjectCodes" class="form-control" value="<?= h(implode(',', $codes)) ?>">
          </div>
          <div class="mt-2">
            <label class="form-label">Roles (comma-separated)</label>
            <input name="Roles" class="form-control" value="<?= h(implode(',', $roles)) ?>">
          </div>
          <div class="mt-2">
            <label class="form-label">Specific UserIDs (comma-separated)</label>
            <input name="UserIDs" class="form-control" value="<?= h(implode(',', array_map('strval', $users))) ?>">
          </div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Email</label>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="SendEmail" id="sendemail" <?= !empty($msg['EmailAlso']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="sendemail">Send email to audience</label>
          </div>
          <div class="mt-2">
            <label class="form-label">Email Subject</label>
            <input name="EmailSubject" class="form-control" value="<?= h((string)($msg['EmailSubject'] ?? '')) ?>">
          </div>
        </div>
      </div>
      <div class="mt-4 d-flex gap-2">
        <button class="btn btn-secondary" name="Action" value="draft">Save Draft</button>
        <button class="btn btn-primary" name="Action" value="publish">Save and Publish</button>
        <a class="btn btn-outline-secondary" href="index.php?route=systemmessages/preview&MessageID=<?= (int)($msg['MessageID'] ?? 0) ?>">Preview</a>
      </div>
    </form>
  </div>
</div>
