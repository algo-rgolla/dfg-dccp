<?php declare(strict_types=1); ?>
<div class="card shadow-sm">
  <div class="card-header"><strong><?= htmlspecialchars($title ?? 'Create System Message', ENT_QUOTES) ?></strong></div>
  <div class="card-body">
    <form method="post" action="index.php?route=systemmessages/create">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Title</label>
          <input name="Title" class="form-control" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Severity</label>
          <select name="Severity" class="form-select">
            <option value="info">info</option>
            <option value="success">success</option>
            <option value="warning">warning</option>
            <option value="danger">danger</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Priority (lower is higher)</label>
          <input name="Priority" type="number" class="form-control" value="10">
        </div>

        <div class="col-12">
          <label class="form-label">Body</label>
          <textarea name="Body" class="form-control" rows="6"></textarea>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" name="IsHtml" id="ishtml" checked>
            <label class="form-check-label" for="ishtml">Body is HTML</label>
          </div>
        </div>

        <div class="col-md-4">
          <label class="form-label">StartAt (UTC)</label>
          <input name="StartAt" type="datetime-local" class="form-control">
        </div>
        <div class="col-md-4">
          <label class="form-label">EndAt (UTC, optional)</label>
          <input name="EndAt" type="datetime-local" class="form-control">
        </div>
        <div class="col-md-4">
          <label class="form-label">Options</label>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="IsDismissible" id="dismiss" checked>
            <label class="form-check-label" for="dismiss">Dismissible (ignored if Requires Ack)</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="RequiresAck" id="reqack">
            <label class="form-check-label" for="reqack">Requires acknowledgement</label>
          </div>
        </div>

        <div class="col-md-6">
          <label class="form-label">Scope: Group Name (optional)</label>
          <input name="ScopeGroupName" class="form-control" placeholder="Matches CAPS GroupName, e.g. APS">
        </div>

        <div class="col-md-6">
          <label class="form-label">Audience</label>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="AudienceGlobal" id="audg">
            <label class="form-check-label" for="audg">Global (all active users)</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="IncludeDescendants" id="incdesc" checked>
            <label class="form-check-label" for="incdesc">Include descendants for selected DataObjectCodes</label>
          </div>
          <div class="mt-2">
            <label class="form-label">DataObjectCodes (comma-separated)</label>
            <input name="DataObjectCodes" class="form-control" placeholder="GOV, HD-HLT, ...">
          </div>
          <div class="mt-2">
            <label class="form-label">Roles (comma-separated)</label>
            <input name="Roles" class="form-control" placeholder="CommsAdmin, Analyst, ...">
          </div>
          <div class="mt-2">
            <label class="form-label">Specific UserIDs (comma-separated)</label>
            <input name="UserIDs" class="form-control" placeholder="123,456,789">
          </div>
        </div>

        <div class="col-md-6">
          <label class="form-label">Email</label>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="SendEmail" id="sendemail">
            <label class="form-check-label" for="sendemail">Send email to audience</label>
          </div>
          <div class="mt-2">
            <label class="form-label">Email Subject</label>
            <input name="EmailSubject" class="form-control" placeholder="Subject line">
          </div>
        </div>
      </div>

      <div class="mt-4 d-flex gap-2">
        <button class="btn btn-secondary" name="Action" value="draft">Save Draft</button>
        <button class="btn btn-primary"  name="Action" value="publish">Publish</button>
        <a class="btn btn-outline-secondary" href="index.php?route=systemmessages/preview">Preview (needs ID)</a>
      </div>
    </form>
  </div>
</div>
