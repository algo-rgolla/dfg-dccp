<?php
declare(strict_types=1);
?>
<div class="p-3">
  <h5 class="mb-3"><i class="bi bi-sliders me-2"></i>Help - Request Limit Change</h5>
  <p class="text-muted">
    Use this screen to request a credit limit and, where applicable, transaction limit change for a card. The screen now supports staged approvals and rule-driven approver handling.
  </p>

  <div class="alert alert-info py-2 mb-3" role="alert">
    <i class="bi bi-info-circle me-2"></i>
    Use this help as a guide to the main purpose of the screen, the important checks to make, and any workflow or data impacts to be aware of.
  </div>

  <hr>

  <h6><i class="bi bi-info-circle me-2"></i>What You Enter</h6>
  <ul>
    <li><strong>Scope:</strong> for supported card types you may change both limits, credit only, or transaction only.</li>
    <li><strong>New limits:</strong> enter or select the requested new values.</li>
    <li><strong>Duration:</strong> choose permanent or temporary and complete the date fields where required.</li>
    <li><strong>Reason and confirmation:</strong> explain the request and complete the mandatory aged transaction declaration.</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i>How Approvers Work</h6>
  <ul>
    <li>The approval method is controlled by workflow rules.</li>
    <li>Some rules require a manual approver email entry.</li>
    <li>Some rules auto-determine one approver.</li>
    <li>Some rules notify multiple approvers and any one of them can approve the request.</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i>Approval Process Panel</h6>
  <ul>
    <li>Once the request has been submitted, the screen shows the current approval stage and status.</li>
    <li>Completed approval actions appear in the approval process summary.</li>
    <li>The requester cannot nominate themselves as the approver.</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i>Validation Notes</h6>
  <ul>
    <li>Credit limit values must meet the configured amount rules.</li>
    <li>Transaction limits cannot exceed the relevant credit limit.</li>
    <li>Temporary dates must be complete and valid before submit.</li>
  </ul>

  <hr>
  <p class="text-muted small mb-0">
    Tip: If the approver area is read-only, the workflow rules have already determined who will receive the approval request.
  </p>
</div>
