<?php
declare(strict_types=1);
?>
<div class="p-3">
  <h5 class="mb-3"><i class="bi bi-check2-square me-2"></i>Help - Limit Change Approval</h5>
  <p class="text-muted">
    This screen is used by approvers to review and action a submitted limit change request. It supports sequential approval stages and shared approval stages where multiple approvers may be notified.
  </p>

  <div class="alert alert-info py-2 mb-3" role="alert">
    <i class="bi bi-info-circle me-2"></i>
    Use this help as a guide to the main purpose of the screen, the important checks to make, and any workflow or data impacts to be aware of.
  </div>

  <hr>

  <h6><i class="bi bi-info-circle me-2"></i>What You Can See</h6>
  <ul>
    <li>request summary, requester details, and current status</li>
    <li>approval stage information such as <strong>Stage X of Y</strong></li>
    <li>the current approver display or shared-stage message</li>
    <li>previous approvers and timestamps for earlier approval actions</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i>Available Actions</h6>
  <ul>
    <li><strong>Approve:</strong> records your approval and either advances the request or finalises it.</li>
    <li><strong>Reject:</strong> rejects the request and requires a reason.</li>
    <li><strong>Forward:</strong> sends the request to another valid approver where allowed.</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i>Important Rules</h6>
  <ul>
    <li>You cannot action your own limit change request.</li>
    <li>Only valid approvers for the current stage can action the request.</li>
    <li>If this is a shared stage, any one of the notified approvers can approve it.</li>
  </ul>

  <hr>
  <p class="text-muted small mb-0">
    Tip: Check the Previous Approvers section before forwarding or rejecting so you understand what has already happened in the workflow.
  </p>
</div>
