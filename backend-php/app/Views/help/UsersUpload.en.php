<?php
declare(strict_types=1);
?>
<div class="p-3">
  <h5 class="mb-3"><i class="bi bi-upload me-2"></i>Help - Upload Users</h5>
  <p class="text-muted">
    Use this screen to bulk upload user records or update existing users from an approved file format.
  </p>

  <div class="alert alert-info py-2 mb-3" role="alert">
    <i class="bi bi-info-circle me-2"></i>
    Use this help as a guide to the main purpose of the screen, the important checks to make, and any workflow or data impacts to be aware of.
  </div>

  <hr>

  <h6><i class="bi bi-info-circle me-2"></i>Before Uploading</h6>
  <ul>
    <li>use the correct template or expected column layout</li>
    <li>check for duplicate or malformed records</li>
    <li>review role and email values carefully</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i>After Uploading</h6>
  <ul>
    <li>review the result messages for warnings or failed rows</li>
    <li>spot-check a few updated users to confirm the upload behaved as expected</li>
  </ul>

  <hr>
  <p class="text-muted small mb-0">
    Tip: Bulk uploads are fastest when the source file is cleaned first rather than relying on the import step to expose every issue.
  </p>
</div>
