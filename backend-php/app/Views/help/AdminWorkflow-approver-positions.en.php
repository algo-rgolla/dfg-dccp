<?php
declare(strict_types=1);
?>
<div class="p-3">
  <h5 class="mb-3"><i class="bi bi-person-lines-fill me-2"></i>Help - Workflow Approver Positions</h5>
  <p class="text-muted">
    This screen maintains the approver directory used by workflow rules when an approver type is resolved automatically.
  </p>

  <div class="alert alert-info py-2 mb-3" role="alert">
    <i class="bi bi-info-circle me-2"></i>
    Use this help as a guide to the main purpose of the screen, the important checks to make, and any workflow or data impacts to be aware of.
  </div>

  <hr>

  <h6><i class="bi bi-info-circle me-2"></i>What These Records Control</h6>
  <ul>
    <li>which people appear for approver types such as Supervisor, SES, ASFIN, or CFO</li>
    <li>which employee groups a position applies to</li>
    <li>the display labels and selection values used by workflow logic</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i>Why It Matters</h6>
  <ul>
    <li>If a rule resolves to an approver type with no valid position records, the workflow may have no approvers available.</li>
    <li>These records also influence which approvers appear on request and forward screens.</li>
  </ul>

  <hr>
  <p class="text-muted small mb-0">
    Tip: After changing approver positions, test the relevant workflow rule to make sure the expected people are now being resolved.
  </p>
</div>
